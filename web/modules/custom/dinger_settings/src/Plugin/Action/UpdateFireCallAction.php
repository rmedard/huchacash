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
use Drupal\dinger_settings\Utils\CallStatus;
use Drupal\node\NodeInterface;
use Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;

#[Action(
  id: 'dinger_settings_update_firecall_action',
  label: new TranslatableMarkup('Update FireCall Action'),
  category: new TranslatableMarkup('Custom'),
  type: 'node'
)]
final class UpdateFireCallAction extends ActionBase implements ContainerFactoryPluginInterface {

  private static array $processing = [];

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

  public function execute(?NodeInterface $call = NULL): void {

    // Get a unique identifier for this entity
    $entity_key = $call ? $call->getEntityTypeId() . ':' . $call->id() : 'unknown';
    // Check if we're already processing this entity
    if (isset(self::$processing[$entity_key])) {
      $this->logger->warning('Prevented infinite recursion for entity: @key', [
        '@key' => $entity_key
      ]);
      return;
    }

    // Mark as processing
    self::$processing[$entity_key] = TRUE;

    try {
      $this->logger->info('Executing fireCall update. Id: ' . $call->uuid());
      /** @var NodeInterface $originalCall */
      $originalCall = $call->getOriginal();
      $initialStatus = CallStatus::fromString($originalCall->get('field_call_status')->getString());
      $currentStatus = CallStatus::fromString($call->get('field_call_status')->getString());
      if ($initialStatus !== $currentStatus) {
        if ($currentStatus->isFinalState()) {
          $this->firestoreCloudService->deleteFireCall($call->uuid());
        } else {
          $this->firestoreCloudService->updateFireCall($call);
        }
      }
    } catch (Exception $e) {
      $this->logger->error($e->getMessage());
    } finally {
      unset(self::$processing[$entity_key]);
    }
  }

}
