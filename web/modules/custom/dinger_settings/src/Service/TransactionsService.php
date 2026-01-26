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
use Drupal\dinger_settings\Utils\BidType;
use Drupal\dinger_settings\Utils\CallStatus;
use Drupal\dinger_settings\Utils\CallType;
use Drupal\dinger_settings\Utils\OrderType;
use Drupal\dinger_settings\Utils\TransactionStatus;
use Drupal\dinger_settings\Utils\TransactionType;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\node\NodeStorage;
use InvalidArgumentException;
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

      $deliveryServiceFee = doubleval(trim($attributedCall->get('field_call_proposed_service_fee')->getString()));
      $systemServiceFee = doubleval(trim($attributedCall->get('field_call_system_service_fee')->getString()));

      $storage->create([
        'type' => 'transaction',
        'field_tx_amount' => $deliveryServiceFee - $systemServiceFee,
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
      /** @var Node $txInitiator **/
      $txInitiator = $transaction->get('field_tx_from')->entity;
      switch ($txStatus) {
        case TransactionStatus::INITIATED:
          $this->freezeDebit($txInitiator, $txAmount);
          break;
        case TransactionStatus::CONFIRMED:
          /** @var Node $txBeneficiary **/
          $txBeneficiary = $transaction->get('field_tx_to')->entity;
          switch ($transactionType) {
            case TransactionType::TOP_UP:
              $this->credit($txInitiator, $txAmount);
              break;
            case TransactionType::PURCHASE_COST:
            case TransactionType::DELIVERY_FEE:
            case TransactionType::SERVICE_FEE:
            case TransactionType::REFUND:
            case TransactionType::FINE:
              $this->debit($txInitiator, $txAmount);
              $this->credit($txBeneficiary, $txAmount);
              break;
            case TransactionType::WITHDRAWAL:
              $this->debit($txInitiator, $txAmount);
              break;
          }
          break;
        case TransactionStatus::CANCELLED:
          $this->logger->info('Nothing to do. Creating a cancelled transaction.');
          break;
      }
    } catch (EntityStorageException $e) {
      $this->logger->error($e);
    }
  }

  public function updateAccountsOnTransactionUpdated(Node $transaction): void {
    /** @var Node $originalTransaction * */
    $originalTransaction = $transaction->getOriginal();
    $txStatus = TransactionStatus::tryFrom($transaction->get('field_tx_status')->getString());

    $txStatusChanged = $originalTransaction->get('field_tx_status')->getString() !== $txStatus;
    if (!$txStatusChanged) {
      $this->logger->info('Transaction status was not changed business-wise.');
      return;
    }

    try {
      switch ($txStatus) {
        case TransactionStatus::INITIATED:
          throw new InvalidArgumentException('Transaction type cannot be updated to Initiated');
        case TransactionStatus::CANCELLED:
          $this->unfreezeTransactionDebit($transaction, TRUE);
          break;
        case TransactionStatus::CONFIRMED:
          $this->unfreezeTransactionDebit($transaction, FALSE);
          break;
      }
    } catch (EntityStorageException $e) {
      $this->logger->error($e);
    }
  }

  public function freezeCallServiceFee(NodeInterface $call): void {
    /** @var Node $order */
    $order = $call->get('field_call_order')->entity;
    /** @var Node $initiator */
    $initiator = $order->get('field_order_creator')->entity;

    $callStatus = CallStatus::tryFrom($call->get('field_call_status')->getString());
    if (!$callStatus->isFinalState()) {
      $callType = CallType::tryFrom($call->get('field_call_type')->getString());
      if ($callType === CallType::FIXED_PRICE || $callType === CallType::NEGOTIABLE) {
        $proposedServiceFee = doubleval($call->get('field_call_proposed_service_fee')->value);
        if ($callStatus === CallStatus::LIVE) {
          $this->freezeDebit($initiator, $proposedServiceFee);
        }

        if ($callStatus === CallStatus::ATTRIBUTED) {
          /** @var BiddingService $biddingService */
          $biddingService = Drupal::service('hucha_settings.bidding_service');
          $confirmedBid = $biddingService->findCallConfirmedBid($call);
          $bidType = BidType::fromString($confirmedBid->get('field_bid_type')->getString());
          if ($bidType === BidType::BARGAIN) {
            $bidAmount = doubleval($confirmedBid->get('field_bid_amount')->getString());
            $this->freezeDebit($initiator, $bidAmount - $proposedServiceFee);
          }
        }
      }
    }
  }

  public function unfreezeCallServiceFee(NodeInterface $call): void
  {
    $callType = CallType::from($call->get('field_call_type')->getString());
    if ($callType->freezesBalance()) {

      /** @var Node $order */
      $order = $call->get('field_call_order')->entity;
      /** @var Node $initiator */
      $initiator = $order->get('field_order_creator')->entity;

      $proposedServiceFee = doubleval($call->get('field_call_proposed_service_fee')->value);
      $this->unfreezeDebit($initiator, $proposedServiceFee);
    }
  }

  public function freezeOrderShoppingCost(NodeInterface $order): void
  {
    $orderType = OrderType::from($order->get('field_order_type')->getString());
    if ($orderType === OrderType::SHOPPING_DELIVERY) {
      /** @var Node $initiator */
      $initiator = $order->get('field_order_creator')->entity;

      $shoppingCost = doubleval($order->get('field_order_shopping_total_cost')->value);
      $this->freezeDebit($initiator, $shoppingCost);
    }
  }

  public function unfreezeOrderShoppingCost(NodeInterface $order): void
  {
    $orderType = OrderType::from($order->get('field_order_type')->getString());
    if ($orderType === OrderType::SHOPPING_DELIVERY) {
      /** @var Node $initiator */
      $initiator = $order->get('field_order_creator')->entity;

      $shoppingCost = doubleval($order->get('field_order_shopping_total_cost')->value);
      $this->unfreezeDebit($initiator, $shoppingCost);
    }
  }

  public function freezeBargainedServiceFee(Node $bid): void
  {
    $bidAmount = doubleval($bid->get('field_bid_amount')->getString());
    $call = $bid->get('field_bid_call')->entity;
    $callType = CallType::from($call->get('field_call_type')->getString());
    $callAmount = 0;
    if ($callType->freezesBalance()) {
      $callAmount = doubleval($call->get('field_call_proposed_service_fee')->getString());
    }
    $initiator = $call
      ->get('field_call_order')->entity
      ->get('field_order_creator')->entity;
    $this->freezeDebit($initiator, $bidAmount - $callAmount);
  }

  public function unfreezeBargainedServiceFee(Node $bid): void {
    $bidAmount = doubleval($bid->get('field_bid_amount')->getString());
    $initiator = $bid->get('field_bid_call')->entity
      ->get('field_call_order')->entity
      ->get('field_order_creator')->entity;
    $this->unfreezeDebit($initiator, $bidAmount);
  }


  /**
   * Expense. Remove money from account
   * @throws EntityStorageException
   */
  private function debit(Node $customer, float $amount): void
  {
    $frozenBalance = doubleval($customer->get('field_customer_pending_balance')->getString());
    $newFrozenBalance = $frozenBalance < $amount ? 0 : $frozenBalance - $amount;

    $availableBalance = doubleval($customer->get('field_customer_available_balance')->getString());
    $newBalance = $frozenBalance < $amount ? $availableBalance + $frozenBalance - $amount : $availableBalance;
    $customer
      ->set('field_customer_available_balance', $newBalance)
      ->set('field_customer_pending_balance', $newFrozenBalance)
      ->save();
  }

  /**
   * Income. Add money to account
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

  private function freezeDebit(Node $targetCustomer, float $amount): void
  {
    $availableBalance = doubleval($targetCustomer->get('field_customer_available_balance')->getString());
    $newBalance = $availableBalance - $amount;
    $frozenBalance = doubleval($targetCustomer->get('field_customer_pending_balance')->getString());
    $newFrozenBalance = $frozenBalance + $amount;
    try {
      $targetCustomer
        ->set('field_customer_available_balance', $newBalance)
        ->set('field_customer_pending_balance', $newFrozenBalance)
        ->save();
    } catch (EntityStorageException $e) {
      $this->logger->error($e);
    }
  }

  private function unfreezeDebit(Node $targetCustomer, float $amount): void {
    $availableBalance = doubleval($targetCustomer->get('field_customer_available_balance')->getString());
    $newBalance = $availableBalance + $amount;
    $frozenBalance = doubleval($targetCustomer->get('field_customer_pending_balance')->getString());
    $newFrozenBalance = $frozenBalance - $amount;
    try {
      $targetCustomer
        ->set('field_customer_available_balance', $newBalance)
        ->set('field_customer_pending_balance', $newFrozenBalance)
        ->save();
    } catch (EntityStorageException $e) {
      $this->logger->error($e);
    }
  }

  /**
   * @throws EntityStorageException
   */
  private function unfreezeTransactionDebit(Node $transaction, bool $isRolledBack): void
  {
    $txAmount = doubleval($transaction->get('field_tx_amount')->getString());
    $txType = TransactionType::fromString($transaction->get('field_tx_type')->getString());

    /** @var NodeInterface $txInitiator */
    /** @var NodeInterface $txBeneficiary */
    $txInitiator = $transaction->get('field_tx_from')->entity;
    $txBeneficiary = $transaction->get('field_tx_to')->entity;

    $frozenBalance = doubleval($txInitiator->get('field_customer_pending_balance')->getString());
    $newFrozenBalance = $frozenBalance - $txAmount;

    $availableBalance = doubleval($txBeneficiary->get('field_customer_available_balance')->getString());
    $newBalance = $availableBalance + $txAmount;

    if ($txType == TransactionType::TOP_UP || $isRolledBack) {
      $txInitiator
        ->set('field_customer_pending_balance', $newFrozenBalance)
        ->set('field_customer_available_balance', $newBalance)
        ->save();
    } else {
      $txInitiator
        ->set('field_customer_pending_balance', $newFrozenBalance)
        ->save();
      if ($txType->hasBeneficiary()) {
        $txBeneficiary
          ->set('field_customer_available_balance', $newBalance)
          ->save();
      }
    }
  }
}
