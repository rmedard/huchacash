<?php

namespace Drupal\dinger_settings\Plugin\Validation\Constraint;

use Drupal;
use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\user\UserInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

class UniquePhoneNumberValidator extends ConstraintValidator
{
  public function validate($value, Constraint $constraint): void
  {
    if (!$constraint instanceof UniquePhoneNumberConstraint) {
      return;
    }

    if (!$value instanceof UserInterface) {
      return;
    }

    if ($value->get('field_user_phone_number')->isEmpty()) {
      return;
    }

    $phoneNumber = $value->get('field_user_phone_number')->getString();

    try {
      $query = Drupal::entityTypeManager()->getStorage('user')->getQuery()
        ->accessCheck(false)
        ->condition('field_user_phone_number', $phoneNumber)
        ->count();

      if (!$value->isNew()) {
        $query->condition('uid', $value->id(), '<>');
      }

      if ($query->execute() > 0) {
        $this->context->addViolation($constraint->message, ['%value' => $phoneNumber]);
      }
    } catch (InvalidPluginDefinitionException|PluginNotFoundException $e) {
      Drupal::logger('dinger_settings')->error('UniquePhoneNumber validation failed: ' . $e->getMessage());
    }
  }
}