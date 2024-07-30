<?php

namespace Drupal\bank_account\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

#[Constraint(
  id: 'BankAccountNumberConstraint',
  label: new TranslatableMarkup('Bank account number validation constraint'),
  type: 'bank_account_item'
)]
class BankAccountNumberConstraint extends SymfonyConstraint {

  public string $message = 'Bank account number from %country should start with %initials and be %length characters long.';

  public function validatedBy(): string {
    return BankAccountConstraintsValidator::class;
  }

}
