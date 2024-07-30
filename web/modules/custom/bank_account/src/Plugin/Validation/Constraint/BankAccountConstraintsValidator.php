<?php

namespace Drupal\bank_account\Plugin\Validation\Constraint;

use Drupal\bank_account\Plugin\Field\FieldType\BankAccountItem;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

class BankAccountConstraintsValidator extends ConstraintValidator {

  /**
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  public function validate(mixed $value, Constraint $constraint): void {

    if ($value instanceof BankAccountItem and !$value->isEmpty()) {
      if ($constraint instanceof BankAccountConstraint) {
        $country = $value->get('country')->getString();
        $accountNumber = $value->get('account_number');
        $firstname = $value->get('official_firstname');
        $lastname = $value->get('official_lastname');
        if ($accountNumber === NULL || $accountNumber->getString() === '') {
          $this->context->addViolation($constraint->message);
        }

        if ($firstname === NULL || $firstname->getString() === '') {
          $this->context->addViolation($constraint->message);
        }

        if ($lastname === NULL || $lastname->getString() === '') {
          $this->context->addViolation($constraint->message);
        }
      }

      if ($constraint instanceof BankAccountNumberConstraint) {
        $country = $value->get('country')->getString();
        $accountNumber = $value->get('account_number')->getString();
        $messageOptions = ['%country' => $country, '%initials' => $country, '%length' => 16];
        if (!str_starts_with($accountNumber, $country)) {
          $this->context->addViolation($constraint->message, $messageOptions);
        }

        if ($country === 'BE' and strlen($accountNumber) !== 16) {
          $this->context->addViolation($constraint->message, $messageOptions);
        }
      }
    }
  }

}
