<?php

namespace Drupal\oauth_custom_grant\Repositories;

use Drupal\Core\Logger\LoggerChannelFactory;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\simple_oauth\Entities\UserEntity;
use Drupal\user\Entity\User;
use Drupal\user\UserAuthInterface;
use League\Container\Exception\NotFoundException;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\UserEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Repositories\UserRepositoryInterface;

class PasswordUserRepository implements UserRepositoryInterface
{

  /**
   * @var UserAuthInterface
   */
  protected UserAuthInterface $userAuth;

  /**
   * Drupal\Core\Logger\LoggerChannelFactory definition.
   *
   * @var LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * @param UserAuthInterface $userAuth
   * @param LoggerChannelFactory $logger
   */
  public function __construct(UserAuthInterface $userAuth, LoggerChannelFactory $logger)
  {
    $this->userAuth = $userAuth;
    $this->logger = $logger->get('PasswordUserRepository');
  }

  /**
   * @inheritDoc
   * @throws OAuthServerException
   */
  public function getUserEntityByUserCredentials($mail, $password, $grantType, ClientEntityInterface $clientEntity): UserEntityInterface
  {
    $user = user_load_by_mail($mail);
    if ($user === false) {
      $this->logger->warning('Login attempt for unknown email: @mail', ['@mail' => $mail]);
      throw OAuthServerException::invalidCredentials();
    }

    $user_id = $this->userAuth->authenticate($user->getEmail(), $password);
    if ($user_id === false) {
      $this->logger->warning('Invalid password for user: @mail', ['@mail' => $mail]);
      throw OAuthServerException::invalidCredentials();
    }

    $userEntity = User::load((int)$user_id);
    user_login_finalize($userEntity);

    $userEntity = new UserEntity();
    $userEntity->setIdentifier($user->id());
    return $userEntity;
  }
}
