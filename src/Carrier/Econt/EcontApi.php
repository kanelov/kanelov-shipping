<?php
namespace Kanelov\Shipping\Carrier\Econt;

use Kanelov\Shipping\Support\Log;

defined( 'ABSPATH' ) || exit;

/**
 * HTTP клиент за JSON API на Еконт (ee.econt.com/services). HTTP Basic auth, TLS проверката е винаги включена.
 */
final class EcontApi {

	const LIVE_URL = 'https://ee.econt.com/services/';
	const DEMO_URL = 'https://demo.econt.com/ee/services/';

	const EP_COUNTRIES = 'Nomenclatures/NomenclaturesService.getCountries.json';
	const EP_CITIES    = 'Nomenclatures/NomenclaturesService.getCities.json';
	const EP_OFFICES   = 'Nomenclatures/NomenclaturesService.getOffices.json';
	const EP_STREETS   = 'Nomenclatures/NomenclaturesService.getStreets.json';
	const EP_QUARTERS  = 'Nomenclatures/NomenclaturesService.getQuarters.json';
	const EP_PROFILES  = 'Profile/ProfileService.getClientProfiles.json';
	const EP_CREATE    = 'Shipments/LabelService.createLabel.json';
	const EP_UPDATE    = 'Shipments/LabelService.updateLabel.json';
	const EP_DELETE    = 'Shipments/LabelService.deleteLabels.json';
	const EP_STATUSES  = 'Shipments/ShipmentService.getShipmentStatuses.json';
	const EP_COURIER   = 'Shipments/ShipmentService.requestCourier.json';

	public function __construct(
		private string $username,
		private string $password,
		private bool $live = false,
		private int $timeout = 30
	) {}

	public static function from_settings( EcontSettings $settings ): self {
		return new self( $settings->username(), $settings->password(), $settings->is_live(), 30 );
	}

	public function has_credentials(): bool {
		return $this->username !== '' && $this->password !== '';
	}

	public function base_url(): string {
		return $this->live ? self::LIVE_URL : self::DEMO_URL;
	}

	/**
	 * Изпраща заявка и връща декодирания отговор. Хвърля EcontApiException при мрежова или API грешка.
	 *
	 * @param array|\stdClass $body
	 */
	public function call( string $endpoint, $body = [], ?int $timeout = null ): array {
		if ( ! $this->has_credentials() ) {
			throw new EcontApiException( [ __( 'Липсват потребителско име и парола за Еконт.', 'kanelov-shipping' ) ], 'NoCredentials' );
		}

		$url  = $this->base_url() . ltrim( $endpoint, '/' );
		$json = wp_json_encode( $body ?: new \stdClass() );

		$response = wp_remote_post( $url, [
			'timeout'   => $timeout ?? $this->timeout,
			'sslverify' => true,
			'headers'   => [
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
				'Authorization' => 'Basic ' . base64_encode( $this->username . ':' . $this->password ),
			],
			'body'      => $json,
		] );

		if ( is_wp_error( $response ) ) {
			Log::error( 'Econt HTTP error', [ 'endpoint' => $endpoint, 'error' => $response->get_error_message() ] );
			throw new EcontApiException( [ $response->get_error_message() ], 'Network' );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( ! is_array( $data ) ) {
			Log::error( 'Econt invalid JSON', [ 'endpoint' => $endpoint, 'code' => $code, 'body' => mb_substr( $raw, 0, 500 ) ] );
			throw new EcontApiException( [ sprintf( __( 'Невалиден отговор от Еконт (HTTP %d).', 'kanelov-shipping' ), $code ) ], 'InvalidResponse' );
		}

		if ( self::is_error( $data ) || $code >= 400 ) {
			$messages = self::collect_messages( $data );
			if ( ! $messages ) {
				$messages = [ sprintf( __( 'Еконт върна грешка (HTTP %d).', 'kanelov-shipping' ), $code ) ];
			}
			Log::error( 'Econt API error', [ 'endpoint' => $endpoint, 'type' => $data['type'] ?? '', 'messages' => $messages ] );
			throw new EcontApiException( $messages, (string) ( $data['type'] ?? 'Http' . $code ) );
		}

		return $data;
	}

	public static function is_error( array $data ): bool {
		$type = (string) ( $data['type'] ?? '' );
		return $type !== '' && ( str_starts_with( $type, 'Ex' ) || isset( $data['innerErrors'] ) );
	}

	/** Събира съобщенията от вложената структура на грешките на Еконт. */
	public static function collect_messages( array $error ): array {
		$out = [];
		$msg = trim( (string) ( $error['message'] ?? '' ) );
		if ( $msg !== '' ) {
			$out[] = $msg;
		}
		foreach ( (array) ( $error['innerErrors'] ?? [] ) as $inner ) {
			if ( is_array( $inner ) ) {
				$out = array_merge( $out, self::collect_messages( $inner ) );
			}
		}
		return array_values( array_unique( $out ) );
	}

	// Удобни методи.

	public function get_client_profiles(): array {
		return $this->call( self::EP_PROFILES, new \stdClass() );
	}

	public function get_cities( string $country_code = 'BGR' ): array {
		return (array) ( $this->call( self::EP_CITIES, [ 'countryCode' => $country_code ], 120 )['cities'] ?? [] );
	}

	public function get_offices( string $country_code = 'BGR', ?int $city_id = null ): array {
		$body = [ 'countryCode' => $country_code ];
		if ( $city_id ) {
			$body['cityID'] = $city_id;
		}
		return (array) ( $this->call( self::EP_OFFICES, $body, 120 )['offices'] ?? [] );
	}

	public function get_streets( int $city_id ): array {
		return (array) ( $this->call( self::EP_STREETS, [ 'cityID' => $city_id ], 60 )['streets'] ?? [] );
	}

	public function get_quarters( int $city_id ): array {
		return (array) ( $this->call( self::EP_QUARTERS, [ 'cityID' => $city_id ], 60 )['quarters'] ?? [] );
	}

	/** @param 'calculate'|'validate'|'create' $mode */
	public function label( array $label, string $mode ): array {
		return $this->call( self::EP_CREATE, [ 'label' => $label, 'mode' => $mode ], 60 );
	}

	public function delete_labels( array $shipment_numbers ): array {
		return $this->call( self::EP_DELETE, [ 'shipmentNumbers' => array_values( $shipment_numbers ) ] );
	}

	public function get_shipment_statuses( array $shipment_numbers ): array {
		return (array) ( $this->call( self::EP_STATUSES, [ 'shipmentNumbers' => array_values( $shipment_numbers ) ] )['shipmentStatuses'] ?? [] );
	}

	public function request_courier( array $request ): array {
		return $this->call( self::EP_COURIER, $request );
	}
}
