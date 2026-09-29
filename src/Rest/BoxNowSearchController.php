<?php
namespace Kanelov\Shipping\Rest;

use Kanelov\Shipping\Carrier\BoxNow\BoxNowLockers;

defined( 'ABSPATH' ) || exit;

/**
 * Публични REST маршрути за търсене на автомати на Box Now (същата форма на отговорите като при Еконт,
 * за да работи общият JavaScript на формата). Само публични данни, отговорите са кешируеми.
 */
final class BoxNowSearchController {

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'routes' ] );
	}

	public function routes(): void {
		$public = [ 'permission_callback' => '__return_true', 'methods' => \WP_REST_Server::READABLE ];

		register_rest_route( EcontSearchController::NS, '/boxnow/cities', $public + [
			'callback' => [ $this, 'cities' ],
			'args'     => [ 'q' => [ 'type' => 'string', 'default' => '' ] ],
		] );
		register_rest_route( EcontSearchController::NS, '/boxnow/offices', $public + [
			'callback' => [ $this, 'lockers' ],
			'args'     => [
				'city_id' => [ 'type' => 'integer', 'default' => 0 ],
				'q'       => [ 'type' => 'string', 'default' => '' ],
				'type'    => [ 'type' => 'string', 'default' => 'locker' ],
			],
		] );
		register_rest_route( EcontSearchController::NS, '/boxnow/nearest', $public + [
			'callback' => [ $this, 'nearest' ],
			'args'     => [
				'lat'  => [ 'type' => 'number', 'required' => true ],
				'lng'  => [ 'type' => 'number', 'required' => true ],
				'type' => [ 'type' => 'string', 'default' => 'locker' ],
			],
		] );
	}

	private function respond( array $data, int $max_age = 600 ): \WP_REST_Response {
		$r = new \WP_REST_Response( $data );
		$r->header( 'Cache-Control', 'public, max-age=' . $max_age );
		return $r;
	}

	public function cities( \WP_REST_Request $req ): \WP_REST_Response {
		return $this->respond( ( new BoxNowLockers() )->search_cities( sanitize_text_field( (string) $req['q'] ) ) );
	}

	public function lockers( \WP_REST_Request $req ): \WP_REST_Response {
		$l = new BoxNowLockers();
		$q = sanitize_text_field( (string) $req['q'] );
		return $this->respond( (int) $req['city_id'] > 0 ? $l->lockers_in_city( (int) $req['city_id'] ) : ( $q !== '' ? $l->search( $q ) : [] ) );
	}

	public function nearest( \WP_REST_Request $req ): \WP_REST_Response {
		return $this->respond( ( new BoxNowLockers() )->nearest( (float) $req['lat'], (float) $req['lng'] ), 0 );
	}
}
