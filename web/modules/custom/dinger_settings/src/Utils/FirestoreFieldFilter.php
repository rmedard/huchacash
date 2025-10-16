<?php

namespace Drupal\dinger_settings\Utils;

use InvalidArgumentException;

final class FirestoreFieldFilter
{
  private string $fieldName;
  private FirestoreFieldValue $fieldValue;
  private FirestoreOperator $operator;

  /**
   * @param string $fieldName
   * @param FirestoreFieldValue $fieldValue
   * @param FirestoreOperator $operator
   */
  public function __construct(string $fieldName, FirestoreFieldValue $fieldValue, FirestoreOperator $operator)
  {
    if (empty($fieldName)) {
      throw new InvalidArgumentException("The fieldName is required.");
    }

    $this->fieldName = $fieldName;
    $this->fieldValue = $fieldValue;
    $this->operator = $operator;
  }
  public function getFieldName(): string
  {
    return $this->fieldName;
  }

  public function getFieldValue(): FirestoreFieldValue
  {
    return $this->fieldValue;
  }

  public function getOperator(): FirestoreOperator
  {
    return $this->operator;
  }

  // Convenience factory methods
  public static function string(string $fieldName, string $value, FirestoreOperator $operator): self
  {
    return new self($fieldName, FirestoreFieldValue::string($value), $operator);
  }

  public static function integer(string $fieldName, int $value, FirestoreOperator $operator): self
  {
    return new self($fieldName, FirestoreFieldValue::integer($value), $operator);
  }

  public static function boolean(string $fieldName, bool $value, FirestoreOperator $operator): self
  {
    return new self($fieldName, FirestoreFieldValue::boolean($value), $operator);
  }

  public static function double(string $fieldName, float $value, FirestoreOperator $operator): self
  {
    return new self($fieldName, FirestoreFieldValue::double($value), $operator);
  }
}
