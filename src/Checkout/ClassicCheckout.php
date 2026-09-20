<?php
namespace Kanelov\Shipping\Checkout;

use Kanelov\Shipping\Carrier\DeliveryData;
use Kanelov\Shipping\Carrier\Econt\EcontCarrier;
use Kanelov\Shipping\Carrier\Econt\EcontSettings;
use Kanelov\Shipping\Carrier\Econt\EcontShippingMethod;
use Kanelov\Shipping\Order\OrderMeta;
use Kanelov\Shipping\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Класически чекаут (шорткод [woocommerce_checkout]).
 * Клиентът попълва само имена, телефон и имейл. Когато е избрана ставка на Еконт, стандартните адресни
 * полета се скриват и стават незадължителни, а вместо тях се показват полетата за офис/Еконтомат/адрес.
 * След поръчка адресът за доставка в WooCommerce се попълва с четим текст, за да се вижда навсякъде.
 */
final class ClassicCheckout {

	/** Полета, които се скриват при доставка с Еконт. */
	const HIDDEN_BILLING = [ 'billing_company', 'billing_country', 'billing_address_1', 'billing_address_2', 'billing_city', 'billing_state', 'billing_postcode' ];

	public function register(): void {
		add_filter( 'woocommerce_checkout_fields', [ $this, 'relax_address_fields' ], 100 );
		add_filter( 'woocommerce_cart_needs_shipping_address', [ $this, 'needs_shipping_address' ] );
		add_action( 'woocommerce_checkout_after_customer_details', [ $this, 'render_fields' ] );
		add_action( 'woocommerce_checkout_process', [ $this, 'validate' ] );
		add_action( 'woocommerce_checkout_create_order', [ $this, 'save_to_order' ], 10, 2 );
		add_filter( 'woocommerce_available_payment_gateways', [ $this, 'filter_gateways' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'assets' ] );
		add_filter( 'woocommerce_order_shipping_to_display', [ $this, 'shipping_to_display' ], 10, 2 );
	}

	/** Избраният в сесията тип доставка с Еконт или null, ако е избран друг куриер. */
	public static function chosen_type(): ?string {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return null;
		}
		$chosen = (array) WC()->session->get( 'chosen_shipping_methods', [] );
		foreach ( $chosen as $rate_id ) {
			$type = EcontShippingMethod::type_from_rate_id( (string) $rate_id );
			if ( $type ) {
				return $type;
			}
		}
		return null;
	}

	public function relax_address_fields( array $fields ): array {
		if ( ! self::chosen_type() ) {
			return $fields;
		}
		foreach ( self::HIDDEN_BILLING as $key ) {
			if ( isset( $fields['billing'][ $key ] ) ) {
				$fields['billing'][ $key ]['required'] = false;
			}
		}
		foreach ( (array) ( $fields['shipping'] ?? [] ) as $key => $f ) {
			$fields['shipping'][ $key ]['required'] = false;
		}
		return $fields;
	}

	public function needs_shipping_address( bool $needs ): bool {
		return self::chosen_type() ? false : $needs;
	}

	public function assets(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() ) {
			return;
		}
		DeliveryFormView::enqueue_scripts();
		wp_enqueue_script( 'ks-checkout', KS_URL . 'assets/js/checkout.js', [ 'jquery', 'ks-delivery-form' ], KS_VERSION, true );
		wp_localize_script( 'ks-checkout', 'ksCheckout', DeliveryFormView::js_config() + [ 'saved' => $this->saved_delivery()->to_array() ] );
	}

	/** Запомненият избор: от потребителския профил или от текущата сесия. */
	private function saved_delivery(): DeliveryData {
		$session = WC()->session ? WC()->session->get( 'ks_delivery' ) : null;
		if ( is_array( $session ) ) {
			return DeliveryData::from_array( $session );
		}
		if ( is_user_logged_in() ) {
			return OrderMeta::get_user_delivery( get_current_user_id() );
		}
		return new DeliveryData();
	}

	public function render_fields(): void {
		?>
		<div id="ks-delivery" class="ks-delivery" data-carrier="<?php echo esc_attr( EcontCarrier::ID ); ?>" hidden>
			<h3><?php esc_html_e( 'Доставка с Еконт', 'kanelov-shipping' ); ?> <span class="ks-delivery__type"></span></h3>
			<?php DeliveryFormView::render( $this->saved_delivery(), false ); ?>
		</div>
		<?php
	}

	/** Чете DeliveryData от POST на чекаута. */
	public static function delivery_from_post( array $post, string $type ): DeliveryData {
		return DeliveryFormView::from_request( $post, EcontCarrier::ID, $type );
	}

	public function validate(): void {
		$type = self::chosen_type();
		if ( ! $type ) {
			return;
		}
		$delivery = self::delivery_from_post( $_POST, $type ); // phpcs:ignore WordPress.Security.NonceVerification -- WooCommerce проверява nonce на чекаута.
		$carrier  = Plugin::instance()->carriers()->get( EcontCarrier::ID );
		foreach ( $carrier->validate_delivery( $delivery ) as $error ) {
			wc_add_notice( $error, 'error' );
		}
		if ( WC()->session ) {
			WC()->session->set( 'ks_delivery', $delivery->to_array() );
		}
	}

	public function save_to_order( \WC_Order $order, array $data ): void {
		$type = self::chosen_type();
		if ( ! $type ) {
			return;
		}
		$delivery = self::delivery_from_post( $_POST, $type ); // phpcs:ignore WordPress.Security.NonceVerification
		$carrier  = Plugin::instance()->carriers()->get( EcontCarrier::ID );

		OrderMeta::set_delivery( $order, $delivery );

		// Четим адрес в стандартните полета, за да се вижда в админа, имейлите и фактурите.
		$text = $carrier->format_delivery( $delivery );
		$order->set_shipping_first_name( $order->get_billing_first_name() );
		$order->set_shipping_last_name( $order->get_billing_last_name() );
		$order->set_shipping_phone( $order->get_billing_phone() );
		$order->set_shipping_company( '' );
		$order->set_shipping_address_1( $text );
		$order->set_shipping_address_2( '' );
		$order->set_shipping_city( $delivery->city_name );
		$order->set_shipping_postcode( $delivery->post_code );
		$order->set_shipping_state( '' );
		$order->set_shipping_country( 'BG' );
		if ( trim( $order->get_billing_address_1() ) === '' ) {
			$order->set_billing_address_1( $text );
			$order->set_billing_city( $delivery->city_name );
			$order->set_billing_postcode( $delivery->post_code );
			$order->set_billing_country( 'BG' );
		}

		if ( $order->get_customer_id() ) {
			OrderMeta::set_user_delivery( $order->get_customer_id(), $delivery );
		}
		if ( WC()->session ) {
			WC()->session->set( 'ks_delivery', null );
		}
	}

	public function filter_gateways( array $gateways ): array {
		if ( self::chosen_type() === DeliveryData::TYPE_LOCKER && ! ( new EcontSettings() )->locker_allow_cod() ) {
			unset( $gateways['cod'] );
		}
		return $gateways;
	}

	/** В имейлите и „Моят акаунт“ под метода за доставка се показва избраният офис/адрес. */
	public function shipping_to_display( string $text, \WC_Order $order ): string {
		$delivery = OrderMeta::get_delivery( $order );
		if ( $delivery->is_empty() ) {
			return $text;
		}
		$carrier = Plugin::instance()->carriers()->get( $delivery->carrier );
		return $carrier ? $text . '<br><small>' . esc_html( $carrier->format_delivery( $delivery ) ) . '</small>' : $text;
	}
}
