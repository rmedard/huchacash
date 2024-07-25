<?php

namespace Drupal\dinger_settings\Plugin\Validation\Constraint;

use Drupal;
use Drupal\dinger_settings\Form\DingerSettingsConfigForm;
use Drupal\node\NodeInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

class TransactionConstraintsValidator extends ConstraintValidator {

  public function validate(mixed $value, Constraint $constraint): void {
    if ($value instanceof NodeInterface && $value->bundle() === 'transaction') {
      if ($value->isNew()) {
        if ($constraint instanceof TransactionAmountConstraint) {
          $txType = $value->get('field_tx_type')->getString();
          if ($txType !== 'top_up') {
            /** @var \Drupal\node\Entity\Node $transactionInitiator **/
            $transactionInitiator = $value->get('field_tx_from')->entity;
            $systemCustomer = Drupal::config(DingerSettingsConfigForm::SETTINGS)->get('hucha_system_customer');
            $isNotSystemInitiative = $systemCustomer !== $transactionInitiator->id();
            if ($isNotSystemInitiative) {
              $availableBalance = doubleval($transactionInitiator->get('field_customer_available_balance')->getString());
              $transactionAmount = doubleval($value->get('field_tx_amount')->getString());
              if ($transactionAmount > $availableBalance) {
                $this->context->addViolation($constraint->message, ['%value' => $transactionAmount]);
              }
            }
          }
        }

        if ($constraint instanceof TransactionCancelledConstraint) {
          $txStatus = $value->get('field_tx_status')->getString();
          if ($txStatus === 'cancelled') {
            $this->context->addViolation($constraint->message);
          }
        }
      }
    }
  }

}
