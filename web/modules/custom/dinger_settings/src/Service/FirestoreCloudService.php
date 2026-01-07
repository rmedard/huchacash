<?php declare(strict_types = 1);

namespace Drupal\dinger_settings\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Logger\LoggerChannelFactory;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Site\Settings;
use Drupal\dinger_settings\Model\FireBid;
use Drupal\dinger_settings\Model\FireCall;
use Drupal\dinger_settings\Utils\FirestoreFieldFilter;
use Drupal\dinger_settings\Utils\FirestoreFieldValue;
use Drupal\dinger_settings\Utils\FirestoreQueryHelper;
use Drupal\dinger_settings\Utils\FirestoreOperator;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use InvalidArgumentException;
use MrShan0\PHPFirestore\Exceptions\Client\NotFound;
use MrShan0\PHPFirestore\FirestoreClient;
use MrShan0\PHPFirestore\FirestoreDocument;
use Symfony\Component\HttpFoundation\Response;

final class FirestoreCloudService {

  protected Client $httpClient;
  protected ConfigFactoryInterface $configFactory;
  protected LoggerChannelInterface $logger;
  protected ?string $projectId = null;
  protected ?array $credentials = null;


  protected FirestoreClient $firestoreClient;


  /**
   * @throws Exception
   */
  public function __construct(ConfigFactoryInterface $config_factory, LoggerChannelFactory $logger) {
    $this->logger = $logger->get('FirestoreCloudService');
    $this->configFactory = $config_factory;
    $this->initialize();
  }

  /**
   * Initialize credentials and get access token
   * @throws Exception
   */
  private function initialize(): void {
    $settingsFileLocation = Settings::get('gc_tasks_settings_file');
    if (empty($settingsFileLocation) || !file_exists($settingsFileLocation)) {
      throw new Exception('Google Cloud credentials file not found at: ' . $settingsFileLocation);
    }

    $this->credentials = json_decode(file_get_contents($settingsFileLocation), true);
    $this->projectId = $this->credentials['project_id'] ?? null;
    $apiKey = $this->credentials['private_key_id'] ?? null;

    if (empty($this->projectId) || empty($apiKey)) {
      throw new Exception('Project ID not found in credentials file');
    }

    $this->firestoreClient = new FirestoreClient($this->projectId, $apiKey);
  }

  /**
   * Create a fire call document
   * @throws Exception
   */
  public function createFireCall(Node $call): void {
    $callUuid = $call->uuid();
    $this->logger->info('Creating fireCall. CallId: @callId', ['@callId' => $callUuid]);

    try {

      $fireCall = new FireCall($call);
      $fireCallDocument = $fireCall->toFirestoreDocument();
      $this->firestoreClient->addDocument('live_calls', $fireCallDocument, $callUuid);

      $this->logger->info('FireCall created successfully: @callId', ['@callId' => $callUuid]);

    } catch (Exception $e) {
      $this->logger->error('Failed to create FireCall @callId: @error', [
        '@callId' => $callUuid,
        '@error' => $e->getMessage(),
      ]);
      throw $e;
    }
  }

  public function updateAcceptedCall(NodeInterface $bid): void {
    if ($bid->get('field_bid_type')->getString() !== 'accept') {
      throw new InvalidArgumentException('Bid needs to be of type \'accept\'.');
    }

    /** @var NodeInterface $bidder */
    $bidder = $bid->get('field_bid_customer')->entity;

    $updateFields = [];
    $updateFields['executor_id'] = $bidder->uuid();
    $updateFields['executor_name'] = $bidder->get('field_customer_lastname')->getString();

    $callUuid = $bid->get('field_bid_call')->entity->uuid();
    try {
      $this->firestoreClient->updateDocument('live_calls/' . $callUuid, $updateFields, true);
    } catch (RequestException $e) {
      if ($e->getCode() === Response::HTTP_NOT_FOUND) {
        $this->logger->warning('FireCall not found during update: @callId', ['@callId' => $callUuid]);
      } else {
        $this->logger->error('Failed to update FireCall @callId: @error', [
          '@callId' => $callUuid,
          '@error' => $e->getMessage(),
        ]);
        throw $e;
      }
    }
  }

  public function createFireBid(Node $bid): void {
    $bidUuid = $bid->uuid();
    $this->logger->info('Creating FireBid @bidUuid', ['@bidUuid' => $bidUuid]);
    try {
      $fireBid = new FireBid($bid);
      $fireBidDocument = $fireBid->toFirestoreDocument();
      $this->firestoreClient->addDocument('live_bids', $fireBidDocument, $bidUuid);
      $this->logger->info('FireBid created successfully: @bidUuid', ['@bidUuid' => $bidUuid]);
    } catch (Exception $e) {
      $this->logger->error('Failed to create FireBid @bidUuid: @error', [
        '@bidUuid' => $bidUuid,
        '@error' => $e->getMessage()
      ]);
    }
  }

  /**
   * Delete a fire call document
   * @throws Exception
   */
  public function deleteFireCall(string $callUuid): void {
    $this->logger->info('Deleting fireCall. CallId: @callId', ['@callId' => $callUuid]);
    try {
      $this->deleteBidsByCallId($callUuid);
      $this->firestoreClient->deleteDocument('live_calls/' . $callUuid);
    }  catch (Exception $e) {
      $this->logger->error('Failed to delete FireCall @callId: @error', [
        '@callId' => $callUuid,
        '@error' => $e->getMessage(),
      ]);
      throw $e;
    }
  }

  /**
   * Update a fire call document
   * @throws Exception|GuzzleException
   */
  public function updateFireCall(Node $call): void {

    $callUuid = $call->uuid();
    $this->logger->info('Updating fireCall. CallId: @callId', ['@callId' => $callUuid]);

    try {
      $originalCall = $call->getOriginal();
      if (!$originalCall instanceof NodeInterface) {
        $this->logger->warning('Original call not available for update. CallId: @callId', ['@callId' => $callUuid]);
        return;
      }

      $updates = $this->prepareUpdates($call, $originalCall);
      if (empty($updates)) {
        $this->logger->info('No updates needed for FireCall: @callId', ['@callId' => $callUuid]);
        return;
      }

      // Convert updates to Firestore field format
      $updateFields = [];

      foreach ($updates as $update) {
        $updateFields[$update['path']] = $update['value'];
      }

      $this->firestoreClient->updateDocument('live_calls/' . $callUuid, $updateFields, true);
      $this->logger->info('FireCall updated successfully: @callId', ['@callId' => $callUuid]);

    } catch (RequestException $e) {
      if ($e->getCode() === Response::HTTP_NOT_FOUND) {
        $this->logger->warning('FireCall not found during update: @callId', ['@callId' => $callUuid]);
      } else {
        $this->logger->error('Failed to update FireCall @callId: @error', [
          '@callId' => $callUuid,
          '@error' => $e->getMessage(),
        ]);
        throw $e;
      }
    }
  }

  public function updateCustomerBalance(string $customerId, array $updates): void
  {
    if (empty($updates)) {
      $this->logger->warning("No balance updates available for customer: @customerId", ['@customerId' => $customerId]);
      return;
    }

    try {
      $this->firestoreClient->updateDocument('user_devices/' . $customerId, $updates, true);
      $this->logger->info('Balance updated successfully for customer @customerId', ['@customerId' => $customerId]);
    } catch (Exception $exception) {
      $this->logger->warning('Failed to update Balance @customerId: @error', ['@customerId' => $customerId, 'exception' => $exception->getMessage()]);
    }
  }

  private function deleteBidsByCallId(string $callUuid): void {
    $callId = FirestoreFieldValue::string($callUuid);
    $filter = new FirestoreFieldFilter('call_id', $callId, FirestoreOperator::EQUAL);
    $bidDocuments = FirestoreQueryHelper::queryFirestore($this->firestoreClient, 'live_bids', $filter);
    if (empty($bidDocuments)) {
      $this->logger->info('No bids to call @callId to delete', ['@callId' => $callUuid]);
      return;
    }

    /** @var FirestoreDocument $bidDocument */
    foreach ($bidDocuments as $bidDocument) {
      $bidId = basename($bidDocument->getName());
      try {
        $this->logger->info('Deleting bid. BidId: @bidId', ['@bidId' => $bidId]);
        $deleted = $this->firestoreClient->deleteDocument('live_bids/' . $bidId);
        if ($deleted) {
          $this->logger->info('Deleted bid. BidId: @bidId', ['@bidId' => $bidId]);
        } else {
          $this->logger->warning('Deleting bid failed. BidId: @bidId', ['@bidId' => $bidId]);
        }
      } catch (Exception $e) {
        $this->logger->error('Failed to delete Bid @bidId: @error', ['@bidId' => $bidId, '@error' => $e->getMessage()]);
      }
    }
  }


  /**
   * Prepare updates array based on changes between original and current call
   */
  private function prepareUpdates(Node $call, Node $originalCall): array {
    $updates = [];

    // Check expiration time updates
    $initialExpirationTime = $originalCall->get('field_call_expiry_time')->date;
    $currentExpirationTime = $call->get('field_call_expiry_time')->date;

    if ($initialExpirationTime && $currentExpirationTime) {
      $initialTimestamp = $initialExpirationTime->getTimestamp();
      $currentTimestamp = $currentExpirationTime->getTimestamp();

      $this->logger->debug('Expiry time check - Initial: @initial, Current: @current', [
        '@initial' => $initialTimestamp,
        '@current' => $currentTimestamp,
      ]);

      if ($initialTimestamp !== $currentTimestamp && $currentExpirationTime > new DrupalDateTime()) {
        $updates[] = [
          'path' => 'expiration_time',
          'value' => UtilsService::dateTimeToGcTimestamp($currentExpirationTime)
        ];
      }
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
