<?php
namespace Kanelov\Shipping\Carrier\Econt;

use Kanelov\Shipping\Carrier\DeliveryData;
use Kanelov\Shipping\Support\Text;

/**
 * Построява тялото на заявката към LabelService.createLabel от вече събрани данни.
 * Чист клас без WordPress зависимости, покрит с unit тестове.
 *
 * Вход (масив):
 *  sender:   client (масив от профила), agent_name, agent_phone, office_code | address (масив от профила)
 *  receiver: name, phone, email
 *  delivery: DeliveryData
 *  city:     id, name, post_code (от номенклатурата; нужно за адрес)
 *  items:    [ [name, qty, weight (кг за брой или null), price] ]
 *  order_number, order_total, currency, is_cod
 *  options:  weight, pack_count, description, shipment_type, declared_value, sms_notification,
 *            invoice_before_pay_cd, cd_pay_options_template, sender_payment_method,
 *            receiver_pays_shipping, receiver_amount, invoice_num
 *  defaults: default_weight, min_weight, description_mode, description_max_length
 */
final class EcontLabelBuilder {

	public function build( array $in ): array {
		$delivery = $in['delivery'];
		if ( ! $delivery instanceof DeliveryData ) {
			throw new \InvalidArgumentException( 'delivery must be DeliveryData' );
		}
		$opt = (array) ( $in['options'] ?? [] );
		$def = (array) ( $in['defaults'] ?? [] );

		$label = [];

		// Подател.
		$sender          = (array) ( $in['sender'] ?? [] );
		$label['senderClient'] = (array) ( $sender['client'] ?? [] );
		$label['senderAgent']  = [
			'name'   => (string) ( $sender['agent_name'] ?? ( $label['senderClient']['name'] ?? '' ) ),
			'phones' => array_values( array_filter( [ (string) ( $sender['agent_phone'] ?? '' ) ] ) ),
		];
		if ( ! empty( $sender['office_code'] ) ) {
			$label['senderOfficeCode'] = (string) $sender['office_code'];
		} elseif ( ! empty( $sender['address'] ) ) {
			$label['senderAddress'] = (array) $sender['address'];
		}

		// Получател.
		$receiver = (array) ( $in['receiver'] ?? [] );
		$client   = [
			'name'   => (string) ( $receiver['name'] ?? '' ),
			'phones' => array_values( array_filter( [ self::normalize_phone( (string) ( $receiver['phone'] ?? '' ) ) ] ) ),
		];
		if ( ! empty( $receiver['email'] ) ) {
			$client['email'] = (string) $receiver['email'];
		}
		$label['receiverClient'] = $client;
		$label['receiverAgent']  = $client;

		if ( $delivery->is_to_office() ) {
			$label['receiverOfficeCode'] = $delivery->office_code;
		} else {
			$city = (array) ( $in['city'] ?? [] );
			$addr = [
				'city' => [
					'id'       => (int) ( $city['id'] ?? $delivery->city_id ),
					'country'  => [ 'code3' => 'BGR' ],
					'name'     => (string) ( $city['name'] ?? $delivery->city_name ),
					'postCode' => (string) ( $city['post_code'] ?? $delivery->post_code ),
				],
			];
			if ( $delivery->street !== '' ) {
				$addr['street'] = $delivery->street;
			}
			if ( $delivery->street_num !== '' ) {
				$addr['num'] = $delivery->street_num;
			}
			if ( $delivery->quarter !== '' ) {
				$addr['quarter'] = $delivery->quarter;
			}
			$other = self::compose_other( $delivery );
			if ( $other !== '' ) {
				$addr['other'] = $other;
			}
			$label['receiverAddress'] = $addr;
		}

		// Пратка.
		$items  = (array) ( $in['items'] ?? [] );
		$weight = isset( $opt['weight'] ) && is_numeric( $opt['weight'] ) && (float) $opt['weight'] > 0
			? (float) $opt['weight']
			: self::total_weight( $items, (float) ( $def['default_weight'] ?? 0.5 ) );
		$weight = max( (float) ( $def['min_weight'] ?? 0.1 ), $weight );

		$label['packCount']    = max( 1, (int) ( $opt['pack_count'] ?? 1 ) );
		$label['shipmentType'] = (string) ( $opt['shipment_type'] ?? 'pack' );
		$label['weight']       = round( $weight, 3 );
		$label['shipmentDescription'] = self::description(
			$items,
			(string) ( $in['order_number'] ?? '' ),
			(string) ( $opt['description'] ?? '' ),
			(string) ( $def['description_mode'] ?? 'both' ),
			(int) ( $def['description_max_length'] ?? 100 )
		);
		if ( ! empty( $in['order_number'] ) ) {
			$label['orderNumber'] = (string) $in['order_number'];
		}

		// Кой плаща доставката. Получателят плаща на куриера само ако има конкретна сума > 0;
		// метод без сума означава за Еконт „получателят плаща цялата услуга“.
		$receiver_amount = ! empty( $opt['receiver_pays_shipping'] ) && isset( $opt['receiver_amount'] ) && is_numeric( $opt['receiver_amount'] )
			? round( (float) $opt['receiver_amount'], 2 )
			: 0.0;

		// Услуги.
		$services = [];
		$currency = (string) ( $in['currency'] ?? 'EUR' );
		if ( ! empty( $in['is_cod'] ) ) {
			// Ако доставката се събира отделно от куриера, тя не влиза и в наложения платеж.
			$services['cdType']     = 'get';
			$services['cdAmount']   = round( max( 0, (float) ( $in['order_total'] ?? 0 ) - $receiver_amount ), 2 );
			$services['cdCurrency'] = $currency;
			if ( ! empty( $opt['cd_pay_options_template'] ) ) {
				$services['cdPayOptionsTemplate'] = (string) $opt['cd_pay_options_template'];
			}
			if ( ! empty( $opt['invoice_before_pay_cd'] ) ) {
				$services['invoiceBeforePayCD'] = true;
			}
		}
		if ( ! empty( $opt['declared_value'] ) && (float) $opt['declared_value'] > 0 && $delivery->type !== DeliveryData::TYPE_LOCKER ) {
			$services['declaredValueAmount']   = round( (float) $opt['declared_value'], 2 );
			$services['declaredValueCurrency'] = $currency;
		}
		if ( ! empty( $opt['sms_notification'] ) ) {
			$services['smsNotification'] = true;
		}
		if ( ! empty( $opt['invoice_num'] ) ) {
			$services['invoiceNum'] = (string) $opt['invoice_num'];
		}
		if ( $services ) {
			$label['services'] = $services;
		}

		// Кой плаща доставката.
		$label['paymentSenderMethod'] = in_array( $opt['sender_payment_method'] ?? '', [ 'cash', 'credit', 'bonus', 'voucher' ], true )
			? $opt['sender_payment_method']
			: 'cash';
		if ( $receiver_amount > 0 ) {
			$label['paymentReceiverMethod'] = 'cash';
			$label['paymentReceiverAmount'] = $receiver_amount;
		}

		$label['holidayDeliveryDay'] = 'workday';

		return $label;
	}

	public static function total_weight( array $items, float $default_weight ): float {
		$total = 0.0;
		foreach ( $items as $item ) {
			$qty = max( 1, (int) ( $item['qty'] ?? 1 ) );
			$w   = $item['weight'] ?? null;
			$w   = ( is_numeric( $w ) && (float) $w > 0 ) ? (float) $w : $default_weight;
			$total += $w * $qty;
		}
		return $total;
	}

	public static function description( array $items, string $order_number, string $override, string $mode, int $max ): string {
		if ( trim( $override ) !== '' ) {
			return Text::truncate_words( Text::clean_for_label( $override ), $max );
		}
		$names = [];
		foreach ( $items as $item ) {
			$name = Text::clean_for_label( (string) ( $item['name'] ?? '' ) );
			if ( $name === '' ) {
				continue;
			}
			$qty     = (int) ( $item['qty'] ?? 1 );
			$names[] = $qty > 1 ? "{$name} x{$qty}" : $name;
		}
		$products = implode( ', ', $names );
		$prefix   = $order_number !== '' ? '#' . $order_number : '';
		$text     = match ( $mode ) {
			'order_number' => $prefix !== '' ? $prefix : $products,
			'products'     => $products !== '' ? $products : $prefix,
			default        => trim( $prefix . ' ' . $products ),
		};
		if ( $text === '' ) {
			$text = 'Стоки';
		}
		return Text::truncate_words( $text, $max );
	}

	public static function compose_other( DeliveryData $d ): string {
		$parts = [];
		if ( $d->block !== '' ) {
			$parts[] = 'бл. ' . $d->block;
		}
		if ( $d->entrance !== '' ) {
			$parts[] = 'вх. ' . $d->entrance;
		}
		if ( $d->floor !== '' ) {
			$parts[] = 'ет. ' . $d->floor;
		}
		if ( $d->apartment !== '' ) {
			$parts[] = 'ап. ' . $d->apartment;
		}
		if ( $d->note !== '' ) {
			$parts[] = $d->note;
		}
		return implode( ', ', $parts );
	}

	public static function normalize_phone( string $phone ): string {
		$phone = preg_replace( '/[^\d+]/', '', $phone ) ?? '';
		if ( str_starts_with( $phone, '00' ) ) {
			$phone = '+' . substr( $phone, 2 );
		}
		if ( str_starts_with( $phone, '+359' ) ) {
			$phone = '0' . substr( $phone, 4 );
		}
		return $phone;
	}
}
