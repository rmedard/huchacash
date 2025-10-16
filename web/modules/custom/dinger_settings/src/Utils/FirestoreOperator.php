<?php

namespace Drupal\dinger_settings\Utils;

enum FirestoreOperator: string
{
  case EQUAL = 'EQUAL';
  case LESS_THAN = 'LESS_THAN';
  case LESS_THAN_OR_EQUAL = 'LESS_THAN_OR_EQUAL';
  case  GREATER_THAN = 'GREATER_THAN';
  case GREATER_THAN_OR_EQUAL = 'GREATER_THAN_OR_EQUAL';
  case NOT_EQUAL = 'NOT_EQUAL';
  case ARRAY_CONTAINS = 'ARRAY_CONTAINS';
  case IN = 'IN';
  case ARRAY_CONTAINS_ANY = 'ARRAY_CONTAINS_ANY';
  case NOT_IN = 'NOT_IN';

  public static function isValid(string $value): bool
  {
    return self::tryFrom($value) !== null;
  }
}
