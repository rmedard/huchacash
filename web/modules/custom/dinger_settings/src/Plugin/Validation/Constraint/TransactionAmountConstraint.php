<?php

namespace Drupal\dinger_settings\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;

#[Constraint(
  id: 'TransactionAmountConstraint',
  label: new TranslatableMarkup('Transaction Amount Constraint'),
  type: 'entity'
)]
class TransactionAmountConstraint extends TransactionConstraintBase {

  public string $message = '%value is greater than available balance for this customer.';

}
