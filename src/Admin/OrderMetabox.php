<?php
namespace Kanelov\Shipping\Admin;

use Kanelov\Shipping\Carrier\BoxNow\BoxNowCarrier;
use Kanelov\Shipping\Carrier\BoxNow\BoxNowLabelBuilder;
use Kanelov\Shipping\Carrier\BoxNow\BoxNowSettings;
use Kanelov\Shipping\Carrier\CarrierInterface;
use Kanelov\Shipping\Carrier\DeliveryData;
use Kanelov\Shipping\Carrier\Econt\EcontCarrier;
use Kanelov\Shipping\Carrier\Econt\EcontProfile;
use Kanelov\Shipping\Carrier\Econt\EcontSettings;
use Kanelov\Shipping\Checkout\BoxNowFormView;
use Kanelov\Shipping\Checkout\DeliveryFormView;
use Kanelov\Shipping\Order\OrderMeta;
use Kanelov\Shipping\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Кутия „Еконт“ / „Box Now“ в поръчката (според куриера на поръчката): данни за доставка (с редакция), параметри
 * на пратката, калкулация, създаване, изтриване, печат и проследяване на товарителницата. Всичко през admin-ajax с nonce.
 */
final class OrderMetabox {

	const AJAX  = 'ks_order_action';
	const NONCE = 'ks_order_nonce';

	public function register(): void {
		add_action( 'add_meta_boxes', [ $this, 'add' ], 10, 2 );
		add_action( 'wp_ajax_' . self::AJAX, [ $this, 'ajax' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
	}

	/** @param mixed $post_or_order */
	public function add( $screen = '', $post_or_order = null ): void {
		// wc_get_page_screen_id връща 'woocommerce_page_wc-orders' при HPOS и 'shop_order' при класическо съхранение.
		$order = $post_or_order instanceof \WC_Order ? $post_or_order : ( $post_or_order instanceof \WP_Post ? wc_get_order( $post_or_order->ID ) : null );
		$title = $order && self::carrier_of( $order )->id() === BoxNowCarrier::ID
			? __( 'Box Now – доставка и пратка', 'kanelov-shipping' )
			: __( 'Еконт – доставка и товарителница', 'kanelov-shipping' );
		add_meta_box( 'ks-econt', $title, [ $this, 'render' ], wc_get_page_screen_id( 'shop-order' ), 'normal', 'high' );
	}

	/** Куриерът на поръчката (по избраната доставка); Еконт, ако поръчката няма наша доставка. */
	public static function carrier_of( \WC_Order $order ): CarrierInterface {
		$id = OrderMeta::get_delivery( $order )->carrier;
		return Plugin::instance()->carriers()->get( $id ) ?? Plugin::instance()->carriers()->get( EcontCarrier::ID );
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
			'boxnow' => BoxNowFormView::js_config(),
			'i18nAdmin' => [
				'confirmDelete' => __( 'Да изтрия ли товарителницата от Еконт?', 'kanelov-shipping' ),
				'confirmCancel' => __( 'Да откажа ли пратката в Box Now?', 'kanelov-shipping' ),
				'confirmForget' => __( 'Да премахна ли записа само от сайта? Ако пратката още съществува в Еконт, тя остава там.', 'kanelov-shipping' ),
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
		if ( self::carrier_of( $order )->id() === BoxNowCarrier::ID ) {
			$this->render_boxnow( $order );
			return;
		}
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
						<a class="button button-primary" href="<?php echo esc_url( $shipment['pdf_url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Печат PDF', 'kanelov-shipping' ); ?></a>
					<?php endif; ?>
					<button type="button" class="button ks-do" data-do="track"><?php esc_html_e( 'Проследи', 'kanelov-shipping' ); ?></button>
					<button type="button" class="button ks-do ks-do--danger" data-do="delete"><?php esc_html_e( 'Изтрий товарителницата', 'kanelov-shipping' ); ?></button>
					<button type="button" class="button-link ks-do ks-do--forget" data-do="forget" title="<?php esc_attr_e( 'Само премахва номера от поръчката, без заявка към Еконт. За пратки, които вече сте изтрили в ee.econt.com.', 'kanelov-shipping' ); ?>"><?php esc_html_e( 'Премахни записа от сайта', 'kanelov-shipping' ); ?></button>
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
					<?php $dims = EcontCarrier::order_dimensions( $order, $settings ); ?>
					<p class="form-row ks-dims"><label><?php esc_html_e( 'Размери Д×Ш×В (см)', 'kanelov-shipping' ); ?>
						<span class="ks-dims__inputs">
							<input type="number" step="0.1" min="0" name="ks_opt_dim_l" value="<?php echo esc_attr( $dims ? (string) $dims[0] : '' ); ?>" placeholder="Д">
							<input type="number" step="0.1" min="0" name="ks_opt_dim_w" value="<?php echo esc_attr( $dims ? (string) $dims[1] : '' ); ?>" placeholder="Ш">
							<input type="number" step="0.1" min="0" name="ks_opt_dim_h" value="<?php echo esc_attr( $dims ? (string) $dims[2] : '' ); ?>" placeholder="В">
						</span></label></p>
					<p class="form-row"><label><?php esc_html_e( 'Наложен платеж', 'kanelov-shipping' ); ?><input type="text" readonly value="<?php echo esc_attr( $order->get_payment_method() === 'cod' ? wc_format_decimal( $order->get_total(), 2 ) . ' ' . $order->get_currency() : __( 'няма (платена онлайн)', 'kanelov-shipping' ) ); ?>"></label></p>
					<p class="form-row"><label><?php esc_html_e( 'Изпращане от', 'kanelov-shipping' ); ?>
						<select name="ks_opt_send_from">
							<option value="office" <?php selected( $settings->send_from(), 'office' ); ?>><?php esc_html_e( 'Офис (по настройка)', 'kanelov-shipping' ); ?></option>
							<option value="address" <?php selected( $settings->send_from(), 'address' ); ?>><?php esc_html_e( 'Адрес (куриер)', 'kanelov-shipping' ); ?></option>
						</select></label></p>
				</div>
				<p class="form-row"><label><?php esc_html_e( 'Описание на пратката', 'kanelov-shipping' ); ?><input type="text" name="ks_opt_description" placeholder="<?php esc_attr_e( 'по подразбиране: номер на поръчка + продукти', 'kanelov-shipping' ); ?>"></label></p>
				<div class="ks-grid">
					<p class="form-row"><label><?php esc_html_e( 'Преди плащане на НП', 'kanelov-shipping' ); ?>
						<select name="ks_opt_pay_after">
							<option value="" <?php selected( $settings->pay_after(), '' ); ?>><?php esc_html_e( 'Без преглед', 'kanelov-shipping' ); ?></option>
							<option value="accept" <?php selected( $settings->pay_after(), 'accept' ); ?>><?php esc_html_e( 'Преглед на пратката', 'kanelov-shipping' ); ?></option>
							<option value="test" <?php selected( $settings->pay_after(), 'test' ); ?>><?php esc_html_e( 'Тест на стоката', 'kanelov-shipping' ); ?></option>
						</select></label></p>
					<p class="form-row"><label><?php esc_html_e( 'Ако е почивен ден', 'kanelov-shipping' ); ?>
						<?php $holiday = $delivery->delivery_day ?: $settings->holiday_delivery_day(); ?>
						<select name="ks_opt_holiday">
							<option value="workday" <?php selected( $holiday, 'workday' ); ?>><?php esc_html_e( 'Първи работен ден', 'kanelov-shipping' ); ?></option>
							<option value="halfday" <?php selected( $holiday, 'halfday' ); ?>><?php esc_html_e( 'Събота', 'kanelov-shipping' ); ?></option>
						</select></label></p>
					<p class="form-row"><label><?php esc_html_e( 'Номер на фактура', 'kanelov-shipping' ); ?><input type="text" name="ks_opt_invoice_num" value="<?php echo esc_attr( $settings->invoice_num_from_order() ? EcontCarrier::invoice_num( $order ) : '' ); ?>" placeholder="<?php esc_attr_e( 'номер/дд.мм.гггг (по избор)', 'kanelov-shipping' ); ?>"></label></p>
				</div>
				<p class="form-row ks-checks">
					<label><input type="checkbox" name="ks_opt_sms_notification" value="1" <?php checked( $settings->sms_notification() ); ?>> <?php esc_html_e( 'SMS до получателя', 'kanelov-shipping' ); ?></label>
					<label><input type="checkbox" name="ks_opt_declared" value="1" <?php checked( $settings->declared_value_for( (float) $order->get_total() ) > 0 ); ?>> <?php esc_html_e( 'Обявена стойност', 'kanelov-shipping' ); ?></label>
					<label><input type="checkbox" name="ks_opt_packing_list" value="1" <?php checked( $settings->packing_list() ); ?>> <?php esc_html_e( 'Опис на стоките', 'kanelov-shipping' ); ?></label>
				</p>
				<?php $instr = ( new EcontProfile( $settings ) )->instruction_choices(); ?>
				<?php if ( $instr ) : ?>
					<p class="form-row ks-instructions"><strong><?php esc_html_e( 'Инструкции към куриера', 'kanelov-shipping' ); ?></strong>
						<?php foreach ( $instr as $id => $name ) : ?>
							<label><input type="checkbox" name="ks_opt_instructions[]" value="<?php echo esc_attr( $id ); ?>" <?php checked( in_array( (int) $id, $settings->instruction_ids(), true ) ); ?>> <?php echo esc_html( $name ); ?></label>
						<?php endforeach; ?>
					</p>
				<?php endif; ?>
				<p class="ks-actions">
					<button type="button" class="button ks-do" data-do="calculate"><?php esc_html_e( 'Изчисли цена', 'kanelov-shipping' ); ?></button>
					<button type="button" class="button button-primary ks-do" data-do="create"><?php esc_html_e( 'Създай товарителница', 'kanelov-shipping' ); ?></button>
					<span class="ks-calc-result"></span>
				</p>
			</div>
		<?php endif; ?>
		<?php
	}

	/** Кутията за поръчка с доставка до автомат на Box Now. */
	private function render_boxnow( \WC_Order $order ): void {
		$delivery = OrderMeta::get_delivery( $order );
		$shipment = OrderMeta::get_shipment( $order );
		$carrier  = Plugin::instance()->carriers()->get( BoxNowCarrier::ID );
		$settings = new BoxNowSettings();
		$has      = ! empty( $shipment['number'] );
		$weight   = $this->order_weight( $order, new EcontSettings() );
		$dims     = BoxNowCarrier::order_dimensions( $order );
		$auto     = $dims ? BoxNowLabelBuilder::compartment_for( $dims ) : $settings->default_compartment();
		?>
		<div class="ks-order__notices"></div>

		<?php if ( $has ) : ?>
			<div class="ks-shipment">
				<p class="ks-shipment__number">
					<strong><?php esc_html_e( 'Пратка Box Now:', 'kanelov-shipping' ); ?></strong>
					<?php echo esc_html( $shipment['number'] ); ?>
					<?php if ( ! empty( $shipment['order_ref'] ) ) : ?><small>(<?php echo esc_html( sprintf( __( 'заявка %s', 'kanelov-shipping' ), $shipment['order_ref'] ) ); ?>)</small><?php endif; ?>
					<?php if ( ( $shipment['env'] ?? '' ) === 'stage' ) : ?><span class="ks-badge ks-badge--demo">stage</span><?php endif; ?>
				</p>
				<p>
					<?php echo esc_html( sprintf( __( 'Създадена: %s', 'kanelov-shipping' ), wp_date( 'd.m.Y H:i', (int) $shipment['created_at'] ) ) ); ?>
					<?php if ( ! empty( $shipment['cd_amount'] ) ) : ?> · <?php echo esc_html( sprintf( __( 'НП: %s', 'kanelov-shipping' ), number_format( (float) $shipment['cd_amount'], 2 ) ) ); ?><?php endif; ?>
					<?php if ( ! empty( $shipment['compartment'] ) ) : ?> · <?php echo esc_html( sprintf( __( 'отделение %d', 'kanelov-shipping' ), (int) $shipment['compartment'] ) ); ?><?php endif; ?>
					<?php if ( ! empty( $shipment['weight'] ) ) : ?> · <?php echo esc_html( $shipment['weight'] ); ?> кг<?php endif; ?>
				</p>
				<?php if ( ! empty( $shipment['status'] ) ) : ?>
					<p><strong><?php esc_html_e( 'Статус:', 'kanelov-shipping' ); ?></strong> <?php echo esc_html( $shipment['status'] ); ?> <small>(<?php echo esc_html( wp_date( 'd.m.Y H:i', (int) $shipment['status_time'] ) ); ?>)</small></p>
				<?php endif; ?>
				<p class="ks-actions">
					<a class="button button-primary" href="<?php echo esc_url( $carrier->label_url( $order ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Печат PDF', 'kanelov-shipping' ); ?></a>
					<button type="button" class="button ks-do" data-do="track"><?php esc_html_e( 'Проследи', 'kanelov-shipping' ); ?></button>
					<button type="button" class="button ks-do ks-do--danger" data-do="delete"><?php esc_html_e( 'Откажи пратката', 'kanelov-shipping' ); ?></button>
					<button type="button" class="button-link ks-do ks-do--forget" data-do="forget" title="<?php esc_attr_e( 'Само премахва номера от поръчката, без заявка към Box Now.', 'kanelov-shipping' ); ?>"><?php esc_html_e( 'Премахни записа от сайта', 'kanelov-shipping' ); ?></button>
				</p>
				<div class="ks-tracking"></div>
			</div>
		<?php endif; ?>

		<details class="ks-delivery-edit" <?php echo $delivery->office_code === '' ? 'open' : ''; ?>>
			<summary>
				<strong><?php esc_html_e( 'Доставка:', 'kanelov-shipping' ); ?></strong>
				<?php echo $delivery->office_code === '' ? esc_html__( 'не е избран автомат', 'kanelov-shipping' ) : esc_html( $carrier->format_delivery( $delivery ) ); ?>
				<span class="ks-edit-hint"><?php esc_html_e( '(редактирай)', 'kanelov-shipping' ); ?></span>
			</summary>
			<div class="ks-delivery ks-delivery--admin" id="ks-delivery" data-carrier="<?php echo esc_attr( BoxNowCarrier::ID ); ?>">
				<?php BoxNowFormView::render( $delivery, true ); ?>
				<p><button type="button" class="button ks-do" data-do="save_delivery"><?php esc_html_e( 'Запази доставката', 'kanelov-shipping' ); ?></button></p>
			</div>
		</details>

		<?php if ( ! $has ) : ?>
			<div class="ks-label-form">
				<div class="ks-grid">
					<p class="form-row"><label><?php esc_html_e( 'Тегло (кг)', 'kanelov-shipping' ); ?><input type="number" step="0.001" min="0.1" max="20" name="ks_opt_weight" value="<?php echo esc_attr( (string) $weight ); ?>"></label></p>
					<p class="form-row"><label><?php esc_html_e( 'Отделение', 'kanelov-shipping' ); ?>
						<select name="ks_opt_compartment">
							<option value="0"><?php echo esc_html( $auto > 0 ? sprintf( __( 'Автоматично (%d)', 'kanelov-shipping' ), $auto ) : __( 'Автоматично (не се побира!)', 'kanelov-shipping' ) ); ?></option>
							<option value="1"><?php esc_html_e( '1 – малко (до 8 см)', 'kanelov-shipping' ); ?></option>
							<option value="2"><?php esc_html_e( '2 – средно (до 17 см)', 'kanelov-shipping' ); ?></option>
							<option value="3"><?php esc_html_e( '3 – голямо (до 36 см)', 'kanelov-shipping' ); ?></option>
						</select></label></p>
					<p class="form-row"><label><?php esc_html_e( 'Размери на пратката (см)', 'kanelov-shipping' ); ?><input type="text" readonly value="<?php echo esc_attr( $dims ? implode( ' × ', $dims ) : __( 'няма (от настройките)', 'kanelov-shipping' ) ); ?>"></label></p>
					<p class="form-row"><label><?php esc_html_e( 'Наложен платеж', 'kanelov-shipping' ); ?><input type="text" readonly value="<?php echo esc_attr( $order->get_payment_method() === 'cod' ? wc_format_decimal( $order->get_total(), 2 ) . ' ' . $order->get_currency() : __( 'няма (платена онлайн)', 'kanelov-shipping' ) ); ?>"></label></p>
				</div>
				<p class="form-row"><label><?php esc_html_e( 'Описание на пратката', 'kanelov-shipping' ); ?><input type="text" name="ks_opt_description" placeholder="<?php esc_attr_e( 'по подразбиране: номер на поръчка + продукти', 'kanelov-shipping' ); ?>"></label></p>
				<p class="ks-actions">
					<button type="button" class="button ks-do" data-do="calculate"><?php esc_html_e( 'Провери заявката', 'kanelov-shipping' ); ?></button>
					<button type="button" class="button button-primary ks-do" data-do="create"><?php esc_html_e( 'Създай пратка', 'kanelov-shipping' ); ?></button>
					<span class="ks-calc-result"></span>
				</p>
				<p class="description"><?php esc_html_e( 'Box Now взима пратката от склада ви по договорения график; етикетът се разпечатва оттук след създаването.', 'kanelov-shipping' ); ?></p>
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
		$carrier = self::carrier_of( $order );
		$boxnow  = $carrier->id() === BoxNowCarrier::ID;
		$fields  = [];
		parse_str( (string) ( $_POST['fields'] ?? '' ), $fields ); // стойностите остават със slash-ове на WP; from_request ги маха веднъж

		$message = '';
		$errors  = [];
		$extra   = [];

		switch ( $do ) {
			case 'save_delivery':
				$delivery = $boxnow ? BoxNowFormView::from_request( $fields ) : DeliveryFormView::from_request( $fields, EcontCarrier::ID );
				$errors   = $carrier->validate_delivery( $delivery );
				if ( ! $errors ) {
					OrderMeta::set_delivery( $order, $delivery );
					$text = $carrier->format_delivery( $delivery );
					$order->set_shipping_address_1( $text );
					$order->set_shipping_city( $delivery->city_name );
					$order->set_shipping_postcode( $delivery->post_code );
					$order->set_shipping_country( 'BG' );
					$order->add_order_note( sprintf( __( '%1$s: доставката е променена на: %2$s', 'kanelov-shipping' ), $carrier->label(), $text ) );
					$order->save();
					$message = __( 'Доставката е запазена.', 'kanelov-shipping' );
				}
				break;
			case 'calculate':
			case 'create':
				$options = $boxnow ? $this->boxnow_options_from_fields( $fields ) : $this->options_from_fields( $fields, $order );
				$result  = $do === 'create' ? $carrier->create_label( $order, $options ) : $carrier->calculate( $order, $options );
				$errors  = $result->errors;
				if ( $result->success && $boxnow ) {
					$message = $do === 'create'
						? sprintf( __( 'Пратка %s е създадена в Box Now.', 'kanelov-shipping' ), $result->shipment_number ) . ( $result->pdf_url === '' ? ' ' . __( 'Етикетът ще се свали при натискане на „Печат PDF“.', 'kanelov-shipping' ) : '' )
						: $result->message;
				} elseif ( $result->success ) {
					$price   = $result->total_price !== null ? number_format( $result->total_price, 2 ) . ' ' . $result->currency : '?';
					$message = $do === 'create'
						? sprintf( __( 'Товарителница %1$s е създадена. Цена на Еконт: %2$s.', 'kanelov-shipping' ), $result->shipment_number, $price )
						: sprintf( __( 'Цена на Еконт: %s', 'kanelov-shipping' ), $price );
				}
				break;
			case 'delete':
				$result  = $carrier->delete_label( $order );
				$errors  = $result->errors;
				$message = $result->success ? ( $result->message ?: ( $boxnow ? __( 'Пратката е отказана в Box Now.', 'kanelov-shipping' ) : __( 'Товарителницата е изтрита.', 'kanelov-shipping' ) ) ) : '';
				break;
			case 'forget':
				$result  = $carrier->forget_label( $order, __( 'по избор на оператора', 'kanelov-shipping' ) );
				$errors  = $result->errors;
				$message = $result->success ? sprintf( __( 'Записът е премахнат от сайта. В %s нищо не е променено.', 'kanelov-shipping' ), $carrier->label() ) : '';
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

	private function boxnow_options_from_fields( array $fields ): array {
		return [
			'weight'      => (float) str_replace( ',', '.', (string) ( $fields['ks_opt_weight'] ?? 0 ) ),
			'compartment' => (int) ( $fields['ks_opt_compartment'] ?? 0 ),
			'description' => sanitize_text_field( (string) ( $fields['ks_opt_description'] ?? '' ) ),
		];
	}

	private function options_from_fields( array $fields, \WC_Order $order ): array {
		$settings = new EcontSettings();
		$opt      = [
			'weight'           => (float) str_replace( ',', '.', (string) ( $fields['ks_opt_weight'] ?? 0 ) ),
			'pack_count'       => max( 1, (int) ( $fields['ks_opt_pack_count'] ?? 1 ) ),
			'description'      => sanitize_text_field( (string) ( $fields['ks_opt_description'] ?? '' ) ),
			'sms_notification' => ! empty( $fields['ks_opt_sms_notification'] ),
			'declared_value'   => ! empty( $fields['ks_opt_declared'] ) ? (float) $order->get_total() : 0,
			'packing_list'     => ! empty( $fields['ks_opt_packing_list'] ),
			'dimensions'       => [ (float) str_replace( ',', '.', (string) ( $fields['ks_opt_dim_l'] ?? 0 ) ), (float) str_replace( ',', '.', (string) ( $fields['ks_opt_dim_w'] ?? 0 ) ), (float) str_replace( ',', '.', (string) ( $fields['ks_opt_dim_h'] ?? 0 ) ) ],
			'invoice_num'      => trim( (string) ( $fields['ks_opt_invoice_num'] ?? '' ) ) !== '' ? EcontCarrier::invoice_num( $order, sanitize_text_field( (string) $fields['ks_opt_invoice_num'] ) ) : '',
			'pay_after'        => in_array( $fields['ks_opt_pay_after'] ?? '', [ 'accept', 'test' ], true ) ? $fields['ks_opt_pay_after'] : '',
			'holiday_delivery_day' => ( $fields['ks_opt_holiday'] ?? '' ) === 'halfday' ? 'halfday' : 'workday',
			'instructions'     => ( new EcontProfile( $settings ) )->instructions_for( array_map( 'intval', (array) ( $fields['ks_opt_instructions'] ?? [] ) ) ),
		];
		$send_from = (string) ( $fields['ks_opt_send_from'] ?? $settings->send_from() );
		$opt['send_from'] = in_array( $send_from, [ 'office', 'address' ], true ) ? $send_from : $settings->send_from();
		return $opt;
	}
}
