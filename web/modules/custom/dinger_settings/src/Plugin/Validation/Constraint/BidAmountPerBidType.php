<?php

namespace Drupal\dinger_settings\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * @Constraint(
 *   id = "BidAmountPerType",
 *   label = @Translation("Correct amount per bid type", context = "Validation"),
 *   type = "entity"
 * )
 */
class BidAmountPerBidType extends Constraint
{
  public string $hasInvalidBidAmount = '%value has invalid amount';

  /**
   * {@inheritdoc}
   */
  public function validatedBy(): string
  {
    return BidConstraintValidator::class;
  }
}
