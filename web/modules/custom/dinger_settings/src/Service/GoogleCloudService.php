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
use Exception;
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
   * @var LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * @var ?CloudTasksClient
   */
  protected ?CloudTasksClient $cloudTasksClient = null;

  /**
   * Constructs a GoogleCloudService object.
   *
   * @param ConfigFactoryInterface $configFactory
   * @param LoggerChannelFactoryInterface $loggerFactoryInterface
   *
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerChannelFactoryInterface $loggerFactoryInterface,
  ) {
    $this->logger = $this->loggerFactoryInterface->get('GoogleCloudService');
  }

  /**
   * @throws ValidationException
   */
  private function getCloudTasksClient(): CloudTasksClient {
    if (is_null($this->cloudTasksClient)) {
      $this->cloudTasksClient = $this->instantiateGoogleCloudTasksClient();
    }
    return $this->cloudTasksClient;
  }

  /**
   * @param NodeInterface $targetNode
   * @param DrupalDateTime $triggerTime
   *
   * @return Task|null
   * @throws ValidationException
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
   * @throws ApiException
   * @throws ValidationException
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

    $httpRequest = (new HttpRequest())
      ->setHttpMethod(HttpMethod::POST)
      ->setUrl($expireNodeCallbackUrl)
      ->setHeaders([
        'Content-Type' => 'application/json',
      ])
      ->setBody(json_encode([
        'uuid' => $targetNode->uuid(),
        'type' => $targetNode->bundle()
      ]));

    $task = (new Task())
      ->setScheduleTime($scheduleTime)
      ->setHttpRequest($httpRequest);

    $request = (new CreateTaskRequest())
      ->setParent($formattedParent)
      ->setTask($task);

    return $this->getCloudTasksClient()->createTask($request);
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
   * @throws ValidationException
   */
  private function instantiateGoogleCloudTasksClient(): CloudTasksClient {
    try {
      $gcSettingsFileLocation = Settings::get('gc_tasks_settings_file');

      if (empty($gcSettingsFileLocation) || !file_exists($gcSettingsFileLocation)) {
        throw new ValidationException('Google Cloud Tasks credentials file not found');
      }

      // Load credentials as a string
      $credentialsData = file_get_contents($gcSettingsFileLocation);

      // Instead of decoding to array, pass the JSON string directly
      return new CloudTasksClient([
        'credentials' => $credentialsData, // Pass the JSON string instead of decoded array
        'transport' => 'grpc'
      ]);
    }
    catch (Exception $e) {
      $this->logger->error('Creating GC Tasks Client failed: @error', ['@error' => $e->getMessage()]);
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
