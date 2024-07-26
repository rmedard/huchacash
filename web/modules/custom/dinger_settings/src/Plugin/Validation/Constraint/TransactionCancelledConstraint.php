<?php

namespace Drupal\dinger_settings\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

#[Constraint(
  id: 'TransactionCancelledConstraint',
  label: new TranslatableMarkup('Cancelled Transaction Constraint'),
  type: 'entity'
)]
class TransactionCancelledConstraint extends SymfonyConstraint {

  public string $message = 'Initial or original transaction status cannot be \'cancelled\'.';

  /**
   * {@inheritdoc}
   */
  public function validatedBy(): string
  {
    return TransactionConstraintsValidator::class;
  }
}
