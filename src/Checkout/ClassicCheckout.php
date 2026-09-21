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
 * Еконт е една ставка в прегледа на поръчката. Видът доставка (офис, Еконтомат, адрес) клиентът избира с икони
 * в блока „Доставка“ в лявата колона (първо куриер, после вид), на мястото на скритите адресни полета; изборът се пази в сесията, влиза в пакета за доставка и определя
 * цената на ставката. Стандартните адресни полета се скриват и стават незадължителни; след поръчка адресът за
 * доставка в WooCommerce се попълва с четим текст, за да се вижда навсякъде.
 */
final class ClassicCheckout {

	/** Полета, които се скриват при доставка с Еконт. */
	const HIDDEN_BILLING = [ 'billing_company', 'billing_country', 'billing_address_1', 'billing_address_2', 'billing_city', 'billing_state', 'billing_postcode' ];

	const SESSION_TYPE = 'ks_type';

	public function register(): void {
		add_filter( 'woocommerce_checkout_fields', [ $this, 'relax_address_fields' ], 100 );
		add_filter( 'woocommerce_cart_needs_shipping_address', [ $this, 'needs_shipping_address' ] );
		add_filter( 'woocommerce_cart_shipping_packages', [ $this, 'add_type_to_packages' ] );
		add_action( 'woocommerce_checkout_update_order_review', [ $this, 'remember_type_from_review' ] );
		add_filter( 'woocommerce_update_order_review_fragments', [ $this, 'fragments' ] );
		add_action( 'woocommerce_after_checkout_billing_form', [ $this, 'render_fields' ] ); // в лявата колона, на мястото на скрития адрес
		add_action( 'woocommerce_checkout_process', [ $this, 'validate' ] );
		add_action( 'woocommerce_checkout_create_order', [ $this, 'save_to_order' ], 10, 2 );
		add_filter( 'woocommerce_available_payment_gateways', [ $this, 'filter_gateways' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'assets' ] );
		add_filter( 'woocommerce_order_shipping_to_display', [ $this, 'shipping_to_display' ], 10, 2 );
	}

	// Избор в сесията.

	/** Дали в сесията е избрана ставката на Еконт. */
	public static function is_econt_chosen(): bool {
		if ( ! function_exists( 'WC' ) || ! WC()->session || ! WC()->cart || ! WC()->cart->needs_shipping() ) {
			return false;
		}
		foreach ( (array) WC()->session->get( 'chosen_shipping_methods', [] ) as $rate_id ) {
			if ( EcontShippingMethod::is_econt_rate( (string) $rate_id ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Видът доставка, който клиентът иска: от сесията, иначе запомненият от профила, иначе този по подразбиране.
	 * '' = още не е избран.
	 */
	public static function requested_type(): string {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return '';
		}
		$type = WC()->session->get( self::SESSION_TYPE );
		if ( is_string( $type ) && in_array( $type, DeliveryData::TYPES, true ) ) {
			return $type;
		}
		if ( $type === null ) {
			$saved = self::saved_delivery()->type;
			return $saved !== '' ? $saved : ( new EcontSettings() )->default_type();
		}
		return '';
	}

	/** Избраният вид доставка с Еконт или null, ако е избран друг куриер (или няма избран вид). */
	public static function chosen_type(): ?string {
		if ( ! self::is_econt_chosen() ) {
			return null;
		}
		return self::requested_type() ?: null;
	}

	private static function set_type( string $type ): void {
		if ( WC()->session ) {
			WC()->session->set( self::SESSION_TYPE, in_array( $type, DeliveryData::TYPES, true ) ? $type : '' );
		}
	}

	/** Видът влиза в пакета: така хешът на пакета се сменя и ставката се преизчислява при смяна на вида. */
	public function add_type_to_packages( array $packages ): array {
		$type = self::requested_type();
		foreach ( $packages as $i => $package ) {
			$packages[ $i ]['ks_type'] = $type;
		}
		return $packages;
	}

	/** WooCommerce праща всички полета на формата при всяко обновяване на прегледа (post_data). */
	public function remember_type_from_review( $post_data ): void {
		parse_str( (string) $post_data, $data );
		if ( array_key_exists( DeliveryFormView::PREFIX . 'type', (array) $data ) ) {
			self::set_type( sanitize_text_field( (string) $data[ DeliveryFormView::PREFIX . 'type' ] ) );
		}
	}

	/** Наличните видове и цените им се обновяват заедно с прегледа на поръчката. */
	public function fragments( array $fragments ): array {
		$fragments['#ks-type-options'] = self::options_json();
		return $fragments;
	}

	/** JSON с наличните видове доставка от ставката на Еконт (label, cost, price като текст). */
	private static function options_json(): string {
		$options = [];
		if ( function_exists( 'WC' ) && WC()->shipping() ) {
			foreach ( WC()->shipping()->get_packages() as $package ) {
				foreach ( (array) ( $package['rates'] ?? [] ) as $rate ) {
					if ( ! $rate instanceof \WC_Shipping_Rate || ! EcontShippingMethod::is_econt_rate( $rate->get_id() ) ) {
						continue;
					}
					foreach ( (array) ( $rate->get_meta_data()['ks_options'] ?? [] ) as $type => $o ) {
						$options[ $type ] = [
							'label' => (string) $o['label'],
							'cost'  => (float) $o['cost'],
							'price' => (float) $o['cost'] > 0 ? html_entity_decode( wp_strip_all_tags( wc_price( (float) $o['cost'] ) ), ENT_QUOTES, 'UTF-8' ) : __( 'безплатно', 'kanelov-shipping' ), // чист текст: JS го слага с textContent
						];
					}
					break 2;
				}
			}
		}
		return '<script type="application/json" id="ks-type-options">' . wp_json_encode( $options ) . '</script>';
	}

	// Полета.

	public function relax_address_fields( array $fields ): array {
		if ( ! self::is_econt_chosen() ) {
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
		return self::is_econt_chosen() ? false : $needs;
	}

	public function assets(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() ) {
			return;
		}
		DeliveryFormView::enqueue_scripts();
		wp_enqueue_script( 'ks-checkout', KS_URL . 'assets/js/checkout.js', [ 'jquery', 'ks-delivery-form' ], KS_VERSION, true );
		wp_localize_script( 'ks-checkout', 'ksCheckout', DeliveryFormView::js_config() + [
			'saved'       => self::saved_delivery()->to_array(),
			'defaultType' => ( new EcontSettings() )->default_type(),
		] );
	}

	/** Запомненият избор: от текущата сесия или от потребителския профил. */
	private static function saved_delivery(): DeliveryData {
		$session = WC()->session ? WC()->session->get( 'ks_delivery' ) : null;
		if ( is_array( $session ) ) {
			return DeliveryData::from_array( $session );
		}
		if ( is_user_logged_in() ) {
			return OrderMeta::get_user_delivery( get_current_user_id() );
		}
		return new DeliveryData();
	}

	/** Куриери, които още не са готови: показват се като сива карта „скоро“. */
	const COMING_SOON = [ 'boxnow' => 'Box Now' ];

	public function render_fields(): void {
		$saved       = self::saved_delivery();
		$saved->type = self::requested_type();
		$carriers    = Plugin::instance()->carriers()->all();
		?>
		<div id="ks-delivery" class="ks-delivery" data-type="<?php echo esc_attr( $saved->type ); ?>" hidden>
			<h3><?php esc_html_e( 'Доставка', 'kanelov-shipping' ); ?></h3>

			<fieldset class="ks-step ks-step--carrier ks-carrier-picker">
				<legend class="ks-step__label"><?php esc_html_e( 'Куриер', 'kanelov-shipping' ); ?></legend>
				<div class="ks-carrier-picker__options">
					<?php foreach ( $carriers as $carrier ) : ?>
						<button type="button" class="ks-carrier-option" data-carrier="<?php echo esc_attr( $carrier->id() ); ?>" data-method="<?php echo esc_attr( EcontShippingMethod::ID ); ?>">
							<span class="ks-carrier-option__name"><?php echo esc_html( $carrier->label() ); ?></span>
							<span class="ks-carrier-option__sub"></span>
						</button>
					<?php endforeach; ?>
					<?php foreach ( self::COMING_SOON as $id => $name ) : ?>
						<span class="ks-carrier-option ks-carrier-option--soon" data-carrier="<?php echo esc_attr( $id ); ?>" aria-disabled="true">
							<span class="ks-carrier-option__name"><?php echo esc_html( $name ); ?></span>
							<span class="ks-carrier-option__sub"><?php esc_html_e( 'скоро', 'kanelov-shipping' ); ?></span>
						</span>
					<?php endforeach; ?>
				</div>
			</fieldset>

			<div class="ks-carrier-form" data-carrier="<?php echo esc_attr( EcontCarrier::ID ); ?>" hidden>
				<?php DeliveryFormView::render( $saved, false ); ?>
			</div>
			<?php echo self::options_json(); // phpcs:ignore WordPress.Security.EscapeOutput -- JSON в script таг. ?>
		</div>
		<?php
	}

	/** Чете DeliveryData от POST на чекаута. */
	public static function delivery_from_post( array $post, string $type ): DeliveryData {
		return DeliveryFormView::from_request( $post, EcontCarrier::ID, $type );
	}

	public function validate(): void {
		// phpcs:disable WordPress.Security.NonceVerification -- WooCommerce проверява nonce на чекаута.
		if ( isset( $_POST[ DeliveryFormView::PREFIX . 'type' ] ) ) {
			self::set_type( sanitize_text_field( wp_unslash( (string) $_POST[ DeliveryFormView::PREFIX . 'type' ] ) ) );
		}
		if ( ! self::is_econt_chosen() ) {
			return;
		}
		$type = self::requested_type();
		if ( $type === '' ) {
			wc_add_notice( __( 'Изберете как да получите пратката с Еконт: в офис, от Еконтомат или на адрес.', 'kanelov-shipping' ), 'error' );
			return;
		}
		$delivery = self::delivery_from_post( $_POST, $type );
		// phpcs:enable
		$carrier = Plugin::instance()->carriers()->get( EcontCarrier::ID );
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
	public function shipping_to_display( $text, $order ): string {
		$text = (string) $text;
		if ( ! $order instanceof \WC_Order ) {
			return $text;
		}
		$delivery = OrderMeta::get_delivery( $order );
		if ( $delivery->is_empty() ) {
			return $text;
		}
		$carrier = Plugin::instance()->carriers()->get( $delivery->carrier );
		return $carrier ? $text . ' (' . esc_html( $carrier->format_delivery( $delivery ) ) . ')' : $text;
	}
}
