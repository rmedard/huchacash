<?php

declare(strict_types=1);

namespace Drupal\dinger_settings\Plugin\rest\resource;

use Drupal;
use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\Entity\Node;
use Drupal\rest\Attribute\RestResource;
use Drupal\rest\ModifiedResourceResponse;
use Drupal\rest\Plugin\ResourceBase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

#[RestResource(
  id: 'me_resource',
  label: new TranslatableMarkup('Me Resource'),
  uri_paths: [
    'canonical' => '/api/me'
  ],
)]
final class MeResource extends ResourceBase
{

  protected AccountProxyInterface $loggedUser;

  public function __construct(array           $configuration,
                                              $plugin_id,
                                              $plugin_definition,
                              array           $serializer_formats,
                              LoggerInterface $logger,
                              AccountProxyInterface $loggedUser)
  {
    $this->loggedUser = $loggedUser;
    parent::__construct($configuration, $plugin_id, $plugin_definition, $serializer_formats, $logger);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self
  {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->getParameter('serializer.formats'),
      $container->get('logger.factory')->get('me_resource'),
      $container->get('current_user')
    );
  }

  /**
   * Override access to bypass the REST permission check.
   * We do our own authorization in get().
   */
  public function access($method, \Drupal\Core\Session\AccountInterface $account, $route_match = NULL) {
    if ($method === 'GET' && $account->isAuthenticated()) {
      return \Drupal\Core\Access\AccessResult::allowed();
    }
    return parent::access($method, $account, $route_match);
  }

  public function get(): ModifiedResourceResponse
  {
    $response = new ModifiedResourceResponse();

    $user_id = $this->loggedUser->id();

    if (!$user_id || $user_id === 0) {
      $response->setStatusCode(Response::HTTP_UNAUTHORIZED);
      $response->setContent(json_encode(['error' => 'Not authenticated']));
      return $response;
    }

    try {
      $user_storage = Drupal::entityTypeManager()->getStorage('user');
      $user = $user_storage->load($user_id);

      if (!$user || $user->isAnonymous()) {
        $response->setStatusCode(Response::HTTP_UNAUTHORIZED);
        $response->setContent(json_encode(['error' => 'User not found']));
        return $response;
      }

      $roles = $user->getRoles(true);

      if (!in_array('customer', $roles)) {
        $response->setStatusCode(Response::HTTP_FORBIDDEN);
        $response->setContent(json_encode([
          'error' => 'Customer role required',
          'your_roles' => $roles,
          'user_id' => $user_id,
          'user_name' => $user->getAccountName()
        ]));
        return $response;
      }

      $customerIds = Drupal::entityTypeManager()
        ->getStorage('node')
        ->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'customer')
        ->condition('field_customer_user.target_id', $user_id)
        ->execute();

      if (count($customerIds) === 0) {
        $response->setStatusCode(Response::HTTP_NOT_FOUND);
        $response->setContent(json_encode([
          'error' => 'No customer profile found for this user',
          'user_id' => $user_id
        ]));
        return $response;
      }

      if (count($customerIds) > 1) {
        $response->setStatusCode(Response::HTTP_NOT_ACCEPTABLE);
        $response->setContent(json_encode([
          'error' => 'Multiple customer profiles found',
          'count' => count($customerIds)
        ]));
        return $response;
      }

      $customerId = reset($customerIds);
      $customer = Node::load($customerId);

      $response = new ModifiedResourceResponse([
        'customer_id' => $customer->uuid(),
        'user_id' => $user_id,
        'user_name' => $user->getAccountName()
      ]);

      return $response;

    } catch (InvalidPluginDefinitionException|PluginNotFoundException $e) {
      $this->logger->error('Error loading user/customer: ' . $e->getMessage());
      $response->setStatusCode(Response::HTTP_INTERNAL_SERVER_ERROR);
      $response->setContent(json_encode(['error' => 'Internal server error']));
      return $response;
    }
  }

}
