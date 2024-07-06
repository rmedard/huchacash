<?php

namespace Drupal\dinger_settings\Plugin\Action;

use Drupal\Core\Access\AccessResultAllowed;
use Drupal\Core\Access\AccessResultForbidden;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Action\ActionBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\dinger_settings\Service\GoogleCloudService;
use Drupal\dinger_settings\Utils\GcNodeType;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

abstract class HuchaGcAction extends ActionBase implements ContainerFactoryPluginInterface {

  const GC_TASK_FIELD_NAME = 'field_gc_task_name';

  /**
   * @var \Drupal\dinger_settings\Service\GoogleCloudService
   */
  public GoogleCloudService $googleCloudService;

  public function __construct(array $configuration, $plugin_id, $plugin_definition, GoogleCloudService $googleCloudService) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->googleCloudService = $googleCloudService;
  }

  /**
   * @inheritDoc
   */
  public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE): bool|AccessResultInterface {
    $isAllowed = $object instanceof NodeInterface && in_array($object->bundle(), ['order', 'call']);
    return $isAllowed ? new AccessResultAllowed() : new AccessResultForbidden();
  }

  /**
   * @inheritDoc
   */
  abstract public function execute();

  protected function getTriggerTime(NodeInterface $node): DrupalDateTime {
    return match ($node->bundle()) {
      GcNodeType::CALL => $node->get('field_call_expiry_time')->date,
      GcNodeType::ORDER => $node->get('field_order_delivery_time')->date,
      default => throw new BadRequestHttpException('Unsupported Node Type'),
    };
  }

}
