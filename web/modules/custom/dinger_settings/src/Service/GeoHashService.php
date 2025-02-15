<?php

namespace Drupal\dinger_settings\Service;

use Drupal\Core\Logger\LoggerChannelFactory;
use Drupal\Core\Logger\LoggerChannelInterface;

final class GeoHashService {

  /**
   * Drupal\Core\Logger\LoggerChannelFactory definition.
   *
   * @var LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * @param LoggerChannelFactory $logger
   */
  public function __construct(LoggerChannelFactory $logger)
  {
    $this->logger = $logger->get('GeoHashService');
  }

  // Default geohash length
  const int GEOHASH_PRECISION = 10;

  // Characters used in location geohashes
  const string BASE32 = '0123456789bcdefghjkmnpqrstuvwxyz';

  // The meridional circumference of the earth in meters
  const int EARTH_MERI_CIRCUMFERENCE = 40007860;

  // Length of a degree latitude at the equator
  const int METERS_PER_DEGREE_LATITUDE = 110574;

  // Number of bits per geohash character
  const int BITS_PER_CHAR = 5;

  // Maximum length of a geohash in bits
  const int|float MAXIMUM_BITS_PRECISION = 22 * self::BITS_PER_CHAR;

  // Equatorial radius of the earth in meters
  const float EARTH_EQ_RADIUS = 6378137.0;

  // The following value assumes a polar radius of
  // const EARTH_POL_RADIUS = 6356752.3;
  // The formula to calculate E2 is
  // E2 == (EARTH_EQ_RADIUS^2-EARTH_POL_RADIUS^2)/(EARTH_EQ_RADIUS^2)
  // The exact value is used here to avoid rounding errors
  const float E2 = 0.00669447819799;

  // Cutoff for rounding errors on double calculations
  const float EPSILON = 1e-12;


  function log2($x): float|int {
    return log($x) / log(2);
  }

  function validateKey($key): void {
    $error = null;
    if (gettype($key) !== 'string') {
      $error = 'key must be a string';
    } else if (strlen($key) === 0) {
      $error = 'key cannot be the empty string';
    } else if (1 + self::GEOHASH_PRECISION + strlen($key) > 755) {
      // Firebase can only store child paths up to 768 characters
      // The child path for this key is at the least: 'i/<geohash>key'
      $error = 'key is too long to be stored in Firebase';
    } else if (preg_match('/[\[\].#$\/\x{0000}-\x{001F}\x{007F}]/', $key)) {
      // Firebase does not allow node keys to contain the following characters
      $error = 'key cannot contain any of the following characters: . # $ ] [ /';
    }

    if ($error) {
      throw new \Error('Invalid GeoFire key \'' . $key . '\': ' . $error);
    }

  }

  private function validateLocation($location): void {
    $error = null;

    if (!gettype($location) == 'array') {
      $error = 'location must be an array';
    } else if (count($location) !== 2) {
      $error = 'expected array of length 2, got length ' . count($location);
    } else {
      $latitude = $location[0];
      $longitude = $location[1];

      if (!is_numeric($latitude)) {
        $error = 'latitude must be a number';
      } else if ($latitude < -90 || $latitude > 90) {
        $error = 'latitude must be within the range [-90, 90]';
      } else if (!is_numeric($longitude)) {
        $error = 'longitude must be a number';
      } else if ($longitude < -180 || $longitude > 180) {
        $error = 'longitude must be within the range [-180, 180]';
      }
    }

    if ($error) {
      throw new \Error('Invalid GeoFire location \'' . $location . '\': ' . $error);
    }
  }

  function validateGeohash($geohash): void {
    $error = null;

    if (gettype($geohash) !== 'string') {
      $error = 'geohash must be a string';
    } else if (strlen($geohash) === 0) {
      $error = 'geohash cannot be the empty string';
    } else {
      foreach (str_split($geohash) as $letter) {
        if (!str_contains(self::BASE32, $letter)) {
          $error = 'geohash cannot contain \'' . $letter . '\'';
        }
      }
    }

    if ($error) {
      throw new \Error('Invalid GeoFire geohash \'' . $geohash . '\': ' . $error);
    }
  }

  function validateCriteria($newQueryCriteria, $requireCenterAndRadius = false): void {
    if (gettype($newQueryCriteria) !== 'object') {
      throw new \Error('query criteria must be an object');
    } else if (!isset($newQueryCriteria->center) && !isset($newQueryCriteria->radius)) {
      throw new \Error('radius and/or center must be specified');
    } else if ($requireCenterAndRadius && (!isset($newQueryCriteria->center) || !isset($newQueryCriteria->radius))) {
      throw new \Error('query criteria for a new query must contain both a center and a radius');
    }

    // Throw an error if there are any extraneous attributes
    $keys = get_object_vars($newQueryCriteria);
    foreach ($keys as $key) {
      if ($key !== 'center' && $key !== 'radius') {
        throw new \Error('Unexpected attribute \'' . $key . '\' found in query criteria');
      }
    }

    // Validate the 'center' attribute
    if (isset($newQueryCriteria->center)) {
      $this->validateLocation($newQueryCriteria->center);
    }

    // Validate the 'radius' attribute
    if (isset($newQueryCriteria->radius)) {
      if (!is_numeric($newQueryCriteria->radius)) {
        throw new \Error('radius must be a number');
      } else if ($newQueryCriteria->radius < 0) {
        throw new \Error('radius must be greater than or equal to 0');
      }
    }
  }

  function degreesToRadians($degrees): float|int {
    if (!is_numeric($degrees)) {
      throw new \Error('Error: degrees must be a number');
    }
    return ($degrees * pi() / 180);
  }

  function encodeGeohash($location, $precision = self::GEOHASH_PRECISION): string {
    $this->validateLocation($location);
    if (isset($precision)) {
      if (!is_numeric($precision)) {
        throw new \Error('precision must be a number');
      } else if ($precision <= 0) {
        throw new \Error('precision must be greater than 0');
      } else if ($precision > 22) {
        throw new \Error('precision cannot be greater than 22');
      } else if (gettype($precision) !== 'integer') {
        throw new \Error('precision must be an integer');
      }
    }

    $latitudeRange = (object)[
      'min' => -90,
      'max' => 90,
    ];

    $longitudeRange = (object)[
      'min' => -180,
      'max' => 180,
    ];

    $hash = '';
    $hashVal = 0;
    $bits = 0;
    $even = true;

    while (strlen($hash) < $precision) {
      $val = $even ? $location[1] : $location[0];
      $range = $even ? $longitudeRange : $latitudeRange;
      $mid = ($range->min + $range->max) / 2;

      if ($val > $mid) {
        $hashVal = ($hashVal << 1) + 1;
        $range->min = $mid;
      } else {
        $hashVal = ($hashVal << 1) + 0;
        $range->max = $mid;
      }

      $even = !$even;
      if ($bits < 4) {
        $bits++;
      } else {
        $bits = 0;
        $hash .= self::BASE32[$hashVal];
        $hashVal = 0;
      }
    }

    return $hash;
  }

  function metersToLongitudeDegrees($distance, $latitude)
  {
    $radians = $this->degreesToRadians($latitude);
    $num = cos($radians) * self::EARTH_EQ_RADIUS * pi() / 180;
    $denom = 1 / sqrt(1 - self::E2 * sin($radians) * sin($radians));
    $deltaDeg = $num * $denom;
    if ($deltaDeg < self::EPSILON) {
      return $distance > 0 ? 360 : 0;
    } else {
      return min(360, $distance / $deltaDeg);
    }
  }

  function longitudeBitsForResolution($resolution, $latitude)
  {
    $degs = $this->metersToLongitudeDegrees($resolution, $latitude);
    return (abs($degs) > 0.000001) ? max(1, $this->log2(360 / $degs)) : 1;
  }

  function latitudeBitsForResolution($resolution)
  {
    return min($this->log2(self::EARTH_MERI_CIRCUMFERENCE / 2 / $resolution), self::MAXIMUM_BITS_PRECISION);
  }

  function wrapLongitude($longitude)
  {
    if ($longitude <= 180 && $longitude >= -180) {
      return $longitude;
    }

    $adjusted = $longitude + 180;
    if ($adjusted > 0) {
      return ($adjusted % 360) - 180;
    } else {
      return 180 - (-$adjusted % 360);
    }
  }

  function boundingBoxBits($coordinate, $size)
  {
    $latDeltaDegrees = $size / self::METERS_PER_DEGREE_LATITUDE;
    $latitudeNorth = min(90, $coordinate[0] + $latDeltaDegrees);
    $latitudeSouth = max(-90, $coordinate[0] - $latDeltaDegrees);
    $bitsLat = floor($this->latitudeBitsForResolution($size)) * 2;
    $bitsLongNorth = floor($this->longitudeBitsForResolution($size, $latitudeNorth)) * 2 - 1;
    $bitsLongSouth = floor($this->longitudeBitsForResolution($size, $latitudeSouth)) * 2 - 1;
    return min($bitsLat, $bitsLongNorth, $bitsLongSouth, self::MAXIMUM_BITS_PRECISION);
  }

  function boundingBoxCoordinates($center, $radius): array {
    $latDegrees = $radius / self::METERS_PER_DEGREE_LATITUDE;
    $latitudeNorth = min(90, $center[0] + $latDegrees);
    $latitudeSouth = max(-90, $center[0] - $latDegrees);
    $longDegsNorth = $this->metersToLongitudeDegrees($radius, $latitudeNorth);
    $longDegsSouth = $this->metersToLongitudeDegrees($radius, $latitudeSouth);
    $longDegs = max($longDegsNorth, $longDegsSouth);
    return [
      [$center[0], $center[1]],
      [$center[0], $this->wrapLongitude($center[1] - $longDegs)],
      [$center[0], $this->wrapLongitude($center[1] + $longDegs)],
      [$latitudeNorth, $center[1]],
      [$latitudeNorth, $this->wrapLongitude($center[1] - $longDegs)],
      [$latitudeNorth, $this->wrapLongitude($center[1] + $longDegs)],
      [$latitudeSouth, $center[1]],
      [$latitudeSouth, $this->wrapLongitude($center[1] - $longDegs)],
      [$latitudeSouth, $this->wrapLongitude($center[1] + $longDegs)]
    ];
  }

  function geohashQuery($geohash, $bits): array {
    $this->validateGeohash($geohash);
    $precision = ceil($bits / self::BITS_PER_CHAR);
    if (strlen($geohash) < $precision) {
      return [$geohash, $geohash . '~'];
    }
    $geohash = substr($geohash, 0, $precision);
    $base = substr($geohash, 0, strlen($geohash) - 1);
    $lastValue = strpos(self::BASE32, substr($geohash, strlen($geohash) - 1, 0));
    $significantBits = $bits - (strlen($base) * self::BITS_PER_CHAR);
    $unusedBits = (self::BITS_PER_CHAR - $significantBits);
    // delete unused bits
    $startValue = ($lastValue >> $unusedBits) << $unusedBits;
    $endValue = $startValue + (1 << $unusedBits);
    if ($endValue > 31) {
      return [$base . self::BASE32[$startValue], $base . '~'];
    } else {
      return [$base . self::BASE32[$startValue], $base . self::BASE32[$endValue]];
    }
  }

  function geohashQueries($center, $radius): void {
    $this->validateLocation($center);
    $queryBits = max(1, $this->boundingBoxBits($center, $radius));
    $geohashPrecision = ceil($queryBits / self::BITS_PER_CHAR);
    $coordinates = $this->boundingBoxCoordinates($center, $radius);
    $queries = [];
    foreach ($coordinates as $coordinate) {
      $queries[] = $this->geohashQuery($this->encodeGeohash($coordinate, $geohashPrecision), $queryBits);
    }
    // remove duplicates
    $queries = array_unique($queries);
  }

  function encodeGeoFireObject($location, $geohash): object {
    $this->validateLocation($location);
    $this->validateGeohash($geohash);
    return (object)[
      '.priority' => $geohash,
      'g' => $geohash,
      'l' => $location
    ];
  }

  function decodeGeoFireObject($geoFireObj)
  {
    if ($geoFireObj && in_array('l', $geoFireObj) && gettype($geoFireObj->l) == 'array' && count($geoFireObj->l) === 2) {
      return $geoFireObj->l;
    } else {
      $errorMessage = 'Unexpected location object encountered: ' . json_encode($geoFireObj, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
      $this->logger->error($errorMessage);
      throw new \Error($errorMessage);
    }
  }

  function geoFireGetKey($snapshot)
  {
    if (gettype($snapshot->key) === 'string' || $snapshot->key === null) {
      $key = $snapshot->key;
    } else if (is_callable($snapshot->key)) {
      $key = $snapshot->key();
    } else {
      $key = $snapshot->name();
    }

    return $key;
  }

  function distance($location1, $location2): float|int {
    $this->validateLocation($location1);
    $this->validateLocation($location2);

    $radius = 6371; // Earth's radius in kilometers
    $latDelta = $this->degreesToRadians($location2[0] - $location1[0]);
    $lonDelta = $this->degreesToRadians($location2[1] - $location1[1]);

    $a = (sin($latDelta / 2) * sin($latDelta / 2)) +
      (cos($this->degreesToRadians($location1[0])) * cos($this->degreesToRadians($location2[0])) *
        sin($lonDelta / 2) * sin($lonDelta / 2));

    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

    return $radius * $c;
  }
}
