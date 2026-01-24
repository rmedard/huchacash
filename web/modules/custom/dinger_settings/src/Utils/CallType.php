<?php

namespace Drupal\dinger_settings\Utils;

enum CallType: string
{
  case FIXED_PRICE = 'fixed_price';
  case NEGOTIABLE = 'negotiable';
  case OPEN = 'open';

  public function freezesBalance(): bool
  {
    return in_array($this, [self::FIXED_PRICE, self::NEGOTIABLE]);
  }

  public function allowsBargain(): bool
  {
    return in_array($this, [self::OPEN, self::NEGOTIABLE]);
  }
}
