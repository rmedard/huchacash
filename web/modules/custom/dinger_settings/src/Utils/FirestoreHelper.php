<?php

namespace Drupal\dinger_settings\Utils;

use Drupal;
use Exception;
use MrShan0\PHPFirestore\Exceptions\Client\BadRequest;
use MrShan0\PHPFirestore\Exceptions\Client\Conflict;
use MrShan0\PHPFirestore\Exceptions\Client\Forbidden;
use MrShan0\PHPFirestore\Exceptions\Client\NotFound;
use MrShan0\PHPFirestore\Exceptions\Client\Unauthorized;
use MrShan0\PHPFirestore\Exceptions\Server\InternalServerError;
use MrShan0\PHPFirestore\Exceptions\UnhandledRequestError;
use MrShan0\PHPFirestore\FirestoreClient;
use MrShan0\PHPFirestore\FirestoreDocument;

final class FirestoreHelper
{
  /**
   * Query Firestore on a single collection and a single field
   * @param FirestoreClient $firestoreClient
   * @param string $collection
   * @param FirestoreFieldFilter $fieldFilter
   * @param array $options
   * @return array
   */
  public static function queryFirestore(FirestoreClient $firestoreClient, string $collection, FirestoreFieldFilter $fieldFilter, array $options = []): array
  {
    try {
      $structuredQuery = [
        'structuredQuery' => [
          'from' => [
            ['collectionId' => $collection]
          ],
          'where' => [
            'fieldFilter' => [
              'field' => ['fieldPath' => $fieldFilter->getFieldName()],
              'op' => $fieldFilter->getOperator()->value,
              'value' => $fieldFilter->getFieldValue()->toFirestoreValue()
            ]
          ]
        ]
      ];

      self::handleOptions($structuredQuery, $options);
      return self::getDocumentsRequest($firestoreClient, $structuredQuery);

    } catch (Exception $e) {
      Drupal::logger('dinger_settings')->error('Firestore query failed: @message', [
        '@message' => $e->getMessage()
      ]);
      return [];
    }
  }

  /**
   * Helper to query with multiple filters (AND condition)
   * @param FirestoreClient $firestoreClient
   * @param string $collection
   * @param FirestoreFieldFilter[] $filters
   * @param array $options
   * @return array
   */
  public static function queryFirestoreMultiple(FirestoreClient $firestoreClient, string $collection, array $filters, array $options = []): array
  {
    try {
      $filterArray = [];
      foreach ($filters as $filter) {
        $filterArray[] = [
          'fieldFilter' => [
            'field' => ['fieldPath' => $filter->getFieldName()],
            'op' => $filter->getOperator()->value,
            'value' => $filter->getFieldValue()->toFirestoreValue()
          ]
        ];
      }

      $structuredQuery = [
        'structuredQuery' => [
          'from' => [
            ['collectionId' => $collection]
          ],
          'where' => [
            'compositeFilter' => [
              'op' => 'AND',
              'filters' => $filterArray
            ]
          ]
        ]
      ];


      self::handleOptions($structuredQuery, $options);
      return self::getDocumentsRequest($firestoreClient, $structuredQuery);

    } catch (Exception $e) {
      Drupal::logger('dinger_settings')->error('Firestore multiple query failed: @message', [
        '@message' => $e->getMessage()
      ]);
      return [];
    }
  }

  private static function handleOptions(array &$structuredQuery, array $options) : void {
    // Add optional limit
    if (isset($options['limit'])) {
      $structuredQuery['structuredQuery']['limit'] = $options['limit'];
    }

    // Add optional offset
    if (isset($options['offset'])) {
      $structuredQuery['structuredQuery']['offset'] = $options['offset'];
    }

    // Add optional orderBy
    if (isset($options['orderBy'])) {
      $structuredQuery['structuredQuery']['orderBy'] = [
        [
          'field' => ['fieldPath' => $options['orderBy']],
          'direction' => $options['direction'] ?? 'ASCENDING'
        ]
      ];
    }
  }

  /**
   * @throws BadRequest
   * @throws NotFound
   * @throws Forbidden
   * @throws Unauthorized
   * @throws InternalServerError
   * @throws Conflict
   * @throws UnhandledRequestError
   */
  private static function getDocumentsRequest(FirestoreClient $firestoreClient, array $structuredQuery): array
  {
    $response = $firestoreClient->request('POST', 'documents:runQuery', ['json' => $structuredQuery]);

    if (!is_array($response)) {
      return [];
    }

    $documents = [];
    foreach ($response as $result) {
      if (isset($result['document'])) {
        $documents[] = new FirestoreDocument($result['document']);
      }
    }

    return $documents;
  }
}
