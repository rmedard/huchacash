<?php

namespace Drupal\dinger_settings\Plugin\Action;

use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;
use Google\ApiCore\ApiException;
use Google\ApiCore\ValidationException;

#[Action(
  id: 'update_gc_task_action',
  label: new TranslatableMarkup('Update GC Task Action'),
  category: new TranslatableMarkup('Custom'),
  type: 'node'
)]
final class UpdateHuchaGcAction extends BaseHuchaGcAction {

  /**
   * @throws ValidationException
   * @throws ApiException
   */
  public function execute(NodeInterface $entity = NULL): void {

    /**
     * Update entity with created Task name
     */
    $expirationTask = $this->googleCloudService->updateNodeExpirationTask($entity, $this->getTriggerTime($entity));
    if ($expirationTask) {
      $entity->set(self::GC_TASK_FIELD_NAME, $expirationTask->getName());
    } else {
      $this->logger->error('The update huchaGcTask operation has failed.');
    }
  }
}
