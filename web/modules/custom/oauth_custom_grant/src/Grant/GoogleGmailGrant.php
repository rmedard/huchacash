<?php

namespace Drupal\oauth_custom_grant\Grant;

use DateInterval;
use Drupal;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\UserEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Grant\AbstractGrant;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\UserRepositoryInterface;
use League\OAuth2\Server\RequestEvent;
use League\OAuth2\Server\ResponseTypes\ResponseTypeInterface;
use Psr\Http\Message\ServerRequestInterface;

class GoogleGmailGrant extends AbstractGrant
{

  /**
   * @param UserRepositoryInterface $userRepository
   * @param RefreshTokenRepositoryInterface $refreshTokenRepository
   */
  public function __construct(
    UserRepositoryInterface $userRepository,
    RefreshTokenRepositoryInterface $refreshTokenRepository)
  {
    $this->setUserRepository($userRepository);
    $this->setRefreshTokenRepository($refreshTokenRepository);

    $this->refreshTokenTTL = new DateInterval('P1M');
  }

  /**
   * {@inheritdoc}
   */
  public function getIdentifier(): string
  {
    return 'gmail_credentials';
  }

  /**
   * {@inheritdoc}
   */
  public function respondToAccessTokenRequest(ServerRequestInterface $request, ResponseTypeInterface $responseType, DateInterval $accessTokenTTL): ResponseTypeInterface
  {
    $logger = Drupal::logger('GoogleGmailGrant');
    try {
      // Validate request
      $logger->info("Validating request");
      $client = $this->validateClient($request);
      $scopes = $this->validateScopes($this->getRequestParameter('scope', $request, $this->defaultScope));
      $user = $this->validateUser($request, $client);

      // Finalize the requested scopes
      $logger->info("Finalising request scopes");
      $finalizedScopes = $this->scopeRepository->finalizeScopes($scopes, $this->getIdentifier(), $client, $user->getIdentifier());

      // Issue and persist new tokens
      $logger->info("Issue and persist new tokens");
      $accessToken = $this->issueAccessToken($accessTokenTTL, $client, $user->getIdentifier(), $finalizedScopes);
      $refreshToken = $this->issueRefreshToken($accessToken);

      // Inject tokens into response
      $logger->info("Inject tokens into response");
      $responseType->setAccessToken($accessToken);
      $responseType->setRefreshToken($refreshToken);

    } catch (OAuthServerException $e) {
      $logger->error($e);
    }
    return $responseType;
  }

  /**
   * @param ServerRequestInterface $request
   * @param ClientEntityInterface $client
   *
   * @return UserEntityInterface
   * @throws OAuthServerException
   *
   */
  protected function validateUser(ServerRequestInterface $request, ClientEntityInterface $client): UserEntityInterface
  {
    $email = $this->getRequestParameter('email', $request);
    if (is_null($email)) {
      throw OAuthServerException::invalidRequest('email');
    }

    $custom_token = $this->getRequestParameter('custom_token', $request);
    if (is_null($custom_token)) {
      throw OAuthServerException::invalidRequest('custom_token');
    }

    $user = $this->userRepository->getUserEntityByUserCredentials(
      $custom_token,
      $email,
      $this->getIdentifier(),
      $client
    );
    if ($user instanceof UserEntityInterface === false) {
      $this->getEmitter()->emit(new RequestEvent(RequestEvent::USER_AUTHENTICATION_FAILED, $request));

      throw OAuthServerException::invalidCredentials();
    }

    return $user;
  }
}
