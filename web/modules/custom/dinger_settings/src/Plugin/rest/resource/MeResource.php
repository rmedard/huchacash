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
use Symfony\Component\HttpFoundation\Request;

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

  public function get(): ModifiedResourceResponse
  {
    // ========== COMPREHENSIVE DEBUG ==========
    $request = Drupal::request();

    $this->logger->info('=== ME RESOURCE COMPREHENSIVE DEBUG ===');

    // 1. Check the authenticated user
    $this->logger->info('User ID: ' . $this->loggedUser->id());
    $this->logger->info('User Name: ' . $this->loggedUser->getAccountName());
    $this->logger->info('Is Authenticated: ' . ($this->loggedUser->isAuthenticated() ? 'YES' : 'NO'));

    // 2. Check all roles (including inherited)
    $roles = $this->loggedUser->getRoles();
    $this->logger->info('All Roles: ' . print_r($roles, true));

    $roles_true = $this->loggedUser->getRoles(true);
    $this->logger->info('True Roles (excluding authenticated): ' . print_r($roles_true, true));

    // 3. Check specific permissions
    $permission_to_check = 'restful get me_resource';
    $has_permission = $this->loggedUser->hasPermission($permission_to_check);
    $this->logger->info('Has permission "' . $permission_to_check . '": ' . ($has_permission ? 'YES' : 'NO'));

    // 4. Check all permissions the user has (this will be a long list, but useful)
    // Uncomment if needed, but may be verbose
    // $user = Drupal::entityTypeManager()->getStorage('user')->load($this->loggedUser->id());
    // if ($user) {
    //   $all_perms = $user->getPermissions();
    //   $this->logger->info('All permissions: ' . print_r($all_perms, true));
    // }

    // 5. Check the request headers that Drupal sees
    $this->logger->info('Authorization header: ' . ($request->headers->get('Authorization') ? 'PRESENT' : 'NOT PRESENT'));
    $this->logger->info('X-Consumer-ID header: ' . ($request->headers->get('X-Consumer-ID') ?: 'NOT PRESENT'));

    // 6. Check if the REST resource permissions are being applied correctly
    $route_name = $request->attributes->get('_route');
    $this->logger->info('Route name: ' . $route_name);

    // 7. Check if there's any access restriction on the route
    $route = Drupal::service('router')->matchRequest($request);
    $this->logger->info('Route access: ' . print_r($route, true));

    $this->logger->info('=== END DEBUG ===');

    // Your existing logic with more detailed error messages
    $response = new ModifiedResourceResponse();

    if (!$this->loggedUser->isAuthenticated()) {
      $this->logger->info('User is not authenticated');
      $response->setStatusCode(Response::HTTP_UNAUTHORIZED);
      $response->setContent(json_encode(['error' => 'Not authenticated']));
      return $response;
    }

    $roles = $this->loggedUser->getRoles(true);
    $this->logger->info('User is authenticated with roles: ' . implode(', ', $roles));

    if (!in_array('customer', $roles)) {
      $this->logger->info('Customer role not found in user roles');
      $response->setStatusCode(Response::HTTP_FORBIDDEN);
      $response->setContent(json_encode([
        'error' => 'Customer role required',
        'your_roles' => $roles,
        'user_id' => $this->loggedUser->id(),
        'user_name' => $this->loggedUser->getAccountName()
      ]));
      return $response;
    }

    // Check for customer node
    try {
      $customerIds = Drupal::entityTypeManager()
        ->getStorage('node')->getQuery()->accessCheck(FALSE)
        ->condition('type', 'customer')
        ->condition('field_customer_user.target_id', $this->loggedUser->id())
        ->execute();

      $this->logger->info('Customer nodes found: ' . count($customerIds));

      if (count($customerIds) == 0) {
        $response->setStatusCode(Response::HTTP_NOT_FOUND);
        $response->setContent(json_encode([
          'error' => 'No customer profile found for this user',
          'user_id' => $this->loggedUser->id()
        ]));
        return $response;
      }

      if (count($customerIds) > 1) {
        $response->setStatusCode(Response::HTTP_NOT_ACCEPTABLE);
        $response->setContent(json_encode(['error' => 'Multiple customer profiles found']));
        return $response;
      }

      $customerId = reset($customerIds);
      $customer = Node::load($customerId);

      $this->logger->info('Success! Returning customer ID: ' . $customer->uuid());
      $response = new ModifiedResourceResponse(['customer_id' => $customer->uuid()]);
      return $response;

    } catch (InvalidPluginDefinitionException|PluginNotFoundException $e) {
      $this->logger->error('Error: ' . $e->getMessage());
      $response->setStatusCode(Response::HTTP_INTERNAL_SERVER_ERROR);
      $response->setContent(json_encode(['error' => $e->getMessage()]));
      return $response;
    }
  }

}
