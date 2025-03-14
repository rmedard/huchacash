<?php

namespace Drupal\dinger_settings\Utils;

interface TransactionStatus
{
  const string INITIATED = 'initiated';
  const string CONFIRMED = 'confirmed';
  const string CANCELLED = 'cancelled';
}
