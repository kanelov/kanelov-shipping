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

	/** Първият ред за доставка, ако е на наш куриер (ks_*); иначе null. */
	public static function our_line( \WC_Order $order ): ?\WC_Order_Item_Shipping {
		$items = $order->get_items( 'shipping' );
		$item  = $items ? reset( $items ) : null;
		return $item instanceof \WC_Order_Item_Shipping && str_starts_with( (string) $item->get_method_id(), 'ks_' ) ? $item : null;
	}

	/**
	 * Дали записът на новата доставка трябва да смени цената: само когато поръчката има точно един ред за доставка,
	 * той е на наш куриер и методът или видът са различни. Чужд ред (взимане от магазина, друг куриер) не се пипа.
	 */
	public static function needs_reprice( \WC_Order $order, CarrierInterface $target, DeliveryData $delivery ): bool {
		$line = self::our_line( $order );
		if ( ! $line || count( $order->get_items( 'shipping' ) ) !== 1 ) {
			return false;
		}
		$line_type = (string) $line->get_meta( 'ks_type', true );
		if ( $line_type === '' ) {
			$line_type = OrderMeta::get_delivery( $order )->type;
		}
		return $line->get_method_id() !== $target->method_id() || $line_type !== $delivery->type;
	}

	/** Адресът и класът, по които WooCommerce смята ДДС за доставката на поръчката. */
	private static function tax_location( \WC_Order $order ): array {
		$class = (string) get_option( 'woocommerce_shipping_tax_class', 'inherit' );
		$loc   = $order->get_taxable_location(); // логиката на WooCommerce („Изчисли ДДС на база“, взимане на място, база)
		$loc['tax_class'] = $class === 'inherit' ? '' : $class;
		return $loc;
	}

	/**
	 * Заменя единствения ред за доставка с новия метод и цена. Пипа само реда за доставка: ДДС-ът на продуктите
	 * не се преизчислява, а тоталът се сменя точно с разликата в цената на доставката. Записва поръчката.
	 *
	 * @return array{old: float, new: float} старата и новата цена за доставка с ДДС
	 */
	public static function apply( \WC_Order $order, CarrierInterface $carrier, array $quote ): array {
		$item = self::our_line( $order );
		if ( ! $item ) {
			throw new \RuntimeException( __( 'Поръчката няма ред за доставка с Еконт или Box Now.', 'kanelov-shipping' ) );
		}
		$old_gross = (float) $item->get_total() + (float) $item->get_total_tax();
		$old_total = (float) $order->get_total();

		$item->set_method_id( $quote['method_id'] );
		$item->set_instance_id( (string) $quote['instance_id'] );
		$item->set_method_title( $quote['title'] );
		$item->set_total( wc_format_decimal( $quote['cost'], wc_get_price_decimals() ) );
		$item->update_meta_data( 'ks_carrier', $carrier->id() );
		$item->update_meta_data( 'ks_type', $quote['type'] );
		$item->delete_meta_data( 'ks_options' );
		if ( wc_tax_enabled() ) {
			$item->calculate_taxes( self::tax_location( $order ) );
		} else {
			$item->set_taxes( false );
		}
		$new_gross = (float) $item->get_total() + (float) $item->get_total_tax();

		$order->update_taxes();        // сумира данъците от редовете, без да ги преизчислява; записва
		$order->calculate_shipping();  // сумата на редовете за доставка
		$order->set_total( wc_format_decimal( $old_total + $new_gross - $old_gross, wc_get_price_decimals() ) );
		$order->save();

		return [ 'old' => round( $old_gross, 2 ), 'new' => round( $new_gross, 2 ) ];
	}
}
