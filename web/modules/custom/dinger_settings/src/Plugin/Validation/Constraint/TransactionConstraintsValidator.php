<?php

namespace Drupal\dinger_settings\Plugin\Validation\Constraint;

use Drupal;
use Drupal\dinger_settings\Form\DingerSettingsConfigForm;
use Drupal\dinger_settings\Utils\TransactionStatus;
use Drupal\dinger_settings\Utils\TransactionType;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

class TransactionConstraintsValidator extends ConstraintValidator {

  public function validate(mixed $value, Constraint $constraint): void {
    if ($value instanceof NodeInterface && $value->bundle() === 'transaction') {
      $transactionType = TransactionType::fromString($value->get('field_tx_type')->getString());
      $transactionStatus = TransactionStatus::fromString($value->get('field_tx_status')->getString());
      $constraintClass = get_class($constraint);
      switch ($constraintClass) {
        case TransactionAmountConstraint::class:
          /** @var TransactionAmountConstraint $constraint **/
          if ($value->isNew()) {
            if ($transactionType !== TransactionType::TOP_UP) {
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
          if ($value->isNew()) {
            if ($transactionStatus === TransactionStatus::CANCELLED) {
              $this->context->addViolation($constraint->message);
            }
          }
          break;
        case TransactionBankAccountConstraint::class:
          /** @var TransactionBankAccountConstraint $constraint **/
          if ($transactionType === TransactionType::WITHDRAWAL and $value->get('field_tx_bank_account')->isEmpty()) {
            $this->context->addViolation($constraint->message);
          }
          break;
        case TransactionTypeConstraint::class:
          /** @var TransactionTypeConstraint $constraint **/
          if ($transactionType->hasBeneficiary() and $value->get('field_tx_to')->isEmpty()) {
            $this->context->addViolation($constraint->message);
          }

          if (!$transactionType->hasBeneficiary() and !$value->get('field_tx_to')->isEmpty()) {
            $this->context->addViolation($constraint->message);
          }
      }
    }
  }
}
