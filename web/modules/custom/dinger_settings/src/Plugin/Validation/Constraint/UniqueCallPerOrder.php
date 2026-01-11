<?php

namespace Drupal\dinger_settings\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\Validator\Constraint;
use Drupal\Core\Validation\Attribute\Constraint as ConstraintAttribute;

#[ConstraintAttribute(
  id: self::PLUGIN_ID,
  label: new TranslatableMarkup('Unique Call per Order'),
  type: 'entity'
)]
class UniqueCallPerOrder extends Constraint
{
  public const string PLUGIN_ID = 'UniqueCallPerOrder';
  public string $hasAnotherLiveCall = '%value has another live call for service';

  public function validatedBy(): string
  {
    return CallConstraintsValidator::class;
  }
}
