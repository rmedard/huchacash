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

  protected ?string $serviceAccountEmail = null;

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

        $credentialsContents = json_decode(file_get_contents($gcSettingsFileLocation), true);
        $this->serviceAccountEmail = $credentialsContents['client_email'];

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
   * @throws ApiException
   */
  public function createNodeExpirationTask(NodeInterface $targetNode, DrupalDateTime $triggerTime): array
  {
    if ($this->isEligible($targetNode, $triggerTime)) {
      return $this->createGcTask($targetNode, $triggerTime);
    }
    return [];
  }

  /**
   * @throws ApiException
   */
  public function updateNodeExpirationTask(NodeInterface $targetNode, DrupalDateTime $triggerTime): array
  {
    if ($this->isEligible($targetNode, $triggerTime)) {
      $taskName = trim($targetNode->get(BaseHuchaGcAction::GC_TASK_FIELD_NAME)->getString());
      if ($targetNode->bundle() === 'order') {
        $callsCleanerTaskName = trim($targetNode->get(BaseHuchaGcAction::GC_TASK_FIELD_NAME_CALLS_CLEANER)->getString());
        $this->deleteGcTask($callsCleanerTaskName);
      }
      $this->deleteGcTask($taskName);
      return $this->createGcTask($targetNode, $triggerTime);
    }
    $this->logger->warning('Expiration Update Not Eligible for (@type:@id)', ['@type' => $targetNode->bundle(), '@id' => $targetNode->id()]);
    return [];
  }

  /**
   * Create a Google Cloud Task.
   *
   * @param NodeInterface $targetNode
   * @param DrupalDateTime $triggerTime
   * @return array
   * @throws ApiException
   */
  private function createGcTask(NodeInterface $targetNode, DrupalDateTime $triggerTime): array
  {
    if ($targetNode->bundle() !== 'order') {
      $this->logger->warning('Attempt to create GC Task for invalid node bundle: (@type:@id)', ['@type' => $targetNode->bundle(), '@id' => $targetNode->id()]);
      return [];
    }

    try {
      $this->logger->info('Creating GC Task for Node (@type) ID: @id', ['@type' => $targetNode->bundle(), '@id' => $targetNode->uuid()]);

      $config = $this->configFactory->get('dinger_settings');
      $projectId = $config->get('gc_tasks_project_id');
      $location = $config->get('gc_tasks_location');
      $queue = $config->get('gc_tasks_queue');
      $callbackToken = $config->get('callback_token');

      $formattedParent = CloudTasksClient::queueName($projectId, $location, $queue);

      $callbackUrl = Drupal::request()->getSchemeAndHttpHost() . '/expire-node/' . $callbackToken;

      $httpRequest = (new HttpRequest());
      $httpRequest = $httpRequest
        ->setHttpMethod(HttpMethod::POST)
        ->setUrl($callbackUrl)
        ->setHeaders([
          'Content-Type' => 'application/json',
        ])
        ->setBody(json_encode([
          'uuid' => $targetNode->uuid(),
          'type' => $targetNode->bundle(),
        ]));

      $scheduleTime = new Timestamp();
      $scheduleTime->fromDateTime($triggerTime->getPhpDateTime());

      $task = (new Task())
        ->setScheduleTime($scheduleTime)
        ->setHttpRequest($httpRequest);

      $request = CreateTaskRequest::build($formattedParent, $task);

      $resultTasks = [];

      // Use the safe client initialization
      $client = $this->getCloudTasksClient();
      $result = $client->createTask($request);

      $resultTasks[BaseHuchaGcAction::GC_TASK_FIELD_NAME] = $result;

      $this->logger->info('Task successfully created for Node ID: @id', ['@id' => $targetNode->uuid()]);

      // Create order calls cleaner task
      if ($targetNode->bundle() === 'order') {
        $url = "https://$location-$projectId.cloudfunctions.net/onOrderExpired";
        $callsCleanerHttpRequest = (new HttpRequest())
          ->setHttpMethod(HttpMethod::POST)
          ->setUrl($url)
          ->setHeaders([
            'Content-Type' => 'application/json',
          ])
          ->setBody(json_encode(['order_id' => $targetNode->uuid()]))
          ->setOidcToken((new OidcToken())
            ->setServiceAccountEmail($this->serviceAccountEmail)
            ->setAudience($url));
        $callsCleanerTask = (new Task())->setScheduleTime($scheduleTime)->setHttpRequest($callsCleanerHttpRequest);
        $callsCleanerRequest = CreateTaskRequest::build($formattedParent, $callsCleanerTask);
        try {
          $cleanerResult = $client->createTask($callsCleanerRequest);
          $resultTasks[BaseHuchaGcAction::GC_TASK_FIELD_NAME_CALLS_CLEANER] = $cleanerResult;
          $this->logger->info('Order calls cleaner task [@name] created for order ID: [@id]', [ '@name' => $cleanerResult->getName(), '@id' => $targetNode->uuid()]);
        } catch (Exception $e) {
          $this->logger->error('Creating order calls cleaner task failed for order ID: @id - @msg', [
            '@id' => $targetNode->uuid(),
            '@msg' => $e->getMessage(),
          ]);
        }
      }

      return $resultTasks;
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

    $this->logger->info('Deleting GC Task: ' . $taskName);
    try {
      $this
        ->getCloudTasksClient()
        ->deleteTask(DeleteTaskRequest::build($taskName));
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
