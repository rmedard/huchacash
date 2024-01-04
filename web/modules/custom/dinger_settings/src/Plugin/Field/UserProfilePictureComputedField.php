<?php

namespace Drupal\dinger_settings\Plugin\Field;

use Drupal;
use Drupal\Core\Entity\Plugin\DataType\EntityAdapter;
use Drupal\Core\Field\FieldItemList;
use Drupal\Core\TypedData\ComputedItemListTrait;
use Drupal\file\FileInterface;
use Drupal\user\UserInterface;

class UserProfilePictureComputedField extends FieldItemList
{
  use ComputedItemListTrait;

  protected function computeValue(): void
  {
    Drupal::logger('Enhancer')->info('Enhancer called');
    $imageUri = '';
    $adaptor = $this->parent;
    if ($adaptor instanceof EntityAdapter) {
      $user = $adaptor->getEntity();
        if ($user instanceof UserInterface) {
          $file = $user->get('user_picture')->entity;
          if ($file instanceof FileInterface) {
            $imageUri = $file->createFileUrl(false);
            Drupal::logger('Enhancer')->info('userProfileUrl: ' . $imageUri);
          }
      }
    }
    $this->list[0] = $this->createItem(0, $imageUri);
  }
}
