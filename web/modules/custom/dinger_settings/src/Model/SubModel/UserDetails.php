<?php

namespace Drupal\dinger_settings\Model\SubModel;

use Drupal\node\Entity\Node;

class UserDetails {
  public string $id;
  public string $photo;
  public string $lastname;
  public string $phone;
  public string $email;

  public function __construct(Node $customer) {
    $this->id = $customer->uuid();
    /** @description Photo will be populated directly in Firestore.
     * The backend only creates live data, never reads
     */
    $this->photo = '';
    $this->lastname = $customer->get('field_customer_lastname')->getString();
    $userEntity = $customer->get('field_customer_user')->entity;
    $this->phone = $userEntity?->get('field_user_phone_number')->getString();
    $this->email = $userEntity?->getEmail() ?? '';
  }
}
