<?php

namespace Drupal\dinger_settings\Plugin\Validation\Constraint;

use Drupal;
use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

class CallConstraintValidator extends ConstraintValidator
{

  public function validate($value, Constraint $constraint): void
  {
    if ($value instanceof NodeInterface && $value->bundle() === 'call') {
      if ($constraint instanceof UniqueCallPerOrder) {
        if ($this->hasOtherOpenCalls($value)) {
          $this->context->addViolation($constraint->hasAnotherLiveCall, ['%value' => $value->label()]);
        }
      } elseif ($constraint instanceof AmountPerCallType) {
        if ($this->hasInvalidAmount($value)) {
          $this->context->addViolation($constraint->hasInvalidCallAmount, ['%value' => 'Call']);
        }
      }
    }
  }

  private function hasOtherOpenCalls(Node $call): bool
  {
    try {
      $callsCount = Drupal::entityTypeManager()->getStorage('node')->getQuery()
        ->condition('type', 'call')
        ->condition('nid', $call->id(), '<>')
        ->condition('field_call_order.target_id', $call->get('field_call_order')->target_id)
        ->condition('field_call_status', ['live', 'attributed', 'completed'], 'IN')
        ->count()
        ->execute();
      return $callsCount > 0;
    } catch (InvalidPluginDefinitionException|PluginNotFoundException $e) {
      Drupal::logger('dinger_settings')->error('Call validation failed: ' . $e->getMessage());
    }
    return false;
  }

  private function hasInvalidAmount(Node $call): bool
  {
    $proposed_fee = doubleval($call->get('field_call_proposed_service_fee')->value);
    $type = $call->get('field_call_type')->value;
    return $proposed_fee == 0 and $type == 'fixed_price';
  }
}
