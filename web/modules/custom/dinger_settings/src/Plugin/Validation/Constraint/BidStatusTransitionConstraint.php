<?php

namespace Drupal\dinger_settings\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Drupal\dinger_settings\Utils\BidStatus;

#[Constraint(
  id: self::PLUGIN_ID,
  label: new TranslatableMarkup('Bid Status Transition Constraint'),
  type: 'field'
)]
class BidStatusTransitionConstraint extends StatusTransitionConstraintBase
{

  public const string PLUGIN_ID = 'BidStatusTransition';
  public function allowedTransitions(): array
  {
    return [
      BidStatus::PENDING->value => [BidStatus::ACCEPTED, BidStatus::REJECTED],
      BidStatus::ACCEPTED->value => [BidStatus::CONFIRMED, BidStatus::RENOUNCED],
      BidStatus::CONFIRMED->value => [],
      BidStatus::REJECTED->value => [],
      BidStatus::RENOUNCED->value => [],
    ];
  }

  public function statusEnumClass(): string
  {
    return BidStatus::class;
  }
}
