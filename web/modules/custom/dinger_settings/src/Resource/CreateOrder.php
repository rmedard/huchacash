<?php

namespace Drupal\dinger_settings\Resource;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Session\AccountInterface;
use Drupal\jsonapi\JsonApiResource\JsonApiDocumentTopLevel;
use Drupal\jsonapi\ResourceResponse;
use Drupal\jsonapi_resources\Resource\EntityQueryResourceBase;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 *
 */
class CreateOrder extends EntityQueryResourceBase implements ContainerInjectionInterface
{

  protected AccountInterface $currentUser;

  /**
   * @param AccountInterface $current_user
   */
  public function __construct(AccountInterface $current_user)
  {
    $this->currentUser = $current_user;
  }

  /**
   * @param ContainerInterface $container
   * @return CreateOrder
   */
  public static function create(ContainerInterface $container): CreateOrder
  {
    return new static(
      $container->get('current_user')
    );
  }

  /**
   * @throws EntityStorageException
   * @throws InvalidPluginDefinitionException
   */
  public function process(Request $request, JsonApiDocumentTopLevel $document): ResourceResponse {
    return $this->processEntityCreation($request, $document);
  }

  protected function modifyCreatedEntity(EntityInterface $created_entity, Request $request): void
  {
    assert($created_entity instanceof NodeInterface);
    $created_entity->setOwnerId($this->currentUser->id());
  }

}
