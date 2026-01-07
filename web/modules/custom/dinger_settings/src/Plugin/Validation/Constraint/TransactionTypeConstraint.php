<?php

namespace Drupal\dinger_settings\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;

#[Constraint(
  id: 'TransactionTypeConstraint',
  label: new TranslatableMarkup('Transaction Type Constraint'),
  type: 'entity'
)]
class TransactionTypeConstraint extends TransactionConstraintBase
{
  public string $message = 'The transaction type %value is invalid.';
}
