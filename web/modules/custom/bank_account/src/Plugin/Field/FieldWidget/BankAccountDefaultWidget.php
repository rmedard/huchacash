<?php

namespace Drupal\bank_account\Plugin\Field\FieldWidget;

use Drupal\Core\Field\Attribute\FieldWidget;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Field\WidgetInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

#[FieldWidget(
  id: 'bank_account_widget_default',
  label: new TranslatableMarkup('Bank account default widget'),
  field_types: ['bank_account_item'],
)]
class BankAccountDefaultWidget extends WidgetBase implements WidgetInterface {

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state): array {
    // $item is where the current saved values are stored.
    $item =& $items[$delta];

    $element += array(
      '#type' => 'fieldset',
    );

    $element['country'] = array(
      '#title' => t('Country'),
      '#type' => 'select',
      '#options' => [
        'BE' => $this->t('Belgium'),
        'ES' => $this->t('Spain')
      ],
      '#default_value' => $item->country ?? '',
    );

    $element['account_number'] = array(
      '#title' => t('Account number'),
      '#type' => 'textfield',
      '#default_value' => $item->account_number ?? '',
    );

    $element['official_lastname'] = array(
      '#title' => t('Lastname'),
      '#type' => 'textfield',
      '#default_value' => $item->official_lastname ?? '',
    );

    $element['official_firstname'] = array(
      '#title' => t('Firstname'),
      '#type' => 'textfield',
      '#default_value' => $item->official_firstname ?? '',
    );

    return $element;
  }

}
