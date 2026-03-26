<?php

namespace Drupal\oauth_custom_grant\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Site\Settings;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Verifies Firebase ID tokens via the Google Identity Toolkit REST API.
 *
 * For production hardening, consider replacing the REST lookup with full
 * JWT signature verification against Google's public keys — this avoids
 * a network round-trip and removes the dependency on the Firebase API key.
 * The REST approach is simpler and sufficient for most use cases.
 */
class FirebaseTokenVerifier {

  protected ClientInterface $httpClient;
  protected LoggerInterface $logger;

  public function __construct(ClientInterface $httpClient, $loggerFactory, protected CacheBackendInterface $cache) {
    $this->httpClient = $httpClient;
    $this->logger = $loggerFactory->get('FirebaseTokenVerifier');
  }

  /**
   * Verifies a Firebase ID token and returns its payload.
   *
   * @param string $idToken
   *   The Firebase ID token from the mobile client.
   *
   * @return array{
   *   uid: string,
   *   phone_number: string|null,
   *   email: string|null,
   *   email_verified: bool,
   * }
   *   Verified token payload.
   *
   * @throws RuntimeException
   * @throws GuzzleException
   *   If the token is invalid or verification fails.
   */

  // FirebaseTokenVerifier.php — improved using your existing service account

  public function verify(string $idToken): array {

    $gcSettingsFileLocation = Settings::get('gc_tasks_settings_file');

    // Validate the file path
    if (!file_exists($gcSettingsFileLocation) || !is_readable($gcSettingsFileLocation)) {
      throw new RuntimeException("The Google Cloud credentials file is missing or unreadable at: $gcSettingsFileLocation");
    }

    $credentialsContents = json_decode(file_get_contents($gcSettingsFileLocation), true);
    $projectId = $credentialsContents['project_id'] ?? null;
    if (empty($projectId)) {
      throw new RuntimeException('Project ID not found in credentials file');
    }

    $publicKeys = $this->getGooglePublicKeys();

    // Decode header to find which key was used
    [$headerB64] = explode('.', $idToken);
    $header = json_decode(base64_decode(strtr($headerB64, '-_', '+/')), true);
    $kid = $header['kid'] ?? null;

    if (!$kid || !isset($publicKeys[$kid])) {
      throw new RuntimeException('Firebase token signed with unknown key.');
    }

    // Verify signature + claims using firebase/php-jwt
    $decoded = JWT::decode($idToken, new Key($publicKeys[$kid], 'RS256'));

    // Validate claims manually
    $now = time();
    if ($decoded->iss !== "https://securetoken.google.com/{$projectId}") {
      throw new RuntimeException('Invalid token issuer.');
    }
    if ($decoded->aud !== $projectId) {
      throw new RuntimeException('Invalid token audience.');
    }
    if ($decoded->exp < $now || $decoded->iat > $now) {
      throw new RuntimeException('Token is expired or not yet valid.');
    }

    return [
      'uid'            => $decoded->sub,
      'phone_number'   => $decoded->phone_number ?? null,
      'email'          => $decoded->email ?? null,
      'email_verified' => (bool) ($decoded->email_verified ?? false),
    ];
  }

  /**
   * @throws GuzzleException
   */
  private function getGooglePublicKeys(): array {
    $cacheId = 'oauth_custom_grant:google_public_keys';
    $cached  = $this->cache->get($cacheId);
    if ($cached) {
      return $cached->data;
    }

    $response   = $this->httpClient->get(
      'https://www.googleapis.com/robot/v1/metadata/x509/securetoken@system.gserviceaccount.com',
      ['timeout' => 10]
    );
    $publicKeys = json_decode((string) $response->getBody(), true);

    preg_match('/max-age=(\d+)/', $response->getHeader('Cache-Control')[0] ?? '', $m);
    $maxAge = (int) ($m[1] ?? 3600);

    $this->cache->set($cacheId, $publicKeys, time() + $maxAge);

    return $publicKeys;
  }
}
