<?php

namespace Drupal\dinger_settings\Utils;

enum TransactionType: string
{
  case TOP_UP = 'top_up';
  case PURCHASE_COST = 'purchase_cost';
  case DELIVERY_FEE = 'delivery_fee';
  case SERVICE_FEE = 'service_fee';
  case FINE = 'fine';
  case WITHDRAWAL = 'withdrawal';
  case REFUND = 'refund';

  public static function fromString(string $value): self
  {
    return self::tryFrom($value);
  }
}
