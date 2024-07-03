<?php declare(strict_types = 1);

namespace Drupal\dinger_settings\Service;

use Drupal\Core\Logger\LoggerChannelFactory;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Site\Settings;
use Drupal\dinger_settings\Model\FireCall;
use Drupal\node\Entity\Node;
use Google\Cloud\Firestore\FirestoreClient;
use Google\Cloud\Firestore\Transaction;

final class FirestoreCloudService {

  /**
   * Drupal\Core\Logger\LoggerChannelFactory definition.
   *
   * @var LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * @param LoggerChannelFactory $logger
   */
  public function __construct(LoggerChannelFactory $logger)
  {
    $this->logger = $logger->get('firestore_service');
  }
  /**
   * @param $callId
   * @param $orderNumber
   *
   * @throws \Google\Cloud\Core\Exception\GoogleException
   */
  public function setCallOrderNumber($callId, $orderNumber): void {
    $this->logger->info('Firestore Cloud: Setting order number. CallId: @callId | OrderNbr: @orderNbr', ['@callId' => $callId, '@orderNbr' => $orderNumber]);
    $settingsFileLocation = Settings::get('gc_tasks_settings_file');
    $firestoreClient = new FirestoreClient(['keyFilePath' => $settingsFileLocation]);
    $callReference = $firestoreClient->collection('live_calls')->document($callId);
    $firestoreClient->runTransaction(function(Transaction $transaction) use ($callReference, $orderNumber) {
      $transaction->update($callReference, [
        [
          'path' => 'order_confirmation_number',
          'value' => $orderNumber,
        ],
      ]);
    });
  }

  /**
   * @throws \Google\Cloud\Core\Exception\GoogleException
   */
  public function createFireCall(Node $call): void {

    $this->logger->info('Firestore Cloud: Creating fireCall. CallId: @callId', ['@callId' => $call->uuid()]);
    $fireCall = new FireCall($call);

    $this->logger->info('<pre><code>' . print_r($fireCall, TRUE) . '</code></pre>');

    $settingsFileLocation = Settings::get('gc_tasks_settings_file');
    $firestoreClient = new FirestoreClient(['keyFilePath' => $settingsFileLocation]);
    $collectionReference = $firestoreClient->collection('live_calls');
    $result = $collectionReference
      ->document($fireCall->id)
      ->create($fireCall->toFirestoreBody());
    $this->logger->info('<pre><code>' . print_r($result, TRUE) . '</code></pre>');
  }
}
