<?php
namespace Kanelov\Shipping\Carrier;

/**
 * Изборът на клиента за доставка, независим от куриера. Пази се като мета `_ks_delivery` в поръчката
 * и като user meta за регистрирани клиенти. Без WordPress зависимости, за да е тестваем.
 */
final class DeliveryData {

	const TYPE_OFFICE = 'office';
	const TYPE_LOCKER = 'locker'; // Еконтомат (АПС), BoxNow шкаф и т.н.
	const TYPE_DOOR   = 'door';

	const TYPES = [ self::TYPE_OFFICE, self::TYPE_LOCKER, self::TYPE_DOOR ];

	public string $carrier     = '';
	public string $type        = '';
	public int $city_id        = 0;
	public string $city_name   = '';
	public string $post_code   = '';
	public string $office_code = '';
	public string $office_name = '';
	public string $street      = '';
	public string $street_num  = '';
	public string $quarter     = '';
	public string $block       = '';
	public string $entrance    = '';
	public string $floor       = '';
	public string $apartment   = '';
	public string $note        = '';
	/** Ако доставката се пада в почивен ден: '' (по настройка), 'workday' или 'halfday' (събота). Само до адрес. */
	public string $delivery_day = '';

	public static function from_array( array $data ): self {
		$self = new self();
		foreach ( get_object_vars( $self ) as $key => $default ) {
			if ( ! array_key_exists( $key, $data ) ) {
				continue;
			}
			$value = $data[ $key ];
			$self->$key = is_int( $default ) ? (int) $value : trim( (string) $value );
		}
		if ( ! in_array( $self->type, self::TYPES, true ) ) {
			$self->type = '';
		}
		return $self;
	}

	public function to_array(): array {
		return get_object_vars( $this );
	}

	public function is_empty(): bool {
		return $this->type === '' || $this->city_id === 0;
	}

	public function is_to_office(): bool {
		return $this->type === self::TYPE_OFFICE || $this->type === self::TYPE_LOCKER;
	}
}
