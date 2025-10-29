<?php

namespace Drupal\dinger_settings\Model;

use DateTime;
use Drupal;
use Drupal\dinger_settings\Model\SubModel\Order;
use Drupal\dinger_settings\Model\SubModel\UserDetails;
use Drupal\dinger_settings\Service\GeoHashService;
use Drupal\dinger_settings\Service\UtilsService;
use Drupal\node\Entity\Node;
use Google\Cloud\Core\Timestamp;
use MrShan0\PHPFirestore\Fields\FirestoreGeoPoint;
use MrShan0\PHPFirestore\Fields\FirestoreTimestamp;
use MrShan0\PHPFirestore\FirestoreDocument;

class FireCall {
  public string $id;
  public string $status;
  public Timestamp $expirationTime;

  public DateTime $expirationDateTime;

  public Order $order;

  public UserDetails $caller;
  public UserDetails $executor;
  public int $proposedFee;
  public bool $canBargain;

  public function __construct(Node $call) {
    $this->id = $call->uuid();
    $this->status = $call->get('field_call_status')->getString();

    /** @var Drupal\Core\Datetime\DrupalDateTime $expirationTime */
    $expTime = $call->get('field_call_expiry_time')->date;
    $this->expirationTime = UtilsService::dateTimeToGcTimestamp($expTime);
    $this->expirationDateTime = new DateTime();
    $this->expirationDateTime->setTimestamp($expTime->getTimestamp());

    /** @var Node $orderEntity **/
    $orderEntity = $call->get('field_call_order')->entity;
    $this->order = new Order($orderEntity);
    $this->caller = new UserDetails($orderEntity->get('field_order_creator')->entity);

    if (!$orderEntity->get('field_order_executor')->isEmpty()) {
      $this->executor = new UserDetails($orderEntity->get('field_order_executor')->entity);
    }
    $this->proposedFee = $call->get('field_call_proposed_service_fee')->value;
    $this->canBargain = in_array($call->get('field_call_type')->getString(), ['negotiable', 'open']);
  }

  public function toFirestoreDocument(): FirestoreDocument {
    /** @var GeoHashService $geoHashService **/
    $geoHashService = Drupal::service('dinger_settings.geohash_service');

    $document = new FirestoreDocument();
    $document->setTimestamp('order_delivery_time', new FirestoreTimestamp($this->order->deliveryTime));
    $document->setGeoPoint('delivery_address', new FirestoreGeoPoint($this->order->deliveryAddressLat, $this->order->deliveryAddressLng));
    $document->setString('delivery_address_full', $this->order->deliveryAddress);
    $document->setString('delivery_address_geo_hash', $geoHashService->encodeGeohash([$this->order->deliveryAddressLat, $this->order->deliveryAddressLng]));
    $document->setGeoPoint('pickup_address', new FirestoreGeoPoint($this->order->pickupAddressLat, $this->order->pickupAddressLng));
    $document->setString('pickup_address_full', $this->order->pickupAddress);
    $document->setString('pickup_address_geo_hash', $geoHashService->encodeGeohash([$this->order->pickupAddressLat, $this->order->pickupAddressLng]));
    $document->setTimestamp('expiration_time', new FirestoreTimestamp($this->expirationDateTime));
    $document->setString('order_id', $this->order->id);
    $document->setString('status', $this->status);
    $document->setString('order_type', $this->order->type);
    $document->setString('caller_id', $this->caller->id);
    $document->setString('caller_photo', $this->caller->photo);
    $document->setString('caller_name', $this->caller->lastname);
    $document->setInteger('proposed_fee', $this->proposedFee);
    $document->setDouble('shopping_cost', $this->order->shoppingCost);
    $document->setBoolean('can_bargain', $this->canBargain);
    $document->setInteger('order_number', intval($this->order->nid));
    $document->setInteger('order_confirmation_number', 0);
    return $document;
  }
}
