<?php
namespace Drupal\dinger_settings\Service;

use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Logger\LoggerChannelFactory;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\node\NodeInterface;

class EntityHandlerService {

  protected LoggerChannelInterface $logger;

  /**
   * @param LoggerChannelFactory $logger
   */
  public function __construct(LoggerChannelFactory $logger) {
    $this->logger = $logger->get('entity_handler_service');
  }

  public function onCallInserted(NodeInterface $call): void {
    $this->logger->info(t('Call @reference created successfully', ['@reference' => $call->getTitle()]));
    if ($call->get('field_call_status')->value == 'live') {

      /** Update order status **/
      $order = $call->get('field_call_order')->entity;
      if ($order instanceof NodeInterface) {
        try {
          $order->set('field_order_status', 'bidding');
          $order->field_order_calls[] = ['target_id' => $call->id()];
          $order->save();
          $this->logger->info(t('Order @reference status updated for call @callReference',
            [
              '@reference' => $order->getTitle(),
              '@callReference' => $call->getTitle()
            ]));
        } catch (EntityStorageException $e) {
          $this->logger->error('Attaching call to order failed. Order: ' . $order->id() . '. Error: ' . $e->getMessage());
        }
      }
    }
  }
}
