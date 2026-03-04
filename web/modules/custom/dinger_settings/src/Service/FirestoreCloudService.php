<?php declare(strict_types = 1);

namespace Drupal\dinger_settings\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Logger\LoggerChannelFactory;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Site\Settings;
use Drupal\dinger_settings\Model\FireBid;
use Drupal\dinger_settings\Model\FireCall;
use Drupal\dinger_settings\Utils\BidStatus;
use Drupal\dinger_settings\Utils\BidType;
use Drupal\dinger_settings\Utils\CallStatus;
use Drupal\dinger_settings\Utils\DateUtils;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use InvalidArgumentException;
use MrShan0\PHPFirestore\FirestoreClient;
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
    }
  }

  public function updateAcceptedCall(NodeInterface $bid): void {
    if ($bid->get('field_bid_type')->getString() !== BidType::ACCEPT->value) {
      throw new InvalidArgumentException('Bid needs to be of type \'accept\'.');
    }

    /** @var NodeInterface $bidder */
    $bidder = $bid->get('field_bid_customer')->entity;

    $updateFields = [];
    $updateFields['executor_id'] = $bidder->uuid();
    $updateFields['executor_name'] = $bidder->get('field_customer_lastname')->getString();
    $updateFields['executor_photo'] = "";

    $callUuid = $bid->get('field_bid_call')->entity->uuid();
    try {
      $this->firestoreClient->updateDocument('live_calls/' . $callUuid, $updateFields, true);
    } catch (RequestException $e) {
      $this->logger->error($e);
      if ($e->getCode() === Response::HTTP_NOT_FOUND) {
        $this->logger->warning('FireCall not found during update: @callId', ['@callId' => $callUuid]);
      } else {
        $this->logger->error('Failed to update FireCall @callId: @error', [
          '@callId' => $callUuid,
          '@error' => $e->getMessage(),
        ]);
      }
    }
  }

  public function updateBidStatus(String $bidUuid, BidStatus $bidStatus): void {
    $this->logger->info('Updating bid: @bidUuid setting to status: @status', ['@bidUuid' => $bidUuid, '@status' => $bidStatus->value]);
    $updateFields = [];
    $updateFields['status'] = $bidStatus->value;
    try {
      $this->firestoreClient->updateDocument('live_bids/' . $bidUuid, $updateFields, true);
    } catch (RequestException $e) {
      $this->logger->error($e);
      if ($e->getCode() === Response::HTTP_NOT_FOUND) {
        $this->logger->warning('FireBid not found during update: @bidId', ['@bidId' => $bidUuid]);
      } else {
        $this->logger->error('Failed to update FireBid @bidId: @error', [
          '@callId' => $bidUuid,
          '@error' => $e->getMessage(),
        ]);
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
   * Update a fire call document
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
      $this->logger->error($e);
      if ($e->getCode() === Response::HTTP_NOT_FOUND) {
        $this->logger->warning('FireCall not found during update: @callId', ['@callId' => $callUuid]);
        $this->logger->info('Attempting to re-create FireCall: @callId', ['@callId' => $callUuid]);
        $this->createFireCall($call);
      } else {
        $this->logger->error('Failed to update FireCall @callId: @error', [
          '@callId' => $callUuid,
          '@error' => $e->getMessage(),
        ]);
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
      $this->firestoreClient->updateDocument('customer_balances/' . $customerId, $updates, true);
      $this->logger->info('Balance updated successfully for customer @customerId', ['@customerId' => $customerId]);
    } catch (Exception $exception) {
      $this->logger->warning('Failed to update Balance @customerId: @error', ['@customerId' => $customerId, 'exception' => $exception->getMessage()]);
    }
  }

  /**
   * Prepare updates array based on changes between original and current call
   */
  private function prepareUpdates(Node $call, Node $originalCall): array {
    $updates = [];

    // Check status updates
    $initialStatus = CallStatus::fromString($originalCall->get('field_call_status')->getString());
    $currentStatus = CallStatus::fromString($call->get('field_call_status')->getString());
    if ($initialStatus !== $currentStatus) {
      /**
       * No need to update attributed fireCall.
       * This is handled by firebase function onLiveBidStatusUpdated (on Bid Confirmed)
       */
      if ($currentStatus === CallStatus::ATTRIBUTED) {
        return [];
      }

      $updates[] = [
        'path' => 'status',
        'value' => $currentStatus
      ];
    }

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
          'value' => DateUtils::dateTimeToGcTimestamp($currentExpirationTime)
        ];
      }
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
