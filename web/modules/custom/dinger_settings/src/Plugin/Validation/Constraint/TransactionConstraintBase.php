<?php

namespace Drupal\dinger_settings\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

abstract class TransactionConstraintBase extends Constraint {

  /**
   * {@inheritdoc}
   */
  public function validatedBy(): string
  {
    return TransactionConstraintsValidator::class;
  }
}
