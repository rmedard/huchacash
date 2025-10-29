<?php

declare(strict_types=1);

namespace Drupal\dinger_settings\Service;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\node\Entity\Node;
use Stripe\Customer;

/**
 * @todo Add class description.
 */
final class CustomersService {

  protected LoggerChannelInterface $logger;
  /**
   * Constructs a CustomersService object.
   */
  public function __construct(
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    $this->logger = $this->loggerFactory->get('CustomersService');
  }

  public function findCustomerByUserId(int $userId): Node|false {
    try {
      $customerIds = $this->entityTypeManager
        ->getStorage('node')->getQuery()
        ->accessCheck(false)
        ->condition('field_customer_user.target_id', $userId)
        ->execute();
      if (count($customerIds) === 0) {
        $this->logger->info('No customer found for user ID ' . $userId);
        return false;
      }
      return Node::load(reset($customerIds));
    } catch (InvalidPluginDefinitionException|PluginNotFoundException $e) {
      $this->logger->error('Fetching customer failed: ' . $e->getMessage());
    }
    return false;
  }

}
