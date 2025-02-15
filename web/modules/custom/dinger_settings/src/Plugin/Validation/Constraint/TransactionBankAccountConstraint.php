<?php

namespace Drupal\dinger_settings\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;

#[Constraint(
  id: 'TransactionBankAccountConstraint',
  label: new TranslatableMarkup('Transaction Bank Account Constraint'),
  type: 'entity'
)]
class TransactionBankAccountConstraint extends TransactionConstraintBase {

  public string $message = 'Bank account is mandatory for withdrawal transactions.';

}
