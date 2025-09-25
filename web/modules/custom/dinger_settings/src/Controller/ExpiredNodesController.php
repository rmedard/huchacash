<?php

namespace Drupal\dinger_settings\Controller;

use Drupal;
use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Config\ConfigFactory;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactory;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\dinger_settings\Service\FirestoreCloudServiceOld;
use Drupal\dinger_settings\Utils\GcNodeType;
use Drupal\node\NodeInterface;
use Google\Cloud\Core\Exception\GoogleException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ExpiredNodesController extends ControllerBase
{
  /**
   * Drupal\Core\Logger\LoggerChannelFactory definition.
   *
   * @var LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  protected EntityStorageInterface $nodeStorage;

  /**
   * Secret to compare against a passed token.
   *
   * Implementing https://www.drupal.org/project/key
   * is a stronger approach.
   * In this example, you would need $config['dinger_settings']['token'] = 'yourtokeninsettingsphp'; in settings.php.
   *
   * @var string
   */
  protected string $secret;

  /**
   * @throws InvalidPluginDefinitionException
   * @throws PluginNotFoundException
   */
  public function __construct(LoggerChannelFactory $logger, ConfigFactory $configFactory, EntityTypeManagerInterface $entityTypeManager)
  {
    $this->logger = $logger->get('ExpiredNodesController');
    $this->secret = $configFactory->get('dinger_settings')->get('callback_token');
    $this->nodeStorage = $entityTypeManager->getStorage('node');
  }

  /**
   * @throws InvalidPluginDefinitionException
   * @throws PluginNotFoundException
   */
  public static function create(ContainerInterface $container): ExpiredNodesController
  {
    return new ExpiredNodesController(
      $container->get('logger.factory'),
      $container->get('config.factory'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * @throws InvalidPluginDefinitionException
   * @throws PluginNotFoundException
   */
  public function capture(Request $request): Response {
    $response = new Response();
    $payload = $request->getContent();
    if (empty($payload)) {
      $message = 'The payload was empty.';
      $this->logger->error($message);
      $response->setContent($message);
      return $response;
    }

    $decoded = (array) Json::decode($payload);
    $this->logger->debug('<pre><code>' . print_r($decoded, TRUE) . '</code></pre>');
    $uuid = array_key_exists('uuid', $decoded) ? $decoded['uuid'] : '';
    $type = array_key_exists('type', $decoded) ? $decoded['type'] : '';

    if (empty($uuid) || empty($type)) {
      $message = 'Missing uuid or node type. UUID missing: @uuid_missing. Type missing: @type_missing';
      $this->logger->error($message, ['@uuid_missing' => empty($uuid) ? 'true' : 'false', '@type_missing' => empty($type) ? 'true' : 'false']);
      $response->setContent('Missing uuid or node type');
      return $response;
    }

    $entities = $this->nodeStorage->loadByProperties(['uuid' => $uuid]);
    $node = reset($entities);
    if ($node === null or !$node instanceof NodeInterface) {
      $response->setContent($this->t('Node @id not found!', ['@id' => $uuid]));
      $response->setStatusCode(Response::HTTP_NOT_FOUND);
      return $response;
    }

    switch ($type) {
      case GcNodeType::CALL:
        $callStatus = $node->get('field_call_status')->getString();
        if ($callStatus == 'live') {
          $this->logger->info($this->t('Call @id has expired', ['@id' => $node->id()]));
          try {
            $node->set('field_call_status', 'expired');
            $node->save();

            /** @var FirestoreCloudServiceOld $firestoreCloudService **/
            $firestoreCloudService = Drupal::service('dinger_settings.firestore_cloud_service');
            $firestoreCloudService->deleteFireCall($node->uuid());

          } catch (EntityStorageException|GoogleException $e) {
            $this->logger->error('Updating call and/or call failed: ' . $e->getMessage());
          }
        }
        break;
      case GcNodeType::ORDER:
        $orderStatus = $node->get('field_order_status')->getString();
        if ($orderStatus == 'idle') {
          $this->logger->info($this->t('Order @id has expired', ['@id' => $node->id()]));
          try {
            $node->set('field_order_status', 'cancelled');
            $node->save();
          } catch (EntityStorageException $e) {
            $this->logger->error('Updating Order failed: ' . $e->getMessage());
          }
        }
        break;
    }

    $response->setContent('Success!');
    $response->setStatusCode(Response::HTTP_OK);
    return $response;
  }

  /**
   * Simple authorization using a token.
   *
   * @param string $token
   *    A random token only your webhook knows about.
   *
   * @return AccessResult
   *   AccessResult allowed or forbidden.
   */
  public function authorize(string $token): AccessResult
  {
    if ($token === $this->secret) {
      $this->logger->info('Expire node endpoint request accepted');
      return AccessResult::allowed();
    }
    $this->logger->error('Expire node endpoint request rejected');
    return AccessResult::forbidden();
  }
}
