<?php

namespace Drupal\dinger_settings\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Drupal\dinger_settings\Utils\OrderStatus;

#[Constraint(
  id: self::PLUGIN_ID,
  label: new TranslatableMarkup('Order Status Transition Constraint'),
  type: 'field'
)]
class OrderStatusTransitionConstraint extends StatusTransitionConstraintBase
{
  public const string PLUGIN_ID = 'OrderStatusTransition';

  public function allowedTransitions(): array
  {
    return [
      OrderStatus::IDLE->value => [OrderStatus::BIDDING, OrderStatus::CANCELLED],
      OrderStatus::BIDDING->value => [OrderStatus::IDLE, OrderStatus::DELIVERING, OrderStatus::CANCELLED],
      OrderStatus::DELIVERING->value => [OrderStatus::DELIVERED, OrderStatus::CANCELLED],
      OrderStatus::DELIVERED->value => [],
      OrderStatus::CANCELLED->value => [],
    ];
  }

  public function statusEnumClass(): string
  {
    return OrderStatus::class;
  }
}
