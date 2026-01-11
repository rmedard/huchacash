<?php

namespace Drupal\dinger_settings\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;

#[Constraint(
  id: self::PLUGIN_ID,
  label: new TranslatableMarkup('Transaction Amount Constraint'),
  type: 'entity'
)]
class TransactionAmountConstraint extends TransactionConstraintBase {

  public const string PLUGIN_ID = 'TransactionAmountConstraint';
  public string $message = '%value is greater than available balance for this customer.';

  /**
   * {@inheritdoc}
   */
  public function validatedBy(): string
  {
    return TransactionConstraintsValidator::class;
  }
}
