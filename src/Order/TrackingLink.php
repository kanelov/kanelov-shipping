<?php
namespace Kanelov\Shipping\Order;

use Kanelov\Shipping\Carrier\Econt\EcontCarrier;

defined( 'ABSPATH' ) || exit;

/**
 * Линк за проследяване в имейлите към клиента и в „Моят акаунт“, когато поръчката има товарителница.
 * Няма отделен имейл: ползва се стандартният имейл на WooCommerce (напр. „Изпълнена поръчка“).
 */
final class TrackingLink {

	public function register(): void {
		add_action( 'woocommerce_email_after_order_table', [ $this, 'email' ], 10, 4 );
		add_action( 'woocommerce_order_details_after_order_table', [ $this, 'account' ] );
	}

	private function block( \WC_Order $order ): string {
		$shipment = OrderMeta::get_shipment( $order );
		if ( empty( $shipment['number'] ) ) {
			return '';
		}
		$url = EcontCarrier::tracking_url( (string) $shipment['number'] );
		return sprintf(
			'<p class="ks-tracking-link"><strong>%s</strong> <a href="%s" target="_blank" rel="noopener">%s</a><br><a href="%s" style="display:inline-block;margin-top:8px;padding:10px 18px;background:#1c4fa1;color:#fff;text-decoration:none;border-radius:4px">%s</a></p>',
			esc_html__( 'Товарителница Еконт:', 'kanelov-shipping' ),
			esc_url( $url ),
			esc_html( (string) $shipment['number'] ),
			esc_url( $url ),
			esc_html__( 'Проследи пратката', 'kanelov-shipping' )
		);
	}

	public function email( $order, $sent_to_admin = false, $plain_text = false, $email = null ): void {
		if ( $sent_to_admin || ! $order instanceof \WC_Order ) {
			return;
		}
		$shipment = OrderMeta::get_shipment( $order );
		if ( empty( $shipment['number'] ) ) {
			return;
		}
		if ( $plain_text ) {
			echo "\n" . esc_html__( 'Проследи пратката:', 'kanelov-shipping' ) . ' ' . esc_url( EcontCarrier::tracking_url( (string) $shipment['number'] ) ) . "\n";
			return;
		}
		echo wp_kses_post( $this->block( $order ) );
	}

	public function account( \WC_Order $order ): void {
		echo wp_kses_post( $this->block( $order ) );
	}
}
