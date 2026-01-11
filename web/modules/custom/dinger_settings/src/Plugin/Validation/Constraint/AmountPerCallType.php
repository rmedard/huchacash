<?php

namespace Drupal\dinger_settings\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\Validator\Constraint;
use Drupal\Core\Validation\Attribute\Constraint as ConstraintAttribute;

#[ConstraintAttribute(
  id: self::PLUGIN_ID,
  label: new TranslatableMarkup('Correct amount per call type'),
  type: 'entity'
)]
class AmountPerCallType extends Constraint
{
  public const string PLUGIN_ID = 'AmountPerCallType';
  public string $hasInvalidCallAmount = '%value has invalid amount';

  /**
   * {@inheritdoc}
   */
  public function validatedBy(): string
  {
    return CallConstraintsValidator::class;
  }
}
