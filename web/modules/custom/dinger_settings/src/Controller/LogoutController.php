<?php

declare(strict_types=1);

namespace Drupal\dinger_settings\Controller;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactory;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class LogoutController extends ControllerBase {

  protected LoggerChannelInterface $logger;

  protected AccountProxyInterface $loggedUser;

  public function __construct(
    LoggerChannelFactory $loggerFactory,
    EntityTypeManagerInterface $entityTypeManager,
    AccountProxyInterface $loggedUser
  ) {
    $this->logger = $loggerFactory->get('LogoutController');
    $this->entityTypeManager = $entityTypeManager;
    $this->loggedUser = $loggedUser;
  }

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('logger.factory'),
      $container->get('entity_type.manager'),
      $container->get('current_user')
    );
  }

  public function logout(): JsonResponse {
    $this->logger->info('Logout triggered. User logged-in: ' . $this->loggedUser->isAuthenticated());
    if (!$this->loggedUser->isAuthenticated()) {
      return new JsonResponse(['message' => 'User not authenticated'], Response::HTTP_UNAUTHORIZED);
    }

    try {
      $tokenEntityStorage = $this->entityTypeManager()->getStorage('oauth2_token');
      $userTokenIds = $tokenEntityStorage->getQuery()
        ->accessCheck(false)
        ->condition('auth_user_id', $this->loggedUser->id())
        ->execute();
      if (count($userTokenIds) > 0) {
        $userTokens = $tokenEntityStorage->loadMultiple($userTokenIds);
        foreach ($userTokens as $token) {
          $token->delete();
        }
        $this->logger->info('Logout successful');
        return new JsonResponse(['message' => 'Logout successful'], Response::HTTP_OK);
      } else {
        return new JsonResponse(['message' => 'User not logged in'], Response::HTTP_NO_CONTENT);
      }
    } catch (InvalidPluginDefinitionException|PluginNotFoundException|EntityStorageException $e) {
      $this->logger->error('Logout failed: ' . $e->getMessage());
      return new JsonResponse(['message' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }
}
