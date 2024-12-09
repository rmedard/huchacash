<?php

namespace Drupal\dinger_settings\Plugin\Validation\Constraint;

use Drupal;
use Drupal\dinger_settings\Form\DingerSettingsConfigForm;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

class TransactionConstraintsValidator extends ConstraintValidator {

  public function validate(mixed $value, Constraint $constraint): void {
    if ($value instanceof NodeInterface && $value->bundle() === 'transaction') {
      $constraintClass = get_class($constraint);
      switch ($constraintClass) {
        case TransactionAmountConstraint::class:
          /** @var TransactionAmountConstraint $constraint **/
          if ($value->isNew()) {
            $txType = $value->get('field_tx_type')->getString();
            if ($txType !== 'top_up') {
              /** @var Node $transactionInitiator **/
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
          break;
        case TransactionCancelledConstraint::class:
          /** @var TransactionCancelledConstraint $constraint **/
          $txStatus = $value->get('field_tx_status')->getString();
          if ($value->isNew()) {
            if ($txStatus === 'cancelled') {
              $this->context->addViolation($constraint->message);
            }
          }
          break;
        case TransactionBankAccountConstraint::class:
          /** @var TransactionBankAccountConstraint $constraint **/
          $txType = $value->get('field_tx_type')->getString();
          if ($txType === 'withdrawal' and $value->get('field_tx_bank_account')->isEmpty()) {
            $this->context->addViolation($constraint->message);
          }
          break;
      }
    }
  }
}
