<?php

namespace Drupal\dinger_settings\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

#[Constraint(
  id: 'TransactionAmountConstraint',
  label: new TranslatableMarkup('Transaction Amount Constraint'),
  type: 'entity'
)]
class TransactionAmountConstraint extends SymfonyConstraint {

  public string $message = '%value is greater than available balance for this customer.';

  /**
   * {@inheritdoc}
   */
  public function validatedBy(): string
  {
    return TransactionConstraintsValidator::class;
  }
}
