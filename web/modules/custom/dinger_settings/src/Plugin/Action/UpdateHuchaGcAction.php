<?php

namespace Drupal\dinger_settings\Plugin\Action;

use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\dinger_settings\Service\GoogleCloudService;
use Drupal\node\NodeInterface;
use Google\ApiCore\ApiException;
use Google\ApiCore\ValidationException;
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

  /**
   * @var GoogleCloudService
   */
  public GoogleCloudService $googleCloudService;

  public function __construct(array $configuration, $plugin_id, $plugin_definition, LoggerChannelFactoryInterface $loggerFactory, GoogleCloudService $googleCloudService) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->logger = $loggerFactory->get('UpdateHuchaGcAction');
    $this->googleCloudService = $googleCloudService;
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
