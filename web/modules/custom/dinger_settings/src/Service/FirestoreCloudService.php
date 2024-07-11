<?php declare(strict_types = 1);

namespace Drupal\dinger_settings\Service;

use Drupal\Core\Logger\LoggerChannelFactory;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Site\Settings;
use Drupal\dinger_settings\Model\FireCall;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Google\Cloud\Firestore\FirestoreClient;
use Google\Cloud\Firestore\Transaction;

final class FirestoreCloudService {

  /**
   * Drupal\Core\Logger\LoggerChannelFactory definition.
   *
   * @var LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * @var \Google\Cloud\Firestore\FirestoreClient
   */
  protected FirestoreClient $firestoreClient;

  /**
   * @param LoggerChannelFactory $logger
   *
   * @throws \Google\Cloud\Core\Exception\GoogleException
   */
  public function __construct(LoggerChannelFactory $logger)
  {
    $this->logger = $logger->get('firestoreService');

    /** Initialise Firestore Client **/
    $settingsFileLocation = Settings::get('gc_tasks_settings_file');
    $this->firestoreClient = new FirestoreClient(['keyFilePath' => $settingsFileLocation]);
  }

  public function createFireCall(Node $call): void {

    $this->logger->info('Firestore Cloud: Creating fireCall. CallId: @callId', ['@callId' => $call->uuid()]);
    $fireCall = new FireCall($call);

    $this->logger->info('<pre><code>' . print_r($fireCall, TRUE) . '</code></pre>');

    $collectionReference = $this->firestoreClient->collection('live_calls');
    $result = $collectionReference
      ->document($fireCall->id)
      ->create($fireCall->toFirestoreBody());
    $this->logger->info('<pre><code>' . print_r($result, TRUE) . '</code></pre>');
  }

  public function deleteFireCall(Node $call): void {
    $this->logger->info('Deleting fireCall. CallId: @callId', ['@callId' => $call->uuid()]);
    $result = $this->firestoreClient->collection('live_calls')->document($call->id())->delete();
    $this->logger->info('<pre><code>' . print_r($result, TRUE) . '</code></pre>');
  }

  public function updateFireCall(Node $call): void {
    $this->logger->info('Update FireCall action triggered. CallId: @callId', ['@callId' => $call->id()]);

    $initialCall = $call->original;
    $isUpdated = $initialCall != null;
    if ($isUpdated and $initialCall instanceof NodeInterface) {

      $updates = [];

      /**
       * Check if expiration time updated
       * @var \Drupal\Core\Datetime\DrupalDateTime $initialExpirationTime *
       * @var \Drupal\Core\Datetime\DrupalDateTime $currentExpirationTime *
       */
      $initialExpirationTime = $initialCall->get('field_call_expiry_time')->date;
      $currentExpirationTime = $call->get('field_call_expiry_time')->date;
      $expirationTimeUpdated = $initialExpirationTime->diff($currentExpirationTime, TRUE)->f > 0;
      if ($expirationTimeUpdated) {
        $this->logger->info('Call expiry time updated. CallId: @callId', ['@callId' => $call->id()]);
        $updates[] = [
          'path' => 'expiration_time',
          'value' => UtilsService::dateTimeToGcTimestamp($currentExpirationTime)
        ];
      }

      /**
       * Check if call status updated
       */
      $initialStatus = $initialCall->get('field_call_status')->getString();
      $currentStatus = $call->get('field_call_status')->getString();
      $statusUpdated = $initialStatus != $currentStatus;
      if ($statusUpdated) {
        $updates[] = [
          'path' => 'status',
          'value' => $currentStatus
        ];
      }

      /**
       * Check if order confirmation number has been set for the first time (or changed)
       */
      $initialOrderConfirmationNbr = $initialCall->get('field_call_order_confirm_nbr')->getString();
      $currentOrderConfirmationNbr = $call->get('field_call_order_confirm_nbr')->getString();
      if ($initialOrderConfirmationNbr !== $currentOrderConfirmationNbr) {
        $updates[] = [
          'path' => 'order_confirmation_number',
          'value' => intval($currentOrderConfirmationNbr)
        ];
      }

      if (!empty($updates)) {
        $this->logger->info('Updating fireCall ('. $call->uuid() .') with data: <pre><code>' . print_r($updates, TRUE) . '</code></pre>');
        $callReference = $this->firestoreClient->collection('live_calls')->document($call->uuid());
        $this->firestoreClient->runTransaction(function(Transaction $transaction) use ($callReference, $updates) {
          $transaction->update($callReference, $updates);
        });
      }
    }
  }
}
