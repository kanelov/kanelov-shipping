<?php
namespace Kanelov\Shipping\Admin;

use Kanelov\Shipping\Carrier\Econt\EcontCarrier;
use Kanelov\Shipping\Order\OrderMeta;
use Kanelov\Shipping\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Колона „Еконт“ в списъка с поръчки (с бутон за бърза товарителница) и масово действие „Създай товарителници“
 * (по дата на поръчката).
 * Регистрира се и за класическия екран (shop_order), и за HPOS (wc-orders).
 */
final class OrdersList {

	const COLUMN = 'ks_shipment';
	const BULK   = 'ks_create_labels';
	const SINGLE = 'ks_create_label';

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
		add_action( 'admin_post_' . self::SINGLE, [ $this, 'handle_single' ] );
	}

	/** Линк за бърза товарителница от списъка (със стандартните настройки). */
	public static function single_url( int $order_id ): string {
		return wp_nonce_url( admin_url( 'admin-post.php?action=' . self::SINGLE . '&order=' . $order_id ), self::SINGLE . '_' . $order_id );
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
			printf(
				'<a class="button button-small ks-col-create" href="%s" title="%s">%s</a>',
				esc_url( self::single_url( $order->get_id() ) ),
				esc_attr__( 'Създава товарителница със стандартните настройки. За тегло, пакети или други опции отворете поръчката.', 'kanelov-shipping' ),
				esc_html__( 'Създай товарителница', 'kanelov-shipping' )
			);
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

		// По дата на поръчката (най-старата първа), за да излизат товарителниците подред в Еконт.
		$orders = [];
		foreach ( array_slice( $ids, 0, 50 ) as $id ) {
			$order = wc_get_order( (int) $id );
			if ( $order ) {
				$orders[] = $order;
			}
		}
		usort( $orders, static fn( \WC_Order $a, \WC_Order $b ) => ( $a->get_date_created()?->getTimestamp() ?? 0 ) <=> ( $b->get_date_created()?->getTimestamp() ?? 0 ) ?: $a->get_id() <=> $b->get_id() );

		foreach ( $orders as $order ) {
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

	/** Единична товарителница от списъка: admin-post с nonce, после обратно към списъка. */
	public function handle_single(): void {
		$order_id = (int) ( $_GET['order'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification -- проверява се по-долу.
		check_admin_referer( self::SINGLE . '_' . $order_id );
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'Нямате права.', 'kanelov-shipping' ) );
		}
		$order = wc_get_order( $order_id );
		$ok    = 0;
		$fail  = [];
		if ( ! $order ) {
			$fail[] = __( 'Поръчката не е намерена.', 'kanelov-shipping' );
		} elseif ( OrderMeta::get_delivery( $order )->is_empty() ) {
			$fail[] = sprintf( '#%s: %s', $order->get_order_number(), __( 'не е с доставка Еконт', 'kanelov-shipping' ) );
		} else {
			$result = Plugin::instance()->carriers()->get( EcontCarrier::ID )->create_label( $order );
			if ( $result->success ) {
				$ok = 1;
			} else {
				$fail[] = sprintf( '#%s: %s', $order->get_order_number(), implode( '; ', $result->errors ) );
			}
		}
		set_transient( 'ks_bulk_notice_' . get_current_user_id(), [ 'ok' => $ok, 'fail' => $fail ], 120 );
		$back = wp_get_referer() ?: admin_url( 'admin.php?page=wc-orders' );
		wp_safe_redirect( $back );
		exit;
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
