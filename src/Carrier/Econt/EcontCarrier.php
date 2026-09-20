<?php
namespace Kanelov\Shipping\Carrier\Econt;

use Kanelov\Shipping\Carrier\CarrierInterface;
use Kanelov\Shipping\Carrier\DeliveryData;
use Kanelov\Shipping\Carrier\LabelResult;
use Kanelov\Shipping\Carrier\TrackingResult;
use Kanelov\Shipping\Order\OrderMeta;
use Kanelov\Shipping\Support\Log;

defined( 'ABSPATH' ) || exit;

final class EcontCarrier implements CarrierInterface {

	const ID = 'econt';

	public function id(): string {
		return self::ID;
	}

	public function label(): string {
		return 'Еконт';
	}

	public function supported_types(): array {
		return [ DeliveryData::TYPE_OFFICE, DeliveryData::TYPE_LOCKER, DeliveryData::TYPE_DOOR ];
	}

	private function settings(): EcontSettings {
		return new EcontSettings();
	}

	public function test_connection(): array {
		try {
			( new EcontProfile( $this->settings() ) )->refresh();
			return [];
		} catch ( EcontApiException $e ) {
			return $e->messages();
		}
	}

	public function validate_delivery( DeliveryData $d ): array {
		$errors = [];
		if ( $d->type === '' ) {
			return [ __( 'Изберете вид доставка с Еконт.', 'kanelov-shipping' ) ];
		}
		if ( $d->city_id <= 0 ) {
			$errors[] = __( 'Изберете населено място.', 'kanelov-shipping' );
		}
		$nomenclature = new EcontNomenclature();
		if ( $d->is_to_office() ) {
			if ( $d->office_code === '' ) {
				$errors[] = $d->type === DeliveryData::TYPE_LOCKER
					? __( 'Изберете Еконтомат.', 'kanelov-shipping' )
					: __( 'Изберете офис на Еконт.', 'kanelov-shipping' );
			} elseif ( ! $nomenclature->get_office( $d->office_code ) ) {
				$errors[] = __( 'Избраният офис не е намерен. Изберете отново.', 'kanelov-shipping' );
			}
		} else {
			if ( $d->street === '' && $d->quarter === '' ) {
				$errors[] = __( 'Въведете улица или квартал.', 'kanelov-shipping' );
			}
			if ( $d->street !== '' && $d->street_num === '' && $d->block === '' ) {
				$errors[] = __( 'Въведете номер на улицата или блок.', 'kanelov-shipping' );
			}
		}
		return $errors;
	}

	public function format_delivery( DeliveryData $d ): string {
		if ( $d->is_empty() ) {
			return '';
		}
		if ( $d->is_to_office() ) {
			$prefix = $d->type === DeliveryData::TYPE_LOCKER ? 'Еконтомат' : 'Офис Еконт';
			$office = ( new EcontNomenclature() )->get_office( $d->office_code );
			$name   = $office ? $office['label'] : $d->office_name;
			return trim( "{$prefix}: {$name} [{$d->office_code}]" );
		}
		$parts = array_filter( [
			$d->quarter !== '' ? 'кв. ' . $d->quarter : '',
			$d->street !== '' ? 'ул. ' . $d->street . ( $d->street_num !== '' ? ' ' . $d->street_num : '' ) : '',
			EcontLabelBuilder::compose_other( $d ),
		] );
		return trim( $d->city_name . ( $d->post_code ? ' ' . $d->post_code : '' ) . ', ' . implode( ', ', $parts ), ', ' );
	}

	/** Събира всичко нужно за EcontLabelBuilder от поръчката и настройките. */
	public function build_label( \WC_Order $order, array $options = [] ): array {
		$settings = $this->settings();
		$profile  = new EcontProfile( $settings );
		$delivery = OrderMeta::get_delivery( $order );

		if ( $delivery->is_empty() ) {
			throw new EcontApiException( [ __( 'Поръчката няма избрана доставка с Еконт. Редактирайте данните за доставка.', 'kanelov-shipping' ) ], 'NoDelivery' );
		}
		if ( ! $profile->client() ) {
			throw new EcontApiException( [ __( 'Профилът на подателя не е зареден. Отворете настройките на Еконт и натиснете „Обнови профила“.', 'kanelov-shipping' ) ], 'NoProfile' );
		}

		$sender = [
			'client'      => $profile->client(),
			'agent_name'  => $settings->sender_name() ?: ( $profile->client()['name'] ?? '' ),
			'agent_phone' => $settings->sender_phone() ?: ( $profile->client()['phones'][0] ?? '' ),
		];
		if ( ( $options['send_from'] ?? $settings->send_from() ) === 'office' ) {
			$sender['office_code'] = (string) ( $options['sender_office_code'] ?? $settings->sender_office_code() );
		} else {
			$sender['address'] = $profile->address( (int) ( $options['sender_address'] ?? $settings->sender_address_index() ) );
		}

		$items = [];
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$product = $item->get_product();
			if ( $product && $product->is_virtual() ) {
				continue;
			}
			$weight  = $product && $product->get_weight() !== '' ? wc_get_weight( (float) $product->get_weight(), 'kg' ) : null;
			$items[] = [
				'name'   => $item->get_name(),
				'qty'    => $item->get_quantity(),
				'weight' => $weight,
				'price'  => (float) $item->get_total() + (float) $item->get_total_tax(),
			];
		}

		$is_cod = $order->get_payment_method() === 'cod';

		$input = [
			'sender'       => $sender,
			'receiver'     => [
				'name'  => trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() ) ?: $order->get_formatted_billing_full_name(),
				'phone' => $order->get_shipping_phone() ?: $order->get_billing_phone(),
				'email' => $order->get_billing_email(),
			],
			'delivery'     => $delivery,
			'city'         => ( new EcontNomenclature() )->get_city( $delivery->city_id ) ?? [],
			'items'        => $items,
			'order_number' => $order->get_order_number(),
			'order_total'  => (float) $order->get_total(),
			'currency'     => $order->get_currency(),
			'is_cod'       => $is_cod,
			'options'      => array_merge( [
				'sms_notification'        => $settings->sms_notification(),
				'cd_pay_options_template' => $settings->cd_pay_options_template(),
				'invoice_before_pay_cd'   => $settings->invoice_before_pay_cd(),
				'sender_payment_method'   => $settings->sender_payment_method(),
				'receiver_pays_shipping'  => $settings->receiver_pays_shipping(),
				'receiver_amount'         => (float) $order->get_shipping_total(),
				'shipment_type'           => $settings->shipment_type(),
				'declared_value'          => $settings->declared_value_threshold() > 0 && (float) $order->get_total() >= $settings->declared_value_threshold() ? (float) $order->get_total() : 0,
			], $options ),
			'defaults'     => [
				'default_weight'         => $settings->default_weight(),
				'min_weight'             => $settings->min_weight(),
				'description_mode'       => $settings->description_mode(),
				'description_max_length' => $settings->description_max_length(),
			],
		];

		$label = ( new EcontLabelBuilder() )->build( $input );

		/** Позволява корекция на заявката преди изпращане към Еконт. */
		return apply_filters( 'kanelov_shipping/econt/label', $label, $order, $options );
	}

	private function label_request( \WC_Order $order, array $options, string $mode ): LabelResult {
		try {
			$label    = $this->build_label( $order, $options );
			$response = $this->settings()->api()->label( $label, $mode );
		} catch ( EcontApiException $e ) {
			return LabelResult::failure( $e->messages() );
		}
		$r = LabelResult::ok( $response );
		$l = (array) ( $response['label'] ?? [] );
		$r->shipment_number = (string) ( $l['shipmentNumber'] ?? '' );
		$r->pdf_url         = (string) ( $l['pdfURL'] ?? '' );
		$r->total_price     = isset( $l['totalPrice'] ) ? (float) $l['totalPrice'] : null;
		$r->sender_due      = isset( $l['senderDueAmount'] ) ? (float) $l['senderDueAmount'] : null;
		$r->receiver_due    = isset( $l['receiverDueAmount'] ) ? (float) $l['receiverDueAmount'] : null;
		$r->currency        = (string) ( $l['currency'] ?? '' );
		return $r;
	}

	public function calculate( \WC_Order $order, array $options = [] ): LabelResult {
		return $this->label_request( $order, $options, 'calculate' );
	}

	public function create_label( \WC_Order $order, array $options = [] ): LabelResult {
		$existing = OrderMeta::get_shipment( $order );
		if ( ! empty( $existing['number'] ) ) {
			return LabelResult::failure( [ sprintf( __( 'Поръчката вече има товарителница %s. Изтрийте я, преди да създадете нова.', 'kanelov-shipping' ), $existing['number'] ) ] );
		}
		$result = $this->label_request( $order, $options, 'create' );
		if ( ! $result->success || $result->shipment_number === '' ) {
			if ( $result->success ) {
				$result = LabelResult::failure( [ __( 'Еконт не върна номер на товарителница.', 'kanelov-shipping' ) ], $result->raw );
			}
			return $result;
		}
		$label = (array) ( $result->raw['label'] ?? [] );
		OrderMeta::set_shipment( $order, [
			'carrier'      => self::ID,
			'number'       => $result->shipment_number,
			'pdf_url'      => $result->pdf_url,
			'created_at'   => time(),
			'total_price'  => $result->total_price,
			'sender_due'   => $result->sender_due,
			'receiver_due' => $result->receiver_due,
			'currency'     => $result->currency,
			'cd_amount'    => isset( $label['services']['cdAmount'] ) ? (float) $label['services']['cdAmount'] : null,
			'weight'       => isset( $label['weight'] ) ? (float) $label['weight'] : null,
			'env'          => $this->settings()->is_live() ? 'live' : 'demo',
			'status'       => '',
			'status_time'  => 0,
		] );
		$order->add_order_note( sprintf( __( 'Еконт: създадена товарителница %1$s (цена %2$s %3$s).', 'kanelov-shipping' ), $result->shipment_number, $result->total_price !== null ? number_format( $result->total_price, 2 ) : '-', $result->currency ) );
		$order->save();
		Log::info( 'Econt label created', [ 'order' => $order->get_id(), 'number' => $result->shipment_number ] );
		do_action( 'kanelov_shipping/label_created', $order, $result, self::ID );
		return $result;
	}

	public function delete_label( \WC_Order $order ): LabelResult {
		$shipment = OrderMeta::get_shipment( $order );
		$number   = (string) ( $shipment['number'] ?? '' );
		if ( $number === '' ) {
			return LabelResult::failure( [ __( 'Поръчката няма товарителница.', 'kanelov-shipping' ) ] );
		}
		try {
			$response = $this->settings()->api()->delete_labels( [ $number ] );
		} catch ( EcontApiException $e ) {
			return LabelResult::failure( $e->messages() );
		}
		// Еконт връща грешка на ниво отделна пратка, ако не може да бъде изтрита (напр. вече е приета).
		foreach ( (array) ( $response['results'] ?? [] ) as $res ) {
			if ( ! empty( $res['error'] ) ) {
				return LabelResult::failure( EcontApi::collect_messages( (array) $res['error'] ) ?: [ __( 'Еконт отказа изтриването.', 'kanelov-shipping' ) ], $response );
			}
		}
		OrderMeta::clear_shipment( $order );
		$order->add_order_note( sprintf( __( 'Еконт: товарителница %s е изтрита.', 'kanelov-shipping' ), $number ) );
		$order->save();
		Log::info( 'Econt label deleted', [ 'order' => $order->get_id(), 'number' => $number ] );
		$r = LabelResult::ok( $response );
		$r->shipment_number = $number;
		return $r;
	}

	public function track( \WC_Order $order ): TrackingResult {
		$t        = new TrackingResult();
		$shipment = OrderMeta::get_shipment( $order );
		$number   = (string) ( $shipment['number'] ?? '' );
		if ( $number === '' ) {
			$t->errors[] = __( 'Поръчката няма товарителница.', 'kanelov-shipping' );
			return $t;
		}
		$t->shipment_number = $number;
		$t->tracking_url    = self::tracking_url( $number );
		try {
			$statuses = $this->settings()->api()->get_shipment_statuses( [ $number ] );
		} catch ( EcontApiException $e ) {
			$t->errors = $e->messages();
			return $t;
		}
		$first = (array) ( $statuses[0] ?? [] );
		if ( ! empty( $first['error'] ) ) {
			$t->errors = EcontApi::collect_messages( (array) $first['error'] );
			return $t;
		}
		$status = (array) ( $first['status'] ?? [] );
		$t->success      = true;
		$t->raw          = $status;
		$t->delivered_at = (string) ( $status['deliveryTime'] ?? '' );
		foreach ( (array) ( $status['trackingEvents'] ?? [] ) as $ev ) {
			$t->events[] = [
				'time'  => (string) ( $ev['time'] ?? '' ),
				'event' => (string) ( $ev['destinationType'] ?? '' ) . ' ' . (string) ( $ev['destinationDetails'] ?? '' ),
				'place' => (string) ( $ev['officeName'] ?? ( $ev['cityName'] ?? '' ) ),
			];
		}
		$last      = end( $t->events );
		$t->status = $t->delivered_at !== '' ? __( 'Доставена', 'kanelov-shipping' ) : ( $last ? trim( $last['event'] ) : __( 'Регистрирана', 'kanelov-shipping' ) );

		$shipment['status']      = $t->status;
		$shipment['status_time'] = time();
		OrderMeta::set_shipment( $order, $shipment );
		$order->save();
		return $t;
	}

	public static function tracking_url( string $number ): string {
		return 'https://www.econt.com/services/track-shipment/' . rawurlencode( $number );
	}
}
