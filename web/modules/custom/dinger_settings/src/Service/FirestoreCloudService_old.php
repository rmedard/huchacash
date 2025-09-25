<?php declare(strict_types = 1);

namespace Drupal\dinger_settings\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Http\ClientFactory;
use Drupal\Core\Logger\LoggerChannelFactory;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Site\Settings;
use Drupal\dinger_settings\Model\FireCall;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Exception;
use Google\Auth\Cache\MemoryCacheItemPool;
use Google\Auth\HttpHandler\Guzzle7HttpHandler; // Updated for Guzzle 7
use Google\Cloud\Core\Exception\GoogleException;
use Google\Cloud\Core\Exception\NotFoundException;
use Google\Cloud\Firestore\FirestoreClient;
use GuzzleHttp\Client;

final class FirestoreCloudServiceOld {

  protected Client $httpClient;
  protected ConfigFactoryInterface $configFactory;
  protected LoggerChannelInterface $logger;
  protected ?FirestoreClient $firestoreClient = null;
  protected bool $clientInitializing = false;

  public function __construct(
    ClientFactory $http_client,
    ConfigFactoryInterface $config_factory,
    LoggerChannelFactory $logger
  ) {
    $this->logger = $logger->get('FirestoreCloudService');
    $this->httpClient = $http_client->fromOptions([
      'timeout' => 30,
      'connect_timeout' => 30,
    ]);
    $this->configFactory = $config_factory;
  }

  /**
   * Get or create Firestore client with proper configuration
   * @throws GoogleException
   */
  private function getFirestoreClient(): FirestoreClient {
    $this->logger->info('gRPC extension loaded: ' . (extension_loaded('grpc') ? 'YES' : 'NO'));
    $this->logger->info('GOOGLE_CLOUD_PHP_GRPC_ENABLED: ' . (getenv('GOOGLE_CLOUD_PHP_GRPC_ENABLED') ?: 'not set'));

    if ($this->firestoreClient !== null) {
      return $this->firestoreClient;
    }

    // Use Drupal lock for thread-safe initialization
    $lock = \Drupal::lock();
    $lock_name = 'firestore_client_init';

    if (!$lock->acquire($lock_name, 10)) {
      throw new GoogleException('Could not acquire lock for Firestore client initialization');
    }

    try {
      // Double-check after acquiring lock
      if ($this->firestoreClient !== null) {
        return $this->firestoreClient;
      }

      $settingsFileLocation = Settings::get('gc_tasks_settings_file');
      if (empty($settingsFileLocation) || !file_exists($settingsFileLocation)) {
        throw new GoogleException('Google Cloud credentials file not found at: ' . $settingsFileLocation);
      }

      // Read project ID from credentials file
      $credentials = json_decode(file_get_contents($settingsFileLocation), true);
      $projectId = $credentials['project_id'] ?? null;

      if (empty($projectId)) {
        throw new GoogleException('Project ID not found in credentials file');
      }

      $config = [
        'keyFilePath' => $settingsFileLocation,
        'projectId' => $projectId,
        'authCache' => new MemoryCacheItemPool(),
        'transport' => 'grpc', // Explicitly set gRPC
        'grpcOptions' => [
          'grpc.ssl_target_name_override' => 'firestore.googleapis.com',
          'grpc.default_authority' => 'firestore.googleapis.com',
        ],
      ];

      $this->firestoreClient = new FirestoreClient($config);
      $this->logger->info('Firestore client initialized successfully for project: @projectId', [
        '@projectId' => $projectId,
      ]);

      return $this->firestoreClient;

    } catch (Exception $e) {
      $this->logger->error('Failed to initialize Firestore client: @error', [
        '@error' => $e->getMessage(),
      ]);
      throw $e;
    } finally {
      $lock->release($lock_name);
    }
  }

  /**
   * Create a fire call document
   * @throws GoogleException
   */
  public function createFireCall(Node $call): void {
    $callUuid = $call->uuid();
    $this->logger->info('Creating fireCall. CallId: @callId', ['@callId' => $callUuid]);

    try {
      $fireCall = new FireCall($call);
      $documentRef = $this->getFirestoreClient()
        ->collection('live_calls')
        ->document($fireCall->id);

      $result = $documentRef->create($fireCall->toFirestoreBody());

      $this->logger->info('FireCall created successfully: @callId', ['@callId' => $callUuid]);

    } catch (Exception $e) {
      $this->logger->error('Failed to create FireCall @callId: @error', [
        '@callId' => $callUuid,
        '@error' => $e->getMessage(),
      ]);
      throw $e;
    }
  }

  /**
   * Delete a fire call document
   * @throws GoogleException
   */
  public function deleteFireCall(string $callUuid): void {
    $this->logger->info('Deleting fireCall. CallId: @callId', ['@callId' => $callUuid]);

    try {
      $documentRef = $this->getFirestoreClient()
        ->collection('live_calls')
        ->document($callUuid);

      // Check if document exists before deleting
      $snapshot = $documentRef->snapshot();
      if (!$snapshot->exists()) {
        $this->logger->warning('FireCall not found during deletion: @callId', ['@callId' => $callUuid]);
        return;
      }

      $documentRef->delete();
      $this->logger->info('FireCall deleted successfully: @callId', ['@callId' => $callUuid]);

    } catch (NotFoundException $e) {
      $this->logger->warning('FireCall not found during deletion: @callId', ['@callId' => $callUuid]);
    } catch (Exception $e) {
      $this->logger->error('Failed to delete FireCall @callId: @error', [
        '@callId' => $callUuid,
        '@error' => $e->getMessage(),
      ]);
      throw $e;
    }
  }

  /**
   * Update a fire call document
   * @throws GoogleException
   */
  public function updateFireCall(Node $call): void {
    $callUuid = $call->uuid();
    $this->logger->info('Updating fireCall. CallId: @callId', ['@callId' => $callUuid]);

    try {
      $originalCall = $call->original;
      if (!$originalCall instanceof NodeInterface) {
        $this->logger->warning('Original call not available for update. CallId: @callId', ['@callId' => $callUuid]);
        return;
      }

      $updates = $this->prepareUpdates($call, $originalCall);
      if (empty($updates)) {
        $this->logger->info('No updates needed for FireCall: @callId', ['@callId' => $callUuid]);
        return;
      }

      $documentRef = $this->getFirestoreClient()
        ->collection('live_calls')
        ->document($callUuid);

      // Check if document exists before updating
      $snapshot = $documentRef->snapshot();
      if (!$snapshot->exists()) {
        $this->logger->warning('FireCall not found during update: @callId', ['@callId' => $callUuid]);
        return;
      }

      $documentRef->update($updates);
      $this->logger->info('FireCall updated successfully: @callId', ['@callId' => $callUuid]);

    } catch (NotFoundException $e) {
      $this->logger->warning('FireCall not found during update: @callId', ['@callId' => $callUuid]);
    } catch (Exception $e) {
      $this->logger->error('Failed to update FireCall @callId: @error', [
        '@callId' => $callUuid,
        '@error' => $e->getMessage(),
      ]);
      throw $e;
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

  /**
   * Check if Firestore client is properly initialized
   */
  public function isConnected(): bool {
    try {
      $this->getFirestoreClient();
      return true;
    } catch (Exception $e) {
      return false;
    }
  }
}
