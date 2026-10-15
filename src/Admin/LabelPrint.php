<?php
namespace Kanelov\Shipping\Admin;

use Kanelov\Shipping\Order\OrderMeta;
use Kanelov\Shipping\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Масов печат на товарителници: страница „Доставки > Печат на товарителници“, на която етикетите на избраните поръчки
 * (Еконт и Box Now) се събират в един PDF в браузъра (pdf-lib). Всеки етикет идва през admin-ajax като base64,
 * за да не зависи от CORS или от изпращане на PDF директно от PHP. Без избрани поръчки страницата предлага
 * неотпечатаните товарителници от последните 14 дни.
 */
final class LabelPrint {

	const PAGE  = 'ks-print-labels';
	const AJAX  = 'ks_label_pdf_data';
	const NONCE = 'ks_print_labels';
	const MAX   = 100;

	/** @var array{0: \WC_Order[], 1: \WC_Order[]}|null */
	private ?array $split = null;

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add' ], 61 );
		add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
		add_action( 'wp_ajax_' . self::AJAX, [ $this, 'ajax' ] );
	}

	/** Адрес на страницата за печат на дадени поръчки. */
	public static function url( array $ids ): string {
		$ids = array_slice( array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) ), 0, self::MAX );
		return wp_nonce_url( admin_url( 'admin.php?page=' . self::PAGE . '&orders=' . implode( ',', $ids ) ), self::NONCE );
	}

	public function add(): void {
		add_submenu_page( Menu::parent(), __( 'Печат на товарителници', 'kanelov-shipping' ), __( 'Печат на товарителници', 'kanelov-shipping' ), 'edit_shop_orders', self::PAGE, [ $this, 'render' ] );
	}

	private static function is_page(): bool {
		return isset( $_GET['page'] ) && $_GET['page'] === self::PAGE; // phpcs:ignore WordPress.Security.NonceVerification
	}

	public function assets(): void {
		if ( ! self::is_page() ) {
			return;
		}
		wp_enqueue_style( 'ks-admin', KS_URL . 'assets/css/admin.css', [], KS_VERSION );
		wp_enqueue_script( 'ks-pdf-lib', KS_URL . 'assets/vendor/pdf-lib/pdf-lib.min.js', [], '1.17.1', true );
		wp_enqueue_script( 'ks-print-labels', KS_URL . 'assets/js/print-labels.js', [ 'ks-pdf-lib' ], KS_VERSION, true );
		wp_localize_script( 'ks-print-labels', 'ksPrint', [
			'ajax'   => admin_url( 'admin-ajax.php' ),
			'action' => self::AJAX,
			'nonce'  => wp_create_nonce( self::NONCE ),
			'orders' => array_map( static fn( \WC_Order $o ) => $o->get_id(), $this->split()[0] ),
			'i18n'   => [
				'loading' => __( 'зарежда се…', 'kanelov-shipping' ),
				'ok'      => __( 'готово', 'kanelov-shipping' ),
				'failed'  => __( 'грешка', 'kanelov-shipping' ),
				'none'    => __( 'Нито един етикет не беше зареден.', 'kanelov-shipping' ),
				'ready'   => __( 'Готови за печат: %d етикета.', 'kanelov-shipping' ),
				'file'    => __( 'tovaritelnici', 'kanelov-shipping' ),
			],
		] );
	}

	/** Поръчките от адреса (с проверен nonce) или неотпечатаните от последните 14 дни. */
	private function requested_orders(): array {
		$raw = isset( $_GET['orders'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['orders'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( $raw !== '' && wp_verify_nonce( (string) ( $_GET['_wpnonce'] ?? '' ), self::NONCE ) ) { // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
			$orders = [];
			foreach ( array_slice( array_filter( array_map( 'intval', explode( ',', $raw ) ) ), 0, self::MAX ) as $id ) {
				$order = wc_get_order( $id );
				if ( $order instanceof \WC_Order ) {
					$orders[] = $order;
				}
			}
		} else {
			$orders = array_filter(
				(array) wc_get_orders( [
					'limit'        => self::MAX,
					'type'         => 'shop_order',
					'date_created' => '>' . ( time() - 14 * DAY_IN_SECONDS ),
					'meta_query'   => [ [ 'key' => OrderMeta::SHIPMENT_NUMBER, 'compare' => 'EXISTS' ] ], // phpcs:ignore WordPress.DB.SlowDBQuery
				] ),
				static fn( $o ) => $o instanceof \WC_Order && empty( OrderMeta::get_shipment( $o )['printed_at'] )
			);
		}
		usort( $orders, static fn( \WC_Order $a, \WC_Order $b ) => ( $a->get_date_created()?->getTimestamp() ?? 0 ) <=> ( $b->get_date_created()?->getTimestamp() ?? 0 ) ?: $a->get_id() <=> $b->get_id() );
		return array_values( $orders );
	}

	/** Поръчките на страницата, разделени веднъж на заявка. */
	private function split(): array {
		return $this->split ??= $this->printable( $this->requested_orders() );
	}

	/** @return array{0: \WC_Order[], 1: \WC_Order[]} [с товарителница, без товарителница] */
	private function printable( array $orders ): array {
		$with    = [];
		$without = [];
		foreach ( $orders as $order ) {
			if ( ! empty( OrderMeta::get_shipment( $order )['number'] ) ) {
				$with[] = $order;
			} else {
				$without[] = $order;
			}
		}
		return [ $with, $without ];
	}

	public function render(): void {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'Нямате права.', 'kanelov-shipping' ) );
		}
		$explicit          = isset( $_GET['orders'] ); // phpcs:ignore WordPress.Security.NonceVerification
		[ $with, $without ] = $this->split();
		?>
		<div class="wrap ks-print">
			<h1><?php esc_html_e( 'Печат на товарителници', 'kanelov-shipping' ); ?></h1>
			<?php if ( ! $explicit ) : ?>
				<p class="description"><?php esc_html_e( 'Неотпечатаните товарителници от последните 14 дни. За други поръчки изберете ги в списъка с поръчки и изберете действието „Печат на товарителници“.', 'kanelov-shipping' ); ?></p>
			<?php endif; ?>

			<?php if ( ! $with ) : ?>
				<div class="notice notice-info inline"><p><?php esc_html_e( 'Няма товарителници за печат.', 'kanelov-shipping' ); ?></p></div>
			<?php else : ?>
				<div class="ks-print__bar">
					<label for="ks-print-layout"><?php esc_html_e( 'Подредба', 'kanelov-shipping' ); ?></label>
					<select id="ks-print-layout">
						<option value="label"><?php esc_html_e( 'По един етикет на страница (етикетен принтер 10×15 см)', 'kanelov-shipping' ); ?></option>
						<option value="a4"><?php esc_html_e( 'A4, по 4 етикета на страница', 'kanelov-shipping' ); ?></option>
					</select>
					<button type="button" class="button button-primary" id="ks-print-open" disabled><?php esc_html_e( 'Отвори за печат', 'kanelov-shipping' ); ?></button>
					<a class="button" id="ks-print-download" aria-disabled="true"><?php esc_html_e( 'Свали PDF', 'kanelov-shipping' ); ?></a>
					<span class="ks-print__status" id="ks-print-status" role="status"></span>
				</div>

				<ol class="ks-print__list">
					<?php
					foreach ( $with as $order ) :
						$shipment = OrderMeta::get_shipment( $order );
						$carrier  = Plugin::instance()->carriers()->get( (string) ( $shipment['carrier'] ?? OrderMeta::get_delivery( $order )->carrier ) );
						?>
						<li data-order="<?php echo esc_attr( (string) $order->get_id() ); ?>">
							<a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>">#<?php echo esc_html( $order->get_order_number() ); ?></a>
							<?php echo esc_html( ( $carrier ? $carrier->label() : '' ) . ' ' . $shipment['number'] . ' · ' . trim( $order->get_formatted_billing_full_name() ) ); ?>
							<?php if ( ! empty( $shipment['printed_at'] ) ) : ?>
								<span class="ks-print__again"><?php echo esc_html( sprintf( __( 'отпечатана на %s', 'kanelov-shipping' ), wp_date( 'd.m.Y H:i', (int) $shipment['printed_at'] ) ) ); ?></span>
							<?php endif; ?>
							<span class="ks-print__state"></span>
						</li>
					<?php endforeach; ?>
				</ol>

				<iframe id="ks-print-frame" class="ks-print__frame" title="<?php esc_attr_e( 'Товарителници за печат', 'kanelov-shipping' ); ?>" hidden></iframe>
			<?php endif; ?>

			<?php if ( $without ) : ?>
				<h2><?php esc_html_e( 'Без товарителница (пропуснати)', 'kanelov-shipping' ); ?></h2>
				<ul class="ks-print__skipped">
					<?php foreach ( $without as $order ) : ?>
						<li><a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>">#<?php echo esc_html( $order->get_order_number() ); ?></a> <?php echo esc_html( trim( $order->get_formatted_billing_full_name() ) ); ?></li>
					<?php endforeach; ?>
				</ul>
				<p class="description"><?php esc_html_e( 'За тях използвайте „Създай товарителници и печат“ в списъка с поръчки.', 'kanelov-shipping' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/** Един етикет като base64 PDF. Отбелязва поръчката като отпечатана. */
	public function ajax(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json_error( [ 'message' => __( 'Нямате права.', 'kanelov-shipping' ) ], 403 );
		}
		$order = wc_get_order( absint( $_POST['order_id'] ?? 0 ) );
		if ( ! $order instanceof \WC_Order ) {
			wp_send_json_error( [ 'message' => __( 'Поръчката не е намерена.', 'kanelov-shipping' ) ], 404 );
		}
		$shipment = OrderMeta::get_shipment( $order );
		$carrier  = Plugin::instance()->carriers()->get( (string) ( $shipment['carrier'] ?? OrderMeta::get_delivery( $order )->carrier ) );
		if ( empty( $shipment['number'] ) || ! $carrier ) {
			wp_send_json_error( [ 'message' => __( 'Поръчката няма товарителница.', 'kanelov-shipping' ) ] );
		}
		try {
			$pdf = $carrier->label_pdf( $order );
		} catch ( \RuntimeException $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
		$order    = wc_get_order( $order->get_id() ); // label_pdf може да е записал файла на етикета
		$shipment = OrderMeta::get_shipment( $order );
		$shipment['printed_at'] = time();
		OrderMeta::set_shipment( $order, $shipment );
		$order->save();
		wp_send_json_success( [ 'pdf' => base64_encode( $pdf ), 'number' => (string) $shipment['number'] ] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}
}
