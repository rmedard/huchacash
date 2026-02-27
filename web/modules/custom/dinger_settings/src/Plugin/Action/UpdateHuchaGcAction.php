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
  id: 'update_gc_task_action',
  label: new TranslatableMarkup('Update GC Task Action'),
  category: new TranslatableMarkup('Custom'),
  type: 'node'
)]
final class UpdateHuchaGcAction extends BaseHuchaGcAction {

  /**
   * @throws ApiException
   */
  public function execute(?NodeInterface $entity = NULL): void {
    if ($entity == NULL) {
      $this->logger->error('Empty entity received. System cannot update task.');
      return;
    }

    /**
     * Update entity with created Task name
     */
    $expirationTasks = $this->googleCloudService->updateNodeExpirationTask($entity, $this->getTriggerTime($entity));
    if (!empty($expirationTasks)) {
      $this->applyTaskResults($entity, $expirationTasks);
    } else {
      $this->logger->error('The update huchaGcTask operation has failed.');
    }
  }
}
