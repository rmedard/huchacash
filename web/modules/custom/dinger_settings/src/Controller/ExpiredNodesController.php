<?php

namespace Drupal\dinger_settings\Controller;

use Drupal;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Logger\LoggerChannelFactory;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\dinger_settings\Utils\GcNodeType;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ExpiredNodesController extends ControllerBase
{
  /**
   * Drupal\Core\Logger\LoggerChannelFactory definition.
   *
   * @var LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

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

  public function __construct(LoggerChannelFactory $logger)
  {
    $this->logger = $logger->get('dinger_settings');
    $this->secret = Drupal::service('config.factory')->get('dinger_settings')->get('callback_token');
  }

  public static function create(ContainerInterface $container): StripeController|static
  {
    return new static(
      $container->get('logger.factory')
    );
  }

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

    $node = Drupal::service('entity.repository')->loadEntityByUuid('node', $uuid);
    if ($node === null or !$node instanceof NodeInterface) {
      $response->setContent($this->t('Node @id not found!', ['@id' => $uuid]));
      $response->setStatusCode(Response::HTTP_NOT_FOUND);
      return $response;
    }

    switch ($type) {
      case GcNodeType::CALL:
        $callStatus = $node->get('field_call_status')->value;
        if ($callStatus == 'live') {
          $this->logger->info($this->t('Call @id has expired', ['@id' => $node->id()]));
          try {
            $node->set('field_call_status', 'expired');
            $node->save();
          } catch (EntityStorageException $e) {
            $this->logger->error('Updating call and/or call failed: ' . $e->getMessage());
          }
        }
        break;
      case GcNodeType::ORDER:
        $orderStatus = $node->get('field_order_status')->value;
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
    $response->setStatusCode(200);
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
