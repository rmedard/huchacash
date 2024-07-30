<?php

namespace Drupal\bank_account\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\Attribute\FieldFormatter;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;

#[FieldFormatter(
  id: 'bank_account_formatter_default',
  label: new TranslatableMarkup('Bank Account Default'),
  field_types: ['bank_account_item'],
)]
class BankAccountDefaultFormatter extends FormatterBase {

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode): array {
    $output = array();
    foreach ($items as $delta => $item) {
      $output[$delta] = ['#markup' => $item->official_lastname . ' ' . $item->official_firstname . '<br>' . $item->account_number . ' (' . $item->country . ')'];
    }
    return $output;
  }

}
