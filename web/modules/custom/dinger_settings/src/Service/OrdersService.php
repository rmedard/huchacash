<?php

namespace Drupal\dinger_settings\Service;

use DateTimeZone;
use Drupal;
use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\EntityReferenceFieldItemList;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItemInterface;
use Drupal\dinger_settings\Plugin\Action\BaseHuchaGcAction;
use Drupal\dinger_settings\Utils\CallStatus;
use Drupal\dinger_settings\Utils\OrderStatus;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;

final class OrdersService
{
  protected LoggerChannelInterface $logger;

  /**
   * @param LoggerChannelFactoryInterface $loggerFactory
   * @param EntityTypeManagerInterface $entityTypeManager
   */
  public function __construct(
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly GoogleCloudService            $googleCloudService) {
    $this->logger = $this->loggerFactory->get('OrdersService');
  }

  public function isActive(Node $order): bool {
    $deliveryTime = $order->get('field_order_delivery_time')->date;
    if ($deliveryTime instanceof DrupalDateTime) {
      $deliveryTime->setTimezone(new DateTimeZone(DateTimeItemInterface::STORAGE_TIMEZONE));
      $now = new DrupalDateTime('now');
      $now->setTimezone(new DateTimeZone(DateTimeItemInterface::STORAGE_TIMEZONE));
      return $deliveryTime->getTimestamp() > $now->getTimestamp();
    }
    $this->logger->error('Invalid order state');
    return false;
  }

  public function onOrderCreated(Node $order): void
  {
    $orderStatus = OrderStatus::fromString($order->get('field_order_status')->getString());
    if ($orderStatus->isEntryPoint()) {
      /** @var TransactionsService $transactionsService **/
      $transactionsService = Drupal::service('hucha_settings.transactions_service');
      $transactionsService->freezeOrderShoppingCost($order);
    }
  }

  public function onOrderUpdated(Node $order): void {

    $this->logger->debug('Order @id updated', ['@id' => $order->id()]);

    /** @var Node $originalOrder **/
    $originalOrder = $order->getOriginal();
    $orderStatus = OrderStatus::fromString($order->get('field_order_status')->getString());
    $orderStatusUpdated = $orderStatus !== OrderStatus::fromString($originalOrder->get('field_order_status')->getString());
    if ($orderStatusUpdated) {
      if ($orderStatus->isFinalState()) {
        if ($orderStatus === OrderStatus::DELIVERED) {

          /** @var TransactionsService $transactionsService **/
          $transactionsService = Drupal::service('hucha_settings.transactions_service');
          $transactionsService->processDeliveredOrderTransactions($order);
        }

        if ($orderStatus === OrderStatus::CANCELLED) {

          /** Cancel live calls **/
          $this->logger->info('Order @id has been cancelled. Cancelling its live call.', ['@id' => $order->id()]);
          try {
            $storage = $this->entityTypeManager->getStorage('node');
            $callsIds = $storage->getQuery()
              ->accessCheck(false)
              ->condition('type', 'call')
              ->condition('field_call_status', CallStatus::LIVE->value)
              ->condition('field_call_order.target_id', $order->id())
              ->execute();
            $calls = $storage->loadMultiple($callsIds);

            /** @var $orderCall NodeInterface $calls */
            foreach ($calls as $orderCall) {
              $orderCall->set('field_call_status', CallStatus::CANCELLED->value);
              $orderCall->save();
            }
          } catch (InvalidPluginDefinitionException|PluginNotFoundException|EntityStorageException $e) {
            $this->logger->error($e);
          }

          /** Freeze initiator's balance **/
          /** @var TransactionsService $transactions_service */
          $transactions_service = Drupal::service('hucha_settings.transactions_service');
          $transactions_service->unfreezeOrderShoppingCost($order);
        }
      }
    }
  }

  public function onOrderPresave(Node $order): void
  {
    $this->updateOrderTotalCostOnPresave($order);
    if ($order->isNew()) {
      $this->googleCloudService->createNodeExpirationTasksOnPresave($order);
    } else {
      /** @var Node $originalOrder **/
      $originalOrder = $order->getOriginal();
      $orderStatus = OrderStatus::fromString($order->get('field_order_status')->getString());
      $orderStatusUpdated = $orderStatus !== OrderStatus::fromString($originalOrder->get('field_order_status')->getString());
      if ($orderStatusUpdated) {
        if ($orderStatus === OrderStatus::DELIVERING) {
          /** @var GoogleCloudService $gcService */
          $gcService = Drupal::service('dinger_settings.google_cloud_service');
          $gcService->deleteOrderGcTasks($order);

          $order->get('field_order_attributed_call')->entity->set(BaseHuchaGcAction::GC_TASK_FIELD_NAME, '')->save();
          $order->set(BaseHuchaGcAction::GC_TASK_FIELD_NAME, '');
          $order->set(BaseHuchaGcAction::GC_TASK_FIELD_NAME_CALLS_CLEANER, '');
        }
      }
    }
  }

  public function updateOrderOnCallCompleted(Node $call): void {
    $callStatus = CallStatus::fromString($call->get('field_call_status')->getString());
    if (!$callStatus->isFinalState()) {
      $this->logger->error('Invalid call state');
      return;
    }

    try {
      /** @var $order NodeInterface */
      $order = $call->get('field_call_order')->entity;
      $newOrderStatus = OrderStatus::fromString($order->get('field_order_status')->getString());
      if ($callStatus->needsRollback()) {
        /** @var DrupalDateTime $orderDeliveryTime */
        $orderDeliveryTime = $order->get('field_order_delivery_time')->value;
        $newOrderStatus = $orderDeliveryTime < new DrupalDateTime('now') ? OrderStatus::CANCELLED : OrderStatus::IDLE;
      } else if ($callStatus === CallStatus::COMPLETED) {
        $newOrderStatus = OrderStatus::DELIVERED;
      }
      $order->set('field_order_status', $newOrderStatus->value);
      $order->save();
    } catch (EntityStorageException $e) {
      $this->logger->error($e);
    }
  }


  private function updateOrderTotalCostOnPresave(Node $order): void
  {
    /**
     * Update order total price (Everytime the order is updated because order items may have changed)
     */
    $currentTotalPrice = doubleval($order->get('field_order_shopping_total_cost')->getString());
    /** @var EntityReferenceFieldItemList $orderItems **/
    $orderItems = $order->get('field_order_items');
    $totalPrice = 0;
    /**
     * @var Node $orderItem
     */
    foreach ($orderItems->referencedEntities() as $orderItem) {
      $totalPrice += doubleval($orderItem->get('field_order_item_price')->getString());
    }

    if ($currentTotalPrice !== $totalPrice) {
      $order->set('field_order_shopping_total_cost', $totalPrice);
    }
  }
}
