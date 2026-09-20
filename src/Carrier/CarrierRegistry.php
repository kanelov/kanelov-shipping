<?php
namespace Kanelov\Shipping\Carrier;

defined( 'ABSPATH' ) || exit;

final class CarrierRegistry {

	/** @var array<string, CarrierInterface> */
	private array $carriers = [];

	public function register( CarrierInterface $carrier ): void {
		$this->carriers[ $carrier->id() ] = $carrier;
	}

	public function get( string $id ): ?CarrierInterface {
		return $this->carriers[ $id ] ?? null;
	}

	/** @return array<string, CarrierInterface> */
	public function all(): array {
		return $this->carriers;
	}
}
