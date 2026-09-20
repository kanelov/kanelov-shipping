<?php
namespace Kanelov\Shipping\Order;

use Kanelov\Shipping\Carrier\DeliveryData;

defined( 'ABSPATH' ) || exit;

/**
 * Единствено място, което знае имената на мета ключовете в поръчката и в потребителя.
 * Ключовете започват с подчертавка, за да не се показват в „Допълнителни полета“.
 */
final class OrderMeta {

	const DELIVERY        = '_ks_delivery';        // масив от DeliveryData
	const SHIPMENT        = '_ks_shipment';        // масив: carrier, number, pdf_url, created_at, total_price, cd_amount, status, status_time
	const SHIPMENT_NUMBER = '_ks_shipment_number'; // дублиран за търсене/колона
	const USER_DELIVERY   = '_ks_delivery';        // user meta със същата структура

	public static function get_delivery( \WC_Order $order ): DeliveryData {
		$data = $order->get_meta( self::DELIVERY, true );
		return DeliveryData::from_array( is_array( $data ) ? $data : [] );
	}

	public static function set_delivery( \WC_Order $order, DeliveryData $delivery ): void {
		$order->update_meta_data( self::DELIVERY, $delivery->to_array() );
	}

	public static function get_shipment( \WC_Order $order ): array {
		$data = $order->get_meta( self::SHIPMENT, true );
		return is_array( $data ) ? $data : [];
	}

	public static function set_shipment( \WC_Order $order, array $shipment ): void {
		$order->update_meta_data( self::SHIPMENT, $shipment );
		$order->update_meta_data( self::SHIPMENT_NUMBER, (string) ( $shipment['number'] ?? '' ) );
	}

	public static function clear_shipment( \WC_Order $order ): void {
		$order->delete_meta_data( self::SHIPMENT );
		$order->delete_meta_data( self::SHIPMENT_NUMBER );
	}

	public static function get_user_delivery( int $user_id ): DeliveryData {
		$data = get_user_meta( $user_id, self::USER_DELIVERY, true );
		return DeliveryData::from_array( is_array( $data ) ? $data : [] );
	}

	public static function set_user_delivery( int $user_id, DeliveryData $delivery ): void {
		update_user_meta( $user_id, self::USER_DELIVERY, $delivery->to_array() );
	}
}
