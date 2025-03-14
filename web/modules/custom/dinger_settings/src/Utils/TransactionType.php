<?php

namespace Drupal\dinger_settings\Utils;

interface TransactionType
{
  const string TOP_UP = 'top_up';
  const string PURCHASE_COST = 'purchase_cost';
  const string DELIVERY_FEE = 'delivery_fee';
  const string SERVICE_FEE = 'service_fee';
  const string FINE = 'fine';
  const string WITHDRAWAL = 'withdrawal';
  const string REFUND = 'refund';
}
