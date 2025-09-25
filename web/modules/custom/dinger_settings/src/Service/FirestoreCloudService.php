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
use GuzzleHttp\Exception\RequestException;

final class FirestoreCloudService {

  protected Client $httpClient;
  protected ConfigFactoryInterface $configFactory;
  protected LoggerChannelInterface $logger;
  protected ?string $accessToken = null;
  protected ?string $projectId = null;
  protected ?array $credentials = null;

  private const FIRESTORE_BASE_URL = 'https://firestore.googleapis.com/v1';
  private const GOOGLE_AUTH_URL = 'https://oauth2.googleapis.com/token';

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
   */
  private function firestoreRequest(string $method, string $path, array $data = null): array {
    $this->initialize();

    $url = self::FIRESTORE_BASE_URL . '/projects/' . $this->projectId . '/databases/(default)' . $path;

    $this->logger->debug('Making Firestore request: @method @url', [
      '@method' => $method,
      '@url' => $url,
    ]);

    $options = [
      'headers' => [
        'Authorization' => 'Bearer ' . $this->accessToken,
        'Content-Type' => 'application/json'
      ]
    ];

    if ($data !== null) {
      $options['json'] = $data;
      $this->logger->debug('Request data: @data', ['@data' => json_encode($data)]);
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
   * @throws Exception
   */
  public function createFireCall(Node $call): void {
    $callUuid = $call->uuid();
    $this->logger->info('Creating fireCall. CallId: @callId', ['@callId' => $callUuid]);

    try {
      $fireCall = new FireCall($call);

      $document = [
        'fields' => $this->convertToFirestoreFields($fireCall->toFirestoreBody(), 0)
      ];

      // Use explicit URL construction to avoid query parameter issues
      $path = '/documents/live_calls';
      $queryParams = 'documentId=' . urlencode($fireCall->id);
      $fullPath = $path . '?' . $queryParams;

      $this->firestoreRequest('POST', $fullPath, $document);

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
   * @throws Exception
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
   * @throws Exception
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
        $updateFields[$update['path']] = $this->convertValueToFirestoreField($update['value'], 0);
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
  private function convertToFirestoreFields(array $data, int $depth = 0): array {
    // Prevent infinite recursion
    if ($depth > 20) {
      $this->logger->warning('Maximum recursion depth reached in convertToFirestoreFields');
      return [];
    }

    $fields = [];
    foreach ($data as $key => $value) {
      $fields[$key] = $this->convertValueToFirestoreField($value, $depth + 1);
    }
    return $fields;
  }

  /**
   * Convert value to Firestore field format
   */
  private function convertValueToFirestoreField($value, int $depth = 0): array {
    // Prevent infinite recursion
    if ($depth > 20) {
      $this->logger->warning('Maximum recursion depth reached in convertValueToFirestoreField');
      return ['stringValue' => '[MAX_DEPTH_EXCEEDED]'];
    }

    if ($value === null) {
      return ['nullValue' => null];
    } elseif (is_bool($value)) {
      return ['booleanValue' => $value];
    } elseif (is_int($value)) {
      return ['integerValue' => (string)$value];
    } elseif (is_float($value)) {
      return ['doubleValue' => $value];
    } elseif (is_string($value)) {
      // Check if string is already an ISO 8601 timestamp
      if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?Z$/', $value)) {
        return ['timestampValue' => $value];
      }
      return ['stringValue' => $value];
    } elseif (is_array($value)) {
      // Handle Firestore timestamp format (from UtilsService::dateTimeToGcTimestamp)
      if (isset($value['_seconds']) && isset($value['_nanoseconds'])) {
        return [
          'timestampValue' => gmdate('Y-m-d\TH:i:s', $value['_seconds']) .
            sprintf('.%09dZ', $value['_nanoseconds'])
        ];
      }

      // Handle Geopoint format
      if (isset($value['latitude']) && isset($value['longitude'])) {
        return [
          'geoPointValue' => [
            'latitude' => (float)$value['latitude'],
            'longitude' => (float)$value['longitude']
          ]
        ];
      }

      // Handle arrays (check if it's a sequential array)
      if (array_keys($value) === range(0, count($value) - 1)) {
        $arrayValues = [];
        foreach ($value as $item) {
          $arrayValues[] = $this->convertValueToFirestoreField($item, $depth + 1);
        }
        return [
          'arrayValue' => [
            'values' => $arrayValues
          ]
        ];
      }

      // Handle objects/maps
      return [
        'mapValue' => [
          'fields' => $this->convertToFirestoreFields($value, $depth + 1)
        ]
      ];
    } else {
      // Fallback for unknown types
      return ['stringValue' => (string)$value];
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
      if (isset($field['nullValue'])) {
        $data[$key] = null;
      } elseif (isset($field['booleanValue'])) {
        $data[$key] = $field['booleanValue'];
      } elseif (isset($field['integerValue'])) {
        $data[$key] = (int)$field['integerValue'];
      } elseif (isset($field['doubleValue'])) {
        $data[$key] = $field['doubleValue'];
      } elseif (isset($field['stringValue'])) {
        $data[$key] = $field['stringValue'];
      } elseif (isset($field['timestampValue'])) {
        // Convert timestamp back to array format that matches your existing code
        $timestamp = strtotime($field['timestampValue']);
        $data[$key] = [
          '_seconds' => $timestamp,
          '_nanoseconds' => 0 // We lose nanosecond precision in conversion
        ];
      } elseif (isset($field['geoPointValue'])) {
        $data[$key] = [
          'latitude' => $field['geoPointValue']['latitude'],
          'longitude' => $field['geoPointValue']['longitude']
        ];
      } elseif (isset($field['arrayValue']['values'])) {
        $arrayData = [];
        foreach ($field['arrayValue']['values'] as $item) {
          $converted = $this->convertFromFirestoreFields(['temp' => $item]);
          $arrayData[] = $converted['temp'];
        }
        $data[$key] = $arrayData;
      } elseif (isset($field['mapValue']['fields'])) {
        $data[$key] = $this->convertFromFirestoreFields($field['mapValue']['fields']);
      }
    }
    return $data;
  }
}
