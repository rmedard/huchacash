<?php

namespace Drupal\dinger_settings\Plugin\Validation\Constraint;

use Drupal\dinger_settings\Utils\BidType;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

class BidConstraintValidator extends ConstraintValidator
{

  public function validate($value, Constraint $constraint): void
  {
    if ($value instanceof NodeInterface && $value->bundle() === 'bid') {
      if ($constraint instanceof BidAmountPerBidType) {
        if ($this->hasInvalidAmount($value)) {
          $this->context->addViolation($constraint->hasInvalidBidAmount, ['%value' => 'Bid']);
        }
      }
    }
  }

  private function hasInvalidAmount(Node $bid): bool
  {
    $amount = doubleval($bid->get('field_bid_amount')->getString());
    $type = BidType::from($bid->get('field_bid_type')->getString());
    return $amount == 0 and $type == BidType::BARGAIN;
  }
}
