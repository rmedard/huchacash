<?php

namespace Drupal\rest_entity_extras\Plugin\Field\FieldType;


use Drupal;
use Drupal\Core\Field\EntityReferenceFieldItemList;
use Drupal\Core\TypedData\ComputedItemListTrait;
use Drupal\node\NodeInterface;

/**
 * Computed field item list
 */
class ComputedOrderCallsField extends EntityReferenceFieldItemList {

  use ComputedItemListTrait;

  protected function computeValue(): void
  {
    $entity = $this->getEntity();

    // Only compute for Call entities
    if ($entity->getEntityTypeId() !== 'node' || $entity->bundle() !== 'order') {
      return;
    }

    // Query for bids that reference this call
    $query = Drupal::entityQuery('node')
      ->condition('type', 'call')
      ->condition('field_call_order', $entity->id())
      ->condition('status', NodeInterface::PUBLISHED)
      ->accessCheck();

    $call_ids = $query->execute();

    // Populate the computed field with the call references
    $delta = 0;
    foreach ($call_ids as $call_id) {
      $this->list[$delta] = $this->createItem($delta, ['target_id' => $call_id]);
      $delta++;
    }
  }
}
