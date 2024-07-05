<?php

namespace Drupal\dinger_settings\Plugin\Action;

use Drupal;
use Drupal\Core\Access\AccessResultAllowed;
use Drupal\Core\Access\AccessResultForbidden;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Action\ActionBase;
use Drupal\Core\Annotation\Action;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @Action(
 *    id = "dinger_settings_create_firecall_action",
 *    label = @Translation("Create FireCall action"),
 *    type = "node",
 *    category = @Translation("Custom"),
 *  )
 */
final class CreateFireCallAction extends ActionBase implements ContainerFactoryPluginInterface {

  /**
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  public function __construct(array $configuration, $plugin_id, $plugin_definition, LoggerChannelFactoryInterface $loggerFactory) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->logger = $loggerFactory->get('create_gc_action');
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): CreateFireCallAction {
    return new CreateFireCallAction(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('logger.factory'));
  }

  public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE): bool|AccessResultInterface {
    $isAllowed = $object instanceof NodeInterface && $object->bundle() == 'call';
    return $isAllowed ? new AccessResultAllowed() : new AccessResultForbidden();
  }

  public function execute(NodeInterface $call = NULL): void {

    /** Create fireCall **/
    /** @var \Drupal\dinger_settings\Service\FirestoreCloudService $firestoreService **/
    $firestoreService = Drupal::service('dinger_settings.firestore_cloud_service');
    $firestoreService->createFireCall($call);
  }

}
