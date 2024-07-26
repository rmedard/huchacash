<?php

namespace Drupal\dinger_settings\Plugin\Action;

use Drupal\Core\Access\AccessResultAllowed;
use Drupal\Core\Access\AccessResultForbidden;
use Drupal\Core\Action\ActionBase;
use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\dinger_settings\Service\TransactionsService;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

#[Action(
  id: 'update_accounts_tx_presave_action',
  label: new TranslatableMarkup('Update accounts on transaction presave action'),
  category: new TranslatableMarkup('Custom'),
  type: 'node'
)]
class UpdateAccountsOnTransactionPresaveAction extends ActionBase implements ContainerFactoryPluginInterface {

  /**
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * @var \Drupal\dinger_settings\Service\TransactionsService
   */
  protected TransactionsService $transactionsService;

  public function __construct(array $configuration, $plugin_id, $plugin_definition, LoggerChannelFactoryInterface $loggerFactory, TransactionsService $transactionsService) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->logger = $loggerFactory->get('UpdateAccountsOnTxPresaveAction');
    $this->transactionsService = $transactionsService;
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): UpdateAccountsOnTransactionPresaveAction {
    return new UpdateAccountsOnTransactionPresaveAction(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('logger.factory'),
      $container->get('hucha_settings.transactions_service')
    );
  }

  public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE): AccessResultForbidden|AccessResultAllowed {
    $isAllowed = $object instanceof NodeInterface && $object->bundle() == 'transaction';
    return $isAllowed ? new AccessResultAllowed() : new AccessResultForbidden();
  }
  public function execute(NodeInterface $transaction = NULL): void {
    $this->logger->info('Executing update accounts on transaction. Id: ' . $transaction->uuid());
    $this->transactionsService->updateAccountsOnTransactionPresave($transaction);
  }
}
