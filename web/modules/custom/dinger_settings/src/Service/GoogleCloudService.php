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
use Drupal\dinger_settings\Plugin\Action\CreateGcAction;
use Drupal\dinger_settings\Utils\GcNodeType;
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
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Google Cloud Services
 */

final class GoogleCloudService {

  /**
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * @var \Google\Cloud\Tasks\V2\Client\CloudTasksClient
   */
  protected CloudTasksClient $cloudTasksClient;

  /**
   * Constructs a GoogleCloudService object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactoryInterface
   *
   * @throws \Google\ApiCore\ValidationException
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerChannelFactoryInterface $loggerFactoryInterface,
  ) {
    $this->logger = $this->loggerFactoryInterface->get('GoogleCloudService');
    $this->cloudTasksClient = $this->instantiateGoogleCloudTasksClient();
  }

  /**
   * @param \Drupal\node\NodeInterface $targetNode
   * @param \Drupal\Core\Datetime\DrupalDateTime $triggerTime
   *
   * @return \Google\Cloud\Tasks\V2\Task|null
   */
  public function upsertNodeExpirationTask(NodeInterface $targetNode, DrupalDateTime $triggerTime): ?Task {
    if ($this->isEligible($targetNode, $triggerTime)) {
      $taskName = trim($targetNode->get(CreateGcAction::GC_TASK_FIELD_NAME)->getString());
      try {
        $this->deleteGcTask($taskName);
        return $this->createGcTask($targetNode, $triggerTime);
      } catch (ApiException $e) {
        $this->logger->error('Creating gc task failed: ' . $e);
      }
    }
    return null;
  }

  /**
   * @throws \Google\ApiCore\ApiException
   */
  private function createGcTask(NodeInterface $targetNode, DrupalDateTime $triggerTime): Task {
    $this->logger->info('Creating GC Task for node type: @type | id: @id ', ['@type' => $targetNode->bundle(), '@id' => $targetNode->id()]);
    $config = $this->configFactory->get('dinger_settings');
    $projectId = $config->get('gc_tasks_project_id');
    $location = $config->get('gc_tasks_location');
    $queue = $config->get('gc_tasks_queue');
    $callBackToken = $config->get('callback_token');
    $formattedParent = CloudTasksClient::queueName($projectId, $location, $queue);

    $scheduleTime = new Timestamp();
    $scheduleTime->fromDateTime($triggerTime->getPhpDateTime());

    $expireNodeCallbackUrl = match ($targetNode->bundle()) {
      GcNodeType::CALL, GcNodeType::ORDER => Drupal::request()->getSchemeAndHttpHost() . '/expire-node/' . $callBackToken,
      default => throw new BadRequestHttpException('Unsupported Node Type'),
    };

    $taskRequest = (new CreateTaskRequest())
      ->setParent($formattedParent)
      ->setTask((new Task())
        ->setScheduleTime($scheduleTime)
        ->setHttpRequest((new HttpRequest())
          ->setHttpMethod(HttpMethod::POST)
          ->setUrl($expireNodeCallbackUrl)
          ->setBody(json_encode(['json' => ['uuid' => $targetNode->uuid(), 'type' => $targetNode->bundle()]]))));
    return $this->cloudTasksClient->createTask($taskRequest);
  }

  public function deleteGcTask(string $taskName): void {
    $this->logger->info('Deleting DC Task: ' . $taskName);
    if (empty(trim($taskName))) {
      return;
    }

    try {
      $this
        ->cloudTasksClient
        ->deleteTask((new DeleteTaskRequest())->setName($taskName));
    }
    catch (ApiException $e) {
      $this->logger->warning('Deleting GC Task failed. ' . $e->getMessage());
    }
  }

  /**
   * @throws \Google\ApiCore\ValidationException
   */
  private function instantiateGoogleCloudTasksClient(): CloudTasksClient {
    try {
      $gcSettingsFileLocation = Settings::get('gc_tasks_settings_file');
      $credentialsData = file_get_contents($gcSettingsFileLocation);
      $credentialsArray = json_decode($credentialsData, true);
      return new CloudTasksClient(['credentials' => $credentialsArray]);
    }
    catch (ValidationException $e) {
      $this->logger->error('Creating GC Tasks Client failed. ' . $e->getMessage());
      throw $e;
    }
  }

  private function isEligible(NodeInterface $targetNode, DrupalDateTime $triggerTime): bool {
    if (!$targetNode->hasField(CreateGcAction::GC_TASK_FIELD_NAME)) {
      return FALSE;
    }

    $now = new DrupalDateTime('now');
    $now->setTimezone(new DateTimeZone(DateTimeItemInterface::STORAGE_TIMEZONE));
    return $triggerTime->getTimestamp() > $now->getTimestamp();
  }

}
