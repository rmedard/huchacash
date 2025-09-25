<?php

namespace Drupal\dinger_settings\Model\SubModel;

use Drupal\dinger_settings\Service\UtilsService;
use Drupal\node\Entity\Node;

class Order {
  public string $nid;
  public string $id;
  public string $type;
  public String $deliveryTime;
  public float $deliveryAddressLat;
  public float $deliveryAddressLng;
  public string $deliveryAddress;
  public bool $hasPickupAddress;
  public float $pickupAddressLat;
  public float $pickupAddressLng;
  public string $pickupAddress;
  public float $shoppingCost;

  public function __construct(Node $order) {
    $this->nid = $order->id();
    $this->id = $order->uuid();
    $this->type = $order->get('field_order_type')->getString();
    $this->deliveryTime = UtilsService::dateTimeToGcTimestamp($order->get('field_order_delivery_time')->date)->formatAsString();
    $this->deliveryAddressLat = $order->get('field_order_delivery_address')->lat;
    $this->deliveryAddressLng = $order->get('field_order_delivery_address')->lng;
    $this->deliveryAddress = $order->get('field_order_delivery_address_str')->getString();
    $this->hasPickupAddress = true;
    $this->pickupAddressLat = $order->get('field_order_pickup_address')->lat;
    $this->pickupAddressLng = $order->get('field_order_pickup_address')->lng;
    $this->pickupAddress = $order->get('field_order_pickup_address_str')->getString();
    $this->shoppingCost = $order->get('field_order_shopping_total_cost')->value;
  }
}
