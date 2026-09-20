<?php
namespace Kanelov\Shipping\Admin;

use Kanelov\Shipping\Carrier\Econt\EcontCarrier;
use Kanelov\Shipping\Order\OrderMeta;
use Kanelov\Shipping\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Колона „Еконт“ в списъка с поръчки и масово действие „Създай товарителници“.
 * Регистрира се и за класическия екран (shop_order), и за HPOS (wc-orders).
 */
final class OrdersList {

	const COLUMN = 'ks_shipment';
	const BULK   = 'ks_create_labels';

	public function register(): void {
		foreach ( [ 'manage_edit-shop_order_columns', 'manage_woocommerce_page_wc-orders_columns' ] as $hook ) {
			add_filter( $hook, [ $this, 'columns' ], 20 );
		}
		add_action( 'manage_shop_order_posts_custom_column', [ $this, 'column_legacy' ], 10, 2 );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', [ $this, 'column_hpos' ], 10, 2 );

		foreach ( [ 'bulk_actions-edit-shop_order', 'bulk_actions-woocommerce_page_wc-orders' ] as $hook ) {
			add_filter( $hook, [ $this, 'bulk_actions' ] );
		}
		foreach ( [ 'handle_bulk_actions-edit-shop_order', 'handle_bulk_actions-woocommerce_page_wc-orders' ] as $hook ) {
			add_filter( $hook, [ $this, 'handle_bulk' ], 10, 3 );
		}
		add_action( 'admin_notices', [ $this, 'bulk_notice' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
	}

	public function assets(): void {
		$screen = get_current_screen();
		if ( $screen && in_array( $screen->id, [ 'edit-shop_order', 'woocommerce_page_wc-orders' ], true ) ) {
			wp_enqueue_style( 'ks-admin', KS_URL . 'assets/css/admin.css', [], KS_VERSION );
		}
	}

	public function columns( array $columns ): array {
		$out = [];
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( $key === 'order_total' ) {
				$out[ self::COLUMN ] = __( 'Еконт', 'kanelov-shipping' );
			}
		}
		if ( ! isset( $out[ self::COLUMN ] ) ) {
			$out[ self::COLUMN ] = __( 'Еконт', 'kanelov-shipping' );
		}
		return $out;
	}

	public function column_legacy( string $column, int $post_id ): void {
		if ( $column === self::COLUMN ) {
			$this->cell( wc_get_order( $post_id ) );
		}
	}

	public function column_hpos( string $column, $order ): void {
		if ( $column === self::COLUMN ) {
			$this->cell( $order instanceof \WC_Order ? $order : wc_get_order( $order ) );
		}
	}

	private function cell( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$delivery = OrderMeta::get_delivery( $order );
		if ( $delivery->is_empty() ) {
			echo '<span class="ks-col-shipment">–</span>';
			return;
		}
		$shipment = OrderMeta::get_shipment( $order );
		$carrier  = Plugin::instance()->carriers()->get( $delivery->carrier );
		echo '<span class="ks-col-shipment">';
		if ( ! empty( $shipment['number'] ) ) {
			printf(
				'<a href="%s" target="_blank" rel="noopener">%s</a>%s',
				esc_url( ! empty( $shipment['pdf_url'] ) ? $shipment['pdf_url'] : EcontCarrier::tracking_url( $shipment['number'] ) ),
				esc_html( $shipment['number'] ),
				! empty( $shipment['status'] ) ? '<span class="ks-mini">' . esc_html( $shipment['status'] ) . '</span>' : ''
			);
		} else {
			echo '<span class="ks-mini">' . esc_html__( 'без товарителница', 'kanelov-shipping' ) . '</span>';
		}
		if ( $carrier ) {
			echo '<span class="ks-mini">' . esc_html( $carrier->format_delivery( $delivery ) ) . '</span>';
		}
		echo '</span>';
	}

	public function bulk_actions( array $actions ): array {
		$actions[ self::BULK ] = __( 'Еконт: създай товарителници', 'kanelov-shipping' );
		return $actions;
	}

	public function handle_bulk( string $redirect, string $action, array $ids ): string {
		if ( $action !== self::BULK || ! current_user_can( 'edit_shop_orders' ) ) {
			return $redirect;
		}
		$carrier = Plugin::instance()->carriers()->get( EcontCarrier::ID );
		$ok      = 0;
		$fail    = [];
		foreach ( array_slice( $ids, 0, 50 ) as $id ) {
			$order = wc_get_order( (int) $id );
			if ( ! $order ) {
				continue;
			}
			if ( OrderMeta::get_delivery( $order )->is_empty() ) {
				$fail[] = sprintf( '#%s: %s', $order->get_order_number(), __( 'не е с доставка Еконт', 'kanelov-shipping' ) );
				continue;
			}
			if ( ! empty( OrderMeta::get_shipment( $order )['number'] ) ) {
				continue; // вече има
			}
			$result = $carrier->create_label( $order );
			if ( $result->success ) {
				$ok++;
			} else {
				$fail[] = sprintf( '#%s: %s', $order->get_order_number(), implode( '; ', $result->errors ) );
			}
		}
		set_transient( 'ks_bulk_notice_' . get_current_user_id(), [ 'ok' => $ok, 'fail' => $fail ], 120 );
		return $redirect;
	}

	public function bulk_notice(): void {
		$key    = 'ks_bulk_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) ) {
			return;
		}
		delete_transient( $key );
		printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( sprintf( __( 'Еконт: създадени %d товарителници.', 'kanelov-shipping' ), (int) $notice['ok'] ) ) );
		if ( ! empty( $notice['fail'] ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Неуспешни:', 'kanelov-shipping' ) . '</p><ul>';
			foreach ( $notice['fail'] as $line ) {
				echo '<li>' . esc_html( $line ) . '</li>';
			}
			echo '</ul></div>';
		}
	}
}
