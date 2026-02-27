<?php

namespace Drupal\dinger_settings\Plugin\Action;

use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;
use Google\ApiCore\ApiException;

/**
 * Action task triggered in presave state.
 */
#[Action(
  id: 'create_gc_task_action',
  label: new TranslatableMarkup('Create GC Task Action'),
  category: new TranslatableMarkup('Custom'),
  type: 'node'
)]
final class CreateHuchaGcAction extends BaseHuchaGcAction {

  public function execute(?NodeInterface $entity = NULL): void {
    if ($entity == NULL) {
      $this->logger->error('Empty entity received. System cannot create task.');
      return;
    }

    /**
     * Update entity with created Task name
     */
    try {
      $expirationTasks = $this->googleCloudService->createNodeExpirationTask($entity, $this->getTriggerTime($entity));
      if (!empty($expirationTasks)) {
        $this->applyTaskResults($entity, $expirationTasks);
      } else {
        $this->logger->error('Create HuchaGc Task failed.');
      }
    }
    catch (ApiException $e) {
      $this->logger->error($e);
    }
  }
}
