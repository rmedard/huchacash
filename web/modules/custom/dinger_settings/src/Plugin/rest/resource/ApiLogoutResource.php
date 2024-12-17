<?php

declare(strict_types=1);

namespace Drupal\dinger_settings\Plugin\rest\resource;

use Drupal;
use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\rest\Attribute\RestResource;
use Drupal\rest\ModifiedResourceResponse;
use Drupal\rest\Plugin\ResourceBase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Represents Api Logout records as resources.
 *
 * @DCG
 * The plugin exposes key-value records as REST resources. In order to enable it
 * import the resource configuration into active configuration storage. An
 * example of such configuration can be located in the following file:
 * core/modules/rest/config/optional/rest.resource.entity.node.yml.
 * Alternatively, you can enable it through admin interface provider by REST UI
 * module.
 * @see https://www.drupal.org/project/restui
 *
 * @DCG
 * Notice that this plugin does not provide any validation for the data.
 * Consider creating custom normalizer to validate and normalize the incoming
 * data. It can be enabled in the plugin definition as follows.
 * @code
 *   serialization_class = "Drupal\foo\MyDataStructure",
 * @endcode
 *
 * @DCG
 * For entities, it is recommended to use REST resource plugin provided by
 * Drupal core.
 * @see \Drupal\rest\Plugin\rest\resource\EntityResource
 */
#[RestResource(
  id: 'api_logout',
  label: new TranslatableMarkup('Api Logout'),
  uri_paths: [
    'canonical' => '/api-logout'
  ],
)]
final class ApiLogoutResource extends ResourceBase {

  /**
   * @var AccountProxyInterface
   */
  protected AccountProxyInterface $loggedUser;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    LoggerInterface $logger,
    AccountProxyInterface $loggedUser
  ) {
    $this->loggedUser = $loggedUser;
    parent::__construct($configuration, $plugin_id, $plugin_definition, [], $logger);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('logger.factory')->get('rest'),
      $container->get('current_user')
    );
  }

  /**
   * Return type is ResourceResponse but uncached
   * @return ModifiedResourceResponse
   */
  public function get(): ModifiedResourceResponse {
    $this->logger->info('Logout resource triggered. User logged-in: ' . $this->loggedUser->isAuthenticated());
    if ($this->loggedUser->isAuthenticated()) {
      try {
        $tokenEntityStorage = Drupal::entityTypeManager()->getStorage('oauth2_token');
        $userTokenIds = $tokenEntityStorage->getQuery()
          ->accessCheck(false)
          ->condition('auth_user_id', $this->loggedUser->id())
          ->execute();
        if (count($userTokenIds) > 0) {
          $userTokens = $tokenEntityStorage->loadMultiple($userTokenIds);
          foreach ($userTokens as $tokenId => $token) {
            $token->delete();
          }
          $this->logger->info('Logout successful');
          return new ModifiedResourceResponse(['message' => 'Logout successful'], Response::HTTP_OK);
        } else {
          return new ModifiedResourceResponse(['message' => 'User not logged in'], Response::HTTP_NO_CONTENT);
        }
      } catch (InvalidPluginDefinitionException|PluginNotFoundException|EntityStorageException $e) {
        $this->logger->error('Logout failed: ' . $e->getMessage());
        return new ModifiedResourceResponse(['message' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
      }
    } else {
      return new ModifiedResourceResponse(['message' => 'User not authenticated'], Response::HTTP_UNAUTHORIZED);
    }
  }

}
