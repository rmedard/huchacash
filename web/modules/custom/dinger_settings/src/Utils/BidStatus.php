<?php

namespace Drupal\dinger_settings\Utils;

enum BidStatus: string implements StatusBaseInterface
{
  use StatusBaseType;

  case PENDING = 'pending';
  case ACCEPTED = 'accepted';
  case CONFIRMED = 'confirmed';
  case REJECTED = 'rejected';
  case RENOUNCED = 'renounced';

  public static function entryPoints(): array
  {
    return [self::PENDING, self::CONFIRMED];
  }

  public function isFinalState(): bool
  {
    return match ($this) {
      self::PENDING, self::ACCEPTED => false,
      self::CONFIRMED, self::REJECTED, self::RENOUNCED => true,
    };
  }
}
