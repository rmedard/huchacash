<?php

namespace Drupal\dinger_settings\Controller;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Config\ConfigFactory;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Logger\LoggerChannelFactory;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Stripe\Event;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use UnexpectedValueException;

final class StripeController extends ControllerBase
{
  /**
   * Drupal\Core\Logger\LoggerChannelFactory definition.
   *
   * @var LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

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

  public function __construct(LoggerChannelFactory $logger, ConfigFactory $configFactory)
  {
    $this->logger = $logger->get('StripeController');
    $this->secret = $configFactory->get('dinger_settings')->get('callback_token');
  }

  public static function create(ContainerInterface $container): StripeController
  {
    return new StripeController(
      $container->get('logger.factory'),
      $container->get('config.factory')
    );
  }

  /**
   * @throws InvalidPluginDefinitionException
   * @throws PluginNotFoundException
   */
  public function capture(Request $request): JsonResponse
  {
    $payload = $request->getContent();

    if (empty($payload)) {
      $message = 'The payload was empty.';
      $this->logger->error($message);
      return new JsonResponse(['message' => $message], Response::HTTP_BAD_REQUEST);
    }

    // Use temporarily to inspect payload.
    if ($this->debug) {
      $this->logger->debug('<pre>@payload</pre>', ['@payload' => $payload]);
    }

    $endpoint_secret = 'whsec_VLRUhTpdlqQhRT3i0CLGLeSsc0Hl6709';
    $sig_header = $request->headers->get('stripe-signature');
    try {
      $event = Webhook::constructEvent($payload, $sig_header, $endpoint_secret);
      if ($event->type == Event::PAYMENT_INTENT_SUCCEEDED) {
        $paymentIntent = $event->data->jsonSerialize()['object'];
        $customerUuid = $paymentIntent['metadata']['customer_business_id'];
        $amount = doubleval($paymentIntent['amount']) / 100;
        $customers = $this->entityTypeManager()->getStorage('node')->loadByProperties(['uuid' => $customerUuid]);
        $customer = reset($customers);
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
            $this->logger->info('Top-up transaction created successfully');
          } catch (EntityStorageException $e) {
            $this->logger->error('Saving top-up transaction failed: ' . $e->getMessage());
          }
        } else {
          $this->logger->error('Invalid Customer Data: <pre>' . print_r($customer, TRUE) . '</pre>');
        }
        $this->logger->info('Payment Succeeded by: ' . $customerUuid);
      } else {
        $this->logger->info("Received Stripe Event Type: " . $event->type . ". Not interested in this event.");
      }
    } catch (UnexpectedValueException $e) {
      $this->logger->error($e->getMessage());
      return new JsonResponse(['message' => 'Invalid payload'], Response::HTTP_BAD_REQUEST);
    } catch (SignatureVerificationException $e) {
      $this->logger->error($e->getMessage());
      return new JsonResponse(['message' => 'Signature verification failed'], Response::HTTP_BAD_REQUEST);
    }

    return new JsonResponse(['message' => 'Success!'], Response::HTTP_OK);
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
