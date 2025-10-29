<?php

namespace Drupal\dinger_settings\Model\SubModel;

use Drupal\node\Entity\Node;

class UserDetails {
  public string $id;
  public string $photo;
  public string $lastname;

  public function __construct(Node $customer) {
    $this->id = $customer->uuid();
    /** @description Photo will be populated directly in Firestore */
    $this->photo = '';
    $this->lastname = $customer->get('field_customer_lastname')->getString();
  }
}
