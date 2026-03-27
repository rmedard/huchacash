<?php

namespace Drupal\oauth_custom_grant\Plugin\rest\resource;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
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
  id: 'api_customer_profile_status',
  label: new TranslatableMarkup('Api Customer Profile Status'),
  uri_paths: [
    'canonical' => '/api/customer/profile-status',
  ],
)]
class CustomerProfileStatusResource extends ResourceBase {

  protected AccountProxyInterface $loggedUser;
  protected EntityTypeManagerInterface $entityTypeManager;

  public function __construct(
    array $configuration,
          $plugin_id,
          $plugin_definition,
    array $serializer_formats,
    LoggerInterface $logger,
    AccountProxyInterface $loggedUser,
    EntityTypeManagerInterface $entityTypeManager,
  ) {
    $this->loggedUser = $loggedUser;
    $this->entityTypeManager = $entityTypeManager;
    parent::__construct($configuration, $plugin_id, $plugin_definition, $serializer_formats, $logger);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->getParameter('serializer.formats'),
      $container->get('logger.factory')->get('CustomerProfileStatusResource'),
      $container->get('current_user'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * GET /api/customer/profile-status
   *
   * A profile is complete when field_customer_lastname, field_customer_email
   * and field_customer_age_range are all filled.
   *
   * Response: { "is_complete": true|false, "missing_fields": [] }
   */
  public function get(Request $request): JsonResponse {
    try {
      $nodes = $this->entityTypeManager
        ->getStorage('node')
        ->loadByProperties([
          'type'                => 'customer',
          'field_customer_user' => $this->loggedUser->id(),
        ]);

      if (!$nodes) {
        return new JsonResponse([
          'is_complete'    => FALSE,
          'missing_fields' => ['lastname', 'email', 'age_range'],
        ]);
      }

      $customer      = reset($nodes);
      $missingFields = [];

      if ($customer->get('field_customer_lastname')->isEmpty()) {
        $missingFields[] = 'lastname';
      }
      if ($customer->get('field_customer_email')->isEmpty()) {
        $missingFields[] = 'email';
      }
      if ($customer->get('field_customer_age_range')->isEmpty()) {
        $missingFields[] = 'age_range';
      }

      return new JsonResponse([
        'is_complete'    => empty($missingFields),
        'missing_fields' => $missingFields,
      ]);
    }
    catch (InvalidPluginDefinitionException | PluginNotFoundException $e) {
      $this->logger->error('Profile status check failed: @msg', ['@msg' => $e->getMessage()]);
      return new JsonResponse(['error' => 'Could not check profile status'], 500);
    }
  }
}
