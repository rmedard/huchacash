<?php

namespace Drupal\dinger_settings\Plugin\Action;

use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;
use Google\ApiCore\ApiException;
use Google\ApiCore\ValidationException;

#[Action(
  id: 'create_gc_task_action',
  label: new TranslatableMarkup('Create GC Task Action'),
  category: new TranslatableMarkup('Custom'),
  type: 'node'
)]
final class CreateHuchaGcAction extends BaseHuchaGcAction {

  public function execute(NodeInterface $entity = NULL): void {
    /**
     * Update entity with created Task name
     */
    try {
      $expirationTask = $this->googleCloudService->createNodeExpirationTask($entity, $this->getTriggerTime($entity));
      if ($expirationTask) {
        $entity->set(self::GC_TASK_FIELD_NAME, $expirationTask->getName());
      } else {
        $this->logger->error('Create HuchaGc Task failed.');
      }
    }
    catch (ApiException|ValidationException $e) {
      $this->logger->error($e);
    }
  }
}
