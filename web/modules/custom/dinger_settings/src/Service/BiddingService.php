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

    /** @var $initialBid NodeInterface */
    $initialBid = $bid->original;
    $bidStatus = $bid->get('field_bid_status')->getString();
    $bidStatusUpdated = $bidStatus !== $initialBid->get('field_bid_status')->getString();
    if ($bidStatusUpdated) {
      if ($bidStatus === 'confirmed') {

        try {
          /** @var $call NodeInterface */
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
  }
}
