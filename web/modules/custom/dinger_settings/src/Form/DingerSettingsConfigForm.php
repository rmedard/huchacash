<?php

namespace Drupal\dinger_settings\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\Entity\Node;

/**
 * DingerSettingsConfigForm global configuration form
 */
class DingerSettingsConfigForm extends ConfigFormBase
{

  const string SETTINGS = 'dinger_settings.global_settings';

  protected function getEditableConfigNames(): array
  {
    return [static::SETTINGS];
  }

  public function getFormId(): string
  {
    return 'dinger_settings_config';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config(static::SETTINGS);
    $immutableConfig = $this->configFactory->get(static::SETTINGS);
    $overrides = $this->getOverriddenKeys();

    if (!empty($overrides)) {
      $form['override_notice'] = [
        '#type' => 'markup',
        '#markup' => '<div class="messages messages--warning">' . $this->t('Some settings are overridden in settings.php and cannot be edited here.') . '</div>',
        '#weight' => -100,
      ];
    }

    $customerOverridden = in_array('hucha_system_customer', $overrides);
    $default_hucha_system_customer = NULL;
    $customerValue = $customerOverridden
      ? $immutableConfig->get('hucha_system_customer')
      : $config->get('hucha_system_customer');
    if (!empty($customerValue)) {
      $default_hucha_system_customer = Node::load(intval($customerValue));
    }

    if ($customerOverridden) {
      $customerLabel = $default_hucha_system_customer
        ? $default_hucha_system_customer->label() . ' (' . $customerValue . ')'
        : $customerValue;
      $form['hucha_system_customer'] = [
        '#type' => 'textfield',
        '#title' => $this->t('System Customer'),
        '#default_value' => $customerLabel,
        '#disabled' => TRUE,
        '#description' => $this->t('Overridden in settings.php.'),
      ];
    }
    else {
      $form['hucha_system_customer'] = [
        '#type' => 'entity_autocomplete',
        '#title' => $this->t('System Customer'),
        '#target_type' => 'node',
        '#selection_handler' => 'views',
        '#required' => TRUE,
        '#selection_settings' => [
          'view' => [
            'view_name' => 'admin_customers',
            'display_name' => 'entity_reference_admin_customers',
            'arguments' => []
          ],
          'match_operator' => 'CONTAINS',
        ],
        '#default_value' => $default_hucha_system_customer,
        '#description' => $this->t('Enter a customer to be used as SYSTEM.'),
      ];
    }

    $feeRateOverridden = in_array('hucha_base_service_fee_rate', $overrides);
    $form['hucha_base_service_fee_rate'] = [
      '#type' => 'number',
      '#min' => 0,
      '#precision' => 5,
      '#scale' => 2,
      '#step' => '.50',
      '#required' => !$feeRateOverridden,
      '#disabled' => $feeRateOverridden,
      '#title' => $this->t('Base System Service Fee (%)'),
      '#default_value' => $feeRateOverridden
        ? $immutableConfig->get('hucha_base_service_fee_rate')
        : $config->get('hucha_base_service_fee_rate'),
      '#description' => $feeRateOverridden
        ? $this->t('Overridden in settings.php.')
        : $this->t('Enter a valid percentage value.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  private function getOverriddenKeys(): array {
    if (isset($GLOBALS['config'][static::SETTINGS]) && is_array($GLOBALS['config'][static::SETTINGS])) {
      return array_keys($GLOBALS['config'][static::SETTINGS]);
    }
    return [];
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $overrides = $this->getOverriddenKeys();

    if (!in_array('hucha_system_customer', $overrides)) {
      $customer_id = $form_state->getValue('hucha_system_customer');
      if ($customer_id) {
        $node = Node::load($customer_id);
        if (!$node || $node->bundle() !== 'customer') {
          $form_state->setErrorByName('hucha_system_customer', $this->t('The selected node (ID: @id) is not a valid customer.', ['@id' => $customer_id]));
        }
      }
    }

    if (!in_array('hucha_base_service_fee_rate', $overrides)) {
      $fee_rate = $form_state->getValue('hucha_base_service_fee_rate');
      if ($fee_rate !== NULL && $fee_rate !== '' && (floatval($fee_rate) < 0 || floatval($fee_rate) > 100)) {
        $form_state->setErrorByName('hucha_base_service_fee_rate', $this->t('The service fee rate must be between 0 and 100.'));
      }
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config(static::SETTINGS);
    $overrides = $this->getOverriddenKeys();

    if (!in_array('hucha_system_customer', $overrides)) {
      $config->set('hucha_system_customer', $form_state->getValue('hucha_system_customer'));
    }
    if (!in_array('hucha_base_service_fee_rate', $overrides)) {
      $config->set('hucha_base_service_fee_rate', $form_state->getValue('hucha_base_service_fee_rate'));
    }
    $config->save();
    parent::submitForm($form, $form_state);
  }
}
