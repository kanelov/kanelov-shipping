<?php
namespace Kanelov\Shipping\Checkout;

use Kanelov\Shipping\Carrier\DeliveryData;
use Kanelov\Shipping\Carrier\Econt\EcontCarrier;
use Kanelov\Shipping\Carrier\Econt\EcontSettings;
use Kanelov\Shipping\Carrier\Econt\EcontShippingMethod;
use Kanelov\Shipping\Order\OrderMeta;
use Kanelov\Shipping\Plugin;
use Kanelov\Shipping\Rest\EcontSearchController;

defined( 'ABSPATH' ) || exit;

/**
 * Класически чекаут (шорткод [woocommerce_checkout]).
 * Клиентът попълва само имена, телефон и имейл. Когато е избрана ставка на Еконт, стандартните адресни
 * полета се скриват и стават незадължителни, а вместо тях се показват полетата за офис/Еконтомат/адрес.
 * След поръчка адресът за доставка в WooCommerce се попълва с четим текст, за да се вижда навсякъде.
 */
final class ClassicCheckout {

	const FIELD_PREFIX = 'ks_';

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
		wp_enqueue_style( 'ks-checkout', KS_URL . 'assets/css/checkout.css', [], KS_VERSION );
		wp_enqueue_script( 'ks-checkout', KS_URL . 'assets/js/checkout.js', [ 'jquery' ], KS_VERSION, true );

		$settings = new EcontSettings();
		wp_localize_script( 'ks-checkout', 'ksCheckout', [
			'rest'      => esc_url_raw( rest_url( EcontSearchController::NS . '/econt/' ) ),
			'methodId'  => EcontShippingMethod::ID,
			'saved'     => $this->saved_delivery()->to_array(),
			'i18n'      => [
				'noResults'   => __( 'Няма резултати', 'kanelov-shipping' ),
				'loading'     => __( 'Зареждане…', 'kanelov-shipping' ),
				'chooseCity'  => __( 'Първо изберете населено място', 'kanelov-shipping' ),
				'noOffices'   => __( 'Няма офиси в това населено място', 'kanelov-shipping' ),
				'noLockers'   => __( 'Няма Еконтомати в това населено място', 'kanelov-shipping' ),
				'geoError'    => __( 'Не можахме да определим местоположението ви.', 'kanelov-shipping' ),
				'nearest'     => __( 'Най-близки до вас', 'kanelov-shipping' ),
				'km'          => __( 'км', 'kanelov-shipping' ),
			],
		] );
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
		$saved = $this->saved_delivery();
		$f     = static fn( string $k ) => self::FIELD_PREFIX . $k;
		$types = EcontShippingMethod::rate_types();
		?>
		<div id="ks-delivery" class="ks-delivery" data-carrier="<?php echo esc_attr( EcontCarrier::ID ); ?>" hidden>
			<h3><?php esc_html_e( 'Доставка с Еконт', 'kanelov-shipping' ); ?> <span class="ks-delivery__type"></span></h3>
			<input type="hidden" name="<?php echo esc_attr( $f( 'type' ) ); ?>" value="<?php echo esc_attr( $saved->type ); ?>" class="ks-type">
			<input type="hidden" name="<?php echo esc_attr( $f( 'city_id' ) ); ?>" value="<?php echo esc_attr( (string) $saved->city_id ); ?>" class="ks-city-id">
			<input type="hidden" name="<?php echo esc_attr( $f( 'post_code' ) ); ?>" value="<?php echo esc_attr( $saved->post_code ); ?>" class="ks-post-code">
			<input type="hidden" name="<?php echo esc_attr( $f( 'office_code' ) ); ?>" value="<?php echo esc_attr( $saved->office_code ); ?>" class="ks-office-code">
			<input type="hidden" name="<?php echo esc_attr( $f( 'office_name' ) ); ?>" value="<?php echo esc_attr( $saved->office_name ); ?>" class="ks-office-name">

			<div class="ks-row ks-row--city">
				<?php
				woocommerce_form_field( $f( 'city_name' ), [
					'type'         => 'text',
					'label'        => __( 'Населено място', 'kanelov-shipping' ),
					'required'     => true,
					'class'        => [ 'form-row-wide', 'ks-field-city' ],
					'autocomplete' => 'off',
					'placeholder'  => __( 'Започнете да пишете…', 'kanelov-shipping' ),
					'input_class'  => [ 'ks-city' ],
				], $saved->city_name );
				?>
				<button type="button" class="button ks-nearest" title="<?php esc_attr_e( 'Намери най-близкия до мен', 'kanelov-shipping' ); ?>">📍 <?php esc_html_e( 'Най-близък до мен', 'kanelov-shipping' ); ?></button>
			</div>

			<div class="ks-section ks-section--office" hidden>
				<?php
				woocommerce_form_field( $f( 'office_search' ), [
					'type'         => 'text',
					'label'        => __( 'Офис / Еконтомат', 'kanelov-shipping' ),
					'required'     => true,
					'class'        => [ 'form-row-wide', 'ks-field-office' ],
					'autocomplete' => 'off',
					'placeholder'  => __( 'Търсете по име или адрес…', 'kanelov-shipping' ),
					'input_class'  => [ 'ks-office' ],
				], $saved->office_name );
				?>
				<p class="ks-office-selected" hidden></p>
			</div>

			<div class="ks-section ks-section--door" hidden>
				<?php
				woocommerce_form_field( $f( 'street' ), [
					'type'         => 'text',
					'label'        => __( 'Улица / булевард', 'kanelov-shipping' ),
					'class'        => [ 'form-row-first' ],
					'autocomplete' => 'off',
					'input_class'  => [ 'ks-street' ],
				], $saved->street );
				woocommerce_form_field( $f( 'street_num' ), [
					'type'        => 'text',
					'label'       => __( '№', 'kanelov-shipping' ),
					'class'       => [ 'form-row-last', 'ks-field-num' ],
					'input_class' => [ 'ks-street-num' ],
				], $saved->street_num );
				woocommerce_form_field( $f( 'quarter' ), [
					'type'         => 'text',
					'label'        => __( 'Квартал / ж.к.', 'kanelov-shipping' ),
					'class'        => [ 'form-row-first' ],
					'autocomplete' => 'off',
					'input_class'  => [ 'ks-quarter' ],
				], $saved->quarter );
				woocommerce_form_field( $f( 'block' ), [
					'type'        => 'text',
					'label'       => __( 'Блок', 'kanelov-shipping' ),
					'class'       => [ 'form-row-last', 'ks-field-num' ],
				], $saved->block );
				?>
				<div class="ks-row ks-row--small">
					<?php
					woocommerce_form_field( $f( 'entrance' ), [ 'type' => 'text', 'label' => __( 'Вход', 'kanelov-shipping' ), 'class' => [ 'ks-third' ] ], $saved->entrance );
					woocommerce_form_field( $f( 'floor' ), [ 'type' => 'text', 'label' => __( 'Етаж', 'kanelov-shipping' ), 'class' => [ 'ks-third' ] ], $saved->floor );
					woocommerce_form_field( $f( 'apartment' ), [ 'type' => 'text', 'label' => __( 'Апартамент', 'kanelov-shipping' ), 'class' => [ 'ks-third' ] ], $saved->apartment );
					?>
				</div>
				<?php
				woocommerce_form_field( $f( 'note' ), [
					'type'        => 'text',
					'label'       => __( 'Бележка за куриера', 'kanelov-shipping' ),
					'class'       => [ 'form-row-wide' ],
					'placeholder' => __( 'Ориентир, звънец, фирма…', 'kanelov-shipping' ),
				], $saved->note );
				?>
			</div>
			<script type="application/json" class="ks-type-labels"><?php echo wp_json_encode( $types ); ?></script>
		</div>
		<?php
	}

	/** Чете DeliveryData от POST на чекаута. */
	public static function delivery_from_post( array $post, string $type ): DeliveryData {
		$get = static fn( string $k ) => sanitize_text_field( wp_unslash( (string) ( $post[ self::FIELD_PREFIX . $k ] ?? '' ) ) );
		return DeliveryData::from_array( [
			'carrier'     => EcontCarrier::ID,
			'type'        => $type,
			'city_id'     => (int) $get( 'city_id' ),
			'city_name'   => $get( 'city_name' ),
			'post_code'   => $get( 'post_code' ),
			'office_code' => $get( 'office_code' ),
			'office_name' => $get( 'office_name' ),
			'street'      => $get( 'street' ),
			'street_num'  => $get( 'street_num' ),
			'quarter'     => $get( 'quarter' ),
			'block'       => $get( 'block' ),
			'entrance'    => $get( 'entrance' ),
			'floor'       => $get( 'floor' ),
			'apartment'   => $get( 'apartment' ),
			'note'        => $get( 'note' ),
		] );
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
