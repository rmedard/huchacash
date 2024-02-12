<?php declare(strict_types = 1);

namespace Drupal\dinger_settings\Service;

use DateTimeZone;
use Drupal;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Site\Settings;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItemInterface;
use Drupal\node\NodeInterface;
use Google\ApiCore\ApiException;
use Google\ApiCore\ValidationException;
use Google\Cloud\Tasks\V2\Client\CloudTasksClient;
use Google\Cloud\Tasks\V2\CreateTaskRequest;
use Google\Cloud\Tasks\V2\DeleteTaskRequest;
use Google\Cloud\Tasks\V2\HttpMethod;
use Google\Cloud\Tasks\V2\HttpRequest;
use Google\Cloud\Tasks\V2\Task;
use Google\Protobuf\Timestamp;

/**
 * Google Cloud Services
 */

final class GoogleCloudService {

  /**
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected LoggerChannelInterface $loggerFactory;

  /**
   * Constructs a GoogleCloudService object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactoryInterface
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerChannelFactoryInterface $loggerFactoryInterface,
  ) {
    $this->loggerFactory = $this->loggerFactoryInterface->get('GC_Service');
  }

  /**
   * @param \Drupal\node\NodeInterface $order
   *
   * @return void
   */
  public function createOrderExpirationTask(NodeInterface $order): void {
    $isEligible = $this->checkEligibility($order);
    if ($isEligible) {
      $taskName = trim($order->get('field_gc_task_name')->getString());
      try {
        if (!empty($taskName)) {
          $this->deleteGcTask($taskName);
        }
        $this->createGcTask($order);
      } catch (ApiException | ValidationException $e) {
        $this->loggerFactory->error('Creating gc task failed: ' . $e);
      }
    }
  }

  /**
   * @throws \Google\ApiCore\ApiException
   * @throws \Google\ApiCore\ValidationException
   */
  private function createGcTask(NodeInterface $order): void {
    $this->loggerFactory->info('Creating GC Task for order ' . $order->id());
    /**
     * @var DrupalDateTime $deliveryTime
     */
    $deliveryTime = $order->get('field_order_delivery_time')->date;
    $config = $this->configFactory->get('dinger_settings');
    $projectId = $config->get('gc_tasks_project_id');
    $location = $config->get('gc_tasks_location');
    $queue = $config->get('gc_tasks_queue');
    $callBackToken = $config->get('callback_token');
    $formattedParent = CloudTasksClient::queueName($projectId, $location, $queue);
    $expireNodeCallbackUrl = Drupal::request()->getSchemeAndHttpHost() . '/expire-node/' . $callBackToken;
    $scheduleTime = new Timestamp();
    $scheduleTime->fromDateTime($deliveryTime->getPhpDateTime());
    $taskRequest = (new CreateTaskRequest())
      ->setParent($formattedParent)
      ->setTask((new Task())
        ->setScheduleTime($scheduleTime)
        ->setHttpRequest((new HttpRequest())
          ->setHttpMethod(HttpMethod::POST)
          ->setUrl($expireNodeCallbackUrl)
          ->setBody(json_encode(['json' => ['uuid' => $order->uuid(), 'type' => 'order']]))));
    $task = $this->getGcTasksClient()->createTask($taskRequest);
    // Don't call save() because this action is triggered from preSave state
    $order->set('field_gc_task_name', $task->getName());
  }

  private function deleteGcTask(string $taskName): void {
    $this->loggerFactory->info('Deleting DC Task: ' . $taskName);
    try {
      $this
        ->getGcTasksClient()
        ->deleteTask((new DeleteTaskRequest())->setName($taskName));
    }
    catch (ApiException|ValidationException $e) {
      $this->loggerFactory->warning('Deleting GC Task failed. ' . $e->getMessage());
    }
  }

  /**
   * @throws \Google\ApiCore\ValidationException
   */
  private function getGcTasksClient(): CloudTasksClient {
    try {
      $gcSettingsFileLocation = Settings::get('gc_tasks_settings_file');
      $credentialsData = file_get_contents($gcSettingsFileLocation);
      $credentialsArray = json_decode($credentialsData, true);
      return new CloudTasksClient(['credentials' => $credentialsArray]);
    }
    catch (ValidationException $e) {
      $this->loggerFactory->error('Creating GC Tasks Client failed. ' . $e->getMessage());
      throw $e;
    }
  }

  private function checkEligibility(NodeInterface $order): bool {
    if ($order->isNew()) return TRUE;

    /**
     * @var DrupalDateTime $currentDeliverDate
     */
    $currentDeliverDate = $order->get('field_order_delivery_time')->date;
    $now = new DrupalDateTime('now');
    $now->setTimezone(new DateTimeZone(DateTimeItemInterface::STORAGE_TIMEZONE));
    $isDeliveryTimeInPast = $currentDeliverDate->getTimestamp() < $now->getTimestamp();
    if ($isDeliveryTimeInPast) return FALSE;

    /**
     * @var NodeInterface $originalOrder
     * @var DrupalDateTime $originalDeliverDate
     */
    $originalOrder = $order->original;
    $originalDeliverDate = $originalOrder->get('field_order_delivery_time')->date;
    return $originalDeliverDate->getTimestamp() !== $currentDeliverDate->getTimestamp();
  }

}
