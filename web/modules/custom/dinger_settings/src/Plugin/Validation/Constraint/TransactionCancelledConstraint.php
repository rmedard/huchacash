<?php

namespace Drupal\dinger_settings\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;

#[Constraint(
  id: 'TransactionCancelledConstraint',
  label: new TranslatableMarkup('Cancelled Transaction Constraint'),
  type: 'entity'
)]
class TransactionCancelledConstraint extends TransactionConstraintBase {

  public string $message = 'Initial or original transaction status cannot be \'cancelled\'.';

}
