<?php

declare(strict_types=1);

namespace Drupal\dinger_settings\EventSubscriber;

use Drupal;
use Drupal\Core\Logger\LoggerChannelFactory;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\simple_oauth\Authentication\Provider\SimpleOauthAuthenticationProvider;
use Exception;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Subscriber meant to catch webform requests and attempt to authenticate them
 */
final readonly class WebformAuthSubscriber implements EventSubscriberInterface {

  /**
   * Drupal\Core\Logger\LoggerChannelFactory definition.
   *
   * @var LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * Constructs a WebformAuthSubscriber object.
   */
  public function __construct(
    private SimpleOauthAuthenticationProvider $authenticationProvider, LoggerChannelFactory $logger
  ) {
    $this->logger = $logger->get('WebformAuthSubscriber');
  }

  public function authenticateRequest(RequestEvent $event): void
  {
    $request = $event->getRequest();

    $paths = ['/form/bug-reporting'];

    // Check if this is a webform submission route
    if (in_array($request->getPathInfo(), $paths)) {
      $this->logger->info('Subscriber triggered by path: ' . $request->getPathInfo());
      if ($request->headers->has('Authorization') and Drupal::currentUser()->isAnonymous()) {
        try {
          $account = $this->authenticationProvider->authenticate($request);
          if ($account) {
            user_login_finalize($account);
            $event->getResponse()->headers->set('Authorization', 'Bearer ' . $request->headers->get('Authorization'));
          }
        }
        catch (Exception $e) {
          $this->logger->error($e);
          $this->logger->error('OAuth authentication failed: @error | Key: @key', ['@error' => $e->getMessage(), '@key' => $request->headers->get('Authorization')]);
//          $response = new JsonResponse(
//            ['error' => 'Invalid OAuth token'],
//            401
//          );
//          $event->setResponse($response);
        }
      } else {
        $this->logger->info('No oauth token found. User logged-in: ' . Drupal::currentUser()->isAuthenticated() ? 'true' : 'false');
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::REQUEST => ['authenticateRequest', 300]
    ];
  }

}
