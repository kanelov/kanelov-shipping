<?php
namespace Kanelov\Shipping\Carrier\BoxNow;

use Kanelov\Shipping\Support\Text;

/**
 * Построява заявката POST /delivery-requests на Box Now от чисти данни (без WordPress), за да е тестваема.
 *
 * Вход:
 *  origin       [location_id, name, phone, email]
 *  receiver     [name, phone, email]
 *  locker_id    id на автомата (destination locationId)
 *  order_number референция на поръчката (уникална за партньора)
 *  items        [[name, qty, weight|null, price], ...]
 *  order_total  цялата сума на поръчката (за наложен платеж)
 *  is_cod       bool
 *  options      weight (общо тегло, 0 = от продуктите), compartment (0 = автоматично, 1..3), dimensions [Д,Ш,В]|null,
 *               allow_return, notify_email, description
 *  defaults     default_weight, default_compartment, description_max_length
 */
final class BoxNowLabelBuilder {

	/** Отделения: [дължина, ширина, височина] в см по размер. */
	const COMPARTMENTS = [
		1 => [ 60.0, 45.0, 8.0 ],
		2 => [ 60.0, 45.0, 17.0 ],
		3 => [ 60.0, 45.0, 36.0 ],
	];

	const MAX_WEIGHT = 20.0;
	const COD_MAX    = 5000.0;

	public function build( array $in ): array {
		$options  = (array) ( $in['options'] ?? [] );
		$defaults = (array) ( $in['defaults'] ?? [] );
		$items    = (array) ( $in['items'] ?? [] );

		$goods = 0.0;
		foreach ( $items as $it ) {
			$goods += (float) ( $it['price'] ?? 0 );
		}
		$weight = (float) ( $options['weight'] ?? 0 );
		if ( $weight <= 0 ) {
			$weight = self::items_weight( $items, (float) ( $defaults['default_weight'] ?? 0.5 ) );
		}
		$weight = round( max( 0.1, $weight ), 3 );

		$compartment = (int) ( $options['compartment'] ?? 0 );
		if ( ! in_array( $compartment, [ 1, 2, 3 ], true ) ) {
			$dims        = $options['dimensions'] ?? null;
			$compartment = is_array( $dims ) && count( $dims ) === 3 && min( $dims ) > 0 ? self::compartment_for( $dims ) : (int) ( $defaults['default_compartment'] ?? 2 );
			if ( $compartment === 0 ) {
				throw new BoxNowApiException( [ sprintf( 'Пратката (%s см) не се побира в най-голямото отделение на автомата (60×45×36 см).', implode( '×', array_map( static fn( $v ) => rtrim( rtrim( number_format( (float) $v, 1, '.', '' ), '0' ), '.' ), $dims ) ) ) ], 'TooBig' );
			}
		}

		$is_cod = ! empty( $in['is_cod'] );
		$total  = round( (float) ( $in['order_total'] ?? $goods ), 2 );
		if ( $is_cod && $total > self::COD_MAX ) {
			throw new BoxNowApiException( [ sprintf( 'Наложен платеж над %s не се приема от Box Now.', number_format( self::COD_MAX, 2 ) ) ], 'CodMax' );
		}

		$description = trim( (string) ( $options['description'] ?? '' ) );
		if ( $description === '' ) {
			$description = self::description( $items, (string) ( $in['order_number'] ?? '' ), (int) ( $defaults['description_max_length'] ?? 100 ) );
		}

		$origin   = (array) ( $in['origin'] ?? [] );
		$receiver = (array) ( $in['receiver'] ?? [] );

		$body = [
			'orderNumber'         => (string) ( $in['order_number'] ?? '' ),
			'invoiceValue'        => number_format( $total, 2, '.', '' ),
			'paymentMode'         => $is_cod ? 'cod' : 'prepaid',
			'amountToBeCollected' => $is_cod ? number_format( $total, 2, '.', '' ) : '0.00',
			'allowReturn'         => ! empty( $options['allow_return'] ),
			'origin'              => [
				'contactName'   => Text::clean_for_label( (string) ( $origin['name'] ?? '' ) ),
				'contactNumber' => self::phone( (string) ( $origin['phone'] ?? '' ) ),
				'contactEmail'  => trim( (string) ( $origin['email'] ?? '' ) ),
				'locationId'    => (string) ( $origin['location_id'] ?? '' ),
			],
			'destination'         => [
				'contactName'   => Text::clean_for_label( (string) ( $receiver['name'] ?? '' ) ),
				'contactNumber' => self::phone( (string) ( $receiver['phone'] ?? '' ) ),
				'contactEmail'  => trim( (string) ( $receiver['email'] ?? '' ) ),
				'locationId'    => (string) ( $in['locker_id'] ?? '' ),
			],
			'items'               => [
				[
					'id'              => (string) ( $in['order_number'] ?? '' ) . '-1',
					'name'            => $description,
					'value'           => number_format( round( $goods, 2 ), 2, '.', '' ),
					'weight'          => $weight,
					'compartmentSize' => $compartment,
				],
			],
		];
		$notify = trim( (string) ( $options['notify_email'] ?? '' ) );
		if ( $notify !== '' ) {
			$body['notifyOnAccepted'] = $notify;
		}
		return $body;
	}

	/** Общо тегло на продуктите в кг; продукт без тегло получава теглото по подразбиране. */
	public static function items_weight( array $items, float $default ): float {
		$w = 0.0;
		foreach ( $items as $it ) {
			$qty = max( 1, (int) ( $it['qty'] ?? 1 ) );
			$iw  = $it['weight'] ?? null;
			$w  += ( $iw === null || $iw === '' || (float) $iw <= 0 ? $default : (float) $iw ) * $qty;
		}
		return $w;
	}

	/**
	 * Най-малкото отделение, в което се побира пратка с размери [Д, Ш, В] в см (всяко завъртане е позволено).
	 * 0 = не се побира и в най-голямото.
	 */
	public static function compartment_for( array $dims ): int {
		$d = array_values( array_map( 'floatval', $dims ) );
		if ( count( $d ) !== 3 ) {
			return 0;
		}
		foreach ( self::COMPARTMENTS as $size => $box ) {
			foreach ( self::permutations( $d ) as $p ) {
				if ( $p[0] <= $box[0] + 0.001 && $p[1] <= $box[1] + 0.001 && $p[2] <= $box[2] + 0.001 ) {
					return $size;
				}
			}
		}
		return 0;
	}

	private static function permutations( array $d ): array {
		[ $a, $b, $c ] = $d;
		return [ [ $a, $b, $c ], [ $a, $c, $b ], [ $b, $a, $c ], [ $b, $c, $a ], [ $c, $a, $b ], [ $c, $b, $a ] ];
	}

	/** Телефон във формата на Box Now: +359 xx xxx xxxx (само цифри след кода). */
	public static function phone( string $phone ): string {
		$digits = preg_replace( '/[^0-9+]/', '', $phone ) ?? '';
		if ( str_starts_with( $digits, '00' ) ) {
			$digits = '+' . substr( $digits, 2 );
		}
		$digits = '+' . ltrim( $digits, '+' );
		if ( preg_match( '/^\+0(\d{9})$/', $digits, $m ) ) {
			$digits = '+359' . $m[1]; // 0888123456 → +359888123456
		} elseif ( preg_match( '/^\+(\d{9})$/', $digits, $m ) && str_starts_with( $m[1], '8' ) ) {
			$digits = '+359' . $m[1]; // 888123456 без водеща нула
		}
		if ( preg_match( '/^\+359(\d{2})(\d{3})(\d{4})$/', $digits, $m ) ) {
			return "+359 {$m[1]} {$m[2]} {$m[3]}";
		}
		return $digits === '+' ? '' : $digits;
	}

	public static function description( array $items, string $order_number, int $max ): string {
		$names = [];
		foreach ( $items as $it ) {
			$qty     = (int) ( $it['qty'] ?? 1 );
			$names[] = Text::clean_for_label( (string) ( $it['name'] ?? '' ) ) . ( $qty > 1 ? " x{$qty}" : '' );
		}
		$text = trim( ( $order_number !== '' ? "Поръчка {$order_number}: " : '' ) . implode( ', ', array_filter( $names ) ) );
		return Text::truncate_words( $text, $max ) ?: ( $order_number !== '' ? "Поръчка {$order_number}" : 'Пратка' );
	}
}
