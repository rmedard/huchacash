<?php

namespace Drupal\dinger_settings\Service;

use Drupal;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\dinger_settings\Utils\CallStatus;
use Drupal\dinger_settings\Utils\CallType;
use Drupal\dinger_settings\Utils\OrderStatus;
use Drupal\node\NodeInterface;

final class CallsService
{


  /**
   * Drupal\Core\Logger\LoggerChannelFactory definition.
   *
   * @var LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  public function __construct(
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly FirestoreCloudService         $firestoreCloudService,
    private readonly GoogleCloudService            $googleCloudService
  )
  {
    $this->logger = $this->loggerFactory->get('CallsService');
  }

  public function onCallPresave(NodeInterface $call): void {
    if ($call->isNew()) {
      $this->googleCloudService->createNodeExpirationTasksOnPresave($call);
    }
  }

  public function onCallInserted(NodeInterface $call): void
  {
    /** Create fireCall **/
    $this->firestoreCloudService->createFireCall($call);

    /** Update order status **/
    /** @var NodeInterface $order * */
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

  public function onCallUpdated(NodeInterface $call): void
  {
    if ($call->isNew()) {
      $this->logger->error('Call should NOT be new. CallID: ' . $call->id());
      return;
    }

    /** @var FirestoreCloudService $firestoreCloudService * */
    $firestoreCloudService = Drupal::service('dinger_settings.firestore_cloud_service');
    $firestoreCloudService->updateFireCall($call);

    /** @var $originalCall NodeInterface */
    $originalCall = $call->getOriginal();
    $callStatus = CallStatus::fromString($call->get('field_call_status')->getString());
    $originalCallStatus = CallStatus::fromString($originalCall->get('field_call_status')->getString());
    $callStatusUpdated = $callStatus !== $originalCallStatus;

    if ($callStatusUpdated) {
      $this->logger->info('Call status updated. CallID: @id. Status: [@from => @to]', [
        '@id' => $call->id(),
        '@from' => $originalCallStatus->value,
        '@to' => $callStatus->value
      ]);

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
      } else {
        if ($callStatus === CallStatus::ATTRIBUTED) {
          $callType = CallType::from($call->get('field_call_type')->getString());
          if ($callType !== CallType::FIXED_PRICE) {
            $this->firestoreCloudService->updateFireCall($call);
          }
        }
      }
    }
  }
}
