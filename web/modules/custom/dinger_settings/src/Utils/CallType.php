<?php

namespace Drupal\dinger_settings\Utils;

enum CallType: string
{
  case FIXED_PRICE = 'fixed_price';
  case NEGOTIABLE = 'negotiable';
  case OPEN = 'open';
}
