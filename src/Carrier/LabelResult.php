<?php
namespace Kanelov\Shipping\Carrier;

final class LabelResult {

	public bool $success = false;
	/** @var string[] */
	public array $errors = [];
	public string $shipment_number = '';
	/** Пояснение при успех (напр. „вече не съществува в Еконт“). */
	public string $message = '';
	public string $pdf_url = '';
	public ?float $total_price = null;
	public ?float $sender_due = null;
	public ?float $receiver_due = null;
	public string $currency = '';
	public array $raw = [];

	public static function failure( array $errors, array $raw = [] ): self {
		$r          = new self();
		$r->errors  = array_values( array_filter( array_map( 'strval', $errors ) ) );
		$r->raw     = $raw;
		return $r;
	}

	public static function ok( array $raw = [] ): self {
		$r          = new self();
		$r->success = true;
		$r->raw     = $raw;
		return $r;
	}
}
