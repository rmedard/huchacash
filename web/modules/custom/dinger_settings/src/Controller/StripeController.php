<?php

namespace Drupal\dinger_settings\Controller;

use Drupal;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Logger\LoggerChannelFactory;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use UnexpectedValueException;

class StripeController extends ControllerBase
{
  /**
   * Drupal\Core\Logger\LoggerChannelFactory definition.
   *
   * @var LoggerChannelInterface
   */
  protected Drupal\Core\Logger\LoggerChannelInterface $logger;

  /**
   * Enable or disable debugging.
   *
   * @var bool
   */
  protected bool $debug = FALSE;

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

  public function capture(Request $request): Response
  {
    // Keep things fast.
    // Don't load a themed site for the response.
    // Most Webhook providers just want a 200 response.
    $response = new Response();

    // Capture the payload.
    // Option 2: $payload = file_get_contents("php://input");.
    $payload = $request->getContent();

    // Check if it is empty.
    if (empty($payload)) {
      $message = 'The payload was empty.';
      $this->logger->error($message);
      $response->setContent($message);
      return $response;
    }

    // Use temporarily to inspect payload.
    if ($this->debug) {
      $this->logger->debug('<pre>@payload</pre>', ['@payload' => $payload]);
    }

    // Add the $payload to our defined queue.
//    $this->queue->createItem($payload);

    $endpoint_secret = 'whsec_VLRUhTpdlqQhRT3i0CLGLeSsc0Hl6709';
    $sig_header = $request->headers->get('stripe-signature');
    try {
      $event = Webhook::constructEvent($payload, $sig_header, $endpoint_secret);
      switch ($event->type) {
        case 'payment_intent.amount_capturable_updated':
          $this->logger->info('amount_capturable_updated');
          break;
        case 'payment_intent.canceled':
          $this->logger->info('payment_intent.canceled');
          break;
        case 'payment_intent.created':
          $this->logger->info('Payment Created');
          break;
        case 'payment_intent.partially_funded':
          $this->logger->info('payment_intent.partially_funded');
          break;
        case 'payment_intent.payment_failed':
          $this->logger->info('payment_intent.payment_failed');
          break;
        case 'payment_intent.processing':
          $this->logger->info('payment_intent.processing');
          break;
        case 'payment_intent.requires_action':
          $this->logger->info('payment_intent.requires_action');
          break;
        case 'payment_intent.succeeded':
          $paymentIntent = $event->data->jsonSerialize()['object'];
          $customerUuid = $paymentIntent['metadata']['customer_business_id'];
          $amount = doubleval($paymentIntent['amount']) / 100;
          $customer = Drupal::service('entity.repository')->loadEntityByUuid('node', $customerUuid);
          if ($customer instanceof NodeInterface) {
            try {
              Node::create([
                'type' => 'transaction',
                'field_tx_from' => $customer->id(),
                'field_tx_to' => $customer->id(),
                'field_tx_amount' => $amount,
                'field_tx_type' => 'top_up',
                'field_tx_status' => 'confirmed',
                'uid' => $customer->get('field_customer_user')->target_id
              ])->save();
              $balance = doubleval($customer->get('field_customer_available_balance')->getString());
              $customer
                ->set('field_customer_available_balance', $balance + $amount)
                ->save();
              $this->logger->info('Top-up transaction created successfully');
            } catch (EntityStorageException $e) {
              $this->logger->error('Saving top-up transaction failed: ' . $e->getMessage());
            }
          }
          $this->logger->info('Payment Succeeded by: ' . $customerUuid);
          break;
        default:
          echo 'Received unknown event type ' . $event->type;
      }
    } catch (UnexpectedValueException $e) {
      $this->logger->error($e->getMessage());
      $response->setContent('Invalid payload');
      $response->setStatusCode(400);
      return $response;
    } catch (SignatureVerificationException $e) {
      $this->logger->error($e->getMessage());
      $response->setContent('Signature verification failed');
      $response->setStatusCode(400);
      return $response;
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
      $this->logger->info('Stripe endpoint request accepted');
      return AccessResult::allowed();
    }
    $this->logger->error('Stripe endpoint request rejected');
    return AccessResult::forbidden();
  }
}
