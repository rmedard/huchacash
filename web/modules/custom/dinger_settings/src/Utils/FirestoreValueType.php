<?php

namespace Drupal\dinger_settings\Utils;

enum FirestoreValueType: string
{
  case STRING = 'stringValue';
  case INTEGER = 'integerValue';
  case BOOLEAN = 'booleanValue';
  case DOUBLE = 'doubleValue';
  case TIMESTAMP = 'timestampValue';
  case NULL = 'nullValue';
}
