<?php

namespace Drupal\dinger_settings\Model;

use DateTime;
use Drupal\dinger_settings\Model\SubModel\UserDetails;
use Drupal\node\Entity\Node;
use MrShan0\PHPFirestore\Fields\FirestoreTimestamp;
use MrShan0\PHPFirestore\FirestoreDocument;

class FireBid {
  public string $id;
  public string $status;
  public string $type;
  public FireCall $call;
  public UserDetails $bidder;
  public int $bargainAmount;
  public DateTime $createdAt;

  public function __construct(Node $bid)
  {
    $this->id = $bid->uuid();
    $this->status = $bid->get('field_bid_status')->getString();
    $this->type = $bid->get('field_bid_type')->getString();
    $this->call = new FireCall($bid->get('field_bid_call')->entity);
    $this->bidder = new UserDetails($bid->get('field_bid_customer')->entity);
    $this->bargainAmount = $bid->get('field_bid_amount')->value;
    try {
      $this->createdAt = new DateTime();
      $this->createdAt->setTimestamp($bid->getCreatedTime());
    } catch (\DateMalformedStringException $e) {
      \Drupal::logger('dinger_settings')->error('Mapping createdAt failed: ' . $e->getMessage());
      $this->createdAt = new DateTime();
    }
  }

  public function toFirestoreDocument(): FireStoreDocument {
    $document = new FirestoreDocument();
    $document->setInteger('bargain_amount', $this->bargainAmount);
    $document->setString('bidder_id', $this->bidder->id);
    $document->setString('bidder_name', $this->bidder->lastname);
    $document->setString('bidder_photo', $this->bidder->photo);
    $document->setInteger('call_amount', $this->call->proposedFee);
    $document->setString('call_id', $this->call->id);
    $document->setString('caller_id', $this->call->caller->id);
    $document->setString('caller_name', $this->call->caller->lastname);
    $document->setString('caller_photo', $this->call->caller->photo);
    $document->setString('status', $this->status);
    $document->setString('type', $this->type);
    $document->setTimestamp('created_at', new FireStoreTimestamp($this->createdAt));
    return $document;
  }
}
