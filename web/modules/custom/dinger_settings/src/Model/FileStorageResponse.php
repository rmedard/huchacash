<?php

namespace Drupal\dinger_settings\Model;

class FileStorageResponse
{

  private string $publicUrl {
    get {
      return $this->publicUrl;
    }
  }
  private string $downloadToken {
    get {
      return $this->downloadToken;
    }
  }
  private string $storagePath {
    get {
      return $this->storagePath;
    }
  }
  private string $bucketName {
    get {
      return $this->bucketName;
    }
  }

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
