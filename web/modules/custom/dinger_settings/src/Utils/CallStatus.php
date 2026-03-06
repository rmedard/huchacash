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

  public static function entryPoints(): array
  {
    return [CallStatus::LIVE];
  }

  public function needsRollback(): bool {
    return in_array($this, [self::EXPIRED, self::CANCELLED]);
  }

  public function freezesBalance(): bool {
    return self::isEntryPoint();
  }

  public static function finalStates(): array
  {
    return [self::EXPIRED, self::CANCELLED, self::COMPLETED];
  }
}
