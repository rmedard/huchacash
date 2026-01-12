<?php

namespace Drupal\dinger_settings\Service;

use Drupal;
use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactory;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\dinger_settings\Form\DingerSettingsConfigForm;
use Drupal\dinger_settings\Utils\BidStatus;
use Drupal\dinger_settings\Utils\CallStatus;
use Drupal\dinger_settings\Utils\OrderStatus;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Random\RandomException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

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
      $order->set('field_order_status', OrderStatus::BIDDING);
      $order->field_order_calls[] = ['target_id' => $call->id()];
      $order->save();
      $this->logger->info(t('Order @reference status updated for call @callReference',
        [
          '@reference' => $order->getTitle(),
          '@callReference' => $call->getTitle()
        ]));


      /** Freeze initiator's balance **/
      /** @var TransactionsService $transactions_service */
      $transactions_service = Drupal::service('hucha_settings.transactions_service');
      $transactions_service->freezeCallShoppingBalance($call);

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
      /** @var TransactionsService $transition_service */
      $transition_service = Drupal::service('hucha_settings.transactions_service');
      if ($callStatus->isFinalState()) {
        if ($callStatus->needsRollback()) {
          $transition_service->unfreezeCallBalance($call);
        }
      } else {
        $transition_service->freezeCallServiceFee($call);
      }
    }
  }

  public function onCallPresave(NodeInterface $call): void {
    $this->logger->info('Triggered call presave for call #' . $call->id());

    $isCallUpdate = !$call->isNew();
    if ($isCallUpdate) {
      /** @var $originalCall NodeInterface */
      $originalCall = $call->getOriginal();
      $callStatus = CallStatus::fromString($call->get('field_call_status')->getString());
      $callStatusUpdated = $callStatus !== CallStatus::fromString($originalCall->get('field_call_status')->getString());

      if ($callStatusUpdated) {
        try {
          /** @var $order NodeInterface */
          $order = $call->get('field_call_order')->entity;
          switch ($callStatus) {
            case CallStatus::ATTRIBUTED:
              $this->processPreAttributedCall($call);
              break;
            case CallStatus::CANCELLED:
            case CallStatus::EXPIRED:
              $callBidIds = $this->entityTypeManager
                ->getStorage('node')
                ->getQuery()->accessCheck(FALSE)
                ->condition('type', 'bid')
                ->condition('field_bid_call.target_id', $call->id())
                ->execute();
              $bids = Node::loadMultiple($callBidIds);
              foreach ($bids as $bid) {
                $bid->set('field_bid_status', BidStatus::REJECTED->value);
                $bid->save();
              }

              /** @var Drupal\Core\Datetime\DrupalDateTime $orderDeliveryTime */
              $orderDeliveryTime = $order->get('field_order_delivery_time')->value;
              if ($orderDeliveryTime < new DrupalDateTime('now')) {
                $order->set('field_order_status', OrderStatus::CANCELLED->value);
              } else {
                if (OrderStatus::fromString($order->get('field_order_status')->getString()) !== OrderStatus::CANCELLED) {
                  $order->set('field_order_status', OrderStatus::IDLE->value);
                }
              }
              $order->save();
              break;
            case CallStatus::COMPLETED:
              $order->set('field_order_status', OrderStatus::DELIVERED->value);
              $order->save();
              break;
            case CallStatus::LIVE:
              $this->logger->warning('Triggered call presave for LIVE call #' . $call->id() . ' => Should never happen');
              break;
          }
        } catch (EntityStorageException|InvalidPluginDefinitionException|PluginNotFoundException $e) {
          $this->logger->error($e);
        }
      }
    }
  }

  public function getNextOrderNumber(): int {
    try {
      return random_int(1000, 9999);
    }
    catch (RandomException $e) {
      $this->logger->error($e->getMessage());
      return 1;
    }
  }

  private function processPreAttributedCall(NodeInterface $call): void {
    $this->logger->debug('Processing pre-attributed Call: ' . $call->id());
    $callStatus = CallStatus::fromString($call->get('field_call_status')->getString());
    if ($callStatus !== CallStatus::ATTRIBUTED) {
      throw new BadRequestHttpException(t('Call @id has invalid status. @invalid should be attributed', [
        '@id' => $call->id(),
        '@status' => $callStatus,
      ]));
    }

    /**
     * Set order delivery executor
     */
    /** @var $order NodeInterface */
    $order = $call->get('field_call_order')->entity;
    /** @var $biddingService BiddingService */
    $biddingService = Drupal::service('hucha_settings.bidding_service');
    $confirmedBid = $biddingService->findCallConfirmedBid($call);
    try {
      $confirmedCostBeforeServiceFee = doubleval($confirmedBid->get('field_bid_amount')->getString());
      $immutableConfig = Drupal::config(DingerSettingsConfigForm::SETTINGS);
      $systemServiceFeeRate = doubleval($immutableConfig->get('hucha_base_service_fee_rate'));
      $systemServiceFee = $confirmedCostBeforeServiceFee * $systemServiceFeeRate / 100;
      $call
        ->set('field_call_order_confirm_nbr', $this->getNextOrderNumber())
        ->set('field_call_proposed_service_fee', $confirmedCostBeforeServiceFee)
        ->set('field_call_system_service_fee', $systemServiceFee);

      $order
        ->set('field_order_status', 'delivering')
        ->set('field_order_executor', $confirmedBid->get('field_bid_customer')->entity)
        ->set('field_order_attributed_call', $call)
        ->save();
    }
    catch (EntityStorageException $e) {
      $this->logger->error($e);
    }
  }
}
