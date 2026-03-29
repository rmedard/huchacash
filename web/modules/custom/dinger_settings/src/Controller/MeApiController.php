<?php

namespace Drupal\dinger_settings\Controller;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class MeApiController extends ControllerBase {

  protected EntityStorageInterface $nodeStorage;

  /**
   * @param EntityStorageInterface $nodeStorage
   */
  public function __construct(EntityStorageInterface $nodeStorage)
  {
    $this->nodeStorage = $nodeStorage;
  }

  /**
   * @throws InvalidPluginDefinitionException
   * @throws PluginNotFoundException
   */
  public static function create(ContainerInterface $container): self
  {
    return new MeApiController($container->get('entity_type.manager')->getStorage('node'));
  }


  public function get(): JsonResponse
  {
    $user = $this->currentUser();
    $customerIds = $this->nodeStorage
      ->getQuery()
      ->condition('type', 'customer')
      ->condition('status', NodeInterface::PUBLISHED)
      ->condition('field_customer_user.target_id', $user->id())
      ->accessCheck(FALSE)
      ->execute();

    if (empty($customerIds)) {
      return new JsonResponse(['error' => 'Customer profile not found'], Response::HTTP_NOT_FOUND);
    }

    $customer = Node::load(reset($customerIds));
    return new JsonResponse([
      'customer_id' => $customer->uuid(),
      'reference' => $customer->getTitle(),
      'user_id' => $user->id(),
      'user_name' => $user->getAccountName()
    ], Response::HTTP_OK);
  }
}
