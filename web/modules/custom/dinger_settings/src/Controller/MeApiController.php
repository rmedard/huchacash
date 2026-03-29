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
      ->condition('field_customer_user.target_id', $user->id())
      ->accessCheck(FALSE)
      ->execute();

    if (empty($customerIds)) {
      return new JsonResponse(
        [
          'code' => 'customer_not_found',
          'message' => 'No customer profile exists for this account'
        ],
        Response::HTTP_NOT_FOUND
      );
    }

    $customer = Node::load(reset($customerIds));
    $isComplete = !$customer->get('field_customer_lastname')->isEmpty()
      && !$customer->get('field_customer_email')->isEmpty()
      && !$customer->get('field_customer_age_range')->isEmpty();
    return new JsonResponse([
      'customer_id' => $customer->uuid(),
      'reference' => $customer->getTitle(),
      'user_id' => $user->id(),
      'user_name' => $user->getAccountName(),
      'is_profile_complete' => $isComplete
    ], Response::HTTP_OK);
  }
}
