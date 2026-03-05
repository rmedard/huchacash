<?php

namespace Drupal\dinger_settings\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\Validator\Constraint;
use Drupal\Core\Validation\Attribute\Constraint as ConstraintAttribute;

#[ConstraintAttribute(
  id: self::PLUGIN_ID,
  label: new TranslatableMarkup('Call Status & Expiration Constraint'),
  type: 'entity:node',
)]
class CallStatusAndExpirationConstraint extends Constraint
{
  public const string PLUGIN_ID = 'CallStatusAndExpiration';

  public string $liveExpiredMessage = 'A call with status "live" cannot have an expiry time in the past.';
  public string $expiredLiveMessage = 'A call with status "expired" cannot have an expiry time in the future.';

  public function validatedBy(): string
  {
    return CallConstraintsValidator::class;
  }
}
