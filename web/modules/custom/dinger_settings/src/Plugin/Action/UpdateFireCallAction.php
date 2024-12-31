<?php

namespace Drupal\dinger_settings\Plugin\Action;

use Drupal\Core\Access\AccessResultAllowed;
use Drupal\Core\Access\AccessResultForbidden;
use Drupal\Core\Action\ActionBase;
use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\dinger_settings\Service\FirestoreCloudService;
use Drupal\node\NodeInterface;
use Google\Cloud\Core\Exception\GoogleException;
use Symfony\Component\DependencyInjection\ContainerInterface;

#[Action(
  id: 'dinger_settings_update_firecall_action',
  label: new TranslatableMarkup('Update FireCall Action'),
  category: new TranslatableMarkup('Custom'),
  type: 'node'
)]
final class UpdateFireCallAction extends ActionBase implements ContainerFactoryPluginInterface {

  /**
   * @var LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * @var FirestoreCloudService
   */
  protected FirestoreCloudService $firestoreCloudService;

  public function __construct(array $configuration, $plugin_id, $plugin_definition, LoggerChannelFactoryInterface $loggerFactory, FirestoreCloudService $firestoreCloudService) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->logger = $loggerFactory->get('UpdateFireCallAction');
    $this->firestoreCloudService = $firestoreCloudService;
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): UpdateFireCallAction {
    return new UpdateFireCallAction(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('logger.factory'),
      $container->get('dinger_settings.firestore_cloud_service')
    );
  }

  public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE): AccessResultForbidden|AccessResultAllowed {
    $isAllowed = $object instanceof NodeInterface && $object->bundle() == 'call';
    return $isAllowed ? new AccessResultAllowed() : new AccessResultForbidden();
  }

  /**
   * @throws GoogleException
   */
  public function execute(NodeInterface $call = NULL): void {
    $this->logger->info('Executing fireCall update. Id: ' . $call->uuid());
    $this->firestoreCloudService->updateFireCall($call);
  }

}
