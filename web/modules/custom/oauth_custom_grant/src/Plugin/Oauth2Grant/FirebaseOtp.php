<?php

namespace Drupal\oauth_custom_grant\Plugin\Oauth2Grant;

use DateInterval;
use Drupal\consumers\Entity\Consumer;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\oauth_custom_grant\Grant\FirebaseOtpGrant;
use Drupal\oauth_custom_grant\Service\FirebaseTokenVerifier;
use Drupal\oauth_custom_grant\Service\FirebaseUserResolver;
use Drupal\simple_oauth\Annotation\Oauth2Grant;
use Drupal\simple_oauth\Plugin\Oauth2GrantBase;
use League\OAuth2\Server\Grant\GrantTypeInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @Oauth2Grant(
 *   id = "firebase_otp",
 *   label = @Translation("Firebase OTP")
 * )
 */
class FirebaseOtp extends Oauth2GrantBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    string $pluginId,
    mixed $pluginDefinition,
    protected RefreshTokenRepositoryInterface $refreshTokenRepository,
    protected FirebaseTokenVerifier $tokenVerifier,
    protected FirebaseUserResolver $userResolver,
    protected ConfigFactoryInterface $configFactory,
  ) {
    parent::__construct($configuration, $pluginId, $pluginDefinition);
  }

  public static function create(
    ContainerInterface $container,
    array $configuration,
                       $plugin_id,
                       $plugin_definition,
  ): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('simple_oauth.repositories.refresh_token'),
      $container->get('oauth_custom_grant.firebase_token_verifier'),
      $container->get('oauth_custom_grant.firebase_user_resolver'),
      $container->get('config.factory'),
    );
  }

  public function getGrantType(Consumer $client): GrantTypeInterface {
    $grant = new FirebaseOtpGrant($this->tokenVerifier, $this->userResolver, $this->refreshTokenRepository);

    $settings = $this->configFactory->get('simple_oauth.settings');
    $grant->setRefreshTokenTTL(new DateInterval('PT' . $settings->get('refresh_token_expiration') . 'S'));

    return $grant;
  }

}
