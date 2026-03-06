<?php

namespace Drupal\dinger_settings\Utils;

enum BidStatus: string implements StatusBaseInterface
{
  use StatusBaseType;

  case PENDING = 'pending';

  /**
   * ACCEPTED by the caller
   */
  case ACCEPTED = 'accepted';
  case CONFIRMED = 'confirmed';

  /**
   * REJECTED by the caller
   */
  case REJECTED = 'rejected';
  case RENOUNCED = 'renounced';

  public static function entryPoints(): array
  {
    return [self::PENDING, self::CONFIRMED];
  }

  public static function finalStates(): array
  {
    return [self::CONFIRMED, self::REJECTED, self::RENOUNCED];
  }
}
