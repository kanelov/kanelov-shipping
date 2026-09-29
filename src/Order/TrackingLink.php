<?php
namespace Kanelov\Shipping\Order;

use Kanelov\Shipping\Plugin;

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

	/** [куриер, номер, адрес за проследяване] или null, ако поръчката няма товарителница. */
	private function shipment( \WC_Order $order ): ?array {
		$shipment = OrderMeta::get_shipment( $order );
		if ( empty( $shipment['number'] ) ) {
			return null;
		}
		$carrier = Plugin::instance()->carriers()->get( (string) ( $shipment['carrier'] ?? OrderMeta::get_delivery( $order )->carrier ) );
		return [ $carrier ? $carrier->label() : '', (string) $shipment['number'], $carrier ? $carrier->tracking_link( (string) $shipment['number'] ) : '' ];
	}

	private function block( \WC_Order $order ): string {
		$s = $this->shipment( $order );
		if ( ! $s ) {
			return '';
		}
		[ $label, $number, $url ] = $s;
		$title = sprintf( __( 'Товарителница %s:', 'kanelov-shipping' ), $label );
		if ( $url === '' ) {
			return sprintf( '<p class="ks-tracking-link"><strong>%s</strong> %s</p>', esc_html( $title ), esc_html( $number ) );
		}
		return sprintf(
			'<p class="ks-tracking-link"><strong>%s</strong> <a href="%s" target="_blank" rel="noopener">%s</a><br><a href="%s" style="display:inline-block;margin-top:8px;padding:10px 18px;background:#1c4fa1;color:#fff;text-decoration:none;border-radius:4px">%s</a></p>',
			esc_html( $title ),
			esc_url( $url ),
			esc_html( $number ),
			esc_url( $url ),
			esc_html__( 'Проследи пратката', 'kanelov-shipping' )
		);
	}

	public function email( $order, $sent_to_admin = false, $plain_text = false, $email = null ): void {
		if ( $sent_to_admin || ! $order instanceof \WC_Order ) {
			return;
		}
		$s = $this->shipment( $order );
		if ( ! $s ) {
			return;
		}
		if ( $plain_text ) {
			echo "\n" . esc_html( sprintf( __( 'Товарителница %1$s: %2$s', 'kanelov-shipping' ), $s[0], $s[1] ) ) . ( $s[2] !== '' ? ' ' . esc_url( $s[2] ) : '' ) . "\n";
			return;
		}
		echo wp_kses_post( $this->block( $order ) );
	}

	public function account( \WC_Order $order ): void {
		echo wp_kses_post( $this->block( $order ) );
	}
}
