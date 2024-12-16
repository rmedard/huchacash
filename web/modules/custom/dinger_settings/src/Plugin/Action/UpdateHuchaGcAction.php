<?php

namespace Drupal\dinger_settings\Plugin\Action;

use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\dinger_settings\Service\GoogleCloudService;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

#[Action(
  id: 'update_gc_task_action',
  label: new TranslatableMarkup('Update GC Task Action'),
  category: new TranslatableMarkup('Custom'),
  type: 'node'
)]
final class UpdateHuchaGcAction extends HuchaGcAction {

  /**
   * @var LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  public function __construct(array $configuration, $plugin_id, $plugin_definition, LoggerChannelFactoryInterface $loggerFactory, GoogleCloudService $googleCloudService) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $googleCloudService);
    $this->logger = $loggerFactory->get('UpdateHuchaGcAction');
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): UpdateHuchaGcAction {
    return new UpdateHuchaGcAction(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('logger.factory'),
      $container->get('dinger_settings.google_cloud_service'),
    );
  }

  public function execute(NodeInterface $entity = NULL): void {

    /**
     * Update entity with created Task name
     */
    $expirationTask = $this->googleCloudService->upsertNodeExpirationTask($entity, $this->getTriggerTime($entity));
    $entity->set(self::GC_TASK_FIELD_NAME, $expirationTask->getName());
  }

}
