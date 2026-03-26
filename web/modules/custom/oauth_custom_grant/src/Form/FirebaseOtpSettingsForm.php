<?php

namespace Drupal\oauth_custom_grant\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Admin configuration form for the Firebase OTP grant.
 *
 * Accessible at /admin/config/services/simple-oauth-firebase
 */
class FirebaseOtpSettingsForm extends ConfigFormBase {

  const string SETTINGS = 'oauth_custom_grant.firebase_settings';
  protected function getEditableConfigNames(): array {
    return [static::SETTINGS];
  }

  public function getFormId(): string {
    return 'simple_oauth_firebase_settings';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config(static::SETTINGS);

    $form['firebase'] = [
      '#type'  => 'fieldset',
      '#title' => $this->t('Firebase project credentials'),
    ];

    $form['user'] = [
      '#type'  => 'fieldset',
      '#title' => $this->t('User account settings'),
    ];

    $form['user']['auto_create_users'] = [
      '#type'          => 'checkbox',
      '#title'         => $this->t('Auto-create Drupal users on first OTP login'),
      '#description'   => $this->t('If enabled, a new Drupal user is created automatically when a verified Firebase identity has no matching account.'),
      '#default_value' => $config->get('auto_create_users'),
    ];

    $form['user']['default_role'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('Default role for auto-created users'),
      '#description'   => $this->t('Machine name of the role to assign. Leave as "authenticated" to use only the default role.'),
      '#default_value' => $config->get('default_role') ?: 'authenticated',
    ];

    $form['fields'] = [
      '#type'  => 'fieldset',
      '#title' => $this->t('User entity field mapping'),
      '#description' => $this->t('Machine names of the fields that store phone number and Firebase UID on the user entity. Leave blank to disable that field.'),
    ];

    $form['fields']['phone_field'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('Phone number field'),
      '#default_value' => $config->get('phone_field') ?: 'field_phone',
    ];

    $form['fields']['firebase_uid_field'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('Firebase UID field'),
      '#default_value' => $config->get('firebase_uid_field') ?: 'field_firebase_uid',
    ];

    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config(static::SETTINGS)
      ->set('auto_create_users', (bool) $form_state->getValue('auto_create_users'))
      ->set('default_role',      $form_state->getValue('default_role'))
      ->set('phone_field',       $form_state->getValue('phone_field'))
      ->set('firebase_uid_field',$form_state->getValue('firebase_uid_field'))
      ->save();

    parent::submitForm($form, $form_state);
  }
}
