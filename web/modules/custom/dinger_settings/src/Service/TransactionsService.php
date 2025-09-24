<?php

namespace Drupal\dinger_settings\Service;

use Brick\Math\Exception\MathException;
use Drupal;
use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactory;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\dinger_settings\Form\DingerSettingsConfigForm;
use Drupal\dinger_settings\Utils\TransactionStatus;
use Drupal\dinger_settings\Utils\TransactionType;
use Drupal\node\Entity\Node;
use Drupal\node\NodeStorage;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class TransactionsService
{

  /**
   * @var EntityTypeManagerInterface
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

  public function processDeliveredOrderTransactions(Node $order): void
  {
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
      /** @var NodeStorage $storage * */
      $storage = $this->entityTypeManager->getStorage('node');

      $shoppingCostRef = $order->get('field_order_shopping_total_cost');
      $purchaseCost = $shoppingCostRef->isEmpty() ? 0 : doubleval($shoppingCostRef->getString());
      if ($purchaseCost > 0) {
        $purchaseCostTxId = $storage->create([
          'type' => 'transaction',
          'field_tx_amount' => $purchaseCost,
          'field_tx_from' => $order->get('field_order_creator')->entity,
          'field_tx_to' => $order->get('field_order_executor')->entity,
          'field_tx_type' => TransactionType::PURCHASE_COST,
          'field_tx_status' => TransactionStatus::CONFIRMED,
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
        'field_tx_type' => TransactionType::DELIVERY_FEE,
        'field_tx_status' => TransactionStatus::CONFIRMED,
      ])->save();
      $order->get('field_order_transactions')->appendItem(['target_id' => $deliveryFeeTxId]);

      $systemServiceFeeTxId = $storage->create([
        'type' => 'transaction',
        'field_tx_amount' => $systemServiceFee,
        'field_tx_from' => $order->get('field_order_creator')->entity,
        'field_tx_to' => Node::load($systemCustomer),
        'field_tx_type' => TransactionType::SERVICE_FEE,
        'field_tx_status' => TransactionStatus::CONFIRMED,
      ])->save();
      $order->get('field_order_transactions')->appendItem(['target_id' => $systemServiceFeeTxId]);

      $this->logger->info('Transactions processed. Attaching them to order @order', ['@order' => $order->id()]);

      /**
       * Prevent hooks from firing during this save. VERY IMPORTANT!!
       */
      $order->setSyncing(true);
      $order->save();
    } catch (InvalidPluginDefinitionException|EntityStorageException|PluginNotFoundException|MathException $e) {
      $this->logger->error($e);
    }
  }

  public function updateAccountsOnTransactionPresave(Node $transaction): void
  {
    $txStatus = $transaction->get('field_tx_status')->getString();
    $transactionType = $transaction->get('field_tx_type')->getString();
    $txAmount = doubleval($transaction->get('field_tx_amount')->getString());
    try {
      switch ($txStatus) {
        case TransactionStatus::CONFIRMED:
          if ($transaction->isNew()) {
            if ($transactionType !== TransactionType::TOP_UP) {
              /** @var Node $txInitiator * */
              $txInitiator = $transaction->get('field_tx_from')->entity;
              $this->debit($txInitiator, $txAmount, TRUE);
            }

            if ($transactionType !== TransactionType::WITHDRAWAL) {
              /** @var Node $txBeneficiary * */
              $txBeneficiary = $transaction->get('field_tx_to')->entity;
              $this->credit($txBeneficiary, $txAmount);
            }
          } else {
            /** @var Node $originalTransaction * */
            $originalTransaction = $transaction->original;
            $txStatusChanged = $originalTransaction->get('field_tx_status')->getString() !== $txStatus;
            if ($txStatusChanged) {
              /** @var Node $txInitiator * */
              $txInitiator = $transaction->get('field_tx_from')->entity;
              $this->debit($txInitiator, $txAmount, FALSE);

              if ($transactionType !== TransactionType::WITHDRAWAL) {
                /** @var Node $txBeneficiary * */
                $txBeneficiary = $transaction->get('field_tx_to')->entity;
                $this->credit($txBeneficiary, $txAmount);
              }
            }
          }
          break;
        case TransactionStatus::INITIATED:
          /** @var Node $txInitiator * */
          $txInitiator = $transaction->get('field_tx_from')->entity;
          $this->freezeDebit($txInitiator, $txAmount);
          break;
        case TransactionStatus::CANCELLED:
          /** @var Node $txInitiator * */
          $txInitiator = $transaction->get('field_tx_from')->entity;
          $this->unfreezeDebit($txInitiator, $txAmount);
          break;
      }
    } catch (EntityStorageException $e) {
      $this->logger->error($e);
    }
  }

  /**
   * @throws EntityStorageException
   */
  private function debit(Node $customer, float $amount, bool $isDirectDebit): void
  {
    $accountName = $isDirectDebit ? 'field_customer_available_balance' : 'field_customer_pending_balance';
    $availableBalance = doubleval($customer->get($accountName)->getString());
    $newBalance = $availableBalance - $amount;
    $customer
      ->set($accountName, $newBalance)
      ->setSyncing(TRUE)
      ->save();
  }

  /**
   * @throws EntityStorageException
   */
  private function credit(Node $customer, float $amount): void
  {
    $availableBalance = doubleval($customer->get('field_customer_available_balance')->getString());
    $newBalance = $availableBalance + $amount;
    $customer
      ->set('field_customer_available_balance', $newBalance)
      ->setSyncing(TRUE)
      ->save();
  }

  /**
   * @throws EntityStorageException
   */
  private function freezeDebit(Node $customer, float $amount): void
  {
    $availableBalance = doubleval($customer->get('field_customer_available_balance')->getString());
    $newBalance = $availableBalance - $amount;
    $frozenBalance = doubleval($customer->get('field_customer_pending_balance')->getString());
    $newFrozenBalance = $frozenBalance + $amount;
    $customer
      ->set('field_customer_available_balance', $newBalance)
      ->set('field_customer_pending_balance', $newFrozenBalance)
      ->setSyncing(TRUE)
      ->save();
  }

  /**
   * @throws EntityStorageException
   */
  private function unfreezeDebit(Node $customer, float $amount): void
  {
    $availableBalance = doubleval($customer->get('field_customer_available_balance')->getString());
    $newBalance = $availableBalance + $amount;
    $frozenBalance = doubleval($customer->get('field_customer_pending_balance')->getString());
    $newFrozenBalance = $frozenBalance - $amount;
    $customer
      ->set('field_customer_available_balance', $newBalance)
      ->set('field_customer_pending_balance', $newFrozenBalance)
      ->setSyncing(TRUE) // Do not trigger hooks on customer
      ->save();
  }
}
