<?php

namespace Drupal\dinger_settings\Service;

use Drupal\Core\Config\ConfigException;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Site\Settings;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

final class FirebaseFunctionsService
{
  protected LoggerChannelInterface $logger;
  protected ImmutableConfig $config;

  protected ?string $projectId = null;
  protected ?array $credentials = null;

  private ?string $cachedAccessToken = null;
  private ?int $tokenExpiryTime = null;

  public function __construct(private readonly ClientInterface $httpClient,
                              private readonly LoggerChannelFactoryInterface $loggerFactory,
                              private readonly ConfigFactoryInterface $configFactory) {
    $this->logger = $this->loggerFactory->get('FirebaseFunctionsService');
    $this->initialize();
  }

  private function initialize(): void
  {
    $settingsFileLocation = Settings::get('gc_tasks_settings_file');
    if (empty($settingsFileLocation) || !file_exists($settingsFileLocation)) {
      throw new ConfigException('Google Cloud credentials file not found at: ' . $settingsFileLocation);
    }
    $this->credentials = json_decode(file_get_contents($settingsFileLocation), TRUE);

    if (!isset($this->credentials['project_id'], $this->credentials['client_email'], $this->credentials['private_key'])) {
      throw new ConfigException('Invalid Google Cloud credentials file format.');
    }

    $this->projectId = $this->credentials['project_id'];
    $this->config = $this->configFactory->get('dinger_settings');
  }

  /**
   * Call a Firebase Cloud Function.
   *
   * @param string $functionName
   *   The name of the function to call.
   * @param array $data
   *   The data to send to the function.
   * @param string $region
   *   The region where the function is deployed (default: europe-west1).
   *
   * @return array|null
   *   The response data or NULL on failure.
   */
  public function call(string $functionName, array $data = [], string $region = 'europe-west1'): ?array {
    $url = "https://{$region}-{$this->projectId}.cloudfunctions.net/{$functionName}";

    $accessToken = $this->getAccessToken();
    if (!$accessToken) {
      $this->logger->error('Failed to get access token for function call.');
      return NULL;
    }

    try {
      $response = $this->httpClient->request('POST', $url, [
        'headers' => [
          'Authorization' => 'Bearer ' . $accessToken,
          'Content-Type' => 'application/json',
        ],
        'json' => $data,
        'timeout' => 30,
      ]);

      $result = json_decode($response->getBody()->getContents(), TRUE);

      $this->logger->info('Successfully called Firebase function: @name', [
        '@name' => $functionName,
      ]);

      return $result;

    } catch (GuzzleException $e) {
      $this->logger->error('Firebase function call failed: @name | @message', [
        '@name' => $functionName,
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Get OAuth2 access token with caching.
   */
  private function getAccessToken(): string|false {
    if ($this->cachedAccessToken && $this->tokenExpiryTime && time() < ($this->tokenExpiryTime - 300)) {
      return $this->cachedAccessToken;
    }

    $tokenUri = $this->credentials['token_uri'];
    $now = time();

    $payload = [
      'iss' => $this->credentials['client_email'],
      'scope' => 'https://www.googleapis.com/auth/cloud-platform',
      'aud' => $tokenUri,
      'iat' => $now,
      'exp' => $now + 3600,
    ];

    $jwt = $this->createJWT($payload, $this->credentials['private_key']);

    try {
      $response = $this->httpClient->request('POST', $tokenUri, [
        'form_params' => [
          'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
          'assertion' => $jwt,
        ],
      ]);
      $result = json_decode($response->getBody()->getContents(), TRUE);

      if (isset($result['access_token'])) {
        $this->cachedAccessToken = $result['access_token'];
        $this->tokenExpiryTime = time() + ($result['expires_in'] ?? 3600);
        return $this->cachedAccessToken;
      }

      $this->logger->error('Access token not found in response.');
      return FALSE;
    } catch (GuzzleException $e) {
      $this->logger->error('Fetching access token failed: ' . $e->getMessage());
    }
    return FALSE;
  }

  private function createJWT(array $payload, string $privateKey): string {
    $header = ['alg' => 'RS256', 'typ' => 'JWT'];

    $segments = [
      $this->base64UrlEncode(json_encode($header)),
      $this->base64UrlEncode(json_encode($payload)),
    ];

    $signingInput = implode('.', $segments);
    openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256);
    $segments[] = $this->base64UrlEncode($signature);

    return implode('.', $segments);
  }

  private function base64UrlEncode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
  }

}
