<?php
namespace Kanelov\Shipping\Carrier;

final class TrackingResult {

	public bool $success = false;
	/** @var string[] */
	public array $errors = [];
	public string $shipment_number = '';
	public string $status = '';
	public string $delivered_at = '';
	/** @var array<int, array{time:string, event:string, place:string}> */
	public array $events = [];
	public string $tracking_url = '';
	public array $raw = [];
}
