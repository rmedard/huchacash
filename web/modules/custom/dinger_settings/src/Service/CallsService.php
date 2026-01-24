<?php

namespace Drupal\dinger_settings\Service;

use Drupal;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactory;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\dinger_settings\Utils\CallStatus;
use Drupal\dinger_settings\Utils\CallType;
use Drupal\dinger_settings\Utils\OrderStatus;
use Drupal\node\NodeInterface;

final class CallsService {

  /**
   * @var EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Drupal\Core\Logger\LoggerChannelFactory definition.
   *
   * @var LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * @param EntityTypeManagerInterface $entityTypeManager
   * @param LoggerChannelFactory $logger
   */
  public function __construct(EntityTypeManagerInterface $entityTypeManager, LoggerChannelFactory $logger)
  {
    $this->entityTypeManager = $entityTypeManager;
    $this->logger = $logger->get('CallsService');
  }

  public function onCallInserted(NodeInterface $call): void {
    /** Update order status **/
    /** @var NodeInterface $order **/
    $order = $call->get('field_call_order')->entity;
    try {
      $order->set('field_order_status', OrderStatus::BIDDING->value);
      $order->save();
      $this->logger->info(t('Order @reference status updated for call @callReference',
        [
          '@reference' => $order->getTitle(),
          '@callReference' => $call->getTitle()
        ]));

      $callStatus = CallStatus::fromString($call->get('field_call_status')->getString());
      if ($callStatus->freezesBalance()) {
        /** @var TransactionsService $transition_service */
        $transition_service = Drupal::service('hucha_settings.transactions_service');
        $transition_service->freezeCallServiceFee($call);
      }
    } catch (EntityStorageException $e) {
      $this->logger->error('Attaching call to order failed. Order: ' . $order->id() . '. Error: ' . $e->getMessage());
    }
  }

  public function onCallUpdated(NodeInterface $call): void {
    if ($call->isNew()) {
      $this->logger->error('Call should NOT be new. CallID: ' . $call->id());
      return;
    }

    /** @var $originalCall NodeInterface */
    $originalCall = $call->getOriginal();
    $callStatus = CallStatus::fromString($call->get('field_call_status')->getString());
    $originalCallStatus = CallStatus::fromString($originalCall->get('field_call_status')->getString());
    $callStatusUpdated = $callStatus !== $originalCallStatus;

    if ($callStatusUpdated) {
      if ($callStatus->isFinalState()) {
        /** @var OrdersService $orderService */
        $orderService = Drupal::service('hucha_settings.orders_service');
        $orderService->updateOrderOnCallCompleted($call);

        if ($callStatus->needsRollback()) {
          /** @var BiddingService $biddingService */
          $biddingService = Drupal::service('hucha_settings.bidding_service');
          $biddingService->rejectAllBidsByCall($call);

          /** @var TransactionsService $transition_service */
          $transition_service = Drupal::service('hucha_settings.transactions_service');
          $transition_service->unfreezeCallServiceFee($call);
        }
      }
    }

    $callType = CallType::tryFrom($call->get('field_call_type')->getString());
    if ($callType->allowsBargain()) {
      /** @var TransactionsService $transition_service */
      $transition_service = Drupal::service('hucha_settings.transactions_service');
      $transition_service->freezeBargainedServiceFee($call);
    }
  }
}
