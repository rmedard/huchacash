<?php declare(strict_types=1);

namespace Drupal\dinger_settings\Plugin\Action;

use Drupal;
use Drupal\Core\Access\AccessResultAllowed;
use Drupal\Core\Access\AccessResultForbidden;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Action\ActionBase;
use Drupal\Core\Annotation\Action;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\dinger_settings\Utils\GcNodeType;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a Create GC action.
 *
 * @Action(
 *   id = "dinger_settings_create_gc_action",
 *   label = @Translation("Create GC action"),
 *   type = "node",
 *   category = @Translation("Custom"),
 * )
 *
 * @DCG
 * For updating entity fields consider extending FieldUpdateActionBase.
 * @see \Drupal\Core\Field\FieldUpdateActionBase
 *
 * @DCG
 * In order to set up the action through admin interface the plugin has to be
 * configurable.
 * @see https://www.drupal.org/project/drupal/issues/2815301
 * @see https://www.drupal.org/project/drupal/issues/2815297
 *
 * @DCG
 * The whole action API is subject of change.
 * @see https://www.drupal.org/project/drupal/issues/2011038
 */

final class CreateGcAction extends ActionBase implements ContainerFactoryPluginInterface {

  const GC_TASK_FIELD_NAME = 'field_gc_task_name';

  /**
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected LoggerChannelInterface $loggerFactory;

  public function __construct(array $configuration, $plugin_id, $plugin_definition, ConfigFactoryInterface $configFactory, LoggerChannelFactoryInterface $loggerFactory) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->configFactory = $configFactory;
    $this->loggerFactory = $loggerFactory->get('create_gc_action');
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): CreateGcAction {
    return new CreateGcAction(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('config.factory'),
      $container->get('logger.factory'));
  }

  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE): bool|AccessResultInterface {
    $isAllowed = $object instanceof NodeInterface && in_array($object->bundle(), ['order', 'call']);
    return $isAllowed ? new AccessResultAllowed() : new AccessResultForbidden();
  }

  /**
   * {@inheritdoc}
   */
  public function execute(ContentEntityInterface $entity = NULL): void {
    if ($entity instanceof NodeInterface) {
      $triggerTime = new DrupalDateTime();
      switch ($entity->bundle()) {
        case GcNodeType::ORDER:
          $triggerTime = $entity->get('field_order_delivery_time')->date;
          break;
        case GcNodeType::CALL:
          $triggerTime = $entity->get('field_call_expiry_time')->date;
          break;
      }

      /**
       * Update entity with created Task name
       * @var \Drupal\dinger_settings\Service\GoogleCloudService $gcService
       */
      $gcService = Drupal::service('dinger_settings.google_cloud_service');
      $expirationTask = $gcService->upsertNodeExpirationTask($entity, $triggerTime);
      try {
        $entity->set(self::GC_TASK_FIELD_NAME, $expirationTask->getName());
        //$entity->save();
      }
      catch (EntityStorageException $e) {
        $this->loggerFactory->error($e);
      }
    }
  }
}
