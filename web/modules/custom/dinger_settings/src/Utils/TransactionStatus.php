<?php

namespace Drupal\dinger_settings\Utils;

enum TransactionStatus: string implements StatusBaseInterface
{
  use StatusBaseType;
  case INITIATED = 'initiated';
  case CONFIRMED = 'confirmed';
  case CANCELLED = 'cancelled';

  public static function entryPoints(): array
  {
    return [self::INITIATED, self::CONFIRMED];
  }

  public function isFinalState(): bool
  {
    return match ($this) {
      self::INITIATED => false,
      self::CONFIRMED, self::CANCELLED => true,
    };
  }
}
