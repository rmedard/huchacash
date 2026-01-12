<?php

namespace Drupal\dinger_settings\Utils;

trait StatusBaseType
{
  public static function fromString(string $value): self
  {
    \Drupal::logger('StatusBaseType')->debug('StatusBaseType fromString: ' . $value);
    return self::tryFrom($value);
  }

  public static function entryPointsString(): string {
    $values = array_map(fn($case) => $case->value, self::entryPoints());
    return '[' . implode(', ', $values) . ']';
  }

  public function toString(): string
  {
    return $this->value;
  }
}
