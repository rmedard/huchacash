<?php

namespace Drupal\bank_account\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

#[Constraint(
  id: 'BankAccountConstraint',
  label: new TranslatableMarkup('Bank account validation constraint'),
  type: 'bank_account_item'
)]
class BankAccountConstraint extends SymfonyConstraint {

  public string $message = 'Missing bank account details.';

  public function validatedBy(): string {
    return BankAccountConstraintsValidator::class;
  }

}
