<?php

namespace Drupal\dinger_settings\Service;

use Drupal\Core\Site\Settings;
use Google\Cloud\Firestore\FirestoreClient;
use Google\Cloud\Firestore\Transaction;

class FirestoreCloudService {

  /**
   * @param $callId
   * @param $orderNumber
   *
   * @throws \Google\Cloud\Core\Exception\GoogleException
   */
  public function setCallOrderNumber($callId, $orderNumber): void {
    $settingsFileLocation = Settings::get('gc_tasks_settings_file');
//    $credentialsData = file_get_contents($gcSettingsFileLocation);
//    $credentialsArray = json_decode($credentialsData, true);
    $firestoreClient = new FirestoreClient(['keyFilePath' => $settingsFileLocation]);
    $callReference = $firestoreClient->collection('calls')->document($callId);
    $firestoreClient->runTransaction(function(Transaction $transaction) use ($callReference, $orderNumber) {
      $transaction->update($callReference, [
        [
          'path' => 'order_confirmation_number',
          'value' => $orderNumber,
        ],
      ]);
    });
  }

}
