<?php
namespace Kanelov\Shipping\Carrier\BoxNow;

use Kanelov\Shipping\Carrier\CarrierInterface;
use Kanelov\Shipping\Carrier\DeliveryData;
use Kanelov\Shipping\Carrier\Econt\EcontCarrier;
use Kanelov\Shipping\Carrier\LabelResult;
use Kanelov\Shipping\Carrier\TrackingResult;
use Kanelov\Shipping\Order\OrderMeta;
use Kanelov\Shipping\Support\Log;

defined( 'ABSPATH' ) || exit;

/**
 * Куриер Box Now: доставка само до автомат, заявка за доставка през Partner API, PDF етикет (сваля се с токен
 * и се пази в uploads), отказ и статус на пратката.
 */
final class BoxNowCarrier implements CarrierInterface {

	const ID = 'boxnow';

	/** admin-post действие за етикета (сваля го наново, ако файлът липсва). */
	const LABEL_ACTION = 'ks_boxnow_label';

	/** Статуси на Box Now на български. */
	const STATES = [
		'new'               => 'Регистрирана',
		'in-transit'        => 'Пътува',
		'in-depot'          => 'В склад на Box Now',
		'final-destination' => 'В автомата, чака взимане',
		'delivered'         => 'Доставена',
		'returned'          => 'Върната на подателя',
		'expired-return'    => 'Не е взета, връща се',
		'cancelled'         => 'Отказана',
		'canceled'          => 'Отказана',
		'cancelled-return'  => 'Отказано връщане',
		'wait-for-load'     => 'В автомат, чака куриер',
		'lost'              => 'Изгубена',
		'missing'           => 'Липсва',
	];

	public function id(): string {
		return self::ID;
	}

	public function label(): string {
		return 'Box Now';
	}

	public function method_id(): string {
		return BoxNowShippingMethod::ID;
	}

	public function supported_types(): array {
		return [ DeliveryData::TYPE_LOCKER ];
	}

	private function settings(): BoxNowSettings {
		return new BoxNowSettings();
	}

	/** Зарежда складовете и разрешенията от Box Now и ги пази за настройките. */
	public function refresh_profile(): array {
		$api      = $this->settings()->api();
		$origins  = $api->origins();
		$partners = [];
		try {
			$partners = $api->entrusted_partners();
		} catch ( BoxNowApiException $e ) {
			Log::info( 'BoxNow entrusted-partners unavailable: ' . $e->getMessage() );
		}
		$permissions = [];
		$partner_id  = $this->settings()->partner_id();
		foreach ( $partners as $p ) {
			if ( ! is_array( $p ) ) {
				continue;
			}
			if ( $partner_id === '' || (string) ( $p['id'] ?? '' ) === $partner_id || count( $partners ) === 1 ) {
				$permissions = (array) ( $p['permission'] ?? $p['permissions'] ?? [] );
				break;
			}
		}
		$profile = [ 'time' => time(), 'origins' => array_values( $origins ), 'partners' => array_values( $partners ), 'permissions' => $permissions ];
		update_option( BoxNowSettings::OPTION_PROFILE, $profile, false );
		return $profile;
	}

	public function test_connection(): array {
		try {
			$this->refresh_profile();
			return [];
		} catch ( BoxNowApiException $e ) {
			return $e->messages();
		}
	}

	public function validate_delivery( DeliveryData $d ): array {
		if ( $d->office_code === '' ) {
			return [ __( 'Изберете автомат на Box Now.', 'kanelov-shipping' ) ];
		}
		$locker = ( new BoxNowLockers() )->get( $d->office_code );
		if ( ! $locker ) {
			return [ __( 'Избраният автомат не е намерен. Изберете отново.', 'kanelov-shipping' ) ];
		}
		return [];
	}

	public function format_delivery( DeliveryData $d ): string {
		if ( $d->office_code === '' ) {
			return '';
		}
		$locker = ( new BoxNowLockers() )->get( $d->office_code );
		$name   = $locker ? $locker['label'] : $d->office_name;
		$city   = $locker ? (string) ( $locker['city']['name'] ?? '' ) : $d->city_name;
		return trim( 'Box Now: ' . ( $city !== '' && ! str_contains( $name, $city ) ? $city . ', ' : '' ) . $name . ' [' . $d->office_code . ']' );
	}

	/** Събира данните за BoxNowLabelBuilder от поръчката и настройките. */
	public function build_request( \WC_Order $order, array $options = [] ): array {
		$settings = $this->settings();
		$delivery = OrderMeta::get_delivery( $order );
		if ( $delivery->carrier !== self::ID || $delivery->office_code === '' ) {
			throw new BoxNowApiException( [ __( 'Поръчката няма избран автомат на Box Now. Редактирайте данните за доставка.', 'kanelov-shipping' ) ], 'NoDelivery' );
		}
		if ( $settings->origin_id() === '' ) {
			throw new BoxNowApiException( [ __( 'Не е избран склад в настройките на Box Now („Изпращане от“).', 'kanelov-shipping' ) ], 'NoOrigin' );
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
			$items[] = [
				'name'   => $item->get_name(),
				'qty'    => $item->get_quantity(),
				'weight' => $product && $product->get_weight() !== '' ? wc_get_weight( (float) $product->get_weight(), 'kg' ) : null,
				'price'  => (float) $item->get_total() + (float) $item->get_total_tax(),
			];
		}
		$attempt = (int) $order->get_meta( '_ks_boxnow_attempt', true );
		$input   = [
			'origin'       => [
				'location_id' => $settings->origin_id(),
				'name'        => $settings->sender_name(),
				'phone'       => $settings->sender_phone(),
				'email'       => $settings->sender_email(),
			],
			'receiver'     => [
				'name'  => trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() ) ?: $order->get_formatted_billing_full_name(),
				'phone' => $order->get_shipping_phone() ?: $order->get_billing_phone(),
				'email' => $order->get_billing_email(),
			],
			'locker_id'    => $delivery->office_code,
			// Box Now не приема два пъти един номер: след отказ следващата заявка е „номер-2“.
			'order_number' => $order->get_order_number() . ( $attempt > 0 ? '-' . ( $attempt + 1 ) : '' ),
			'items'        => $items,
			'order_total'  => (float) $order->get_total(),
			'is_cod'       => $order->get_payment_method() === 'cod',
			'options'      => array_merge( [
				'weight'       => 0,
				'compartment'  => 0,
				'dimensions'   => EcontCarrier::order_dimensions( $order, new \Kanelov\Shipping\Carrier\Econt\EcontSettings() ),
				'allow_return' => $settings->allow_return(),
				'notify_email' => $settings->notify_email(),
				'description'  => '',
			], $options ),
			'defaults'     => [
				'default_weight'         => $settings->default_weight(),
				'default_compartment'    => $settings->default_compartment(),
				'description_max_length' => $settings->description_max_length(),
			],
		];
		$body = ( new BoxNowLabelBuilder() )->build( $input );
		return apply_filters( 'kanelov_shipping/boxnow/delivery_request', $body, $order, $options );
	}

	/** Box Now няма предварителна калкулация; показва подготвената заявка за проверка. */
	public function calculate( \WC_Order $order, array $options = [] ): LabelResult {
		try {
			$body = $this->build_request( $order, $options );
		} catch ( BoxNowApiException $e ) {
			return LabelResult::failure( $e->messages() );
		}
		$r          = LabelResult::ok( $body );
		$r->message = sprintf( __( 'Готово за изпращане: автомат %1$s, отделение %2$d, тегло %3$s кг, %4$s.', 'kanelov-shipping' ), $body['destination']['locationId'], (int) $body['items'][0]['compartmentSize'], $body['items'][0]['weight'], $body['paymentMode'] === 'cod' ? sprintf( __( 'наложен платеж %s', 'kanelov-shipping' ), $body['amountToBeCollected'] ) : __( 'предплатена', 'kanelov-shipping' ) );
		return $r;
	}

	public function create_label( \WC_Order $order, array $options = [] ): LabelResult {
		$existing = OrderMeta::get_shipment( $order );
		if ( ! empty( $existing['number'] ) ) {
			return LabelResult::failure( [ sprintf( __( 'Поръчката вече има пратка %s. Откажете я, преди да създадете нова.', 'kanelov-shipping' ), $existing['number'] ) ] );
		}
		try {
			$body     = $this->build_request( $order, $options );
			$response = $this->settings()->api()->create_delivery_request( $body );
		} catch ( BoxNowApiException $e ) {
			return LabelResult::failure( $e->messages() );
		}
		$parcel = (string) ( $response['parcels'][0]['id'] ?? '' );
		if ( $parcel === '' ) {
			return LabelResult::failure( [ __( 'Box Now не върна номер на пратка.', 'kanelov-shipping' ) ], $response );
		}
		$order->update_meta_data( '_ks_boxnow_attempt', (int) $order->get_meta( '_ks_boxnow_attempt', true ) + 1 );
		OrderMeta::set_shipment( $order, [
			'carrier'     => self::ID,
			'number'      => $parcel,
			'reference'   => (string) ( $response['referenceNumber'] ?? $body['orderNumber'] ),
			'order_ref'   => (string) $body['orderNumber'],
			'pdf_url'     => '',
			'created_at'  => time(),
			'cd_amount'   => $body['paymentMode'] === 'cod' ? (float) $body['amountToBeCollected'] : null,
			'weight'      => (float) $body['items'][0]['weight'],
			'compartment' => (int) $body['items'][0]['compartmentSize'],
			'locker'      => (string) $body['destination']['locationId'],
			'env'         => $this->settings()->is_live() ? 'live' : 'stage',
			'status'      => self::STATES['new'],
			'status_time' => time(),
		] );
		$order->add_order_note( sprintf( __( 'Box Now: създадена пратка %1$s (заявка %2$s).', 'kanelov-shipping' ), $parcel, $body['orderNumber'] ) );
		$order->save();
		Log::info( 'BoxNow parcel created', [ 'order' => $order->get_id(), 'parcel' => $parcel ] );

		// Етикетът се сваля веднага, докато токенът е топъл; при неуспех се сваля при първото натискане на „Печат“.
		$url = $this->ensure_label_file( $order );
		$r   = LabelResult::ok( $response );
		$r->shipment_number = $parcel;
		$r->pdf_url         = $url;
		$r->currency        = $order->get_currency();
		return $r;
	}

	public function delete_label( \WC_Order $order ): LabelResult {
		$shipment = OrderMeta::get_shipment( $order );
		$number   = (string) ( $shipment['number'] ?? '' );
		if ( $number === '' ) {
			return LabelResult::failure( [ __( 'Поръчката няма пратка.', 'kanelov-shipping' ) ] );
		}
		try {
			$response = $this->settings()->api()->cancel_parcel( $number );
		} catch ( BoxNowApiException $e ) {
			return LabelResult::failure( $e->messages() );
		}
		$this->delete_label_file( $order );
		OrderMeta::clear_shipment( $order );
		$order->add_order_note( sprintf( __( 'Box Now: пратка %s е отказана.', 'kanelov-shipping' ), $number ) );
		$order->save();
		Log::info( 'BoxNow parcel cancelled', [ 'order' => $order->get_id(), 'parcel' => $number ] );
		$r = LabelResult::ok( $response );
		$r->shipment_number = $number;
		return $r;
	}

	public function forget_label( \WC_Order $order, string $reason = '' ): LabelResult {
		$shipment = OrderMeta::get_shipment( $order );
		$number   = (string) ( $shipment['number'] ?? '' );
		if ( $number === '' ) {
			return LabelResult::failure( [ __( 'Поръчката няма пратка.', 'kanelov-shipping' ) ] );
		}
		$this->delete_label_file( $order );
		OrderMeta::clear_shipment( $order );
		$order->add_order_note( sprintf( __( 'Box Now: записът за пратка %1$s е премахнат от сайта%2$s.', 'kanelov-shipping' ), $number, $reason !== '' ? ', ' . $reason : '' ) );
		$order->save();
		$r = LabelResult::ok( [] );
		$r->shipment_number = $number;
		return $r;
	}

	public function track( \WC_Order $order ): TrackingResult {
		$t        = new TrackingResult();
		$shipment = OrderMeta::get_shipment( $order );
		$number   = (string) ( $shipment['number'] ?? '' );
		if ( $number === '' ) {
			$t->errors[] = __( 'Поръчката няма пратка.', 'kanelov-shipping' );
			return $t;
		}
		$t->shipment_number = $number;
		try {
			$parcels = $this->settings()->api()->parcels( [ 'parcelId' => $number, 'limit' => 1 ] );
		} catch ( BoxNowApiException $e ) {
			$t->errors = $e->messages();
			return $t;
		}
		$p = (array) ( $parcels[0] ?? [] );
		if ( ! $p ) {
			$t->errors[] = __( 'Box Now не намира такава пратка.', 'kanelov-shipping' );
			return $t;
		}
		$t->success = true;
		$t->raw     = $p;
		$state      = (string) ( $p['state'] ?? '' );
		$t->status  = self::STATES[ $state ] ?? ( $state !== '' ? $state : __( 'Регистрирана', 'kanelov-shipping' ) );
		foreach ( (array) ( $p['events'] ?? [] ) as $ev ) {
			$type        = (string) ( $ev['type'] ?? '' );
			$t->events[] = [
				'time'  => self::local_time( (string) ( $ev['createTime'] ?? '' ) ),
				'event' => self::STATES[ $type ] ?? $type,
				'place' => trim( (string) ( $ev['locationDisplayName'] ?? '' ) . ' ' . (string) ( $ev['postalCode'] ?? '' ) ),
			];
			if ( $type === 'delivered' ) {
				$t->delivered_at = (string) ( $ev['createTime'] ?? '' );
			}
		}
		$shipment['status']      = $t->status;
		$shipment['state']       = $state;
		$shipment['status_time'] = time();
		OrderMeta::set_shipment( $order, $shipment );
		$order->save();
		return $t;
	}

	private static function local_time( string $iso ): string {
		$ts = $iso !== '' ? strtotime( $iso ) : false;
		return $ts ? wp_date( 'd.m.Y H:i', $ts ) : $iso;
	}

	public function tracking_link( string $number ): string {
		return '';
	}

	// PDF етикет: сваля се с токен и се пази в uploads/kanelov-shipping/ с непознато име.

	public function label_url( \WC_Order $order ): string {
		$shipment = OrderMeta::get_shipment( $order );
		if ( empty( $shipment['number'] ) ) {
			return '';
		}
		if ( ! empty( $shipment['pdf_url'] ) && ! empty( $shipment['pdf_file'] ) && file_exists( (string) $shipment['pdf_file'] ) ) {
			return (string) $shipment['pdf_url'];
		}
		return wp_nonce_url( admin_url( 'admin-post.php?action=' . self::LABEL_ACTION . '&order=' . $order->get_id() ), self::LABEL_ACTION . '_' . $order->get_id() );
	}

	/** Сваля етикета (ако още не е) и връща публичния му адрес; '' при грешка. */
	public function ensure_label_file( \WC_Order $order ): string {
		$shipment = OrderMeta::get_shipment( $order );
		$number   = (string) ( $shipment['number'] ?? '' );
		if ( $number === '' ) {
			return '';
		}
		if ( ! empty( $shipment['pdf_file'] ) && file_exists( (string) $shipment['pdf_file'] ) && ! empty( $shipment['pdf_url'] ) ) {
			return (string) $shipment['pdf_url'];
		}
		try {
			$pdf = $this->settings()->api()->label_pdf( $number );
		} catch ( BoxNowApiException $e ) {
			Log::error( 'BoxNow label download failed', [ 'order' => $order->get_id(), 'parcel' => $number, 'error' => $e->getMessage() ] );
			return '';
		}
		if ( $pdf === '' || ! str_starts_with( $pdf, '%PDF' ) ) {
			Log::error( 'BoxNow label is not a PDF', [ 'order' => $order->get_id(), 'parcel' => $number ] );
			return '';
		}
		$uploads = wp_get_upload_dir();
		$dir     = trailingslashit( (string) $uploads['basedir'] ) . 'kanelov-shipping';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			@file_put_contents( $dir . '/index.html', '' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		$name = 'boxnow-' . $number . '-' . wp_generate_password( 12, false ) . '.pdf';
		$file = $dir . '/' . $name;
		if ( @file_put_contents( $file, $pdf ) === false ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			Log::error( 'BoxNow label could not be saved', [ 'file' => $file ] );
			return '';
		}
		$url                  = trailingslashit( (string) $uploads['baseurl'] ) . 'kanelov-shipping/' . $name;
		$shipment['pdf_file'] = $file;
		$shipment['pdf_url']  = $url;
		OrderMeta::set_shipment( $order, $shipment );
		$order->save();
		return $url;
	}

	private function delete_label_file( \WC_Order $order ): void {
		$shipment = OrderMeta::get_shipment( $order );
		$file     = (string) ( $shipment['pdf_file'] ?? '' );
		if ( $file !== '' && file_exists( $file ) ) {
			@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
	}

	/** admin-post: сваля етикета при нужда и препраща към PDF файла. */
	public function register(): void {
		add_action( 'admin_post_' . self::LABEL_ACTION, [ $this, 'serve_label' ] );
	}

	public function serve_label(): void {
		$order_id = (int) ( $_GET['order'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification -- проверява се по-долу.
		check_admin_referer( self::LABEL_ACTION . '_' . $order_id );
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'Нямате права.', 'kanelov-shipping' ) );
		}
		$order = wc_get_order( $order_id );
		$url   = $order ? $this->ensure_label_file( $order ) : '';
		if ( $url === '' ) {
			wp_die( esc_html__( 'Етикетът не можа да бъде свален от Box Now. Вижте WooCommerce > Статус > Логове (kanelov-shipping).', 'kanelov-shipping' ) );
		}
		wp_safe_redirect( $url );
		exit;
	}
}
