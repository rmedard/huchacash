<?php

namespace Drupal\dinger_settings\Model;

class FileStorageResponse
{

  public private(set) string $publicUrl;
  public private(set) string $downloadToken;
  public private(set) string $storagePath;
  public private(set) string $bucketName;

  /**
   * @param string $publicUrl
   * @param string $downloadToken
   * @param string $storagePath
   * @param string $bucketName
   */
  public function __construct(string $publicUrl, string $downloadToken, string $storagePath, string $bucketName)
  {
    $this->publicUrl = $publicUrl;
    $this->downloadToken = $downloadToken;
    $this->storagePath = $storagePath;
    $this->bucketName = $bucketName;
  }
}
