<?php

namespace Drupal\dinger_settings\Utils;

trait StatusBaseType
{
  public static function fromString(string $value): self
  {
    return self::tryFrom($value);
  }

  public static function entryPointsString(): string {
    $values = array_map(fn($case) => $case->value, self::entryPoints());
    return '[' . implode(', ', $values) . ']';
  }
}
