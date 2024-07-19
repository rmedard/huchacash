<?php

namespace Drupal\dinger_settings\Service;

use DateTimeZone;
use Drupal;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Logger\LoggerChannelFactory;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItemInterface;
use Drupal\node\Entity\Node;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class OrdersService
{
  protected LoggerChannelInterface $logger;

  /**
   * @param LoggerChannelFactory $logger
   */
  public function __construct(LoggerChannelFactory $logger) {
    $this->logger = $logger->get('orders_service');
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
    if ($order->isNew()) {
      throw new BadRequestHttpException('Order has invalid state. Should not be new.');
    }

    /** @var \Drupal\node\Entity\Node $originalOrder **/
    $originalOrder = $order->original;
    $orderStatus = $order->get('field_order_status')->getString();
    $orderStatusUpdated = $orderStatus !== $originalOrder->get('field_order_status')->getString();
    if ($orderStatusUpdated) {
      if ($orderStatus === 'delivered') {
        /** @var \Drupal\dinger_settings\Service\TransactionsService $transactionsService **/
        $transactionsService = Drupal::service('hucha_settings.transactions_service');
        $transactionsService->processDeliveredOrderTransactions($order);
      }
    }
  }
}
