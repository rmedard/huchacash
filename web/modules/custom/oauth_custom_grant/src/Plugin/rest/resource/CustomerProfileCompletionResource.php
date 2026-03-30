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
      $container->get('logger.factory')->get('CustomerProfileCompletionResource'),
      $container->get('current_user'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * POST /api/customer/complete-profile
   *
   * Expected body: { "lastname": "", "email": "", "age_range": "", "phone": "" }
   * Saves the required fields and publishes the customer node.
   */
  public function post(Request $request): JsonResponse {
    $data = json_decode($request->getContent(), TRUE);

    $lastname  = trim($data['lastname']  ?? '');
    $email     = trim($data['email']     ?? '');
    $ageRange  = trim($data['age_range'] ?? '');
    $phone     = trim($data['phone']     ?? '');

    if (empty($lastname) || empty($email) || empty($ageRange) || empty($phone)) {
      return new JsonResponse([
        'error' => 'lastname, email, age_range and phone are required.',
      ], 400);
    }

    try {
      $nodes = $this->entityTypeManager
        ->getStorage('node')
        ->loadByProperties([
          'type'                => 'customer',
          'field_customer_user' => $this->loggedUser->id(),
        ]);

      if (!$nodes) {
        return new JsonResponse(['error' => 'Customer profile not found.'], 404);
      }

      $customer = reset($nodes);
      $customer->set('field_customer_lastname',   $lastname);
      $customer->set('field_customer_age_range',  $ageRange);
      $customer->set('status', 1); // publish once profile is complete
      $customer->save();

      // Save email on the referenced user entity.
      $user = $customer->get('field_customer_user')->entity;
      $user->setEmail($email);
      $user->set('field_user_phone_number', $phone);
      $user->save();

      return new JsonResponse(['status' => 'ok']);
    }
    catch (InvalidPluginDefinitionException | PluginNotFoundException $e) {
      return new JsonResponse(['error' => $e->getMessage()], 404);
    }
    catch (EntityStorageException $e) {
      $this->logger->error('Failed to save customer profile: @msg', ['@msg' => $e->getMessage()]);
      return new JsonResponse(['error' => 'Failed to save profile.'], 500);
    }
  }
}
