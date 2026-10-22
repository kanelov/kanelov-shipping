<?php
namespace Kanelov\Shipping\Order;

use Kanelov\Shipping\Carrier\CarrierInterface;
use Kanelov\Shipping\Carrier\DeliveryData;

defined( 'ABSPATH' ) || exit;

/**
 * Смяна на куриера или вида доставка в съществуваща поръчка (от кутията в админа): новата цена се смята по
 * настройките на метода в зоната за доставка, както в чекаута (цена по вид, праг за безплатна доставка),
 * редът за доставка в поръчката се заменя и тоталът се преизчислява.
 */
final class ShippingSwitch {

	/** Сумата на стоките за прага „безплатна доставка“, както в чекаута (с ДДС, без виртуални продукти). */
	public static function goods_total( \WC_Order $order ): float {
		$total = 0.0;
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$product = $item->get_product();
			if ( $product && $product->is_virtual() ) {
				continue;
			}
			$total += (float) $item->get_total() + (float) $item->get_total_tax();
		}
		return (float) apply_filters( 'kanelov_shipping/free_shipping_basis', $total, [ 'order' => $order ] );
	}

	/**
	 * Методът и цената за куриера и вида доставка в зоната на адреса.
	 *
	 * @return array{method_id: string, instance_id: int, title: string, cost: float, type: string}|string масив или четимо съобщение за грешка
	 */
	public static function quote( \WC_Order $order, CarrierInterface $carrier, DeliveryData $delivery ) {
		$zone = \WC_Shipping_Zones::get_zone_matching_package( [
			'destination' => [
				'country'   => $order->get_shipping_country() ?: 'BG',
				'state'     => $order->get_shipping_state(),
				'postcode'  => $delivery->post_code ?: $order->get_shipping_postcode(),
				'city'      => $delivery->city_name ?: $order->get_shipping_city(),
				'address'   => '',
				'address_2' => '',
			],
		] );
		foreach ( $zone->get_shipping_methods( true ) as $method ) {
			if ( $method->id !== $carrier->method_id() || ! method_exists( $method, 'type_option' ) ) {
				continue;
			}
			$option = $method->type_option( $delivery->type, self::goods_total( $order ) );
			if ( ! $option ) {
				return sprintf( __( 'Видът доставка „%1$s“ е изключен в метода „%2$s“ за зоната „%3$s“.', 'kanelov-shipping' ), $delivery->type, $carrier->label(), $zone->get_zone_name() );
			}
			return [
				'method_id'   => (string) $method->id,
				'instance_id' => (int) $method->get_instance_id(),
				'title'       => trim( $method->get_title() . ' ' . $option['label'] ),
				'cost'        => (float) $option['cost'],
				'type'        => $delivery->type,
			];
		}
		return sprintf( __( 'Методът „%1$s“ не е добавен в зоната за доставка „%2$s“ (WooCommerce > Настройки > Доставка > Зони).', 'kanelov-shipping' ), $carrier->label(), $zone->get_zone_name() );
	}

	/**
	 * Заменя реда за доставка с новия метод и цена и преизчислява поръчката (без да я записва изрично: calculate_totals записва).
	 *
	 * @return array{old: float, new: float} старата и новата цена за доставка с ДДС
	 */
	public static function apply( \WC_Order $order, CarrierInterface $carrier, array $quote ): array {
		$items = $order->get_items( 'shipping' );
		$item  = $items ? reset( $items ) : new \WC_Order_Item_Shipping();
		$old   = $items ? (float) $item->get_total() + (float) $item->get_total_tax() : 0.0;

		$item->set_method_id( $quote['method_id'] );
		$item->set_instance_id( (string) $quote['instance_id'] );
		$item->set_method_title( $quote['title'] );
		$item->set_total( wc_format_decimal( $quote['cost'] ) );
		$item->update_meta_data( 'ks_carrier', $carrier->id() );
		$item->update_meta_data( 'ks_type', $quote['type'] );
		$item->delete_meta_data( 'ks_options' );
		if ( ! $items ) {
			$order->add_item( $item );
		}
		$order->calculate_totals( wc_tax_enabled() );

		return [ 'old' => round( $old, 2 ), 'new' => round( (float) $item->get_total() + (float) $item->get_total_tax(), 2 ) ];
	}
}
