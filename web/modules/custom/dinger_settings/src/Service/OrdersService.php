<?php

namespace Drupal\dinger_settings\Service;

use DateTimeZone;
use Drupal;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Logger\LoggerChannelFactory;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItemInterface;
use Drupal\dinger_settings\Plugin\Action\BaseHuchaGcAction;
use Drupal\dinger_settings\Utils\OrderStatus;
use Drupal\node\Entity\Node;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class OrdersService
{
  protected LoggerChannelInterface $logger;

  /**
   * @param LoggerChannelFactory $logger
   */
  public function __construct(LoggerChannelFactory $logger) {
    $this->logger = $logger->get('OrdersService');
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

  public function onOrderUpdated(Node $order): void {

    $this->logger->debug('Order @id updated', ['@id' => $order->id()]);

    if ($order->isNew()) {
      throw new BadRequestHttpException('Order has invalid state. Should not be new.');
    }

    /** @var Node $originalOrder **/
    $originalOrder = $order->getOriginal();
    $orderStatus = OrderStatus::fromString($order->get('field_order_status')->getString());
    $orderStatusUpdated = $orderStatus !== OrderStatus::fromString($originalOrder->get('field_order_status')->getString());
    if ($orderStatusUpdated) {
      if ($orderStatus === OrderStatus::DELIVERED) {

        /** @var TransactionsService $transactionsService **/
        $transactionsService = Drupal::service('hucha_settings.transactions_service');
        $transactionsService->processDeliveredOrderTransactions($order);

        /** @var Node $attributedCall **/
        $attributedCall = $order->get('field_order_attributed_call')->entity;

        /** @var GoogleCloudService $googleCloudService **/
        $googleCloudService = Drupal::service('dinger_settings.google_cloud_service');
        $googleCloudService->deleteGcTask($attributedCall->get(BaseHuchaGcAction::GC_TASK_FIELD_NAME)->getString());
        $googleCloudService->deleteGcTask($order->get(BaseHuchaGcAction::GC_TASK_FIELD_NAME)->getString());
      }
    }
  }
}
