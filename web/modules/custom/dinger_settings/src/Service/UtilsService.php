<?php

namespace Drupal\dinger_settings\Service;

use DateTimeZone;
use Drupal;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItemInterface;
use Exception;
use Google\Cloud\Core\Timestamp;

class UtilsService
{
  public function generateRandomId($length = 10): string
  {
    $permitted_chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $input_length = strlen($permitted_chars);
    $random_string = '';
    try {
      for ($i = 0; $i < $length; $i++) {
        $random_character = $permitted_chars[random_int(0, $input_length - 1)];
        $random_string .= $random_character;
      }
    } catch (Exception $e) {
      Drupal::logger('utils_service')->error('Utils Random Generator Failed: ' . $e->getMessage());
    }
    return $random_string;
  }

  public function isInThePast(DrupalDateTime $date): bool
  {
    $timezone = new DateTimeZone(DateTimeItemInterface::STORAGE_TIMEZONE);
    $now = new DrupalDateTime('now');
    $now->setTimezone($timezone);
    $date->setTimezone($timezone);
    return $now->getTimestamp() - $date->getTimestamp() >= 0;
  }

  public static function dateTimeToGcTimestamp(DrupalDateTime $dateTime): Timestamp {
    $timezone = new DateTimeZone(DateTimeItemInterface::STORAGE_TIMEZONE);
    $dateTime->setTimezone($timezone);
    return new Timestamp($dateTime->getPhpDateTime());
  }
}
