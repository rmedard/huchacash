<?php

namespace Drupal\dinger_settings\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;

#[Constraint(
  id: self::PLUGIN_ID,
  label: new TranslatableMarkup('Cancelled Transaction Constraint'),
  type: 'entity'
)]
class TransactionCancelledConstraint extends TransactionConstraintBase {

  public const string PLUGIN_ID = 'TransactionCancelledConstraint';
  public string $message = 'Initial or original transaction status cannot be \'cancelled\'.';

  /**
   * {@inheritdoc}
   */
  public function validatedBy(): string
  {
    return TransactionConstraintsValidator::class;
  }
}
