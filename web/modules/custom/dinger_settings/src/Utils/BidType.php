<?php

namespace Drupal\dinger_settings\Utils;

enum BidType: string
{
  case ACCEPT = 'accept';
  case BARGAIN = 'bargain';

  public static function fromString(string $value): self
  {
    return self::tryFrom($value);
  }
}
