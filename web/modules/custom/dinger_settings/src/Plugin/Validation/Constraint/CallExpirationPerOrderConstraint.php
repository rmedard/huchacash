<?php

namespace Drupal\dinger_settings\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\Validator\Constraint;
use Drupal\Core\Validation\Attribute\Constraint as ConstraintAttribute;

#[ConstraintAttribute(
  id: self::PLUGIN_ID,
  label: new TranslatableMarkup('Call should expire before order delivery'),
  type: 'entity:node'
)]
class CallExpirationPerOrderConstraint extends Constraint
{
  public const string PLUGIN_ID = 'CallExpirationPerOrderConstraint';
  public string $expiresLaterThanDelivery = 'Call expires %expiration which is later that order delivery time: %deliveryTime';

  public function validatedBy(): string
  {
    return CallConstraintsValidator::class;
  }
}
