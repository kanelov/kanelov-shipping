<?php
namespace Kanelov\Shipping\Carrier\BoxNow;

use Kanelov\Shipping\Support\Log;

defined( 'ABSPATH' ) || exit;

/**
 * Автоматите на Box Now в собствена таблица (за бързо търсене в чекаута). Синхронизация нощем през Action Scheduler
 * от публичния JSON на Box Now; ако той не е достъпен, от /destinations с токен.
 * Населеното място се извлича от данните на автомата, а id-то му е хеш на името (Box Now няма номенклатура на градове).
 */
final class BoxNowLockers {

	const AS_GROUP         = 'kanelov-shipping';
	const JOB_SYNC         = 'ks_boxnow_sync';
	const OPTION_LAST_SYNC = 'ks_boxnow_last_sync';

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ks_boxnow_lockers';
	}

	public function register_jobs(): void {
		add_action( self::JOB_SYNC, [ $this, 'job_sync' ] );
		add_action( 'init', [ $this, 'ensure_schedule' ] );
	}

	public function ensure_schedule(): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) || get_transient( 'ks_boxnow_schedule_checked' ) ) {
			return;
		}
		set_transient( 'ks_boxnow_schedule_checked', 1, HOUR_IN_SECONDS );
		if ( ! as_has_scheduled_action( self::JOB_SYNC, [], self::AS_GROUP ) ) {
			as_schedule_recurring_action( strtotime( 'tomorrow 03:50' ), DAY_IN_SECONDS, self::JOB_SYNC, [], self::AS_GROUP );
		}
	}

	/** Пуска синхронизацията във фонов режим (бутон в настройките, обновяване на плъгина). */
	public function sync_now(): void {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::JOB_SYNC, [], self::AS_GROUP );
		}
	}

	/** Синхронизация веднага, в текущата заявка. Връща броя автомати или хвърля. */
	public function sync(): int {
		$settings = new BoxNowSettings();
		try {
			$lockers = BoxNowApi::fetch_locations( $settings->is_live() );
		} catch ( BoxNowApiException $e ) {
			Log::error( 'BoxNow locations JSON failed, falling back to /destinations: ' . $e->getMessage() );
			if ( ! $settings->is_configured() ) {
				throw $e;
			}
			$lockers = $settings->api()->destinations();
		}
		$count = $this->store( $lockers );
		$state = [ 'time' => time(), 'count' => $count ];
		update_option( self::OPTION_LAST_SYNC, $state, false );
		Log::info( 'BoxNow lockers synced', [ 'count' => $count ] );
		return $count;
	}

	public function job_sync(): void {
		try {
			$this->sync();
		} catch ( BoxNowApiException $e ) {
			Log::error( 'Sync BoxNow lockers failed: ' . $e->getMessage() );
		}
	}

	public function last_sync(): array {
		return (array) get_option( self::OPTION_LAST_SYNC, [] );
	}

	private function store( array $lockers ): int {
		global $wpdb;
		$table = self::table();
		$now   = current_time( 'mysql', true );
		$rows  = [];
		foreach ( $lockers as $l ) {
			if ( ! is_array( $l ) ) {
				continue;
			}
			$id = (string) ( $l['id'] ?? '' );
			if ( $id === '' ) {
				continue;
			}
			$type = (string) ( $l['type'] ?? 'apm' );
			if ( $type !== '' && $type !== 'apm' ) {
				continue; // само автомати за доставка (без складове/any-apm)
			}
			$city   = self::derive_city( $l );
			$rows[] = $wpdb->prepare(
				'(%s,%s,%s,%s,%s,%s,%d,%f,%f,%s,%s,%s)',
				$id,
				trim( (string) ( $l['name'] ?? $l['title'] ?? '' ) ),
				trim( (string) ( $l['addressLine1'] ?? '' ) ),
				trim( (string) ( $l['addressLine2'] ?? '' ) ),
				trim( (string) ( $l['postalCode'] ?? '' ) ),
				$city,
				self::city_id( $city ),
				(float) ( $l['lat'] ?? $l['latitude'] ?? 0 ),
				(float) ( $l['lng'] ?? $l['longitude'] ?? 0 ),
				trim( (string) ( $l['note'] ?? '' ) ),
				(string) wp_json_encode( $l, JSON_UNESCAPED_UNICODE ),
				$now
			);
		}
		if ( ! $rows ) {
			return 0;
		}
		foreach ( array_chunk( $rows, 300 ) as $chunk ) {
			$wpdb->query( "INSERT INTO {$table} (id,name,address,address2,post_code,city,city_id,latitude,longitude,note,raw,updated_at) VALUES " . implode( ',', $chunk ) .
				' ON DUPLICATE KEY UPDATE name=VALUES(name), address=VALUES(address), address2=VALUES(address2), post_code=VALUES(post_code), city=VALUES(city), city_id=VALUES(city_id), latitude=VALUES(latitude), longitude=VALUES(longitude), note=VALUES(note), raw=VALUES(raw), updated_at=VALUES(updated_at)' );
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE updated_at < %s", $now ) );
		return count( $rows );
	}

	/**
	 * Населеното място на автомата. Box Now дава го в различни полета според версията на списъка;
	 * взима се първото непразно: city, addressLine2 (без пощенски код), region, накрая титлата преди запетая.
	 */
	public static function derive_city( array $l ): string {
		foreach ( [ 'city', 'cityName', 'addressLine2', 'municipality', 'region' ] as $key ) {
			$v = trim( (string) ( $l[ $key ] ?? '' ) );
			$v = trim( preg_replace( '/\b\d{4}\b/u', '', $v ) ?? $v, " ,-\t" );
			if ( $v !== '' && ! preg_match( '/^[a-z]{2}-[A-Z]{2}$/', $v ) ) {
				return self::normalize_city( $v );
			}
		}
		$title = (string) ( $l['title'] ?? '' );
		if ( str_contains( $title, ',' ) ) {
			$parts = explode( ',', $title );
			$last  = trim( (string) end( $parts ) );
			$last = trim( preg_replace( '/\b\d{4}\b/u', '', $last ) ?? $last, " ,-\t" );
			if ( $last !== '' ) {
				return self::normalize_city( $last );
			}
		}
		return '';
	}

	/** „гр. София“, „СОФИЯ“, „Sofia“ → „София“ (по възможност еднакъв запис за един град). */
	public static function normalize_city( string $city ): string {
		$city = trim( preg_replace( '/^(гр\.|с\.|град|село)\s*/ui', '', $city ) ?? $city );
		$city = preg_replace( '/\s+/u', ' ', $city ) ?? $city;
		if ( mb_strtoupper( $city ) === $city && mb_strlen( $city ) > 2 ) {
			$city = mb_convert_case( mb_strtolower( $city ), MB_CASE_TITLE );
		}
		return $city;
	}

	/** Числово id на населеното място от името (стабилно между синхронизации). */
	public static function city_id( string $city ): int {
		if ( $city === '' ) {
			return 0;
		}
		return ( crc32( mb_strtolower( $city ) ) & 0x7fffffff ) ?: 1;
	}

	// Търсене.

	public function search_cities( string $q, int $limit = 15 ): array {
		global $wpdb;
		$table = self::table();
		$q     = trim( $q );
		$where = $q === '' ? '' : $wpdb->prepare( 'AND (city LIKE %s OR post_code LIKE %s)', $wpdb->esc_like( $q ) . '%', $wpdb->esc_like( $q ) . '%' );
		$rows  = $wpdb->get_results( $wpdb->prepare(
			"SELECT city_id, city, MIN(post_code) AS post_code, COUNT(*) AS cnt FROM {$table} WHERE city <> '' {$where} GROUP BY city_id, city ORDER BY cnt DESC, city ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$limit
		), ARRAY_A );
		return array_map( [ $this, 'format_city' ], (array) $rows );
	}

	public function get_city( int $id ): ?array {
		global $wpdb;
		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT city_id, city, MIN(post_code) AS post_code, COUNT(*) AS cnt FROM {$table} WHERE city_id = %d GROUP BY city_id, city", $id ), ARRAY_A );
		return $row && ! empty( $row['city'] ) ? $this->format_city( $row ) : null;
	}

	private function format_city( array $r ): array {
		return [
			'id'         => (int) $r['city_id'],
			'name'       => (string) $r['city'],
			'label'      => (string) $r['city'],
			'post_code'  => (string) ( $r['post_code'] ?? '' ),
			'has_office' => false,
			'has_aps'    => true,
			'count'      => (int) ( $r['cnt'] ?? 0 ),
		];
	}

	public function lockers_in_city( int $city_id ): array {
		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id, name, address, address2, post_code, city, city_id, latitude, longitude, note FROM {$table} WHERE city_id = %d ORDER BY name ASC", $city_id ), ARRAY_A );
		return array_map( [ $this, 'format_locker' ], (array) $rows );
	}

	/** Търсене по име/адрес във всички автомати (когато няма избран град). */
	public function search( string $q, int $limit = 20 ): array {
		global $wpdb;
		$table = self::table();
		$like  = '%' . $wpdb->esc_like( trim( $q ) ) . '%';
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id, name, address, address2, post_code, city, city_id, latitude, longitude, note FROM {$table} WHERE name LIKE %s OR address LIKE %s OR city LIKE %s ORDER BY city, name LIMIT %d", $like, $like, $like, $limit ), ARRAY_A );
		return array_map( [ $this, 'format_locker' ], (array) $rows );
	}

	public function get( string $id ): ?array {
		global $wpdb;
		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT id, name, address, address2, post_code, city, city_id, latitude, longitude, note FROM {$table} WHERE id = %s", $id ), ARRAY_A );
		return $row ? $this->format_locker( $row ) : null;
	}

	public function nearest( float $lat, float $lng, int $limit = 5 ): array {
		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, name, address, address2, post_code, city, city_id, latitude, longitude, note,
			 (6371 * ACOS( LEAST(1, COS(RADIANS(%f)) * COS(RADIANS(latitude)) * COS(RADIANS(longitude) - RADIANS(%f)) + SIN(RADIANS(%f)) * SIN(RADIANS(latitude)) ) )) AS km
			 FROM {$table} WHERE latitude <> 0 ORDER BY km ASC LIMIT %d",
			$lat, $lng, $lat, $limit
		), ARRAY_A );
		return array_map( [ $this, 'format_locker' ], (array) $rows );
	}

	public function count(): int {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() );
	}

	/** Същата форма като офисите на Еконт, за да работи общият JavaScript. */
	private function format_locker( array $r ): array {
		$address = trim( (string) $r['address'] );
		return [
			'id'      => (string) $r['id'],
			'code'    => (string) $r['id'],
			'city_id' => (int) $r['city_id'],
			'name'    => (string) $r['name'],
			'address' => $address,
			'label'   => trim( $r['name'] . ( $address !== '' && $address !== $r['name'] ? ' – ' . $address : '' ) ),
			'lat'     => (float) $r['latitude'] ?: null,
			'lng'     => (float) $r['longitude'] ?: null,
			'hours'   => (string) ( $r['note'] ?? '' ),
			'is_aps'  => true,
			'km'      => isset( $r['km'] ) ? round( (float) $r['km'], 1 ) : null,
			'city'    => [ 'id' => (int) $r['city_id'], 'name' => (string) $r['city'], 'post_code' => (string) $r['post_code'] ],
		];
	}
}
