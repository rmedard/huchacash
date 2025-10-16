<?php

namespace Drupal\dinger_settings\Utils;

final class FirestoreFieldValue
{
  private FirestoreValueType $fieldType;
  private mixed $fieldValue;

  /**
   * @param FirestoreValueType $fieldType
   * @param string $fieldValue
   */
  private function __construct(FirestoreValueType $fieldType, mixed $fieldValue)
  {
    $this->fieldType = $fieldType;
    $this->fieldValue = $fieldValue;
  }

  public function getFieldType(): FirestoreValueType
  {
    return $this->fieldType;
  }

  public function getFieldValue(): mixed
  {
    return $this->fieldValue;
  }

  public function toFirestoreValue(): array
  {
    return [$this->fieldType->value => $this->fieldValue];
  }

  // Convenient factory methods

  public static function string(string $value): self
  {
    return new self(FirestoreValueType::STRING, $value);
  }

  public static function integer(int $value): self
  {
    return new self(FirestoreValueType::INTEGER, $value);
  }

  public static function boolean(bool $value): self
  {
    return new self(FirestoreValueType::BOOLEAN, $value);
  }

  public static function double(float $value): self
  {
    return new self(FirestoreValueType::DOUBLE, $value);
  }

  public static function null(): self
  {
    return new self(FirestoreValueType::NULL, null);
  }
}
