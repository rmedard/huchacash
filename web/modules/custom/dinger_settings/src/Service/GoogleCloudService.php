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
use Drupal\dinger_settings\Plugin\Action\BaseHuchaGcAction;
use Drupal\node\NodeInterface;
use Exception;
use Google\ApiCore\ApiException;
use Google\ApiCore\ValidationException;
use Google\Cloud\Tasks\V2\Client\CloudTasksClient;
use Google\Cloud\Tasks\V2\CreateTaskRequest;
use Google\Cloud\Tasks\V2\DeleteTaskRequest;
use Google\Cloud\Tasks\V2\HttpMethod;
use Google\Cloud\Tasks\V2\HttpRequest;
use Google\Cloud\Tasks\V2\OidcToken;
use Google\Cloud\Tasks\V2\Task;
use Google\Protobuf\Timestamp;
use RuntimeException;

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

  protected bool $clientInitializing = false;

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
  public function getCloudTasksClient(): CloudTasksClient {
    if ($this->cloudTasksClient === null && !$this->clientInitializing) {
      $this->logger->debug('Initializing CloudTasksClient...');
      $this->clientInitializing = true;

      try {
        $gcSettingsFileLocation = Settings::get('gc_tasks_settings_file');

        // Validate the file path
        if (!file_exists($gcSettingsFileLocation) || !is_readable($gcSettingsFileLocation)) {
          throw new RuntimeException("The Google Cloud credentials file is missing or unreadable at: $gcSettingsFileLocation");
        }

        $this->cloudTasksClient = new CloudTasksClient(
          [
            'credentials' => $gcSettingsFileLocation,
            'logger' => $this->logger,
          ]);
        $this->logger->info('CloudTasksClient initialized successfully.');
      } catch (Exception $e) {
        $this->logger->warning('Failed to create GC client => Class: ' . get_class($e));
        $this->logger->error('Failed to initialize CloudTasksClient: @error', ['@error' => $e->getMessage()]);
        throw $e;
      } finally {
        $this->clientInitializing = false;
      }
    }
    return $this->cloudTasksClient;
  }

  /**
   * @throws ValidationException
   * @throws ApiException
   */
  public function createNodeExpirationTask(NodeInterface $targetNode, DrupalDateTime $triggerTime): ?Task
  {
    if ($this->isEligible($targetNode, $triggerTime)) {
      return $this->createGcTask($targetNode, $triggerTime);
    }
    return null;
  }

  /**
   * @throws ValidationException
   * @throws ApiException
   */
  public function updateNodeExpirationTask(NodeInterface $targetNode, DrupalDateTime $triggerTime): ?Task
  {
    if ($this->isEligible($targetNode, $triggerTime)) {
      $taskName = trim($targetNode->get(BaseHuchaGcAction::GC_TASK_FIELD_NAME)->getString());
      $this->deleteGcTask($taskName);
      return $this->createGcTask($targetNode, $triggerTime);
    }
    $this->logger->warning('Expiration Update Not Eligible for (@type:@id)', ['@type' => $targetNode->bundle(), '@id' => $targetNode->id()]);
    return null;
  }

  /**
   * Create a Google Cloud Task.
   *
   * @param NodeInterface $targetNode
   * @param DrupalDateTime $triggerTime
   * @return Task
   * @throws ApiException
   * @throws ValidationException
   */
  private function createGcTask(NodeInterface $targetNode, DrupalDateTime $triggerTime): Task {
    try {
      $this->logger->info('Creating GC Task for Node (@type) ID: @id', ['@type' => $targetNode->bundle(), '@id' => $targetNode->uuid()]);

      $config = $this->configFactory->get('dinger_settings');
      $projectId = $config->get('gc_tasks_project_id');
      $location = $config->get('gc_tasks_location');
      $queue = $config->get('gc_tasks_queue');
      $callbackToken = $config->get('callback_token');

      $formattedParent = CloudTasksClient::queueName($projectId, $location, $queue);

      $scheduleTime = new Timestamp();
      $scheduleTime->fromDateTime($triggerTime->getPhpDateTime());

      $callbackUrl = Drupal::request()->getSchemeAndHttpHost() . '/expire-node/' . $callbackToken;


      $oidcToken = new OidcToken();
      $oidcToken->setServiceAccountEmail('dinger-cash-344019@appspot.gserviceaccount.com');

      $httpRequest = (new HttpRequest())
        ->setOidcToken($oidcToken)
        ->setHttpMethod(HttpMethod::POST)
        ->setUrl($callbackUrl)
        ->setHeaders([
          'Content-Type' => 'application/json',
        ])
        ->setBody(json_encode([
          'uuid' => $targetNode->uuid(),
          'type' => $targetNode->bundle(),
        ]));

      $task = (new Task())
        ->setScheduleTime($scheduleTime)
        ->setHttpRequest($httpRequest);

      $request = (new CreateTaskRequest())
        ->setParent($formattedParent)
        ->setTask($task);

      // Use the safe client initialization
      $client = $this->getCloudTasksClient();
      $result = $client->createTask($request);

      $this->logger->info('Task successfully created for Node ID: @id', ['@id' => $targetNode->uuid()]);

      return $result;
    } catch (Exception $e) {
      $this->logger->error('Error creating GC Task for Node ID @id: @message', [
        '@id' => $targetNode->id(),
        '@message' => $e->getMessage(),
      ]);
      throw $e;
    }
  }

  public function deleteGcTask(string $taskName): void {
    if (empty(trim($taskName))) {
      return;
    }

    $this->logger->info('Deleting DC Task: ' . $taskName);
    try {
      $this
        ->getCloudTasksClient()
        ->deleteTask((new DeleteTaskRequest())->setName($taskName));
    }
    catch (ApiException|ValidationException $e) {
      $this->logger->warning('Deleting GC Task failed. ' . $e->getMessage());
    }
  }

  private function isEligible(NodeInterface $targetNode, DrupalDateTime $triggerTime): bool {
    if (!$targetNode->hasField(BaseHuchaGcAction::GC_TASK_FIELD_NAME)) {
      return FALSE;
    }

    $now = new DrupalDateTime('now');
    $now->setTimezone(new DateTimeZone(DateTimeItemInterface::STORAGE_TIMEZONE));
    return $triggerTime->getTimestamp() > $now->getTimestamp();
  }

}
