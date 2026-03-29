<?php

namespace Drupal\dinger_settings\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;

class MeApiController extends ControllerBase {

  public function get() {
    $user = $this->currentUser();

    // Get the customer node linked to this user
    $customerIds = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->getQuery()
      ->condition('type', 'customer')
      ->condition('field_customer_user.target_id', $user->id())
      ->accessCheck(FALSE)
      ->execute();

    if (empty($customerIds)) {
      return new JsonResponse(['error' => 'Customer profile not found'], 404);
    }

    $customer = \Drupal\node\Entity\Node::load(reset($customerIds));

    // Return whatever data structure you need
    return new JsonResponse([
      'customer_id' => $customer->uuid(),
      'reference' => $customer->getTitle(), // or whatever field you need
      'user_id' => $user->id(),
      'user_name' => $user->getAccountName()
    ], 200);
  }
}
