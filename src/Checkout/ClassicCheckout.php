<?php
namespace Kanelov\Shipping\Checkout;

use Kanelov\Shipping\Carrier\BoxNow\BoxNowCarrier;
use Kanelov\Shipping\Carrier\BoxNow\BoxNowSettings;
use Kanelov\Shipping\Carrier\BoxNow\BoxNowShippingMethod;
use Kanelov\Shipping\Carrier\CarrierInterface;
use Kanelov\Shipping\Carrier\DeliveryData;
use Kanelov\Shipping\Carrier\Econt\EcontCarrier;
use Kanelov\Shipping\Carrier\Econt\EcontSettings;
use Kanelov\Shipping\Carrier\Econt\EcontShippingMethod;
use Kanelov\Shipping\Order\OrderMeta;
use Kanelov\Shipping\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Класически чекаут (шорткод [woocommerce_checkout]).
 * Всеки куриер е една ставка в прегледа на поръчката. В блока „Доставка“ в лявата колона клиентът избира куриер (карти),
 * после за Еконт вид доставка (офис, Еконтомат, адрес) с икони, а за Box Now направо автомат; после населено място
 * и офис/автомат/адрес. Видът за Еконт се пази в сесията, влиза в пакета за доставка и определя цената на ставката.
 * Стандартните адресни полета се скриват и стават незадължителни; след поръчка адресът за доставка в WooCommerce
 * се попълва с четим текст, за да се вижда навсякъде.
 */
final class ClassicCheckout {

	/** Полета, които се скриват при доставка с наш куриер. */
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

	/** Куриерът, чиято ставка е избрана в сесията, или null. */
	public static function chosen_carrier(): ?CarrierInterface {
		if ( ! function_exists( 'WC' ) || ! WC()->session || ! WC()->cart || ! WC()->cart->needs_shipping() ) {
			return null;
		}
		foreach ( (array) WC()->session->get( 'chosen_shipping_methods', [] ) as $rate_id ) {
			$method = explode( ':', (string) $rate_id )[0];
			foreach ( Plugin::instance()->carriers()->all() as $carrier ) {
				if ( $carrier->method_id() === $method ) {
					return $carrier;
				}
			}
		}
		return null;
	}

	/** Методи на WooCommerce за взимане на място. */
	const PICKUP_METHODS = [ 'local_pickup', 'pickup_location' ];

	/** Дали е избрано взимане на място (стандартният Local pickup на WooCommerce). */
	public static function is_pickup_chosen(): bool {
		if ( ! function_exists( 'WC' ) || ! WC()->session || ! WC()->cart || ! WC()->cart->needs_shipping() ) {
			return false;
		}
		foreach ( (array) WC()->session->get( 'chosen_shipping_methods', [] ) as $rate_id ) {
			if ( in_array( explode( ':', (string) $rate_id )[0], self::PICKUP_METHODS, true ) ) {
				return true;
			}
		}
		return false;
	}

	/** Адресът на клиента не е нужен: наш куриер (офис/автомат/адрес се избират в нашия блок) или взимане на място. */
	public static function no_address_needed(): bool {
		return self::chosen_carrier() !== null || ( self::is_pickup_chosen() && ( new EcontSettings() )->hide_address_for_pickup() );
	}

	/** Дали в сесията е избрана ставката на Еконт. */
	public static function is_econt_chosen(): bool {
		return self::chosen_carrier()?->id() === EcontCarrier::ID;
	}

	public static function is_boxnow_chosen(): bool {
		return self::chosen_carrier()?->id() === BoxNowCarrier::ID;
	}

	/**
	 * Видът доставка с Еконт, който клиентът иска: от сесията, иначе запомненият от профила, иначе този по подразбиране.
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
			$saved = self::saved_delivery( EcontCarrier::ID )->type;
			return $saved !== '' ? $saved : ( new EcontSettings() )->default_type();
		}
		return '';
	}

	/** Избраният вид доставка на избрания куриер или null, ако не е избран наш куриер (или няма избран вид). */
	public static function chosen_type(): ?string {
		$carrier = self::chosen_carrier();
		if ( ! $carrier ) {
			return null;
		}
		if ( $carrier->id() === BoxNowCarrier::ID ) {
			return DeliveryData::TYPE_LOCKER;
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

	/** JSON с наличните видове доставка по куриер от ставките (label, cost, price като текст). */
	private static function options_json(): string {
		$options = [];
		if ( function_exists( 'WC' ) && WC()->shipping() ) {
			foreach ( WC()->shipping()->get_packages() as $package ) {
				foreach ( (array) ( $package['rates'] ?? [] ) as $rate ) {
					if ( ! $rate instanceof \WC_Shipping_Rate ) {
						continue;
					}
					$meta    = $rate->get_meta_data();
					$carrier = (string) ( $meta['ks_carrier'] ?? '' );
					if ( $carrier === '' ) {
						continue;
					}
					foreach ( (array) ( $meta['ks_options'] ?? [] ) as $type => $o ) {
						$options[ $carrier ][ $type ] = [
							'label' => (string) $o['label'],
							'cost'  => (float) $o['cost'],
							'price' => (float) $o['cost'] > 0 ? html_entity_decode( wp_strip_all_tags( wc_price( (float) $o['cost'] ) ), ENT_QUOTES, 'UTF-8' ) : __( 'безплатно', 'kanelov-shipping' ), // чист текст: JS го слага с textContent
						];
					}
				}
				break;
			}
		}
		return '<script type="application/json" id="ks-type-options">' . wp_json_encode( $options ?: new \stdClass() ) . '</script>';
	}

	// Полета.

	public function relax_address_fields( array $fields ): array {
		if ( ! self::no_address_needed() ) {
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
		return self::no_address_needed() ? false : $needs;
	}

	public function assets(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() ) {
			return;
		}
		DeliveryFormView::enqueue_scripts();
		wp_enqueue_script( 'ks-checkout', KS_URL . 'assets/js/checkout.js', [ 'jquery', 'ks-delivery-form' ], KS_VERSION, true );
		$carriers = [];
		foreach ( Plugin::instance()->carriers()->all() as $carrier ) {
			$carriers[ $carrier->id() ] = [ 'method' => $carrier->method_id(), 'types' => $carrier->supported_types() ];
		}
		wp_localize_script( 'ks-checkout', 'ksCheckout', DeliveryFormView::js_config() + [
			'carriers'    => $carriers,
			'boxnow'      => BoxNowFormView::js_config(),
			'saved'       => self::saved_delivery( EcontCarrier::ID )->to_array(),
			'savedBoxNow' => self::saved_delivery( BoxNowCarrier::ID )->to_array(),
			'defaultType' => ( new EcontSettings() )->default_type(),
			'pickup'      => ( new EcontSettings() )->hide_address_for_pickup() ? self::PICKUP_METHODS : [],
		] );
	}

	/** Запомненият избор за куриера: от текущата сесия (ако е за същия куриер) или от потребителския профил. */
	private static function saved_delivery( string $carrier ): DeliveryData {
		$session = WC()->session ? WC()->session->get( 'ks_delivery' ) : null;
		if ( is_array( $session ) && ( $session['carrier'] ?? '' ) === $carrier ) {
			return DeliveryData::from_array( $session );
		}
		if ( is_user_logged_in() ) {
			return OrderMeta::get_user_delivery( get_current_user_id(), $carrier );
		}
		return new DeliveryData();
	}

	public function render_fields(): void {
		$econt       = self::saved_delivery( EcontCarrier::ID );
		$econt->type = self::requested_type();
		$boxnow      = self::saved_delivery( BoxNowCarrier::ID );
		$carriers    = Plugin::instance()->carriers()->all();
		?>
		<div id="ks-delivery" class="ks-delivery" data-type="<?php echo esc_attr( $econt->type ); ?>" hidden>
			<h3><?php esc_html_e( 'Доставка', 'kanelov-shipping' ); ?></h3>

			<fieldset class="ks-step ks-step--carrier ks-carrier-picker">
				<legend class="ks-step__label"><?php esc_html_e( 'Куриер', 'kanelov-shipping' ); ?></legend>
				<div class="ks-carrier-picker__options">
					<?php foreach ( $carriers as $carrier ) : ?>
						<button type="button" class="ks-carrier-option" data-carrier="<?php echo esc_attr( $carrier->id() ); ?>" data-method="<?php echo esc_attr( $carrier->method_id() ); ?>">
							<span class="ks-carrier-option__name"><?php echo esc_html( $carrier->label() ); ?></span>
							<span class="ks-carrier-option__sub"></span>
						</button>
					<?php endforeach; ?>
				</div>
				<?php
				// Само за администратори: защо Box Now липсва в тази количка (размери, тегло, настройки).
				$reason = current_user_can( 'manage_woocommerce' ) && WC()->session ? (string) WC()->session->get( BoxNowShippingMethod::SESSION_REASON, '' ) : '';
				if ( $reason !== '' ) :
					?>
					<p class="ks-admin-note"><?php echo esc_html( sprintf( __( 'Box Now не се предлага за тази количка: %s. (Виждат го само администратори.)', 'kanelov-shipping' ), $reason ) ); ?></p>
				<?php endif; ?>
			</fieldset>

			<div class="ks-carrier-form" data-carrier="<?php echo esc_attr( EcontCarrier::ID ); ?>" hidden>
				<?php DeliveryFormView::render( $econt, false ); ?>
			</div>
			<div class="ks-carrier-form" data-carrier="<?php echo esc_attr( BoxNowCarrier::ID ); ?>" data-prefix="<?php echo esc_attr( BoxNowFormView::PREFIX ); ?>" hidden>
				<?php BoxNowFormView::render( $boxnow, false ); ?>
			</div>
			<?php echo self::options_json(); // phpcs:ignore WordPress.Security.EscapeOutput -- JSON в script таг. ?>
		</div>
		<?php
	}

	/** Чете DeliveryData на Еконт от POST на чекаута. */
	public static function delivery_from_post( array $post, string $type ): DeliveryData {
		return DeliveryFormView::from_request( $post, EcontCarrier::ID, $type );
	}

	/** Изборът на клиента за избрания куриер от POST на чекаута. */
	private static function delivery_for( CarrierInterface $carrier, array $post ): ?DeliveryData {
		if ( $carrier->id() === BoxNowCarrier::ID ) {
			return BoxNowFormView::from_request( $post );
		}
		$type = self::requested_type();
		return $type === '' ? null : self::delivery_from_post( $post, $type );
	}

	public function validate(): void {
		// phpcs:disable WordPress.Security.NonceVerification -- WooCommerce проверява nonce на чекаута.
		if ( isset( $_POST[ DeliveryFormView::PREFIX . 'type' ] ) ) {
			self::set_type( sanitize_text_field( wp_unslash( (string) $_POST[ DeliveryFormView::PREFIX . 'type' ] ) ) );
		}
		$carrier = self::chosen_carrier();
		if ( ! $carrier ) {
			return;
		}
		$delivery = self::delivery_for( $carrier, $_POST );
		// phpcs:enable
		if ( ! $delivery ) {
			wc_add_notice( __( 'Изберете как да получите пратката с Еконт: в офис, от Еконтомат или на адрес.', 'kanelov-shipping' ), 'error' );
			return;
		}
		foreach ( $carrier->validate_delivery( $delivery ) as $error ) {
			wc_add_notice( $error, 'error' );
		}
		if ( WC()->session ) {
			WC()->session->set( 'ks_delivery', $delivery->to_array() );
		}
	}

	public function save_to_order( \WC_Order $order, array $data ): void {
		$carrier = self::chosen_carrier();
		if ( ! $carrier ) {
			return;
		}
		$delivery = self::delivery_for( $carrier, $_POST ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! $delivery ) {
			return;
		}

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
		$carrier = self::chosen_carrier();
		if ( ! $carrier || ! isset( $gateways['cod'] ) ) {
			return $gateways;
		}
		if ( $carrier->id() === EcontCarrier::ID && self::chosen_type() === DeliveryData::TYPE_LOCKER && ! ( new EcontSettings() )->locker_allow_cod() ) {
			unset( $gateways['cod'] );
		}
		if ( $carrier->id() === BoxNowCarrier::ID ) {
			$settings = new BoxNowSettings();
			$total    = WC()->cart ? (float) WC()->cart->get_total( 'edit' ) : 0.0;
			if ( ! $settings->allow_cod() || $total > $settings->cod_max() ) {
				unset( $gateways['cod'] );
			}
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
