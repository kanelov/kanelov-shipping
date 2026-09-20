<?php
namespace Kanelov\Shipping\Admin;

use Kanelov\Shipping\Carrier\DeliveryData;
use Kanelov\Shipping\Carrier\Econt\EcontCarrier;
use Kanelov\Shipping\Carrier\Econt\EcontSettings;
use Kanelov\Shipping\Checkout\DeliveryFormView;
use Kanelov\Shipping\Order\OrderMeta;
use Kanelov\Shipping\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Кутия „Еконт“ в поръчката: данни за доставка (с редакция), параметри на пратката, калкулация,
 * създаване, изтриване, печат и проследяване на товарителницата. Всичко през admin-ajax с nonce.
 */
final class OrderMetabox {

	const AJAX  = 'ks_order_action';
	const NONCE = 'ks_order_nonce';

	public function register(): void {
		add_action( 'add_meta_boxes', [ $this, 'add' ] );
		add_action( 'wp_ajax_' . self::AJAX, [ $this, 'ajax' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
	}

	public function add(): void {
		// wc_get_page_screen_id връща 'woocommerce_page_wc-orders' при HPOS и 'shop_order' при класическо съхранение.
		add_meta_box( 'ks-econt', __( 'Еконт – доставка и товарителница', 'kanelov-shipping' ), [ $this, 'render' ], wc_get_page_screen_id( 'shop-order' ), 'normal', 'high' );
	}

	public function assets( string $hook ): void {
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, [ 'shop_order', wc_get_page_screen_id( 'shop-order' ) ], true ) ) {
			return;
		}
		DeliveryFormView::enqueue_scripts();
		wp_enqueue_style( 'ks-admin', KS_URL . 'assets/css/admin.css', [ 'ks-delivery' ], KS_VERSION );
		wp_enqueue_script( 'ks-admin-order', KS_URL . 'assets/js/admin-order.js', [ 'jquery', 'ks-delivery-form' ], KS_VERSION, true );
		wp_localize_script( 'ks-admin-order', 'ksAdmin', DeliveryFormView::js_config() + [
			'ajax'   => admin_url( 'admin-ajax.php' ),
			'action' => self::AJAX,
			'nonce'  => wp_create_nonce( self::NONCE ),
			'i18nAdmin' => [
				'confirmDelete' => __( 'Да изтрия ли товарителницата от Еконт?', 'kanelov-shipping' ),
				'working'       => __( 'Изпълнява се…', 'kanelov-shipping' ),
			],
		] );
	}

	/** @param \WP_Post|\WC_Order $post_or_order */
	public function render( $post_or_order ): void {
		$order = $post_or_order instanceof \WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order ) {
			return;
		}
		echo '<div id="ks-order" data-order="' . esc_attr( (string) $order->get_id() ) . '">';
		$this->render_inner( $order );
		echo '</div>';
	}

	private function render_inner( \WC_Order $order ): void {
		$delivery = OrderMeta::get_delivery( $order );
		$shipment = OrderMeta::get_shipment( $order );
		$carrier  = Plugin::instance()->carriers()->get( EcontCarrier::ID );
		$settings = new EcontSettings();
		$has      = ! empty( $shipment['number'] );
		$weight   = $this->order_weight( $order, $settings );
		?>
		<div class="ks-order__notices"></div>

		<?php if ( $has ) : ?>
			<div class="ks-shipment">
				<p class="ks-shipment__number">
					<strong><?php esc_html_e( 'Товарителница:', 'kanelov-shipping' ); ?></strong>
					<a href="<?php echo esc_url( EcontCarrier::tracking_url( $shipment['number'] ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $shipment['number'] ); ?></a>
					<?php if ( ( $shipment['env'] ?? '' ) === 'demo' ) : ?><span class="ks-badge ks-badge--demo">demo</span><?php endif; ?>
				</p>
				<p>
					<?php echo esc_html( sprintf( __( 'Създадена: %s', 'kanelov-shipping' ), wp_date( 'd.m.Y H:i', (int) $shipment['created_at'] ) ) ); ?>
					<?php if ( isset( $shipment['total_price'] ) ) : ?> · <?php echo esc_html( sprintf( __( 'Цена на Еконт: %s %s', 'kanelov-shipping' ), number_format( (float) $shipment['total_price'], 2 ), $shipment['currency'] ?? '' ) ); ?><?php endif; ?>
					<?php if ( ! empty( $shipment['cd_amount'] ) ) : ?> · <?php echo esc_html( sprintf( __( 'НП: %s', 'kanelov-shipping' ), number_format( (float) $shipment['cd_amount'], 2 ) ) ); ?><?php endif; ?>
					<?php if ( ! empty( $shipment['weight'] ) ) : ?> · <?php echo esc_html( $shipment['weight'] ); ?> кг<?php endif; ?>
				</p>
				<?php if ( ! empty( $shipment['status'] ) ) : ?>
					<p><strong><?php esc_html_e( 'Статус:', 'kanelov-shipping' ); ?></strong> <?php echo esc_html( $shipment['status'] ); ?> <small>(<?php echo esc_html( wp_date( 'd.m.Y H:i', (int) $shipment['status_time'] ) ); ?>)</small></p>
				<?php endif; ?>
				<p class="ks-actions">
					<?php if ( ! empty( $shipment['pdf_url'] ) ) : ?>
						<a class="button button-primary" href="<?php echo esc_url( $shipment['pdf_url'] ); ?>" target="_blank" rel="noopener">🖨 <?php esc_html_e( 'Печат PDF', 'kanelov-shipping' ); ?></a>
					<?php endif; ?>
					<button type="button" class="button ks-do" data-do="track"><?php esc_html_e( 'Проследи', 'kanelov-shipping' ); ?></button>
					<button type="button" class="button ks-do ks-do--danger" data-do="delete"><?php esc_html_e( 'Изтрий товарителницата', 'kanelov-shipping' ); ?></button>
				</p>
				<div class="ks-tracking"></div>
			</div>
		<?php endif; ?>

		<details class="ks-delivery-edit" <?php echo $delivery->is_empty() ? 'open' : ''; ?>>
			<summary>
				<strong><?php esc_html_e( 'Доставка:', 'kanelov-shipping' ); ?></strong>
				<?php echo $delivery->is_empty() ? esc_html__( 'не е избрана', 'kanelov-shipping' ) : esc_html( $carrier->format_delivery( $delivery ) ); ?>
				<span class="ks-edit-hint"><?php esc_html_e( '(редактирай)', 'kanelov-shipping' ); ?></span>
			</summary>
			<div class="ks-delivery ks-delivery--admin" id="ks-delivery">
				<?php DeliveryFormView::render( $delivery, true ); ?>
				<p><button type="button" class="button ks-do" data-do="save_delivery"><?php esc_html_e( 'Запази доставката', 'kanelov-shipping' ); ?></button></p>
			</div>
		</details>

		<?php if ( ! $has ) : ?>
			<div class="ks-label-form">
				<div class="ks-grid">
					<p class="form-row"><label><?php esc_html_e( 'Тегло (кг)', 'kanelov-shipping' ); ?><input type="number" step="0.001" min="0.1" name="ks_opt_weight" value="<?php echo esc_attr( (string) $weight ); ?>"></label></p>
					<p class="form-row"><label><?php esc_html_e( 'Пакети', 'kanelov-shipping' ); ?><input type="number" step="1" min="1" name="ks_opt_pack_count" value="1"></label></p>
					<p class="form-row"><label><?php esc_html_e( 'Наложен платеж', 'kanelov-shipping' ); ?><input type="text" readonly value="<?php echo esc_attr( $order->get_payment_method() === 'cod' ? wc_format_decimal( $order->get_total(), 2 ) . ' ' . $order->get_currency() : __( 'няма (платена онлайн)', 'kanelov-shipping' ) ); ?>"></label></p>
					<p class="form-row"><label><?php esc_html_e( 'Изпращане от', 'kanelov-shipping' ); ?>
						<select name="ks_opt_send_from">
							<option value="office" <?php selected( $settings->send_from(), 'office' ); ?>><?php esc_html_e( 'Офис (по настройка)', 'kanelov-shipping' ); ?></option>
							<option value="address" <?php selected( $settings->send_from(), 'address' ); ?>><?php esc_html_e( 'Адрес (куриер)', 'kanelov-shipping' ); ?></option>
						</select></label></p>
				</div>
				<p class="form-row"><label><?php esc_html_e( 'Описание на пратката', 'kanelov-shipping' ); ?><input type="text" name="ks_opt_description" placeholder="<?php esc_attr_e( 'по подразбиране: номер на поръчка + продукти', 'kanelov-shipping' ); ?>"></label></p>
				<p class="form-row ks-checks">
					<label><input type="checkbox" name="ks_opt_sms_notification" value="1" <?php checked( $settings->sms_notification() ); ?>> <?php esc_html_e( 'SMS до получателя', 'kanelov-shipping' ); ?></label>
					<label><input type="checkbox" name="ks_opt_declared" value="1" <?php checked( $settings->declared_value_threshold() > 0 && (float) $order->get_total() >= $settings->declared_value_threshold() ); ?>> <?php esc_html_e( 'Обявена стойност', 'kanelov-shipping' ); ?></label>
				</p>
				<p class="ks-actions">
					<button type="button" class="button ks-do" data-do="calculate"><?php esc_html_e( 'Изчисли цена', 'kanelov-shipping' ); ?></button>
					<button type="button" class="button button-primary ks-do" data-do="create"><?php esc_html_e( 'Създай товарителница', 'kanelov-shipping' ); ?></button>
					<span class="ks-calc-result"></span>
				</p>
			</div>
		<?php endif; ?>
		<?php
	}

	private function order_weight( \WC_Order $order, EcontSettings $settings ): float {
		$total = 0.0;
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$p = $item->get_product();
			if ( $p && $p->is_virtual() ) {
				continue;
			}
			$w      = $p && $p->get_weight() !== '' ? wc_get_weight( (float) $p->get_weight(), 'kg' ) : $settings->default_weight();
			$total += $w * $item->get_quantity();
		}
		return round( max( $settings->min_weight(), $total ), 3 );
	}

	public function ajax(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json_error( [ 'message' => __( 'Нямате права.', 'kanelov-shipping' ) ], 403 );
		}
		$order = wc_get_order( absint( $_POST['order_id'] ?? 0 ) );
		if ( ! $order ) {
			wp_send_json_error( [ 'message' => __( 'Поръчката не е намерена.', 'kanelov-shipping' ) ], 404 );
		}
		$do      = sanitize_key( $_POST['do'] ?? '' );
		$carrier = Plugin::instance()->carriers()->get( EcontCarrier::ID );
		$fields  = [];
		parse_str( (string) wp_unslash( $_POST['fields'] ?? '' ), $fields );
		$fields = wp_unslash( $fields );

		$message = '';
		$errors  = [];
		$extra   = [];

		switch ( $do ) {
			case 'save_delivery':
				$delivery = DeliveryFormView::from_request( $fields, EcontCarrier::ID );
				$errors   = $carrier->validate_delivery( $delivery );
				if ( ! $errors ) {
					OrderMeta::set_delivery( $order, $delivery );
					$text = $carrier->format_delivery( $delivery );
					$order->set_shipping_address_1( $text );
					$order->set_shipping_city( $delivery->city_name );
					$order->set_shipping_postcode( $delivery->post_code );
					$order->set_shipping_country( 'BG' );
					$order->add_order_note( sprintf( __( 'Еконт: доставката е променена на: %s', 'kanelov-shipping' ), $text ) );
					$order->save();
					$message = __( 'Доставката е запазена.', 'kanelov-shipping' );
				}
				break;
			case 'calculate':
			case 'create':
				$options = $this->options_from_fields( $fields, $order );
				$result  = $do === 'create' ? $carrier->create_label( $order, $options ) : $carrier->calculate( $order, $options );
				$errors  = $result->errors;
				if ( $result->success ) {
					$price   = $result->total_price !== null ? number_format( $result->total_price, 2 ) . ' ' . $result->currency : '?';
					$message = $do === 'create'
						? sprintf( __( 'Товарителница %1$s е създадена. Цена на Еконт: %2$s.', 'kanelov-shipping' ), $result->shipment_number, $price )
						: sprintf( __( 'Цена на Еконт: %s', 'kanelov-shipping' ), $price );
				}
				break;
			case 'delete':
				$result  = $carrier->delete_label( $order );
				$errors  = $result->errors;
				$message = $result->success ? __( 'Товарителницата е изтрита.', 'kanelov-shipping' ) : '';
				break;
			case 'track':
				$t      = $carrier->track( $order );
				$errors = $t->errors;
				if ( $t->success ) {
					$message = sprintf( __( 'Статус: %s', 'kanelov-shipping' ), $t->status );
					$extra['events'] = $t->events;
				}
				break;
			default:
				$errors[] = __( 'Непознато действие.', 'kanelov-shipping' );
		}

		$order = wc_get_order( $order->get_id() );
		ob_start();
		$this->render_inner( $order );
		$html = (string) ob_get_clean();

		if ( $errors ) {
			wp_send_json_error( [ 'message' => implode( ' ', $errors ), 'html' => $html ] + $extra );
		}
		wp_send_json_success( [ 'message' => $message, 'html' => $html ] + $extra );
	}

	private function options_from_fields( array $fields, \WC_Order $order ): array {
		$settings = new EcontSettings();
		$opt      = [
			'weight'           => (float) str_replace( ',', '.', (string) ( $fields['ks_opt_weight'] ?? 0 ) ),
			'pack_count'       => max( 1, (int) ( $fields['ks_opt_pack_count'] ?? 1 ) ),
			'description'      => sanitize_text_field( (string) ( $fields['ks_opt_description'] ?? '' ) ),
			'sms_notification' => ! empty( $fields['ks_opt_sms_notification'] ),
			'declared_value'   => ! empty( $fields['ks_opt_declared'] ) ? (float) $order->get_total() : 0,
		];
		$send_from = (string) ( $fields['ks_opt_send_from'] ?? $settings->send_from() );
		$opt['send_from'] = in_array( $send_from, [ 'office', 'address' ], true ) ? $send_from : $settings->send_from();
		return $opt;
	}
}
