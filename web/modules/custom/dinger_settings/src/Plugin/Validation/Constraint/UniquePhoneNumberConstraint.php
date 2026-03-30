<?php

namespace Drupal\dinger_settings\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\Validator\Constraint;
use Drupal\Core\Validation\Attribute\Constraint as ConstraintAttribute;

#[ConstraintAttribute(
  id: self::PLUGIN_ID,
  label: new TranslatableMarkup('Unique Phone Number'),
  type: 'entity:user'
)]
class UniquePhoneNumberConstraint extends Constraint
{
  public const string PLUGIN_ID = 'UniquePhoneNumberConstraint';
  public string $message = 'The phone number %value is already in use by another account.';

  public function validatedBy(): string
  {
    return UniquePhoneNumberValidator::class;
  }
}