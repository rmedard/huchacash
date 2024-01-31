<?php

namespace Drupal\dinger_settings\Plugin\rest\resource;

use Drupal;
use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Annotation\Translation;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\node\Entity\Node;
use Drupal\rest\Annotation\RestResource;
use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ResourceResponse;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Provides details of currently logged-in user
 * @RestResource(
 *   id = "me_resource",
 *   label = @Translation("Me Resource"),
 *   uri_paths = {
 *      "canonical" = "/me"
 *   }
 * )
 */
class MeResource extends ResourceBase
{

  protected AccountProxyInterface $loggedUser;

  /**
   * @param array $configuration
   * @param $plugin_id
   * @param $plugin_definition
   * @param array $serializer_formats
   * @param LoggerInterface $logger
   * @param AccountProxyInterface $loggedUser
   */
  public function __construct(array           $configuration,
                                              $plugin_id,
                                              $plugin_definition,
                              array           $serializer_formats,
                              LoggerInterface $logger, AccountProxyInterface $loggedUser)
  {
    $this->loggedUser = $loggedUser;
    parent::__construct($configuration, $plugin_id, $plugin_definition, $serializer_formats, $logger);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): ResourceBase|ContainerFactoryPluginInterface|MeResource|static
  {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->getParameter('serializer.formats'),
      $container->get('logger.factory')->get('me_resource'),
      $container->get('current_user')
    );
  }

  public function get(): ResourceResponse
  {
    $this->logger->info('Me resource triggered. User logged-in: ' . $this->loggedUser->isAuthenticated());
    $response = new ResourceResponse();
    if ($this->loggedUser->isAuthenticated()) {
      $roles = $this->loggedUser->getRoles(true);
      $this->logger->info('/me => roles: <pre><code>' . print_r($roles, true) . '<code></pre>');
      if (in_array('customer', $roles)) {
        try {
          $this->logger->info('Fetching customer');
          $customerIds = Drupal::entityTypeManager()
            ->getStorage('node')->getQuery()->accessCheck()
            ->condition('type', 'customer')
            ->condition('field_customer_user.target_id', $this->loggedUser->id())
            ->execute();
          if (count($customerIds) > 1) {
            $this->logger->info('Multiple customers for user: ' . $this->loggedUser->id());
            $response->setStatusCode(Response::HTTP_NOT_ACCEPTABLE);
            $response->setContent('Illegal state');
          } else if (count($customerIds) == 1) {
            $customerId = reset($customerIds);
            $this->logger->info('Loading customer nid: ' . $customerId);
            $customer = Node::load($customerId);
            $response = new ResourceResponse(['customer_id' => $customer->uuid()]);
          } else {
            $this->logger->info('Customer not found for user: ' . $this->loggedUser->id());
            $response->setStatusCode(Response::HTTP_NOT_FOUND);
            $response->setContent('No customer details found');
          }
        } catch (InvalidPluginDefinitionException|PluginNotFoundException $e) {
          $this->logger->error('Fetching customer failed: ' . $e->getMessage());
          $response->setStatusCode(Response::HTTP_INTERNAL_SERVER_ERROR);
          $response->setContent($e->getMessage());
        } finally {
          $response->addCacheableDependency(['#cache' => ['max-age' => 0]]);
        }
      }
    }
    return $response;
  }

}
