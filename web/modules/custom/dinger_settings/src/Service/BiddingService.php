<?php

namespace Drupal\dinger_settings\Service;

use Drupal;
use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactory;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\dinger_settings\Form\DingerSettingsConfigForm;
use Drupal\dinger_settings\Utils\BidStatus;
use Drupal\dinger_settings\Utils\BidType;
use Drupal\dinger_settings\Utils\CallStatus;
use Drupal\dinger_settings\Utils\CallType;
use Drupal\dinger_settings\Utils\OrderStatus;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Random\RandomException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class BiddingService {

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
    $this->logger = $logger->get('BiddingService');
  }

  public function onBidUpdated(NodeInterface $bid): void {
    /** @var $originalBid NodeInterface */
    $originalBid = $bid->getOriginal();
    $bidStatus = BidStatus::fromString($bid->get('field_bid_status')->getString());
    $bidType = BidType::fromString($bid->get('field_bid_type')->getString());
    $bidStatusUpdated = $originalBid != null && $bidStatus !== BidStatus::fromString($originalBid->get('field_bid_status')->getString());
    if ($bidStatusUpdated) {
      /** @var TransactionsService $transactionsService */
      $transactionsService = Drupal::service('hucha_settings.transactions_service');

      if ($bidStatus === BidStatus::ACCEPTED) {
        if ($bidType === BidType::BARGAIN) {
          $transactionsService->freezeBargainedServiceFee($bid);
        }
      }
      if ($bidStatus === BidStatus::RENOUNCED) {
        $transactionsService->unfreezeBargainedServiceFee($bid);
      }
      if ($bidStatus === BidStatus::CONFIRMED) {
        $this->processConfirmedBid($bid);
      }
    }
  }

  public function onBidCreated(NodeInterface $bid): void {
    /** @var FirestoreCloudService $firestoreService */
    $firestoreService = Drupal::service('dinger_settings.firestore_cloud_service');

    $bidType = BidType::from($bid->get('field_bid_type')->getString());
    switch ($bidType) {
      case BidType::ACCEPT:
        $this->processConfirmedBid($bid);
        $firestoreService->updateAcceptedCall($bid);
        break;
      case BidType::BARGAIN:
        $firestoreService->createFireBid($bid);
        break;
    }
  }

  public function findCallConfirmedBid(NodeInterface $call): NodeInterface|null {
    if ($call->bundle() !== 'call') {
      throw new BadRequestHttpException('Node should be a call, but is ' . $call->bundle());
    }

    try {
      $confirmedBidId = $this->entityTypeManager
        ->getStorage('node')
        ->getQuery()->accessCheck(FALSE)
        ->condition('type', 'bid')
        ->condition('field_bid_call.target_id', $call->id())
        ->condition('field_bid_status', BidStatus::CONFIRMED->value)
        ->execute();

      if (count($confirmedBidId) > 1) {
        $this->logger->error(t('Invalid State. Call $callId has more than 1 confirmed bid.', ['$callId' => $call->id()]));
        return null;
      }

      if (count($confirmedBidId) == 0) {
        return null;
      }

      /** @var $bid NodeInterface */
      $bid = Node::load(reset($confirmedBidId));
      return $bid;
    }
    catch (InvalidPluginDefinitionException|PluginNotFoundException $e) {
      $this->logger->error($e);
      return null;
    }
  }

  public function rejectAllBidsByCall(NodeInterface $call): void
  {
    try {
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
    } catch (InvalidPluginDefinitionException|PluginNotFoundException|EntityStorageException $e) {
      $this->logger->debug('Rejecting bids of call @callId failed.', ['@callId' => $call->id()]);
      $this->logger->error($e);
    }
  }

  private function processConfirmedBid(NodeInterface $bid): void {
    $this->logger->info('Process confirmed bid. Bid id: @id', ['@id' => $bid->id()]);
    $bidStatus = BidStatus::fromString($bid->get('field_bid_status')->getString());
    if ($bidStatus !== BidStatus::CONFIRMED) {
      throw new BadRequestHttpException(t('Bid @id has invalid status. @invalid should be confirmed', [
        '@id' => $bid->id(),
        '@status' => $bidStatus
      ]));
    }

    try {
      /**
       * Updating call and related bids
       * @var $call NodeInterface
       */
      $this->logger->info('Updating call status and related bids for bid @bid', ['@bid' => $bid->id()]);
      $call = $bid->get('field_bid_call')->entity;
      $existingBidIds = $this->entityTypeManager
        ->getStorage('node')
        ->getQuery()->accessCheck(FALSE)
        ->condition('type', 'bid')
        ->condition('nid', $bid->id(), '<>')
        ->condition('field_bid_call.target_id', $call->id())
        ->execute();
      $bids = Node::loadMultiple($existingBidIds);
      foreach ($bids as $singleBid) {
        $singleBid->set('field_bid_status', BidStatus::REJECTED->value);
        $singleBid->save();
      }

      /**
       * Check whether bid is result of bargain. Update call accordingly
       */
      $query = $call
        ->set('field_call_status', CallStatus::ATTRIBUTED->value)
        ->set('field_call_order_confirm_nbr', $this->getNextOrderNumber());
      $callType = CallType::tryFrom($call->get('field_call_type')->getString());
      $finalServiceFee = doubleval($call->get('field_call_proposed_service_fee')->getString());
      if ($callType->allowsBargain()) {
        $finalServiceFee = doubleval($bid->get('field_bid_amount')->getString());
        $query->set('field_call_proposed_service_fee', $finalServiceFee);
      }
      $immutableConfig = Drupal::config(DingerSettingsConfigForm::SETTINGS);
      $systemServiceFeeRate = doubleval($immutableConfig->get('hucha_base_service_fee_rate'));
      $systemServiceFee = $finalServiceFee * $systemServiceFeeRate / 100;
      $call->set('field_call_system_service_fee', $systemServiceFee);
      $query->save();


      /**
       * Update order
       */
      $call
        ->get('field_call_order')
        ->entity
        ->set('field_order_status', OrderStatus::DELIVERING->value)
        ->set('field_order_executor', $bid->get('field_bid_customer')->entity)
        ->set('field_order_attributed_call', $call)
        ->save();
    }
    catch (EntityStorageException|InvalidPluginDefinitionException|PluginNotFoundException $e) {
      $this->logger->error($e);
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
}
