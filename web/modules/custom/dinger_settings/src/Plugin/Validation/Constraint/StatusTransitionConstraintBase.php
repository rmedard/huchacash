<?php

namespace Drupal\dinger_settings\Plugin\Validation\Constraint;

use Drupal\dinger_settings\Utils\StatusBaseInterface;
use Symfony\Component\Validator\Constraint;

abstract class StatusTransitionConstraintBase extends Constraint
{

  public string $invalidInitialStatusMessage = 'New @bundle status must be any of @status.';

  public string $message = 'Invalid state transition from "@from" to "@to".';

  /**
   * {@inheritdoc}
   */
  public function validatedBy(): string
  {
    return StatusTransitionsValidator::class;
  }

  /**
   * @return class-string<StatusBaseInterface>
   */
  abstract public function statusEnumClass(): string;

  abstract public function allowedTransitions(): array;
}
