<?php

namespace Drupal\dinger_settings\Service;

use Drupal;
use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactory;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Google\Cloud\Core\Exception\GoogleException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

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

  public function onCallInserted(NodeInterface $call): void {
    if ($call->get('field_call_status')->getString() == 'live') {

      /** Update order status **/
      /** @var NodeInterface $order **/
      $order = $call->get('field_call_order')->entity;
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

  public function onCallUpdated(NodeInterface $call): void {

    if ($call->isNew()) {
      throw new BadRequestHttpException('Call has invalid state. Should not be new.');
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
            $this->processAttributedCall($call);
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

  public function getNextOrderNumber (): int {
    $orderNumber = Drupal::state()->get('next_order_number', 1);
    Drupal::state()->set('next_order_number', $orderNumber + 1);
    return $orderNumber;
  }

  private function processAttributedCall(NodeInterface $call): void {
    $callStatus = $call->get('field_call_status')->getString();
    if ($callStatus !== 'attributed') {
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
    /** @var $biddingService \Drupal\dinger_settings\Service\BiddingService */
    $biddingService = Drupal::service('hucha_settings.bidding_service');
    $confirmedBid = $biddingService->findCallConfirmedBid($call);
    try {
      $order
        ->set('field_order_status', 'delivering')
        ->set('field_order_executor', $confirmedBid->get('field_bid_customer')->entity)
        ->save();
      $call->set('field_call_order_confirm_nbr', $this->getNextOrderNumber());
    }
    catch (EntityStorageException|GoogleException $e) {
      $this->logger->error($e);
    }
  }
}
