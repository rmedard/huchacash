<?php

namespace Drupal\dinger_settings\Service;

use DateTimeZone;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItemInterface;
use Google\Cloud\Core\Timestamp;
use Nette\StaticClass;

final class DateUtils
{
  use StaticClass;
  public static function dateTimeToGcTimestamp(DrupalDateTime $dateTime): Timestamp {
    $timezone = new DateTimeZone(DateTimeItemInterface::STORAGE_TIMEZONE);
    $dateTime->setTimezone($timezone);
    return new Timestamp($dateTime->getPhpDateTime());
  }
}
