<?php

namespace Drupal\dinger_settings\Service;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactory;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class BiddingService {

  /**
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
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
    $this->logger = $logger->get('hucha_bidding_service');
  }

  public function onBidUpdated(NodeInterface $bid): void {

    if ($bid->isNew()) {
      throw new BadRequestHttpException('Bid has invalid state. Should not be new.');
    }

    /** @var $initialBid NodeInterface */
    $initialBid = $bid->original;
    $bidStatus = $bid->get('field_bid_status')->getString();
    $bidStatusUpdated = $bidStatus !== $initialBid->get('field_bid_status')->getString();
    if ($bidStatusUpdated) {
      if ($bidStatus === 'confirmed') {
        $this->processConfirmedBid($bid);
      }
    }
  }

  public function onBidCreated(NodeInterface $bid): void {
    if (!$bid->isNew()) {
      throw new BadRequestHttpException('Bid has invalid state. Should be new.');
    }

    /** @var $call NodeInterface */
    $call = $bid->get('field_bid_call')->entity;
    $call->get('field_call_bids')->appendItem(['target_id' => $bid->id()]);

    $bidType = $bid->get('field_bid_type')->getString();
    if ($bidType === 'accept') {
      $this->processConfirmedBid($bid);
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
        ->condition('field_bid_status', 'confirmed')
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

  private function processConfirmedBid(NodeInterface $bid): void {
    $this->logger->info('Process confirmed bid. Bid id: @id', ['@id' => $bid->id()]);
    $bidStatus = $bid->get('field_bid_status')->getString();
    if ($bidStatus !== 'confirmed') {
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
      foreach ($bids as $bidId => $bid) {
        $bid->set('field_bid_status', 'rejected');
        $bid->save();
      }

      $call->set('field_call_status', 'attributed');
      $call->save();
    }
    catch (EntityStorageException|InvalidPluginDefinitionException|PluginNotFoundException $e) {
      $this->logger->error($e);
    }
  }
}
