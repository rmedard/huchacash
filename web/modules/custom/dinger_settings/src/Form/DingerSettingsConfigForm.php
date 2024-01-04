<?php

namespace Drupal\dinger_settings\Form;

use Drupal\Core\Form\ConfigFormBase;

class DingerSettingsConfigForm extends ConfigFormBase
{

  protected function getEditableConfigNames(): array
  {
    return ['dinger_settings.config'];
  }

  public function getFormId(): string
  {
    return 'dinger_settings_config';
  }
}
