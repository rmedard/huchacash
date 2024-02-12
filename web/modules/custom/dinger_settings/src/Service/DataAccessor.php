<?php

namespace Drupal\dinger_settings\Service;

use DateTimeZone;
use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactory;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItemInterface;
use Drupal\node\Entity\Node;

class DataAccessor
{

  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Drupal\Core\Logger\LoggerChannelFactory definition.
   *
   * @var LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * @param EntityTypeManagerInterface $entityTypeManager
   * @param LoggerChannelFactory $logger
   */
  public function __construct(EntityTypeManagerInterface $entityTypeManager, LoggerChannelFactory $logger)
  {
    $this->entityTypeManager = $entityTypeManager;
    $this->logger = $logger->get('dinger_settings_data');
  }

  public function getOrdersExpiringInHours(int $hours = 0, $orderStatus = 'any'): array {
    $now = new DrupalDateTime('now');
    $now->setTimezone(new DateTimeZone(DateTimeItemInterface::STORAGE_TIMEZONE));
    $now->format(DateTimeItemInterface::DATETIME_STORAGE_FORMAT);
    if ($hours > 0) {
      $now->modify('+' . $hours .' hour' . ($hours > 1 ? '' : 's'));
    }

    $this->logger->info(t('Fetching orders of type \'@status\' expiring before \'@now\' hours.', ['@status' => $orderStatus, '@now' => $now]));

    try {
      $query = $this->entityTypeManager->getStorage('node')->getQuery()
        ->accessCheck()
        ->condition('type', 'order')
        ->condition('field_order_delivery_time', $now, '<=');

      if ($orderStatus !== 'any') {
        $query->condition('field_order_status', $orderStatus);
      }

      $expiringOrderIds = $query->execute();
      $this->logger->info(t('Expiring @status orders: @count', ['@status' => $orderStatus, '@count' => count($expiringOrderIds)]));
      if (!empty($expiringOrderIds)) {
        return Node::loadMultiple($expiringOrderIds);
      }
    } catch (InvalidPluginDefinitionException $e) {
      $this->logger->error("Plugin definition exception: " . $e->getMessage());
    } catch (PluginNotFoundException $e) {
      $this->logger->error("Plugin not found exception: " . $e->getMessage());
    }
    return [];
  }

  public function getExpiredCalls(): array {
    $now = new DrupalDateTime('now');
    $now->setTimezone(new DateTimeZone(DateTimeItemInterface::STORAGE_TIMEZONE));
    $now->format(DateTimeItemInterface::DATETIME_STORAGE_FORMAT);
    try {
      $query = $this->entityTypeManager->getStorage('node')->getQuery()
        ->accessCheck()
        ->condition('type', 'call')
        ->condition('field_call_expiry_time', $now, '<');
      $expiredCallIds = $query->execute();
      if (!empty($expiredCallIds)) {
        return Node::loadMultiple($expiredCallIds);
      }
    } catch (InvalidPluginDefinitionException $e) {
      $this->logger->error("Plugin definition exception: " . $e->getMessage());
    } catch (PluginNotFoundException $e) {
      $this->logger->error("Plugin not found exception: " . $e->getMessage());
    }
    return [];
  }
}
