<?php declare(strict_types = 1);

namespace Drupal\dinger_settings\Service;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Logger\LoggerChannelFactory;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Site\Settings;
use Drupal\dinger_settings\Model\FireCall;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Exception;
use Google\Cloud\Core\Exception\GoogleException;
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
   * @var ?FirestoreClient
   */
  protected ?FirestoreClient $firestoreClient;

  protected bool $clientInitializing = false;

  /**
   * @param LoggerChannelFactory $logger
   *
   */
  public function __construct(LoggerChannelFactory $logger)
  {
    $this->logger = $logger->get('firestoreService');
    $this->firestoreClient = null;
  }

  /**
   * Get or create Firestore client with proper configuration
   *
   * @return FirestoreClient
   * @throws GoogleException
   */
  private function getFirestoreClient(): FirestoreClient {
    if ($this->firestoreClient === null && !$this->clientInitializing) {
      $this->clientInitializing = true;
      $settingsFileLocation = Settings::get('gc_tasks_settings_file');

      if (empty($settingsFileLocation) || !file_exists($settingsFileLocation)) {
        throw new GoogleException('Google Cloud credentials file not found');
      }

      try {
        $this->firestoreClient = new FirestoreClient([
          'keyFilePath' => $settingsFileLocation,
          'suppressKeyFileNotice' => true,
          'transport' => 'grpc'
        ]);
      } catch (Exception $e) {
        $this->logger->error('Failed to initialize Firestore client: @error', ['@error' => $e->getMessage()]);
        throw $e;
      } finally {
        $this->clientInitializing = false;
      }
    }
    return $this->firestoreClient;
  }


  /**
   * @throws GoogleException
   */
  public function createFireCall(Node $call): void {
    $this->logger->info('Firestore Cloud: Creating fireCall. CallId: @callId', ['@callId' => $call->uuid()]);

    try {
      $fireCall = new FireCall($call);
      $collectionReference = $this->getFirestoreClient()->collection('live_calls');
      $result = $collectionReference
        ->document($fireCall->id)
        ->create($fireCall->toFirestoreBody());

      $this->logger->info('FireCall created successfully: @result', ['@result' => print_r($result, true)]);
    } catch (Exception $e) {
      $this->logger->error('Failed to create FireCall: @error', ['@error' => $e->getMessage()]);
      throw $e;
    }
  }

  /**
   * @throws GoogleException
   */
  public function deleteFireCall(string $callUuid): void {
    $this->logger->info('Deleting fireCall. CallId: @callId', ['@callId' => $callUuid]);
    try {
      $result = $this->getFirestoreClient()
        ->collection('live_calls')
        ->document($callUuid)
        ->delete();

      $this->logger->info('FireCall deleted successfully: @result', ['@result' => print_r($result, true)]);
    } catch (Exception $e) {
      $this->logger->error('Failed to delete FireCall: @error', ['@error' => $e->getMessage()]);
      throw $e;
    }
  }


  /**
   * Update a fire call document
   *
   * @param Node $call
   * @throws GoogleException
   */
  public function updateFireCall(Node $call): void {
    $this->logger->info('Update FireCall action triggered. CallId: @callId', ['@callId' => $call->id()]);

    $originalCall = $call->original;
    if (!$originalCall instanceof NodeInterface) {
      return;
    }

    $updates = $this->prepareUpdates($call, $originalCall);

    if (empty($updates)) {
      return;
    }

    try {
      $callReference = $this->getFirestoreClient()
        ->collection('live_calls')
        ->document($call->uuid());

      $this->logger->info('Updating fireCall (@uuid) with data: @updates', [
        '@uuid' => $call->uuid(),
        '@updates' => print_r($updates, true)
      ]);

      $this->getFirestoreClient()->runTransaction(function(Transaction $transaction) use ($callReference, $updates) {
        try {
          $transaction->update($callReference, $updates);
        } catch (Exception $e) {
          $this->logger->error('Transaction failed: @error', ['@error' => $e->getMessage()]);
          throw $e;
        }
      }, ['maxRetries' => 3]);
    } catch (Exception $e) {
      $this->logger->error('Failed to update FireCall: @error', ['@error' => $e->getMessage()]);
      throw $e;
    }
  }


  /**
   * Prepare updates array based on changes between original and current call
   */
  private function prepareUpdates(Node $call, Node $originalCall): array {
    $updates = [];

    /**
     * Check expiration time updates
     * @var DrupalDateTime $initialExpirationTime *
     * @var DrupalDateTime $currentExpirationTime *
     */
    $initialExpirationTime = $originalCall->get('field_call_expiry_time')->date;
    $currentExpirationTime = $call->get('field_call_expiry_time')->date;
    if ($initialExpirationTime->diff($currentExpirationTime, true)->f > 0) {
      $updates[] = [
        'path' => 'expiration_time',
        'value' => UtilsService::dateTimeToGcTimestamp($currentExpirationTime)
      ];
    }

    // Check status updates
    $initialStatus = $originalCall->get('field_call_status')->getString();
    $currentStatus = $call->get('field_call_status')->getString();
    if ($initialStatus !== $currentStatus) {
      $updates[] = [
        'path' => 'status',
        'value' => $currentStatus
      ];
    }

    // Check order confirmation number updates
    $originalOrderConfirmationNbr = $originalCall->get('field_call_order_confirm_nbr')->getString();
    $currentOrderConfirmationNbr = $call->get('field_call_order_confirm_nbr')->getString();
    if ($originalOrderConfirmationNbr !== $currentOrderConfirmationNbr) {
      $updates[] = [
        'path' => 'order_confirmation_number',
        'value' => empty(trim($currentOrderConfirmationNbr)) ? 0 : intval($currentOrderConfirmationNbr)
      ];
    }

    return $updates;
  }
}
