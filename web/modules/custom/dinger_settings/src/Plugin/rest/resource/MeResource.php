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
final class MeResource extends ResourceBase {

  protected AccountProxyInterface $loggedUser;

  /**
   * @param array $configuration
   * @param $plugin_id
   * @param $plugin_definition
   * @param array $serializer_formats
   * @param LoggerInterface $logger
   * @param AccountProxyInterface $loggedUser
   */
  public function __construct(array           $configuration,
                                              $plugin_id,
                                              $plugin_definition,
                              array           $serializer_formats,
                              LoggerInterface $logger, AccountProxyInterface $loggedUser)
  {
    $this->loggedUser = $loggedUser;
    parent::__construct($configuration, $plugin_id, $plugin_definition, $serializer_formats, $logger);
  }

  /**
   * {@inheritdoc}
   */
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
   * Return type is ResourceResponse but uncached
   * @return ModifiedResourceResponse
   */
  public function get(): ModifiedResourceResponse
  {
    // ========== START DEBUGGING ==========
    $request = Drupal::request();

    // Debug 1: Log all headers
    $this->logger->info('=== ME RESOURCE DEBUG START ===');
    $this->logger->info('All headers: ' . print_r($request->headers->all(), true));

    // Debug 2: Check Authorization header specifically
    $auth_header = $request->headers->get('Authorization');
    $this->logger->info('Authorization header: ' . ($auth_header ? $auth_header : 'NOT SET'));

    // Debug 3: Check if there's an OAuth2 token in the request
    $oauth_token = $request->get('oauth_token');
    $this->logger->info('oauth_token parameter: ' . ($oauth_token ? $oauth_token : 'NOT SET'));

    // Debug 4: Check current user details
    $this->logger->info('Current user ID: ' . $this->loggedUser->id());
    $this->logger->info('Current user is authenticated: ' . ($this->loggedUser->isAuthenticated() ? 'YES' : 'NO'));
    $this->logger->info('Current user roles: ' . print_r($this->loggedUser->getRoles(), true));

    // Debug 5: Check if the user has the required permission
    $has_permission = $this->loggedUser->hasPermission('restful get me_resource');
    $this->logger->info('Has "restful get me_resource" permission: ' . ($has_permission ? 'YES' : 'NO'));

    // Debug 6: Check session
    $session = $request->getSession();
    if ($session) {
      $this->logger->info('Session ID: ' . $session->getId());
    } else {
      $this->logger->info('No session found');
    }

    // Debug 7: Check if this is an API gateway request
    $consumer_id = $request->headers->get('x-consumer-id');
    if ($consumer_id) {
      $this->logger->info('X-Consumer-ID header found: ' . $consumer_id);
      $this->logger->info('This request is going through an API gateway!');
    }

    $this->logger->info('=== ME RESOURCE DEBUG END ===');
    // ========== END DEBUGGING ==========

    $this->logger->info('Me resource triggered. User logged-in: ' . $this->loggedUser->isAuthenticated());
    $response = new ModifiedResourceResponse();

    if ($this->loggedUser->isAuthenticated()) {
      $roles = $this->loggedUser->getRoles(true);
      $this->logger->info('/api/me => roles: <pre><code>' . print_r($roles, true) . '<code></pre>');
      if (in_array('customer', $roles)) {
        try {
          $this->logger->info('Fetching customer');
          $customerIds = Drupal::entityTypeManager()
            ->getStorage('node')->getQuery()->accessCheck()
            ->condition('type', 'customer')
            ->condition('field_customer_user.target_id', $this->loggedUser->id())
            ->execute();
          if (count($customerIds) > 1) {
            $this->logger->info('Multiple customers for user: ' . $this->loggedUser->id());
            $response->setStatusCode(Response::HTTP_NOT_ACCEPTABLE);
            $response->setContent('Illegal state');
          } else if (count($customerIds) == 1) {
            $customerId = reset($customerIds);
            $this->logger->info('Loading customer nid: ' . $customerId);
            $customer = Node::load($customerId);
            $response = new ModifiedResourceResponse(['customer_id' => $customer->uuid()]);
          } else {
            $this->logger->info('Customer not found for user: ' . $this->loggedUser->id());
            $response->setStatusCode(Response::HTTP_NOT_FOUND);
            $response->setContent('No customer details found');
          }
        } catch (InvalidPluginDefinitionException|PluginNotFoundException $e) {
          $this->logger->error('Fetching customer failed: ' . $e->getMessage());
          $response->setStatusCode(Response::HTTP_INTERNAL_SERVER_ERROR);
          $response->setContent($e->getMessage());
        }
      } else {
        // User is authenticated but doesn't have customer role
        $this->logger->info('User authenticated but missing customer role. Roles: ' . print_r($roles, true));
        $response->setStatusCode(Response::HTTP_FORBIDDEN);
        $response->setContent('User does not have customer role');
      }
    } else {
      // User is not authenticated
      $this->logger->info('User is not authenticated');
      $response->setStatusCode(Response::HTTP_UNAUTHORIZED);
      $response->setContent('Authentication required');
    }

    return $response;
  }

}
