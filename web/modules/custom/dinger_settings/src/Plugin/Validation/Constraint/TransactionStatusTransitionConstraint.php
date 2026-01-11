<?php

namespace Drupal\dinger_settings\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Drupal\dinger_settings\Utils\TransactionStatus;

#[Constraint(
  id: self::PLUGIN_ID,
  label: new TranslatableMarkup('Transaction Status Transition Constraint'),
  type: 'field'
)]
class TransactionStatusTransitionConstraint extends StatusTransitionConstraintBase
{

  public const string PLUGIN_ID = 'TransactionStatusTransition';

  public function allowedTransitions(): array
  {
    return [
      TransactionStatus::INITIATED->value => [TransactionStatus::CONFIRMED, TransactionStatus::CANCELLED],
      TransactionStatus::CONFIRMED->value => [],
      TransactionStatus::CANCELLED->value => [],
    ];
  }

  public function statusEnumClass(): string
  {
    return TransactionStatus::class;
  }
}
