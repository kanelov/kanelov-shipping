<?php
namespace Kanelov\Shipping\Carrier\Econt;

use Kanelov\Shipping\Carrier\DeliveryData;

defined( 'ABSPATH' ) || exit;

/**
 * Достъп до глобалните настройки на метода (WooCommerce > Доставка > Еконт). Четат се от option
 * `woocommerce_ks_econt_settings`, което WC_Shipping_Method записва само.
 */
final class EcontSettings {

	const OPTION = 'woocommerce_' . EcontShippingMethod::ID . '_settings';

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
		$v = $this->data[ $key ] ?? '';
		return is_numeric( str_replace( ',', '.', (string) $v ) ) ? (float) str_replace( ',', '.', (string) $v ) : $default;
	}

	public function username(): string {
		return (string) $this->get( 'username' );
	}

	public function password(): string {
		return (string) $this->get( 'password' );
	}

	public function is_live(): bool {
		return $this->get( 'environment', 'demo' ) === 'live';
	}

	public function api(): EcontApi {
		return EcontApi::from_settings( $this );
	}

	// Подател.
	public function send_from(): string {
		return (string) $this->get( 'send_from', 'office' ); // office | address
	}

	public function sender_office_code(): string {
		return (string) $this->get( 'sender_office_code' );
	}

	public function sender_address_index(): int {
		return (int) $this->get( 'sender_address', 0 );
	}

	public function sender_name(): string {
		return (string) $this->get( 'sender_name' );
	}

	public function sender_phone(): string {
		return (string) $this->get( 'sender_phone' );
	}

	// Плащане и споразумения.
	public function cd_pay_options_template(): string {
		return (string) $this->get( 'cd_pay_options_template' );
	}

	public function sender_payment_method(): string {
		return (string) $this->get( 'sender_payment_method', 'credit' ); // credit | cash
	}

	public function receiver_pays_shipping(): bool {
		return $this->bool( 'receiver_pays_shipping', false );
	}

	public function sms_notification(): bool {
		return $this->bool( 'sms_notification', true );
	}

	public function declared_value_threshold(): float {
		return $this->float( 'declared_value_threshold', 0 );
	}

	public function invoice_before_pay_cd(): bool {
		return $this->bool( 'invoice_before_pay_cd', false );
	}

	// Пратка.
	public function default_weight(): float {
		return max( 0.1, $this->float( 'default_weight', 0.5 ) );
	}

	public function min_weight(): float {
		return max( 0.1, $this->float( 'min_weight', 0.1 ) );
	}

	public function description_mode(): string {
		return (string) $this->get( 'description_mode', 'both' ); // products | order_number | both
	}

	public function description_max_length(): int {
		return max( 20, (int) $this->get( 'description_max_length', 100 ) );
	}

	public function shipment_type(): string {
		return (string) $this->get( 'shipment_type', 'pack' );
	}

	/** Тестов режим: методът се показва само на потребители с право manage_woocommerce. */
	public function admins_only(): bool {
		return $this->bool( 'admins_only', false );
	}

	public function map_enabled(): bool {
		return $this->bool( 'map_enabled', true );
	}

	/** Вид доставка, избран предварително в чекаута; '' = клиентът избира сам. */
	public function default_type(): string {
		$type = (string) $this->get( 'default_type', DeliveryData::TYPE_OFFICE );
		return in_array( $type, DeliveryData::TYPES, true ) ? $type : '';
	}

	// Ограничения за Еконтомат.
	public function locker_max_weight(): float {
		return $this->float( 'locker_max_weight', 20 );
	}

	public function locker_allow_cod(): bool {
		return $this->bool( 'locker_allow_cod', true );
	}
}
