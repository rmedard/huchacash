<?php

namespace Drupal\dinger_settings\Utils;

enum OrderStatus: string implements StatusBaseInterface
{
  use StatusBaseType;
  case IDLE = 'idle';
  case BIDDING = 'bidding';
  case DELIVERING = 'delivering';
  case DELIVERED = 'delivered';
  case CANCELLED = 'cancelled';

  public static function entryPoints(): array
  {
    return [OrderStatus::IDLE];
  }

  public function isFinalState(): bool
  {
    return match ($this) {
      self::IDLE, self::BIDDING, self::DELIVERING => FALSE,
      self::DELIVERED, self::CANCELLED => TRUE,
    };
  }
}
