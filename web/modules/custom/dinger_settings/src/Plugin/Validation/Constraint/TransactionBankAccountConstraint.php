<?php

namespace Drupal\dinger_settings\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;

#[Constraint(
  id: self::PLUGIN_IN,
  label: new TranslatableMarkup('Transaction Bank Account Constraint'),
  type: 'entity'
)]
class TransactionBankAccountConstraint extends TransactionConstraintBase {

  public const string PLUGIN_IN = 'TransactionBankAccountConstraint';
  public string $message = 'Bank account is mandatory for withdrawal transactions.';


}
