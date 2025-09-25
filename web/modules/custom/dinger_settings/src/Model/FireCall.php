<?php

namespace Drupal\dinger_settings\Model;

use Drupal;
use Drupal\dinger_settings\Model\SubModel\Order;
use Drupal\dinger_settings\Model\SubModel\UserDetails;
use Drupal\dinger_settings\Service\GeoHashService;
use Drupal\dinger_settings\Service\UtilsService;
use Drupal\node\Entity\Node;
use Google\Cloud\Core\Timestamp;

class FireCall {
  public string $id;
  public string $status;
  public Timestamp $expirationTime;

  public Order $order;

  public UserDetails $caller;
  public UserDetails $executor;
  public int $proposedFee;
  public bool $canBargain;

  public function __construct(Node $call) {
    $this->id = $call->uuid();
    $this->status = $call->get('field_call_status')->getString();
    $this->expirationTime = UtilsService::dateTimeToGcTimestamp($call->get('field_call_expiry_time')->date);

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

  public function toFirestoreBody(): array {

    /** @var GeoHashService $geoHashService **/
    $geoHashService = Drupal::service('dinger_settings.geohash_service');
    return [
      'order_delivery_time' => $this->order->deliveryTime,
      'delivery_address' => [
        'latitude' => $this->order->deliveryAddressLat,
        'longitude' => $this->order->deliveryAddressLng
      ],
      'delivery_address_full' => $this->order->deliveryAddress,
      'delivery_address_geo_hash' => $geoHashService->encodeGeohash([$this->order->deliveryAddressLat, $this->order->deliveryAddressLng]),
      'pickup_address' => [
        'latitude' => $this->order->pickupAddressLat,
        'longitude' => $this->order->pickupAddressLng
      ],
      'pickup_address_full' => $this->order->pickupAddress,
      'pickup_address_geo_hash' => $geoHashService->encodeGeohash([$this->order->pickupAddressLat, $this->order->pickupAddressLng]),
      'expiration_time' => $this->expirationTime->formatAsString(),
      'order_id' => $this->order->id,
      'status' => $this->status,
      'order_type' => $this->order->type,
      'caller_id' => $this->caller->id,
      'caller_photo' => $this->caller->photo,
      'caller_name' => $this->caller->lastname,
      'proposed_fee' => $this->proposedFee,
      'shopping_cost' => $this->order->shoppingCost,
      'can_bargain' => $this->canBargain,
      'order_number' => intval($this->order->nid),
      'order_confirmation_number' => 0
    ];
  }
}
