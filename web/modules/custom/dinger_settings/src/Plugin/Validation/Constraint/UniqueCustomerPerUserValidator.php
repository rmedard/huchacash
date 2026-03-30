<?php

namespace Drupal\dinger_settings\Plugin\Validation\Constraint;

use Drupal;
use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\node\NodeInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

class UniqueCustomerPerUserValidator extends ConstraintValidator
{
  public function validate($value, Constraint $constraint): void
  {
    if (!$value instanceof NodeInterface || $value->bundle() !== 'customer') {
      return;
    }

    if (!$constraint instanceof UniqueCustomerPerUserConstraint) {
      return;
    }

    $userId = $value->get('field_customer_user')->target_id;
    if (empty($userId)) {
      return;
    }

    try {
      $query = Drupal::entityTypeManager()->getStorage('node')->getQuery()
        ->accessCheck(false)
        ->condition('type', 'customer')
        ->condition('field_customer_user.target_id', $userId)
        ->count();

      if (!$value->isNew()) {
        $query->condition('nid', $value->id(), '<>');
      }

      if ($query->execute() > 0) {
        $this->context->addViolation($constraint->message, ['%value' => $userId]);
      }
    } catch (InvalidPluginDefinitionException|PluginNotFoundException $e) {
      Drupal::logger('dinger_settings')->error('UniqueCustomerPerUser validation failed: ' . $e->getMessage());
    }
  }
}