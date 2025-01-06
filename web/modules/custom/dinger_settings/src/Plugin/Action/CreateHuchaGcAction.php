<?php

namespace Drupal\dinger_settings\Plugin\Action;

use Drupal\Core\Access\AccessResultAllowed;
use Drupal\Core\Access\AccessResultForbidden;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Action\ActionBase;
use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\dinger_settings\Service\GoogleCloudService;
use Drupal\dinger_settings\Utils\GcNodeType;
use Drupal\node\NodeInterface;
use Google\ApiCore\ApiException;
use Google\ApiCore\ValidationException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

#[Action(
  id: 'create_gc_task_action',
  label: new TranslatableMarkup('Create GC Task Action'),
  category: new TranslatableMarkup('Custom'),
  type: 'node'
)]
final class CreateHuchaGcAction extends ActionBase implements ContainerFactoryPluginInterface {

  const string GC_TASK_FIELD_NAME = 'field_gc_task_name';
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
    $this->logger = $loggerFactory->get('CreateHuchaGcAction');
    $this->googleCloudService = $googleCloudService;
  }
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): CreateHuchaGcAction {
    return new CreateHuchaGcAction(
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
    try {
      $expirationTask = $this->googleCloudService->createNodeExpirationTask($entity, $this->getTriggerTime($entity));
      if ($expirationTask) {
        $entity->set(self::GC_TASK_FIELD_NAME, $expirationTask->getName());
      } else {
        $this->logger->error('Create HuchaGc Task failed.');
      }
    }
    catch (ApiException|ValidationException $e) {
      $this->logger->error($e);
    }
  }

  public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE): bool|AccessResultInterface
  {
    $isAllowed = $object instanceof NodeInterface && in_array($object->bundle(), ['order', 'call']);
    return $isAllowed ? new AccessResultAllowed() : new AccessResultForbidden();
  }

  private function getTriggerTime(NodeInterface $node): DrupalDateTime {
    return match ($node->bundle()) {
      GcNodeType::CALL => $node->get('field_call_expiry_time')->date,
      GcNodeType::ORDER => $node->get('field_order_delivery_time')->date,
      default => throw new BadRequestHttpException('Unsupported Node Type'),
    };
  }
}
