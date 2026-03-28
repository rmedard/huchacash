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

  public function get(): ModifiedResourceResponse
  {
    // Debug the authenticated user
    $this->logger->info('=== ME RESOURCE AUTH DEBUG ===');
    $this->logger->info('User ID: ' . $this->loggedUser->id());
    $this->logger->info('User Name: ' . $this->loggedUser->getAccountName());
    $this->logger->info('Is Authenticated: ' . ($this->loggedUser->isAuthenticated() ? 'YES' : 'NO'));
    $this->logger->info('Roles: ' . print_r($this->loggedUser->getRoles(), true));

    // Check if this is a client (not a real user)
    $user = Drupal::entityTypeManager()->getStorage('user')->load($this->loggedUser->id());
    if ($user) {
      $this->logger->info('User mail: ' . $user->getEmail());
      $this->logger->info('Is blocked: ' . ($user->isBlocked() ? 'YES' : 'NO'));
    }

    $this->logger->info('=== END DEBUG ===');

    // Your existing logic
    $response = new ModifiedResourceResponse();

    if ($this->loggedUser->isAuthenticated()) {
      $roles = $this->loggedUser->getRoles(true);

      if (in_array('customer', $roles)) {
        try {
          $customerIds = Drupal::entityTypeManager()
            ->getStorage('node')->getQuery()->accessCheck()
            ->condition('type', 'customer')
            ->condition('field_customer_user.target_id', $this->loggedUser->id())
            ->execute();

          if (count($customerIds) > 1) {
            $response->setStatusCode(Response::HTTP_NOT_ACCEPTABLE);
            $response->setContent('Illegal state');
          } else if (count($customerIds) == 1) {
            $customerId = reset($customerIds);
            $customer = Node::load($customerId);
            $response = new ModifiedResourceResponse(['customer_id' => $customer->uuid()]);
          } else {
            $response->setStatusCode(Response::HTTP_NOT_FOUND);
            $response->setContent('No customer details found');
          }
        } catch (InvalidPluginDefinitionException|PluginNotFoundException $e) {
          $this->logger->error('Fetching customer failed: ' . $e->getMessage());
          $response->setStatusCode(Response::HTTP_INTERNAL_SERVER_ERROR);
          $response->setContent($e->getMessage());
        }
      } else {
        $response->setStatusCode(Response::HTTP_FORBIDDEN);
        $response->setContent('Customer role required. Your roles: ' . implode(', ', $roles));
      }
    } else {
      $response->setStatusCode(Response::HTTP_UNAUTHORIZED);
      $response->setContent('Authentication required');
    }

    return $response;
  }

}
