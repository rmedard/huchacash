<?php

namespace Drupal\dinger_settings\Utils;

interface StatusBaseInterface
{
  public static function fromString(string $value): self;
  public static function entryPoints(): array;
  public static function entryPointsString(): string;
  public static function finalStates(): array;
  public function toString(): string;

}
