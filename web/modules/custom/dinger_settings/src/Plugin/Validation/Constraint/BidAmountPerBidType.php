<?php

namespace Drupal\dinger_settings\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\Validator\Constraint;
use Drupal\Core\Validation\Attribute\Constraint as ConstraintAttribute;

#[ConstraintAttribute(
  id: self::PLUGIN_ID,
  label: new TranslatableMarkup('Correct amount per bid type'),
  type: 'entity'
)]
class BidAmountPerBidType extends Constraint
{
  public const string PLUGIN_ID = 'BidAmountPerType';
  public string $hasInvalidBidAmount = '%value has invalid amount';

  /**
   * {@inheritdoc}
   */
  public function validatedBy(): string
  {
    return BidConstraintValidator::class;
  }
}
