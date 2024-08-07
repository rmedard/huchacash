<?php

namespace Drupal\bank_account\Plugin\Field\FieldType;

use Drupal\Core\Field\Attribute\FieldType;
use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;

#[FieldType(
  id: 'bank_account_item',
  label: new TranslatableMarkup('Bank Account'),
  description: new TranslatableMarkup('Bank account item definition.'),
  category: 'bank_account',
  default_widget: 'bank_account_widget_default',
  default_formatter: 'bank_account_formatter_default',
  constraints: [
    'BankAccountConstraint' => [],
    'BankAccountNumberConstraint' => []
  ]
)]
class BankAccountItem extends FieldItemBase implements FieldItemInterface {

  /**
   * {@inheritdoc}
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition): array {
    $output = array();

    $output['columns']['country'] = array(
      'type' => 'varchar',
      'length' => 255,
      'not null' => TRUE,
    );

    $output['columns']['account_number'] = array(
      'type' => 'varchar',
      'length' => 255,
      'not null' => TRUE,
    );

    $output['columns']['official_lastname'] = array(
      'type' => 'varchar',
      'length' => 255,
      'not null' => TRUE,
    );

    $output['columns']['official_firstname'] = array(
      'type' => 'varchar',
      'length' => 255,
      'not null' => TRUE,
    );

    return $output;
  }

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition): array {
    $properties['country'] = DataDefinition::create('string')->setLabel(t('Country'))->setRequired(TRUE);
    $properties['account_number'] = DataDefinition::create('string')->setLabel(t('Account Number'))->setRequired(TRUE);
    $properties['official_lastname'] = DataDefinition::create('string')->setLabel(t('Lastname'))->setRequired(TRUE);
    $properties['official_firstname'] = DataDefinition::create('string')->setLabel(t('Firstname'))->setRequired(TRUE);
    return $properties;
  }

  /**
   * @return \Symfony\Component\Validator\ConstraintViolationListInterface
   */
  public function validate(): \Symfony\Component\Validator\ConstraintViolationListInterface {
    return parent::validate(); // TODO: Change the autogenerated stub
  }

  /**
   * {@inheritdoc}
   */
  public function fieldSettingsForm(array $form, FormStateInterface $form_state): array {
    $element = [];
    $element['country'] = [
      '#type' => 'select',
      '#title' => t('Country'),
      '#options' => [
        'BE' => $this->t('Belgium'),
        'ES' => $this->t('Spain'),
      ],
      '#default_value' => $this->getSetting('country'),
    ];
    $element['account_number'] = [
      '#type' => 'textfield',
      '#title' => t('Account Number'),
      '#default_value' => $this->getSetting('account_number'),
    ];
    $element['official_lastname'] = [
      '#type' => 'textfield',
      '#title' => t('Lastname'),
      '#default_value' => $this->getSetting('official_lastname'),
    ];
    $element['official_firstname'] = [
      '#type' => 'textfield',
      '#title' => t('Firstname'),
      '#default_value' => $this->getSetting('official_firstname'),
    ];
    return $element;
  }

  /**
   * {@inheritdoc}
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  public function isEmpty(): bool {
    $country = $this->get('country')->getValue();
    $accountNumber = $this->get('account_number')->getValue();
    $lastname = $this->get('official_lastname')->getValue();
    $firstname = $this->get('official_firstname')->getValue();
    return $this->isNullOrEmpty($country)
      && $this->isNullOrEmpty($accountNumber)
      && $this->isNullOrEmpty($lastname)
      && $this->isNullOrEmpty($firstname);
  }



  private function isNullOrEmpty(mixed $value): bool {
    return $value === NULL or $value === '';
  }

  public static function defaultFieldSettings(): array {
    return parent::defaultFieldSettings();
  }

}
