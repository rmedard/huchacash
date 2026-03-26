<?php

namespace Drupal\oauth_custom_grant\Grant;

use DateInterval;
use Drupal\oauth_custom_grant\Service\FirebaseTokenVerifier;
use Drupal\oauth_custom_grant\Service\FirebaseUserResolver;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Exception\UniqueTokenIdentifierConstraintViolationException;
use League\OAuth2\Server\Grant\AbstractGrant;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use League\OAuth2\Server\ResponseTypes\ResponseTypeInterface;
use Psr\Http\Message\ServerRequestInterface;

class FirebaseOtpGrant  extends AbstractGrant
{
  public function __construct(
    protected FirebaseTokenVerifier $tokenVerifier,
    protected FirebaseUserResolver $userResolver,
    RefreshTokenRepositoryInterface $refreshTokenRepository,
  ) {
    $this->setRefreshTokenRepository($refreshTokenRepository);
    // Refresh tokens are valid for 30 days by default. simple_oauth sets this
    // via the grant plugin's refreshTokenTTL property — kept here for clarity.
    $this->refreshTokenTTL = new \DateInterval('P1M');
  }

  // -------------------------------------------------------------------------
  // AbstractGrant contract
  // -------------------------------------------------------------------------

  public function getIdentifier(): string {
    return 'firebase_otp';
  }

  /**
   * @throws UniqueTokenIdentifierConstraintViolationException
   * @throws OAuthServerException
   */
  public function respondToAccessTokenRequest(ServerRequestInterface $request, ResponseTypeInterface $responseType, DateInterval $accessTokenTTL,): ResponseTypeInterface {

    // 1. Authenticate the OAuth2 client (client_id + client_secret).
    $client = $this->validateClient($request);

    // 2. Extract and validate the firebase_token parameter.
    $firebaseToken = $this->getRequestParameter('firebase_token', $request);

    if (empty($firebaseToken)) {
      throw OAuthServerException::invalidRequest('firebase_token', 'The firebase_token parameter is required.');
    }

    // 3. Verify the Firebase ID token with Google.
    try {
      $payload = $this->tokenVerifier->verify($firebaseToken);
    }
    catch (\RuntimeException $e) {
      throw OAuthServerException::invalidCredentials();
    }

    // 4. Resolve / auto-create the Drupal user.
    try {
      $account = $this->userResolver->resolve($payload);
    }
    catch (\RuntimeException $e) {
      // User not found and auto-create is disabled.
      throw OAuthServerException::invalidCredentials();
    }

    if ($account->isBlocked()) {
      throw OAuthServerException::accessDenied('This account has been blocked.');
    }

    // 5. Determine requested scopes (fall back to default scope).
    $scopes = $this->validateScopes($this->defaultScope);

    // 6. Issue the access token.
    $accessToken = $this->issueAccessToken(
      $accessTokenTTL,
      $client,
      (string) $account->id(),
      $scopes,
    );

    // 7. Issue a refresh token.
    $refreshToken = $this->issueRefreshToken($accessToken);

    $responseType->setAccessToken($accessToken);
    $responseType->setRefreshToken($refreshToken);

    return $responseType;
  }
}
