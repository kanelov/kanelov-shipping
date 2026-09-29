<?php
namespace Kanelov\Shipping\Carrier\BoxNow;

defined( 'ABSPATH' ) || exit;

/**
 * Глобалните настройки на Box Now (WooCommerce > Доставка > Box Now), option `woocommerce_ks_boxnow_settings`.
 */
final class BoxNowSettings {

	const OPTION = 'woocommerce_' . BoxNowShippingMethod::ID . '_settings';

	/** Кеш на данните от Box Now след „Тест на връзката“: складове и разрешения на профила. */
	const OPTION_PROFILE = 'ks_boxnow_profile';

	private array $data;

	public function __construct( ?array $data = null ) {
		$this->data = $data ?? (array) get_option( self::OPTION, [] );
	}

	public function get( string $key, $default = '' ) {
		$value = $this->data[ $key ] ?? $default;
		return is_string( $value ) ? trim( $value ) : $value;
	}

	public function bool( string $key, bool $default = false ): bool {
		$v = $this->data[ $key ] ?? null;
		if ( $v === null || $v === '' ) {
			return $default;
		}
		return in_array( $v, [ 'yes', '1', 1, true ], true );
	}

	public function float( string $key, float $default = 0.0 ): float {
		$v = str_replace( ',', '.', (string) ( $this->data[ $key ] ?? '' ) );
		return is_numeric( $v ) ? (float) $v : $default;
	}

	public function client_id(): string {
		return (string) $this->get( 'client_id' );
	}

	public function client_secret(): string {
		return (string) $this->get( 'client_secret' );
	}

	public function partner_id(): string {
		return (string) $this->get( 'partner_id' );
	}

	public function is_live(): bool {
		return $this->get( 'environment', 'production' ) !== 'stage';
	}

	public function api(): BoxNowApi {
		return BoxNowApi::from_settings( $this );
	}

	public function is_configured(): bool {
		return $this->api()->has_credentials();
	}

	/** locationId на склада/магазина, от който Box Now взима пратките (от /origins). */
	public function origin_id(): string {
		return (string) $this->get( 'origin_id' );
	}

	public function sender_name(): string {
		return (string) $this->get( 'sender_name' ) ?: (string) get_bloginfo( 'name' );
	}

	public function sender_phone(): string {
		return (string) $this->get( 'sender_phone' );
	}

	public function sender_email(): string {
		return (string) $this->get( 'sender_email' ) ?: (string) get_option( 'admin_email' );
	}

	/** Имейл, на който Box Now праща PDF етикета след приета заявка ('' = не праща). */
	public function notify_email(): string {
		return (string) $this->get( 'notify_email' );
	}

	public function admins_only(): bool {
		return $this->bool( 'admins_only', false );
	}

	public function map_enabled(): bool {
		return $this->bool( 'map_enabled', true );
	}

	public function allow_cod(): bool {
		return $this->bool( 'allow_cod', true );
	}

	/** Box Now събира наложен платеж до 5000. */
	public function cod_max(): float {
		return 5000.0;
	}

	public function allow_return(): bool {
		return $this->bool( 'allow_return', true );
	}

	public function max_weight(): float {
		return max( 0.1, $this->float( 'max_weight', 20 ) );
	}

	public function default_weight(): float {
		return max( 0.1, $this->float( 'default_weight', 0.5 ) );
	}

	/** Размер на отделението по подразбиране, когато продуктите нямат размери: 1, 2 или 3. */
	public function default_compartment(): int {
		$c = (int) $this->get( 'default_compartment', 2 );
		return in_array( $c, [ 1, 2, 3 ], true ) ? $c : 2;
	}

	public function description_max_length(): int {
		return max( 20, (int) $this->get( 'description_max_length', 100 ) );
	}

	// Профил от Box Now (складове, разрешения), зареден с „Тест на връзката“.

	public function profile(): array {
		return (array) get_option( self::OPTION_PROFILE, [] );
	}

	/** @return array<string,string> locationId => име, за падащия списък „Изпращане от“ */
	public function origin_choices(): array {
		$out = [];
		foreach ( (array) ( $this->profile()['origins'] ?? [] ) as $o ) {
			$id = (string) ( $o['id'] ?? '' );
			if ( $id === '' ) {
				continue;
			}
			$name     = (string) ( $o['name'] ?? $o['title'] ?? '' );
			$type     = (string) ( $o['type'] ?? '' );
			$addr     = trim( (string) ( $o['addressLine1'] ?? '' ) );
			$out[ $id ] = trim( ( $name !== '' ? $name : $type ) . ( $addr !== '' ? ' – ' . $addr : '' ) . ' [' . $id . ']' );
		}
		return $out;
	}

	/** Разрешенията на партньора от /entrusted-partners (codPayment и др.). */
	public function permissions(): array {
		return (array) ( $this->profile()['permissions'] ?? [] );
	}
}
