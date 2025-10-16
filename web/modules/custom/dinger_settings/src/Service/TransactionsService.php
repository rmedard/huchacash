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
    $this->logger->info('Processing delivered order @id transactions.', ['@id' => $order->id()]);
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

    $attributedCall = $order->get('field_order_attributed_call')->entity;
    if ($attributedCall == null) {
      throw new BadRequestHttpException('Order attributed Call should be set.');
    }

    try {
      /** @var NodeStorage $storage * */
      $storage = $this->entityTypeManager->getStorage('node');

      $shoppingCostRef = $order->get('field_order_shopping_total_cost');
      $purchaseCost = $shoppingCostRef->isEmpty() ? 0 : doubleval($shoppingCostRef->getString());
      if ($purchaseCost > 0) {
        $storage->create([
          'type' => 'transaction',
          'field_tx_amount' => $purchaseCost,
          'field_tx_from' => $order->get('field_order_creator')->entity,
          'field_tx_to' => $order->get('field_order_executor')->entity,
          'field_tx_order' => $order,
          'field_tx_type' => TransactionType::PURCHASE_COST->value,
          'field_tx_status' => TransactionStatus::CONFIRMED->value,
        ])->save();
      }

      $totalDeliveryFee = doubleval(trim($attributedCall->get('field_call_proposed_service_fee')->getString()));
      $systemServiceFee = doubleval(trim($attributedCall->get('field_call_system_service_fee')->getString()));
      $effectiveDeliveryFee = $totalDeliveryFee - $systemServiceFee;

      $storage->create([
        'type' => 'transaction',
        'field_tx_amount' => $effectiveDeliveryFee,
        'field_tx_from' => $order->get('field_order_creator')->entity,
        'field_tx_to' => $order->get('field_order_executor')->entity,
        'field_tx_order' => $order,
        'field_tx_type' => TransactionType::DELIVERY_FEE->value,
        'field_tx_status' => TransactionStatus::CONFIRMED->value,
      ])->save();

      $storage->create([
        'type' => 'transaction',
        'field_tx_amount' => $systemServiceFee,
        'field_tx_from' => $order->get('field_order_creator')->entity,
        'field_tx_to' => Node::load($systemCustomer),
        'field_tx_order' => $order,
        'field_tx_type' => TransactionType::SERVICE_FEE->value,
        'field_tx_status' => TransactionStatus::CONFIRMED->value,
      ])->save();

      $this->logger->info('Transactions processed. Attaching them to order @order', ['@order' => $order->id()]);
    } catch (InvalidPluginDefinitionException|EntityStorageException|PluginNotFoundException|MathException $e) {
      $this->logger->error($e);
    }
  }

  public function updateAccountsOnTransactionCreated(Node $transaction): void
  {
    $txStatus = TransactionStatus::tryFrom($transaction->get('field_tx_status')->getString());
    $transactionType = TransactionType::tryFrom($transaction->get('field_tx_type')->getString());
    $txAmount = doubleval($transaction->get('field_tx_amount')->getString());
    try {
      /** @var Node $txInitiator * */
      $txInitiator = $transaction->get('field_tx_from')->entity;
      if ($txInitiator->bundle() !== 'customer') {
        $this->logger->info('Initiator has to be a customer.');
      }
      switch ($txStatus) {
        case TransactionStatus::CONFIRMED:
          if ($transactionType !== TransactionType::TOP_UP) {
            $this->debit($txInitiator, $txAmount, TRUE);
          }

          if ($transactionType !== TransactionType::WITHDRAWAL) {
            /** @var Node $txBeneficiary * */
            $txBeneficiary = $transaction->get('field_tx_to')->entity;
            if ($txBeneficiary->bundle() !== 'customer') {
              $this->logger->info('Beneficiary has to be a customer.');
            }
            $this->credit($txBeneficiary, $txAmount);
          }
          break;
        case TransactionStatus::INITIATED:
          $this->freezeDebit($txInitiator, $txAmount);
          break;
        case TransactionStatus::CANCELLED:
          $this->unfreezeDebit($txInitiator, $txAmount);
          break;
      }
    } catch (EntityStorageException $e) {
      $this->logger->error($e);
    }
  }

  public function updateAccountsOnTransactionUpdated(Node $transaction): void {
    $txStatus = TransactionStatus::tryFrom($transaction->get('field_tx_status')->getString());
    $transactionType = TransactionType::tryFrom($transaction->get('field_tx_type')->getString());
    $txAmount = doubleval($transaction->get('field_tx_amount')->getString());
    try {
      /** @var Node $txInitiator * */
      $txInitiator = $transaction->get('field_tx_from')->entity;
      if ($txStatus !== TransactionStatus::CONFIRMED) {
        /** @var Node $originalTransaction * */
        $originalTransaction = $transaction->original;
        $txStatusChanged = $originalTransaction->get('field_tx_status')->getString() !== $txStatus;
        if ($txStatusChanged) {
          $this->debit($txInitiator, $txAmount, FALSE);
          if ($transactionType !== TransactionType::WITHDRAWAL) {
            /** @var Node $txBeneficiary * */
            $txBeneficiary = $transaction->get('field_tx_to')->entity;
            $this->credit($txBeneficiary, $txAmount);
          }
        }
      } elseif ($txStatus !== TransactionStatus::CANCELLED) {
        $this->unfreezeDebit($txInitiator, $txAmount);
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
      ->save();
  }
}
