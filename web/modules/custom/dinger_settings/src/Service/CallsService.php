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
use http\Exception\InvalidArgumentException;

class CallsService {

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

  public function onCallUpdated(NodeInterface $call): void {

    if ($call->isNew()) {
      throw new InvalidArgumentException('Call has invalid state. Should not be new.');
    }

    /** @var $originalCall NodeInterface */
    $originalCall = $call->original;
    $callStatus = $call->get('field_call_status')->getString();
    $callStatusUpdated = $callStatus !== $originalCall->get('field_call_status')->getString();

    if ($callStatusUpdated) {
      try {
        /** @var $order NodeInterface */
        $order = $call->get('field_call_order')->entity;
        switch ($callStatus) {
          case 'attributed':
            $order->set('field_order_status', 'delivering');
            $order->save();
            break;
          case 'cancelled':
          case 'expired':
            $callBidIds = $this->entityTypeManager
              ->getStorage('node')
              ->getQuery()->accessCheck(FALSE)
              ->condition('type', 'bid')
              ->condition('field_bid_call.target_id', $call->id())
              ->execute();
            $bids = Node::loadMultiple($callBidIds);
            foreach ($bids as $bidId => $bid) {
              $bid->set('field_bid_status', 'rejected');
              $bid->save();
            }

            if ($order->get('field_order_status')->getString() === 'bidding') {
              $order->set('field_order_status', 'idle');
              $order->save();
            }
        }
      } catch (EntityStorageException|InvalidPluginDefinitionException|PluginNotFoundException $e) {
        $this->logger->error($e);
      }
    }
  }
}
