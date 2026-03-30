<?php

namespace Drupal\dinger_settings\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\Validator\Constraint;
use Drupal\Core\Validation\Attribute\Constraint as ConstraintAttribute;

#[ConstraintAttribute(
  id: self::PLUGIN_ID,
  label: new TranslatableMarkup('Unique Customer per User'),
  type: 'entity:node'
)]
class UniqueCustomerPerUserConstraint extends Constraint
{
  public const string PLUGIN_ID = 'UniqueCustomerPerUserConstraint';
  public string $message = 'User %value is already referenced by another customer.';

  public function validatedBy(): string
  {
    return UniqueCustomerPerUserValidator::class;
  }
}