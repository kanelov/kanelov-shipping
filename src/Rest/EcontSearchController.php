<?php
namespace Kanelov\Shipping\Rest;

use Kanelov\Shipping\Carrier\Econt\EcontNomenclature;

defined( 'ABSPATH' ) || exit;

/**
 * Публични REST маршрути за търсене в номенклатурите на Еконт (нужни на гости в чекаута).
 * Само четене на публични данни, без лични данни, затова без nonce; отговорите са кешируеми.
 */
final class EcontSearchController {

	const NS = 'kanelov-shipping/v1';

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'routes' ] );
	}

	public function routes(): void {
		$public = [ 'permission_callback' => '__return_true', 'methods' => \WP_REST_Server::READABLE ];

		register_rest_route( self::NS, '/econt/cities', $public + [
			'callback' => [ $this, 'cities' ],
			'args'     => [ 'q' => [ 'type' => 'string', 'default' => '' ] ],
		] );
		register_rest_route( self::NS, '/econt/offices', $public + [
			'callback' => [ $this, 'offices' ],
			'args'     => [
				'city_id' => [ 'type' => 'integer', 'required' => true ],
				'type'    => [ 'type' => 'string', 'default' => 'office', 'enum' => [ 'office', 'locker' ] ],
			],
		] );
		register_rest_route( self::NS, '/econt/streets', $public + [
			'callback' => [ $this, 'streets' ],
			'args'     => [ 'city_id' => [ 'type' => 'integer', 'required' => true ], 'q' => [ 'type' => 'string', 'default' => '' ] ],
		] );
		register_rest_route( self::NS, '/econt/quarters', $public + [
			'callback' => [ $this, 'quarters' ],
			'args'     => [ 'city_id' => [ 'type' => 'integer', 'required' => true ], 'q' => [ 'type' => 'string', 'default' => '' ] ],
		] );
		register_rest_route( self::NS, '/econt/nearest', $public + [
			'callback' => [ $this, 'nearest' ],
			'args'     => [
				'lat'  => [ 'type' => 'number', 'required' => true ],
				'lng'  => [ 'type' => 'number', 'required' => true ],
				'type' => [ 'type' => 'string', 'default' => 'office', 'enum' => [ 'office', 'locker' ] ],
			],
		] );
	}

	private function respond( array $data, int $max_age = 3600 ): \WP_REST_Response {
		$r = new \WP_REST_Response( $data );
		$r->header( 'Cache-Control', 'public, max-age=' . $max_age );
		return $r;
	}

	public function cities( \WP_REST_Request $req ): \WP_REST_Response {
		$q = sanitize_text_field( (string) $req['q'] );
		return $this->respond( ( new EcontNomenclature() )->search_cities( $q ) );
	}

	public function offices( \WP_REST_Request $req ): \WP_REST_Response {
		$n = new EcontNomenclature();
		return $this->respond( $n->offices_in_city( (int) $req['city_id'], $req['type'] === 'locker' ) );
	}

	public function streets( \WP_REST_Request $req ): \WP_REST_Response {
		return $this->respond( ( new EcontNomenclature() )->streets( (int) $req['city_id'], sanitize_text_field( (string) $req['q'] ) ) );
	}

	public function quarters( \WP_REST_Request $req ): \WP_REST_Response {
		return $this->respond( ( new EcontNomenclature() )->quarters( (int) $req['city_id'], sanitize_text_field( (string) $req['q'] ) ) );
	}

	public function nearest( \WP_REST_Request $req ): \WP_REST_Response {
		return $this->respond( ( new EcontNomenclature() )->nearest( (float) $req['lat'], (float) $req['lng'], $req['type'] === 'locker' ), 0 );
	}
}
