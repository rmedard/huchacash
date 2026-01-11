<?php

namespace Drupal\dinger_settings\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Drupal\dinger_settings\Utils\CallStatus;

#[Constraint(
  id: self::PLUGIN_ID,
  label: new TranslatableMarkup('Call Status Transition Constraint'),
  type: 'field'
)]
class CallStatusTransitionConstraint extends StatusTransitionConstraintBase
{
  public const string PLUGIN_ID = 'CallStatusTransition';

  public function allowedTransitions(): array
  {
    return [
      CallStatus::LIVE->value => [CallStatus::ATTRIBUTED, CallStatus::EXPIRED, CallStatus::CANCELLED],
      CallStatus::ATTRIBUTED->value => [CallStatus::COMPLETED, CallStatus::CANCELLED],
      CallStatus::EXPIRED->value => [],
      CallStatus::CANCELLED->value => [],
      CallStatus::COMPLETED->value => [],
    ];
  }

  public function statusEnumClass(): string
  {
    return CallStatus::class;
  }
}
