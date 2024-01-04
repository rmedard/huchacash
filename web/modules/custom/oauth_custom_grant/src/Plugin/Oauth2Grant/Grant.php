<?php

namespace Drupal\oauth_custom_grant\Plugin\Oauth2Grant;

use DateInterval;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\oauth_custom_grant\Grant\CustomGrant;
use Drupal\simple_oauth\Annotation\Oauth2Grant;
use Drupal\simple_oauth\Plugin\Oauth2GrantBase;
use Exception;
use League\OAuth2\Server\Grant\GrantTypeInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\UserRepositoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @Oauth2Grant(
 *   id = "custom",
 *   label = @Translation("Custom Grant")
 * )
 */
class Grant extends Oauth2GrantBase
{

  /**
   * @var UserRepositoryInterface
   */
  protected UserRepositoryInterface $userRepository;

  /**
   * @var RefreshTokenRepositoryInterface
   */
  protected RefreshTokenRepositoryInterface $refreshTokenRepository;

  /**
   * The config factory.
   *
   * @var ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * @param array $configuration
   * @param $plugin_id
   * @param $plugin_definition
   * @param UserRepositoryInterface $userRepository
   * @param RefreshTokenRepositoryInterface $refreshTokenRepository
   * @param ConfigFactoryInterface $configFactory
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, UserRepositoryInterface $userRepository, RefreshTokenRepositoryInterface $refreshTokenRepository, ConfigFactoryInterface $configFactory)
  {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->userRepository = $userRepository;
    $this->refreshTokenRepository = $refreshTokenRepository;
    $this->configFactory = $configFactory;
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): Grant|Oauth2GrantBase
  {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('oauth_custom_grant.repositories.user'),
      $container->get('simple_oauth.repositories.refresh_token'),
      $container->get('config.factory')
    );
  }

  /**
   * {@inheritdoc}
   * @throws Exception
   */
  public function getGrantType(): CustomGrant|GrantTypeInterface
  {
    $grant = new CustomGrant($this->userRepository, $this->refreshTokenRepository);
    $settings = $this->configFactory->get('simple_oauth.settings');
    $grant->setRefreshTokenTTL(new DateInterval(sprintf('PT%dS', $settings->get('refresh_token_expiration'))));
    return $grant;
  }
}
