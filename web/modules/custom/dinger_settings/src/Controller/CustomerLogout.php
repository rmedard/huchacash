<?php

declare(strict_types=1);

namespace Drupal\dinger_settings\Controller;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Logger\LoggerChannelFactory;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Session\SessionManagerInterface;
use Drupal\rest\ResourceResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Returns responses for Dinger Settings routes.
 */
final class CustomerLogout extends ControllerBase {

  /**
   * The current user.
   *
   * @var AccountProxyInterface
   */
  protected $currentUser;

  /**
   * Drupal\Core\Logger\LoggerChannelFactory definition.
   *
   * @var LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  protected SessionManagerInterface $sessionManager;

  /**
   * The controller constructor.
   */
  public function __construct(AccountProxyInterface $current_user, SessionManagerInterface $sessionManager, LoggerChannelFactory $loggerFactory) {
    $this->currentUser = $current_user;
    $this->sessionManager = $sessionManager;
    $this->logger = $loggerFactory->get('CustomerLogout');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('current_user'),
      $container->get('session_manager'),
      $container->get('logger.factory')
    );
  }

  /**
   * Builds the response.
   * This route can only be accessed by authenticated users. (See route definition).
   *
   * Note: This does not invalidate oauth token, it only removes browser session.
   */
  public function __invoke(): ResourceResponse
  {
    if (!$this->currentUser->isAnonymous()) {
      if ($this->sessionManager->isStarted()) {
        $this->sessionManager->destroy();
      }
      try {
        $oauthTokenStorage = $this->entityTypeManager()->getStorage('oauth2_token');
        $oauthTokens = $oauthTokenStorage->getQuery()
          ->accessCheck(false)
          ->condition('auth_user_id', $this->currentUser->id())
          ->execute();
        $oauthTokenStorage->delete($oauthTokens);
      } catch (InvalidPluginDefinitionException|PluginNotFoundException|EntityStorageException $e) {
        $this->logger->error($e->getMessage());
      }
      $response = new ResourceResponse();
      $response->setStatusCode(Response::HTTP_OK);
      $response->setContent('You have been successfully logged out.');
      $response->headers->set('Content-Type', 'application/json');
      $response->addCacheableDependency(['#cache' => ['max-age' => 0]]);
      return $response;
    }
    $this->logger->error('User is not logged in.');
    return new ResourceResponse('User is not logged in.', Response::HTTP_UNAUTHORIZED);
  }

}
