<?php

declare(strict_types=1);

namespace Drupal\dinger_settings\Service;

use Drupal\Core\Config\ConfigException;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Site\Settings;
use Drupal\dinger_settings\Model\FileStorageResponse;
use Drupal\file\FileInterface;
use Drupal\node\Entity\Node;
use Drupal\user\UserInterface;
use Exception;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Random\RandomException;

/**
 * Service used to manage file upload, download and delete to/from Firebase storage
 */
final class FireStorageCloudService {

  protected LoggerInterface $logger;
  protected ImmutableConfig $config;

  protected ?string $projectId = null;
  protected ?array $credentials = null;

  // Cache access token to avoid repeated requests
  private ?string $cachedAccessToken = null;
  private ?int $tokenExpiryTime = null;

  /**
   * Constructs a FireStorageCloudService object.
   * @throws Exception
   */
  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly FileSystemInterface $fileSystem
  ) {
    $this->logger = $this->loggerFactory->get('fire_storage_cloud_service');
    $this->initialize();
  }

  /**
   * @throws Exception
   */
  private function initialize(): void {
    $settingsFileLocation = Settings::get('gc_tasks_settings_file');
    if (empty($settingsFileLocation) || !file_exists($settingsFileLocation)) {
      throw new ConfigException('Google Cloud credentials file not found at: ' . $settingsFileLocation);
    }
    $this->credentials = json_decode(file_get_contents($settingsFileLocation), TRUE);

    if (!isset($this->credentials['project_id']) || !isset($this->credentials['client_email']) || !isset($this->credentials['private_key'])) {
      throw new ConfigException('Invalid Google Cloud credentials file format.');
    }

    $this->projectId = $this->credentials['project_id'];
    $this->config = $this->configFactory->get('dinger_settings');
  }

  public function uploadCustomerProfilePhoto(Node $customer): false|FileStorageResponse
  {
    if ($customer->bundle() != 'customer') {
      throw new InvalidArgumentException('Node must be of type Customer.');
    }

    /** @var UserInterface $user */
    $user = $customer->get('field_customer_user')->entity;
    if ($user->get('user_picture')->isEmpty()) {
      return false;
    }

    /** @var FileInterface $userPhoto */
    $userPhoto = $user->get('user_picture')->entity;
    $fileExtension = pathinfo($userPhoto->getFileUri(), PATHINFO_EXTENSION);
    $storagePath = "profile_photos/{$customer->uuid()}.$fileExtension";
    $fireStorageResponse = $this->uploadImage($userPhoto->getFileUri(), $storagePath, $userPhoto->getMimeType());

    if ($fireStorageResponse) {
      try {
        $customer->set('field_customer_photo_storagepath', $fireStorageResponse->storagePath);
        $customer->save();
      } catch (EntityStorageException $e) {
        $this->logger->error('Updating customer failed: ' . $e->getMessage());
        return false;
      }
    }

    return $fireStorageResponse;
  }

  public function deleteCustomerProfilePhoto(Node $customer): bool {
    if ($customer->bundle() != 'customer') {
      throw new InvalidArgumentException('Node must be of type Customer.');
    }

    if ($customer->get('field_customer_photo_storagepath')->isEmpty()) {
      $this->logger->warning('Profile photo already deleted.');
      return false;
    }

    $storage_path = $customer->get('field_customer_photo_storagepath')->getString();
    $bucket = $this->config->get('gc_photo_storage_bucket');
    if (empty($this->projectId) || empty($bucket)) {
      $this->logger->error('Firebase Storage configuration is incomplete.');
      return FALSE;
    }

    $url = "https://firebasestorage.googleapis.com/v0/b/$bucket/o/" . urlencode($storage_path);

    $access_token = $this->getAccessToken();
    if (!$access_token) {
      return FALSE;
    }

    try {
      $response = $this->httpClient->request('DELETE', $url, [
        'headers' => [
          'Authorization' => 'Bearer ' . $access_token,
        ],
      ]);

      $this->logger->info('Successfully deleted file from Firebase Storage: @path', [
        '@path' => $storage_path,
      ]);

      $customer->set('field_customer_photo_storagepath', '');
      $customer->save();

      return TRUE;
    } catch (GuzzleException $e) {
      $this->logger->error('Unable to delete file from Firebase Storage: @path | @message',
        ['@path' => $storage_path, '@message' => $e->getMessage()]);
    } catch (EntityStorageException $e) {
      $this->logger->error('Updating customer failed: ' . $e->getMessage());
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Upload an image to Firebase Storage.
   */
  private function uploadImage(string $file_path, string $storage_path, string $content_type): false|FileStorageResponse
  {
    $bucket = $this->config->get('gc_photo_storage_bucket');
    if (empty($this->projectId) || empty($bucket)) {
      $this->logger->error('Firebase Storage configuration is incomplete.');
      return FALSE;
    }

    // Convert Drupal URI to real path if needed.
    $real_path = $this->getRealPath($file_path);
    if (!$real_path || !file_exists($real_path)) {
      $this->logger->error('File does not exist: @path', ['@path' => $file_path]);
      return FALSE;
    }

    // Read file contents.
    $file_contents = file_get_contents($real_path);
    if ($file_contents === FALSE) {
      $this->logger->error('Could not read file: @path', ['@path' => $real_path]);
      return FALSE;
    }

    // Get access token.
    $access_token = $this->getAccessToken();
    if (!$access_token) {
      $this->logger->error('Failed to get access token.');
      return FALSE;
    }

    try {
      // Generate download token for public access
      $download_token = bin2hex(random_bytes(16));

      // Upload to Firebase Storage with metadata
      $url = "https://firebasestorage.googleapis.com/v0/b/$bucket/o";
      $response = $this->httpClient->request('POST', $url, [
        'query' => [
          'name' => $storage_path,
        ],
        'headers' => [
          'Authorization' => 'Bearer ' . $access_token,
          'Content-Type' => $content_type,
        ],
        'body' => $file_contents,
      ]);

      $result = json_decode($response->getBody()->getContents(), TRUE);

      if (!isset($result['name'])) {
        $this->logger->error('Unexpected response from Firebase Storage: ' . json_encode($result));
        return FALSE;
      }

      // Update metadata to add download token for public URL access
      $this->updateFileMetadata($bucket, $storage_path, $download_token, $access_token);

      // Construct public URL
      $public_url = "https://firebasestorage.googleapis.com/v0/b/$bucket/o/" .
        urlencode($storage_path) . "?alt=media&token=$download_token";

      $this->logger->info('Successfully uploaded file to Firebase Storage: @path', [
        '@path' => $storage_path,
      ]);

      return new FileStorageResponse($public_url, $download_token, $storage_path, $bucket);
    } catch (GuzzleException $e) {
      $this->logger->error('Failed to upload to Firebase Storage: @message', [
        '@message' => $e->getMessage()
      ]);
    } catch (RandomException $e) {
      $this->logger->error('Generating random token failed: ' . $e->getMessage());
    }
    return FALSE;
  }

  /**
   * Update file metadata to add download token.
   */
  private function updateFileMetadata(string $bucket, string $storage_path, string $download_token, string $access_token): void {
    try {
      $metadata_url = "https://firebasestorage.googleapis.com/v0/b/$bucket/o/" . urlencode($storage_path);

      $this->httpClient->request('PATCH', $metadata_url, [
        'headers' => [
          'Authorization' => 'Bearer ' . $access_token,
          'Content-Type' => 'application/json',
        ],
        'json' => [
          'metadata' => [
            'firebaseStorageDownloadTokens' => $download_token,
          ],
        ],
      ]);
    } catch (GuzzleException $e) {
      $this->logger->warning('Failed to update file metadata: @message', [
        '@message' => $e->getMessage()
      ]);
    }
  }

  /**
   * Convert Drupal URI to real filesystem path.
   */
  private function getRealPath(string $file_path): false|string
  {
    // Check if it's a Drupal URI (e.g., public://, private://).
    if (str_contains($file_path, '://')) {
      return $this->fileSystem->realpath($file_path);
    }
    return $file_path;
  }

  /**
   * Get OAuth2 access token with caching.
   */
  private function getAccessToken(): string|false {
    // Return cached token if still valid (with 5 minute buffer)
    if ($this->cachedAccessToken && $this->tokenExpiryTime && time() < ($this->tokenExpiryTime - 300)) {
      return $this->cachedAccessToken;
    }

    $tokenUri = $this->credentials['token_uri'];

    // Create JWT for authentication.
    $now = time();
    $payload = [
      'iss' => $this->credentials['client_email'],
      'scope' => 'https://www.googleapis.com/auth/firebase https://www.googleapis.com/auth/cloud-platform',
      'aud' => $tokenUri,
      'iat' => $now,
      'exp' => $now + 3600,
    ];

    // Sign the JWT.
    $jwt = $this->createJWT($payload, $this->credentials['private_key']);

    // Exchange JWT for access token.
    try {
      $response = $this->httpClient->request('POST', $tokenUri, [
        'form_params' => [
          'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
          'assertion' => $jwt,
        ],
      ]);
      $result = json_decode($response->getBody()->getContents(), TRUE);

      if (isset($result['access_token'])) {
        // Cache the token
        $this->cachedAccessToken = $result['access_token'];
        $this->tokenExpiryTime = time() + ($result['expires_in'] ?? 3600);
        return $this->cachedAccessToken;
      }

      $this->logger->error('Access token not found in response: ' . json_encode($result));
      return FALSE;
    } catch (GuzzleException $e) {
      $this->logger->error('Fetching access token failed: ' . $e->getMessage());
    }
    return FALSE;
  }

  /**
   * Create JWT for service account authentication.
   */
  private function createJWT(array $payload, string $private_key): string
  {
    $header = [
      'alg' => 'RS256',
      'typ' => 'JWT',
    ];

    $segments = [];
    $segments[] = $this->base64UrlEncode(json_encode($header));
    $segments[] = $this->base64UrlEncode(json_encode($payload));
    $signing_input = implode('.', $segments);

    $signature = '';
    openssl_sign($signing_input, $signature, $private_key, OPENSSL_ALGO_SHA256);
    $segments[] = $this->base64UrlEncode($signature);

    return implode('.', $segments);
  }

  /**
   * Base64 URL-safe encoding.
   */
  private function base64UrlEncode(string $data): string
  {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
  }
}
