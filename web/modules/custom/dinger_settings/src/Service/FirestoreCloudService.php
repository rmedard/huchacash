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
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;

final class FirestoreCloudService {

  protected Client $httpClient;
  protected ConfigFactoryInterface $configFactory;
  protected LoggerChannelInterface $logger;
  protected ?string $accessToken = null;
  protected ?string $projectId = null;
  protected ?array $credentials = null;

  private const string FIRESTORE_BASE_URL = 'https://firestore.googleapis.com/v1';
  private const string GOOGLE_AUTH_URL = 'https://oauth2.googleapis.com/token';

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
   * Initialize credentials and get access token
   * @throws Exception
   */
  private function initialize(): void {
    if ($this->accessToken !== null && $this->projectId !== null) {
      return; // Already initialized
    }

    $settingsFileLocation = Settings::get('gc_tasks_settings_file');
    if (empty($settingsFileLocation) || !file_exists($settingsFileLocation)) {
      throw new Exception('Google Cloud credentials file not found at: ' . $settingsFileLocation);
    }

    $this->credentials = json_decode(file_get_contents($settingsFileLocation), true);
    $this->projectId = $this->credentials['project_id'] ?? null;

    if (empty($this->projectId)) {
      throw new Exception('Project ID not found in credentials file');
    }

    $this->getAccessToken();
  }

  /**
   * Get OAuth2 access token using service account credentials
   * @throws Exception
   */
  private function getAccessToken(): void {
    if (!$this->credentials) {
      throw new Exception('Credentials not loaded');
    }

    // Create JWT assertion
    $header = [
      'alg' => 'RS256',
      'typ' => 'JWT'
    ];

    $now = time();
    $payload = [
      'iss' => $this->credentials['client_email'],
      'scope' => 'https://www.googleapis.com/auth/cloud-platform',
      'aud' => self::GOOGLE_AUTH_URL,
      'exp' => $now + 3600,
      'iat' => $now
    ];

    $headerEncoded = $this->base64UrlEncode(json_encode($header));
    $payloadEncoded = $this->base64UrlEncode(json_encode($payload));
    $signature = $this->signJwt($headerEncoded . '.' . $payloadEncoded, $this->credentials['private_key']);

    $jwt = $headerEncoded . '.' . $payloadEncoded . '.' . $signature;

    // Exchange JWT for access token
    try {
      $response = $this->httpClient->post(self::GOOGLE_AUTH_URL, [
        'form_params' => [
          'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
          'assertion' => $jwt
        ],
        'headers' => [
          'Content-Type' => 'application/x-www-form-urlencoded'
        ]
      ]);

      $data = json_decode($response->getBody()->getContents(), true);
      $this->accessToken = $data['access_token'] ?? null;

      if (!$this->accessToken) {
        throw new Exception('Failed to get access token from response');
      }

    } catch (RequestException $e) {
      throw new Exception('Failed to get access token: ' . $e->getMessage());
    } catch (GuzzleException $e) {
      throw new Exception('Failed to get access token: ' . $e->getMessage());
    }
  }

  /**
   * Base64 URL encode
   */
  private function base64UrlEncode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
  }

  /**
   * Sign JWT with RSA private key
   */
  private function signJwt(string $data, string $privateKey): string {
    $key = openssl_pkey_get_private($privateKey);
    if (!$key) {
      throw new Exception('Failed to load private key');
    }

    openssl_sign($data, $signature, $key, OPENSSL_ALGO_SHA256);
    return $this->base64UrlEncode($signature);
  }

  /**
   * Make authenticated request to Firestore REST API
   * @throws Exception|GuzzleException
   */
  private function firestoreRequest(string $method, string $path, array $data = null): array {
    $this->initialize();

    $url = self::FIRESTORE_BASE_URL . '/projects/' . $this->projectId . '/databases/(default)' . $path;

    $options = [
      'headers' => [
        'Authorization' => 'Bearer ' . $this->accessToken,
        'Content-Type' => 'application/json'
      ]
    ];

    if ($data !== null) {
      $options['json'] = $data;
    }

    try {
      $response = $this->httpClient->request($method, $url, $options);
      return json_decode($response->getBody()->getContents(), true) ?? [];
    } catch (RequestException $e) {
      $this->logger->error('Firestore API request failed: @error', ['@error' => $e->getMessage()]);
      throw $e;
    }
  }

  /**
   * Create a fire call document
   * @throws Exception|GuzzleException
   */
  public function createFireCall(Node $call): void {
    $callUuid = $call->uuid();
    $this->logger->info('Creating fireCall. CallId: @callId', ['@callId' => $callUuid]);

    try {
      $fireCall = new FireCall($call);
      $path = '/documents/live_calls/documentId=' . $fireCall->id;

      $document = [
        'fields' => $this->convertToFirestoreFields($fireCall->toFirestoreBody())
      ];

      $this->firestoreRequest('POST', $path, $document);

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
   * @throws Exception|GuzzleException
   */
  public function deleteFireCall(string $callUuid): void {
    $this->logger->info('Deleting fireCall. CallId: @callId', ['@callId' => $callUuid]);

    try {
      $path = '/documents/live_calls/' . $callUuid;
      $this->firestoreRequest('DELETE', $path);

      $this->logger->info('FireCall deleted successfully: @callId', ['@callId' => $callUuid]);

    } catch (RequestException $e) {
      if ($e->getCode() === 404) {
        $this->logger->warning('FireCall not found during deletion: @callId', ['@callId' => $callUuid]);
      } else {
        $this->logger->error('Failed to delete FireCall @callId: @error', [
          '@callId' => $callUuid,
          '@error' => $e->getMessage(),
        ]);
        throw $e;
      }
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

      // Convert updates to Firestore field format
      $updateFields = [];
      $updateMask = [];

      foreach ($updates as $update) {
        $updateFields[$update['path']] = $this->convertValueToFirestoreField($update['value']);
        $updateMask[] = $update['path'];
      }

      $path = '/documents/live_calls/' . $callUuid;
      $data = [
        'fields' => $updateFields
      ];

      // Add update mask to query string
      $updateMaskQuery = implode(',', $updateMask);
      $path .= '?updateMask.fieldPaths=' . urlencode($updateMaskQuery);

      $this->firestoreRequest('PATCH', $path, $data);
      $this->logger->info('FireCall updated successfully: @callId', ['@callId' => $callUuid]);

    } catch (RequestException $e) {
      if ($e->getCode() === 404) {
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

  /**
   * Convert array to Firestore fields format
   */
  private function convertToFirestoreFields(array $data): array {
    return array_map(function ($value) {
      return $this->convertValueToFirestoreField($value);
    }, $data);
  }

  /**
   * Convert value to Firestore field format
   */
  private function convertValueToFirestoreField($value): array {
    if (is_string($value)) {
      return ['stringValue' => $value];
    } elseif (is_int($value)) {
      return ['integerValue' => (string)$value];
    } elseif (is_float($value)) {
      return ['doubleValue' => $value];
    } elseif (is_bool($value)) {
      return ['booleanValue' => $value];
    } elseif (is_array($value) && isset($value['_seconds'], $value['_nanoseconds'])) {
      // Firestore timestamp format
      return [
        'timestampValue' => date('c', $value['_seconds']) // ISO 8601 format
      ];
    } elseif (is_array($value)) {
      return [
        'mapValue' => [
          'fields' => $this->convertToFirestoreFields($value)
        ]
      ];
    } else {
      return ['nullValue' => null];
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
      $this->initialize();
      return true;
    } catch (Exception $e) {
      return false;
    }
  }

  /**
   * Get a document (useful for debugging)
   */
  public function getFireCall(string $callUuid): ?array {
    try {
      $path = '/documents/live_calls/' . $callUuid;
      $response = $this->firestoreRequest('GET', $path);

      if (isset($response['fields'])) {
        return $this->convertFromFirestoreFields($response['fields']);
      }

      return null;
    } catch (RequestException $e) {
      if ($e->getCode() === 404) {
        return null;
      }
      $this->logger->error('Failed to get FireCall @callId: @error', [
        '@callId' => $callUuid,
        '@error' => $e->getMessage(),
      ]);
      return null;
    }
  }

  /**
   * Convert Firestore fields back to regular array
   */
  private function convertFromFirestoreFields(array $fields): array {
    $data = [];
    foreach ($fields as $key => $field) {
      if (isset($field['stringValue'])) {
        $data[$key] = $field['stringValue'];
      } elseif (isset($field['integerValue'])) {
        $data[$key] = (int)$field['integerValue'];
      } elseif (isset($field['doubleValue'])) {
        $data[$key] = $field['doubleValue'];
      } elseif (isset($field['booleanValue'])) {
        $data[$key] = $field['booleanValue'];
      } elseif (isset($field['timestampValue'])) {
        $data[$key] = strtotime($field['timestampValue']);
      } elseif (isset($field['mapValue']['fields'])) {
        $data[$key] = $this->convertFromFirestoreFields($field['mapValue']['fields']);
      }
    }
    return $data;
  }
}
