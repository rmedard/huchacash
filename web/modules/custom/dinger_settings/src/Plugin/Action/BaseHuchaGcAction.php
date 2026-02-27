<?php

namespace Drupal\dinger_settings\Plugin\Action;

use Drupal\Core\Access\AccessResultAllowed;
use Drupal\Core\Access\AccessResultForbidden;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Action\ActionBase;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\dinger_settings\Service\GoogleCloudService;
use Drupal\dinger_settings\Utils\GcNodeType;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

abstract class BaseHuchaGcAction extends ActionBase implements ContainerFactoryPluginInterface {

  const string GC_TASK_FIELD_NAME = 'field_gc_task_name';
  const string GC_TASK_FIELD_NAME_CALLS_CLEANER = 'field_gc_task_name_calls_cleaner';

  /**
   * @var LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * @var GoogleCloudService
   */
  protected GoogleCloudService $googleCloudService;

  public function __construct(array $configuration, $plugin_id, $plugin_definition, LoggerChannelFactoryInterface $loggerFactory, GoogleCloudService $googleCloudService) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->logger = $loggerFactory->get(static::class);
    $this->googleCloudService = $googleCloudService;
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('logger.factory'),
      $container->get('dinger_settings.google_cloud_service')
    );
  }

  public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE): bool|AccessResultInterface {
    $isAllowed = $object instanceof NodeInterface && in_array($object->bundle(), ['order', 'call']);
    return $isAllowed ? new AccessResultAllowed() : new AccessResultForbidden();
  }

  protected function applyTaskResults(NodeInterface $entity, array $expirationTasks): void {
    $entity->set(self::GC_TASK_FIELD_NAME, $expirationTasks[self::GC_TASK_FIELD_NAME]->getName());
    if ($entity->bundle() === 'order') {
      $callsCleanerTask = $expirationTasks[self::GC_TASK_FIELD_NAME_CALLS_CLEANER] ?? null;
      $taskName = isset($callsCleanerTask) ? $callsCleanerTask->getName() : '';
      $entity->set(self::GC_TASK_FIELD_NAME_CALLS_CLEANER, $taskName);
    }
  }

  protected function getTriggerTime(NodeInterface $node): DrupalDateTime {
    return match (GcNodeType::tryFrom($node->bundle())) {
      GcNodeType::CALL => $node->get('field_call_expiry_time')->date,
      GcNodeType::ORDER => $node->get('field_order_delivery_time')->date,
      default => throw new BadRequestHttpException('Unsupported Node Type'),
    };
  }

  abstract public function execute(?NodeInterface $entity = NULL): void;
}
