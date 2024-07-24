<?php

namespace Drupal\dinger_settings\Service;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Drupal;
use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactory;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\dinger_settings\Form\DingerSettingsConfigForm;
use Drupal\node\Entity\Node;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class TransactionsService {

  /**
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
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
    $this->logger = $logger->get('TransactionsService');
  }

  public function processDeliveredOrderTransactions(Node $order): void {
    if ($order->bundle() !== 'order') {
      throw new BadRequestHttpException('Node should be an Order.');
    }

    if ($order->get('field_order_status')->getString() !== 'delivered') {
      throw new BadRequestHttpException('Order status should be delivered.');
    }

    $systemCustomer = Drupal::config(DingerSettingsConfigForm::SETTINGS)->get('hucha_system_customer');
    if (!$systemCustomer or !is_numeric($systemCustomer)) {
      throw new BadRequestHttpException('System customer has not been set.');
    }

    try {
      /** @var \Drupal\node\NodeStorage $storage **/
      $storage = $this->entityTypeManager->getStorage('node');

      $shoppingCostRef = $order->get('field_order_shopping_total_cost');
      $purchaseCost = $shoppingCostRef->isEmpty() ? 0 : doubleval($shoppingCostRef->getString());
      if ($purchaseCost > 0) {
        $purchaseCostTxId = $storage->create([
          'type' => 'transaction',
          'field_tx_amount' => $purchaseCost,
          'field_tx_from' => $order->get('field_order_creator')->entity,
          'field_tx_to' => $order->get('field_order_executor')->entity,
          'field_tx_type' => 'purchase_cost',
          'field_tx_status' => 'confirmed'
        ])->save();
        $order->get('field_order_transactions')->appendItem(['target_id' => $purchaseCostTxId]);
      }

      $attributedCall = $order->get('field_order_attributed_call')->entity;
      $totalDeliveryFee = doubleval(trim($attributedCall->get('field_call_proposed_service_fee')->getString()));
      $systemServiceFee = doubleval(trim($attributedCall->get('field_call_system_service_fee')->getString()));
      $effectiveDeliveryFee = $totalDeliveryFee - $systemServiceFee;

      $deliveryFeeTxId = $storage->create([
        'type' => 'transaction',
        'field_tx_amount' => $effectiveDeliveryFee,
        'field_tx_from' => $order->get('field_order_creator')->entity,
        'field_tx_to' => $order->get('field_order_executor')->entity,
        'field_tx_type' => 'delivery_fee',
        'field_tx_status' => 'confirmed'
      ])->save();
      $order->get('field_order_transactions')->appendItem(['target_id' => $deliveryFeeTxId]);

      $systemServiceFeeTxId = $storage->create([
        'type' => 'transaction',
        'field_tx_amount' => $systemServiceFee,
        'field_tx_from' => $order->get('field_order_creator')->entity,
        'field_tx_to' => Node::load($systemCustomer),
        'field_tx_type' => 'service_fee',
        'field_tx_status' => 'confirmed'
      ])->save();
      $order->get('field_order_transactions')->appendItem(['target_id' => $systemServiceFeeTxId]);
    }
    catch (InvalidPluginDefinitionException|EntityStorageException|PluginNotFoundException $e) {
      $this->logger->error($e);
    }
    catch (MathException $e) {
      $this->logger->error($e->getMessage());
    }
  }
}
