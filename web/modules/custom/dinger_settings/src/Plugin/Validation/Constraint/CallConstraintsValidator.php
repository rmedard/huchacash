<?php

namespace Drupal\dinger_settings\Plugin\Validation\Constraint;

use Drupal;
use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\dinger_settings\Utils\CallStatus;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

class CallConstraintsValidator extends ConstraintValidator
{

  public function validate($value, Constraint $constraint): void
  {
    $logger = \Drupal::logger('CallConstraintsValidator');
    if (!$value instanceof NodeInterface && $value->bundle() !== 'call') {
      return;
    }

    if ($constraint instanceof UniqueCallPerOrderConstraint) {
      if ($this->hasOtherOpenCalls($value)) {
        $logger->warning('Call has other open calls');
        $this->context->addViolation($constraint->hasAnotherLiveCall, ['%value' => $value->label()]);
      }
    } elseif ($constraint instanceof AmountPerCallTypeConstraint) {
      if ($this->hasInvalidAmount($value)) {
        $logger->warning('Call has invalid amount');
        $this->context->addViolation($constraint->hasInvalidCallAmount, ['%value' => 'Call']);
      }
    } elseif ($constraint instanceof CallExpirationPerOrderConstraint) {
      /** @var DrupalDateTime $callExpirationTime */
      $callExpirationTime = $value->get('field_call_expiry_time')->date;
      /** @var DrupalDateTime $orderDeliveryTime */
      $orderDeliveryTime = $value->get('field_call_order')->entity->get('field_order_delivery_time')->date;
      if ($callExpirationTime > $orderDeliveryTime) {
        $logger->warning('Call expires later than delivery');
        $this->context->addViolation($constraint->expiresLaterThanDelivery, [
          '%expiration' => $callExpirationTime->format('Y-m-d H:i:s'),
          '%deliveryTime' => $orderDeliveryTime->format('Y-m-d H:i:s')
        ]);
      }
    } elseif ($constraint instanceof CallStatusAndExpirationConstraint) {

      $status = $value->get('field_call_status')->getString();
      /** @var DrupalDateTime $expiryTime */
      $expiryTime = $value->get('field_call_expiry_time')->date;
      $now = new DrupalDateTime('now');

      // Rule 1: status=live but expiry is in the past.
      if ($status === CallStatus::LIVE->value && $expiryTime < $now) {
        $this->context->buildViolation($constraint->liveExpiredMessage)
          ->atPath('field_call_status')
          ->addViolation();
      }

      // Rule 2: status=expired but expiry is in the future.
      if ($status === CallStatus::EXPIRED->value && $expiryTime > $now) {
        $this->context->buildViolation($constraint->expiredLiveMessage)
          ->atPath('field_call_status')
          ->addViolation();
      }
    }
  }

  private function hasOtherOpenCalls(Node $call): bool
  {
    try {
      $callsCount = Drupal::entityTypeManager()->getStorage('node')->getQuery()
        ->accessCheck(false)
        ->condition('type', 'call')
        ->condition('nid', $call->id(), '<>')
        ->condition('field_call_order.target_id', $call->get('field_call_order')->target_id)
        ->condition('field_call_status', [
          CallStatus::LIVE->value,
          CallStatus::ATTRIBUTED->value,
          CallStatus::COMPLETED->value], 'IN')
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
