<?php
namespace Kanelov\Shipping\Carrier\Econt;

use Kanelov\Shipping\Support\Log;

defined( 'ABSPATH' ) || exit;

/**
 * Номенклатурите на Еконт: градове и офиси/Еконтомати в собствени таблици (за бързо търсене),
 * улици и квартали по град с кеш в transient. Синхронизацията върви през Action Scheduler.
 */
final class EcontNomenclature {

	const AS_GROUP         = 'kanelov-shipping';
	const JOB_SYNC         = 'ks_econt_sync';
	const JOB_SYNC_CITIES  = 'ks_econt_sync_cities';
	const JOB_SYNC_OFFICES = 'ks_econt_sync_offices';
	const OPTION_LAST_SYNC = 'ks_econt_last_sync';

	const COUNTRY = 'BGR';

	public static function table_cities(): string {
		global $wpdb;
		return $wpdb->prefix . 'ks_econt_cities';
	}

	public static function table_offices(): string {
		global $wpdb;
		return $wpdb->prefix . 'ks_econt_offices';
	}

	public function register_jobs(): void {
		add_action( self::JOB_SYNC, [ $this, 'job_sync' ] );
		add_action( self::JOB_SYNC_CITIES, [ $this, 'job_sync_cities' ] );
		add_action( self::JOB_SYNC_OFFICES, [ $this, 'job_sync_offices' ] );
		add_action( 'init', [ $this, 'ensure_schedule' ] );
	}

	/** Ежедневна синхронизация, ако има данни за достъп. */
	public function ensure_schedule(): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}
		if ( ! as_has_scheduled_action( self::JOB_SYNC, [], self::AS_GROUP ) ) {
			as_schedule_recurring_action( strtotime( 'tomorrow 03:30' ), DAY_IN_SECONDS, self::JOB_SYNC, [], self::AS_GROUP );
		}
	}

	/** Пуска синхронизацията веднага (бутон в настройките). */
	public function sync_now(): void {
		as_enqueue_async_action( self::JOB_SYNC_CITIES, [], self::AS_GROUP );
		as_enqueue_async_action( self::JOB_SYNC_OFFICES, [], self::AS_GROUP );
	}

	public function job_sync(): void {
		$this->sync_now();
	}

	public function job_sync_cities(): void {
		$settings = new EcontSettings();
		if ( ! $settings->api()->has_credentials() ) {
			return;
		}
		try {
			$cities = $settings->api()->get_cities( self::COUNTRY );
		} catch ( EcontApiException $e ) {
			Log::error( 'Sync cities failed: ' . $e->getMessage() );
			return;
		}
		$this->store_cities( $cities );
		$this->mark_synced( 'cities', count( $cities ) );
	}

	public function job_sync_offices(): void {
		$settings = new EcontSettings();
		if ( ! $settings->api()->has_credentials() ) {
			return;
		}
		try {
			$offices = $settings->api()->get_offices( self::COUNTRY );
		} catch ( EcontApiException $e ) {
			Log::error( 'Sync offices failed: ' . $e->getMessage() );
			return;
		}
		$this->store_offices( $offices );
		$this->mark_synced( 'offices', count( $offices ) );
	}

	private function mark_synced( string $what, int $count ): void {
		$state          = (array) get_option( self::OPTION_LAST_SYNC, [] );
		$state[ $what ] = [ 'time' => time(), 'count' => $count ];
		update_option( self::OPTION_LAST_SYNC, $state, false );
		Log::info( "Econt {$what} synced", [ 'count' => $count ] );
	}

	public function last_sync(): array {
		return (array) get_option( self::OPTION_LAST_SYNC, [] );
	}

	private function store_cities( array $cities ): void {
		global $wpdb;
		if ( ! $cities ) {
			return;
		}
		$table = self::table_cities();
		$now   = current_time( 'mysql', true );
		$rows  = [];
		foreach ( $cities as $c ) {
			$id = (int) ( $c['id'] ?? 0 );
			if ( ! $id || empty( $c['name'] ) ) {
				continue;
			}
			$rows[] = $wpdb->prepare(
				'(%d,%s,%s,%s,%s,%s,%s,%d,%d,%s)',
				$id,
				(string) ( $c['country']['code3'] ?? self::COUNTRY ),
				(string) ( $c['postCode'] ?? '' ),
				(string) ( $c['type'] ?? '' ),
				(string) $c['name'],
				(string) ( $c['nameEn'] ?? '' ),
				(string) ( $c['regionName'] ?? '' ),
				0,
				0,
				$now
			);
		}
		foreach ( array_chunk( $rows, 500 ) as $chunk ) {
			$wpdb->query( "INSERT INTO {$table} (id,country_code,post_code,type,name,name_en,region_name,has_office,has_aps,updated_at) VALUES " . implode( ',', $chunk ) .
				' ON DUPLICATE KEY UPDATE post_code=VALUES(post_code), type=VALUES(type), name=VALUES(name), name_en=VALUES(name_en), region_name=VALUES(region_name), updated_at=VALUES(updated_at)' );
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE updated_at < %s", $now ) );
		$this->refresh_city_flags();
	}

	private function store_offices( array $offices ): void {
		global $wpdb;
		if ( ! $offices ) {
			return;
		}
		$table = self::table_offices();
		$now   = current_time( 'mysql', true );
		$rows  = [];
		foreach ( $offices as $o ) {
			$id   = (int) ( $o['id'] ?? 0 );
			$code = (string) ( $o['code'] ?? '' );
			if ( ! $id || $code === '' ) {
				continue;
			}
			$hours  = trim( (string) ( $o['normalBusinessHoursFrom'] ?? '' ) . '-' . (string) ( $o['normalBusinessHoursTo'] ?? '' ), '-' );
			$rows[] = $wpdb->prepare(
				'(%d,%s,%d,%d,%d,%s,%s,%s,%f,%f,%s,%s,%s)',
				$id,
				$code,
				(int) ( $o['address']['city']['id'] ?? 0 ),
				! empty( $o['isAPS'] ) ? 1 : 0,
				! empty( $o['isMPS'] ) ? 1 : 0,
				(string) ( $o['name'] ?? '' ),
				(string) ( $o['nameEn'] ?? '' ),
				(string) ( $o['address']['fullAddress'] ?? '' ),
				(float) ( $o['address']['latitude'] ?? 0 ),
				(float) ( $o['address']['longitude'] ?? 0 ),
				implode( ', ', array_map( 'strval', (array) ( $o['phones'] ?? [] ) ) ),
				$hours,
				$now
			);
		}
		foreach ( array_chunk( $rows, 500 ) as $chunk ) {
			$wpdb->query( "INSERT INTO {$table} (id,code,city_id,is_aps,is_mps,name,name_en,address,latitude,longitude,phones,hours,updated_at) VALUES " . implode( ',', $chunk ) .
				' ON DUPLICATE KEY UPDATE code=VALUES(code), city_id=VALUES(city_id), is_aps=VALUES(is_aps), is_mps=VALUES(is_mps), name=VALUES(name), name_en=VALUES(name_en), address=VALUES(address), latitude=VALUES(latitude), longitude=VALUES(longitude), phones=VALUES(phones), hours=VALUES(hours), updated_at=VALUES(updated_at)' );
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE updated_at < %s", $now ) );
		$this->refresh_city_flags();
	}

	private function refresh_city_flags(): void {
		global $wpdb;
		$c = self::table_cities();
		$o = self::table_offices();
		$wpdb->query( "UPDATE {$c} SET has_office = 0, has_aps = 0" );
		$wpdb->query( "UPDATE {$c} c JOIN (SELECT city_id, MAX(is_aps = 0) AS off, MAX(is_aps = 1) AS aps FROM {$o} GROUP BY city_id) x ON x.city_id = c.id SET c.has_office = x.off, c.has_aps = x.aps" );
	}

	// Търсене.

	public function search_cities( string $q, int $limit = 15 ): array {
		global $wpdb;
		$q = trim( $q );
		if ( $q === '' ) {
			return $this->top_cities( $limit );
		}
		$table = self::table_cities();
		$like  = $wpdb->esc_like( $q ) . '%';
		$rows  = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, name, type, post_code, region_name, has_office, has_aps FROM {$table}
			 WHERE name LIKE %s OR name_en LIKE %s OR post_code LIKE %s
			 ORDER BY (has_office + has_aps) DESC, CHAR_LENGTH(name) ASC, name ASC LIMIT %d",
			$like, $like, $like, $limit
		), ARRAY_A );
		return array_map( [ $this, 'format_city' ], (array) $rows );
	}

	public function top_cities( int $limit = 10 ): array {
		global $wpdb;
		$table = self::table_cities();
		$names = [ 'София', 'Пловдив', 'Варна', 'Бургас', 'Русе', 'Стара Загора', 'Плевен', 'Сливен', 'Добрич', 'Шумен' ];
		$in    = implode( ',', array_fill( 0, count( $names ), '%s' ) );
		$rows  = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, name, type, post_code, region_name, has_office, has_aps FROM {$table} WHERE name IN ({$in}) AND type IN ('гр.','') ORDER BY FIELD(name, {$in}) LIMIT %d",
			...array_merge( $names, $names, [ $limit ] )
		), ARRAY_A );
		return array_map( [ $this, 'format_city' ], (array) $rows );
	}

	public function get_city( int $id ): ?array {
		global $wpdb;
		$table = self::table_cities();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT id, name, type, post_code, region_name, has_office, has_aps FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return $row ? $this->format_city( $row ) : null;
	}

	private function format_city( array $r ): array {
		$label = trim( ( $r['type'] ?? '' ) . ' ' . $r['name'] );
		if ( ! empty( $r['region_name'] ) && $r['region_name'] !== $r['name'] ) {
			$label .= ' (' . $r['region_name'] . ')';
		}
		return [
			'id'         => (int) $r['id'],
			'name'       => (string) $r['name'],
			'label'      => $label,
			'post_code'  => (string) $r['post_code'],
			'has_office' => (bool) $r['has_office'],
			'has_aps'    => (bool) $r['has_aps'],
		];
	}

	/** @param bool $aps true = Еконтомати, false = офиси */
	public function offices_in_city( int $city_id, bool $aps ): array {
		global $wpdb;
		$table = self::table_offices();
		$rows  = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, code, name, address, latitude, longitude, hours, is_aps FROM {$table} WHERE city_id = %d AND is_aps = %d ORDER BY name ASC",
			$city_id, $aps ? 1 : 0
		), ARRAY_A );
		return array_map( [ $this, 'format_office' ], (array) $rows );
	}

	public function get_office( string $code ): ?array {
		global $wpdb;
		$table = self::table_offices();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT id, code, city_id, name, address, latitude, longitude, hours, is_aps FROM {$table} WHERE code = %s", $code ), ARRAY_A );
		return $row ? $this->format_office( $row ) : null;
	}

	/** Най-близки офиси/Еконтомати по координати (за бутон „най-близък до мен“). */
	public function nearest( float $lat, float $lng, bool $aps, int $limit = 5 ): array {
		global $wpdb;
		$table = self::table_offices();
		$c     = self::table_cities();
		$rows  = $wpdb->get_results( $wpdb->prepare(
			"SELECT o.id, o.code, o.city_id, o.name, o.address, o.latitude, o.longitude, o.hours, o.is_aps,
			 c.name AS city_name, c.post_code AS city_post_code,
			 (6371 * ACOS( LEAST(1, COS(RADIANS(%f)) * COS(RADIANS(o.latitude)) * COS(RADIANS(o.longitude) - RADIANS(%f)) + SIN(RADIANS(%f)) * SIN(RADIANS(o.latitude)) ) )) AS km
			 FROM {$table} o LEFT JOIN {$c} c ON c.id = o.city_id
			 WHERE o.is_aps = %d AND o.latitude IS NOT NULL AND o.latitude <> 0 ORDER BY km ASC LIMIT %d",
			$lat, $lng, $lat, $aps ? 1 : 0, $limit
		), ARRAY_A );
		return array_map( [ $this, 'format_office' ], (array) $rows );
	}

	private function format_office( array $r ): array {
		return [
			'id'      => (int) $r['id'],
			'code'    => (string) $r['code'],
			'city_id' => isset( $r['city_id'] ) ? (int) $r['city_id'] : 0,
			'name'    => (string) $r['name'],
			'address' => (string) $r['address'],
			'label'   => trim( $r['name'] . ( $r['address'] ? ' – ' . $r['address'] : '' ) ),
			'lat'     => isset( $r['latitude'] ) ? (float) $r['latitude'] : null,
			'lng'     => isset( $r['longitude'] ) ? (float) $r['longitude'] : null,
			'hours'   => (string) ( $r['hours'] ?? '' ),
			'is_aps'  => ! empty( $r['is_aps'] ),
			'km'      => isset( $r['km'] ) ? round( (float) $r['km'], 1 ) : null,
			'city'    => isset( $r['city_name'] ) ? [ 'id' => (int) $r['city_id'], 'name' => (string) $r['city_name'], 'post_code' => (string) ( $r['city_post_code'] ?? '' ) ] : null,
		];
	}

	/** Улици по град, кеширани 7 дни. */
	public function streets( int $city_id, string $q = '', int $limit = 20 ): array {
		return $this->filter_named( $this->cached_list( 'streets', $city_id ), $q, $limit );
	}

	public function quarters( int $city_id, string $q = '', int $limit = 20 ): array {
		return $this->filter_named( $this->cached_list( 'quarters', $city_id ), $q, $limit );
	}

	private function cached_list( string $kind, int $city_id ): array {
		$key  = "ks_econt_{$kind}_{$city_id}";
		$list = get_transient( $key );
		if ( is_array( $list ) ) {
			return $list;
		}
		$settings = new EcontSettings();
		try {
			$items = $kind === 'streets' ? $settings->api()->get_streets( $city_id ) : $settings->api()->get_quarters( $city_id );
		} catch ( EcontApiException $e ) {
			return [];
		}
		$list = [];
		foreach ( $items as $it ) {
			if ( ! empty( $it['name'] ) ) {
				$list[] = [ 'id' => (int) ( $it['id'] ?? 0 ), 'name' => (string) $it['name'] ];
			}
		}
		set_transient( $key, $list, 7 * DAY_IN_SECONDS );
		return $list;
	}

	private function filter_named( array $list, string $q, int $limit ): array {
		$q = mb_strtolower( trim( $q ) );
		if ( $q !== '' ) {
			$list = array_values( array_filter( $list, static fn( $i ) => mb_stripos( $i['name'], $q ) !== false ) );
			usort( $list, static fn( $a, $b ) => ( mb_stripos( $a['name'], $q ) <=> mb_stripos( $b['name'], $q ) ) ?: strcmp( $a['name'], $b['name'] ) );
		}
		return array_slice( $list, 0, $limit );
	}

	/** Проверка дали улица/квартал съществува в номенклатурата (за валидация при поръчка). */
	public function street_exists( int $city_id, string $name ): bool {
		$name = mb_strtolower( trim( $name ) );
		foreach ( $this->cached_list( 'streets', $city_id ) as $s ) {
			if ( mb_strtolower( $s['name'] ) === $name ) {
				return true;
			}
		}
		return false;
	}

	public function quarter_exists( int $city_id, string $name ): bool {
		$name = mb_strtolower( trim( $name ) );
		foreach ( $this->cached_list( 'quarters', $city_id ) as $s ) {
			if ( mb_strtolower( $s['name'] ) === $name ) {
				return true;
			}
		}
		return false;
	}
}
