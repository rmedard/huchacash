<?php

namespace Drupal\rest_entity_extras\Plugin\Field\FieldType;


use Drupal;
use Drupal\Core\Field\EntityReferenceFieldItemList;
use Drupal\Core\TypedData\ComputedItemListTrait;
use Drupal\node\NodeInterface;

/**
 * Computed field item list
 */
class ComputedCallBidsField extends EntityReferenceFieldItemList {

  use ComputedItemListTrait;

  protected function computeValue(): void
  {
    $entity = $this->getEntity();

    // Only compute for Call entities
    if ($entity->getEntityTypeId() !== 'node' || $entity->bundle() !== 'call') {
      return;
    }

    // Query for bids that reference this call
    $query = Drupal::entityQuery('node')
      ->condition('type', 'bid')
      ->condition('field_bid_call', $entity->id())
      ->condition('status', NodeInterface::PUBLISHED)
      ->accessCheck();

    $bid_ids = $query->execute();

    // Populate the computed field with the bid references
    $delta = 0;
    foreach ($bid_ids as $bid_id) {
      $this->list[$delta] = $this->createItem($delta, ['target_id' => $bid_id]);
      $delta++;
    }
  }
}
