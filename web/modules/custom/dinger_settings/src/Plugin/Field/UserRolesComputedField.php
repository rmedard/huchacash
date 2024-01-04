<?php

namespace Drupal\dinger_settings\Plugin\Field;

use Drupal\Core\Entity\Plugin\DataType\EntityAdapter;
use Drupal\Core\Field\FieldItemList;
use Drupal\Core\TypedData\ComputedItemListTrait;
use Drupal\user\Entity\Role;
use Drupal\user\UserInterface;

class UserRolesComputedField extends FieldItemList
{
  use ComputedItemListTrait;

  protected function computeValue(): void
  {
    $userRoles = '';
    $separator = ',';
    $adaptor = $this->parent;
    if ($adaptor instanceof EntityAdapter) {
      $user = $adaptor->getEntity();
      if ($user instanceof UserInterface) {
        $roles = Role::loadMultiple($user->getRoles(true));
        foreach ($roles as $role) {
          $userRoles .= $role->id() . $separator;
        }
      }
    }
    $this->list[0] = $this->createItem(0, rtrim($userRoles, $separator));
  }
}
