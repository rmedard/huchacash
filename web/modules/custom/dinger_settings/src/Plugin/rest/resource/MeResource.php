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
   * {@inheritdoc}
   */
  public function permissions(): array
  {
    // Define custom permissions with a different name to avoid conflicts
    return [
      'get' => [
        'access me resource' => [
          'title' => new TranslatableMarkup('Access Me Resource'),
          'description' => new TranslatableMarkup('Allow users to access the me resource'),
        ],
      ],
    ];
  }

  public function get(): ModifiedResourceResponse
  {
    // Log that we reached the method
    \Drupal::logger('me_resource')->info('Get method executed. User ID: ' . $this->loggedUser->id());

    $response = new ModifiedResourceResponse();

    if (!$this->loggedUser->isAuthenticated()) {
      $response->setStatusCode(Response::HTTP_UNAUTHORIZED);
      $response->setContent(json_encode(['error' => 'Not authenticated']));
      return $response;
    }

    $roles = $this->loggedUser->getRoles(true);
    \Drupal::logger('me_resource')->info('User roles: ' . implode(', ', $roles));

    if (!in_array('customer', $roles)) {
      $response->setStatusCode(Response::HTTP_FORBIDDEN);
      $response->setContent(json_encode([
        'error' => 'Customer role required',
        'your_roles' => $roles,
        'user_id' => $this->loggedUser->id(),
        'user_name' => $this->loggedUser->getAccountName()
      ]));
      return $response;
    }

    try {
      $customerIds = Drupal::entityTypeManager()
        ->getStorage('node')->getQuery()->accessCheck(FALSE)
        ->condition('type', 'customer')
        ->condition('field_customer_user.target_id', $this->loggedUser->id())
        ->execute();

      \Drupal::logger('me_resource')->info('Found ' . count($customerIds) . ' customer nodes');

      if (count($customerIds) == 0) {
        $response->setStatusCode(Response::HTTP_NOT_FOUND);
        $response->setContent(json_encode(['error' => 'No customer profile found']));
        return $response;
      }

      if (count($customerIds) > 1) {
        $response->setStatusCode(Response::HTTP_NOT_ACCEPTABLE);
        $response->setContent(json_encode(['error' => 'Multiple customer profiles found']));
        return $response;
      }

      $customerId = reset($customerIds);
      $customer = Node::load($customerId);
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
