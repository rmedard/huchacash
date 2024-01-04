<?php

namespace Drupal\dinger_settings\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * @Constraint(
 *   id = "UniqueCallPerOrder",
 *   label = @Translation("Unique Call per Order", context = "Validation"),
 *   type = "entity"
 * )
 */
class UniqueCallPerOrder extends Constraint
{
  public string $hasAnotherLiveCall = '%value has another live call for service';

  public function validatedBy(): string
  {
    return CallConstraintValidator::class;
  }
}
