<?php

declare(strict_types=1);

namespace Drupal\oauth_custom_grant\Plugin\rest\resource;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\rest\Attribute\RestResource;
use Drupal\rest\Plugin\ResourceBase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

#[RestResource(
  id: 'api_customer_complete_profile',
  label: new TranslatableMarkup('Api Complete Profile'),
  uri_paths: [
    'create' => '/api/customer/complete-profile',
  ],
)]
class CustomerProfileCompletionResource extends ResourceBase {

  protected AccountProxyInterface $loggedUser;
  protected EntityTypeManagerInterface $entityTypeManager;
  public function __construct(array           $configuration,
                                              $plugin_id,
                                              $plugin_definition,
                              array           $serializer_formats,
                              LoggerInterface $logger, AccountProxyInterface $loggedUser, EntityTypeManagerInterface $entityTypeManager)
  {
    $this->loggedUser = $loggedUser;
    $this->entityTypeManager = $entityTypeManager;
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
      $container->get('logger.factory')->get('CustomerProfileCompletionResource'),
      $container->get('current_user'),
      $container->get('entity_type.manager')
    );
  }

  public function post(Request $request): JsonResponse {
    $data = json_decode($request->getContent(), true);

    // Find the customer node for this user
    try {
      $nodes = $this->entityTypeManager
        ->getStorage('node')
        ->loadByProperties([
          'type' => 'customer',
          'field_customer_user' => $this->loggedUser->id(),
        ]);


      if (!$nodes) {
        return new JsonResponse(['error' => 'Customer profile not found'], 404);
      }

      $customer = reset($nodes);
      $customer->set('field_customer_firstname', $data['firstname'] ?? '');
      $customer->set('field_customer_lastname',  $data['lastname'] ?? '');
      $customer->set('status', 1); // publish once complete
      $customer->save();

      return new JsonResponse(['status' => 'ok']);

    } catch (InvalidPluginDefinitionException|PluginNotFoundException $e) {
      return new JsonResponse(['error' => $e->getMessage()], 404);
    } catch (EntityStorageException $e) {
      $this->logger->error('Failed to save customer profile: @msg', ['@msg' => $e->getMessage()]);
      return new JsonResponse(['error' => 'Failed to save profile'], 500);
    }
  }
}
