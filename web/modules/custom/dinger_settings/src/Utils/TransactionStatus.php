<?php

namespace Drupal\dinger_settings\Utils;

enum TransactionStatus: string
{
  case INITIATED = 'initiated';
  case CONFIRMED = 'confirmed';
  case CANCELLED = 'cancelled';

  public static function fromString(string $value): self
  {
    return self::tryFrom($value);
  }
}
