<?php

namespace Drupal\dinger_settings\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * @Constraint(
 *   id = "AmountPerCallType",
 *   label = @Translation("Correct amount per call type", context = "Validation"),
 *   type = "entity"
 * )
 */
class AmountPerCallType extends Constraint
{
  public string $hasInvalidCallAmount = '%value has invalid amount';

  /**
   * {@inheritdoc}
   */
  public function validatedBy(): string
  {
    return CallConstraintValidator::class;
  }
}
