<?php

namespace Drupal\dinger_settings\Utils;

enum CallStatus: string implements StatusBaseInterface
{
  use StatusBaseType;
  case LIVE = 'live';
  case ATTRIBUTED = 'attributed';
  case EXPIRED = 'expired';
  case CANCELLED = 'cancelled';
  case COMPLETED = 'completed';

  public function isFinalState(): bool
  {
    return match($this) {
      self::LIVE, self::ATTRIBUTED => FALSE,
      self::EXPIRED, self::CANCELLED, self::COMPLETED => TRUE
    };
  }

  public static function entryPoints(): array
  {
    return [CallStatus::LIVE];
  }

  public function needsRollback(): bool {
    return in_array($this, [self::EXPIRED, self::CANCELLED]);
  }

  public function freezesBalance(): bool {
    return in_array($this, self::entryPoints(), true);
  }
}
