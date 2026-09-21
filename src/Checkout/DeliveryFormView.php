<?php
namespace Kanelov\Shipping\Checkout;

use Kanelov\Shipping\Carrier\DeliveryData;
use Kanelov\Shipping\Carrier\Econt\EcontNomenclature;
use Kanelov\Shipping\Carrier\Econt\EcontSettings;
use Kanelov\Shipping\Carrier\Econt\EcontShippingMethod;
use Kanelov\Shipping\Rest\EcontSearchController;

defined( 'ABSPATH' ) || exit;

/**
 * Общ изглед на полетата за офис/Еконтомат/адрес. Ползва се в чекаута и в админа (редакция на поръчка).
 * В чекаута е на стъпки: 1) вид доставка с икони, 2) населено място, 3) офис/Еконтомат или адрес.
 */
final class DeliveryFormView {

	const PREFIX = 'ks_';

	public static function js_config(): array {
		$settings = new EcontSettings();
		return [
			'rest'     => esc_url_raw( rest_url( EcontSearchController::NS . '/econt/' ) ),
			'methodId' => EcontShippingMethod::ID,
			'sync'     => (int) ( ( new EcontNomenclature() )->last_sync()['offices']['time'] ?? 0 ), // обезсилва кеша след синхронизация
			'map'      => $settings->map_enabled() ? [
				'js'  => KS_URL . 'assets/vendor/leaflet/leaflet.js',
				'css' => KS_URL . 'assets/vendor/leaflet/leaflet.css',
			] : null,
			'i18n'     => [
				'noResults'    => __( 'Няма резултати', 'kanelov-shipping' ),
				'loading'      => __( 'Зареждане…', 'kanelov-shipping' ),
				'chooseCity'   => __( 'Първо изберете населено място', 'kanelov-shipping' ),
				'noOffices'    => __( 'Няма офиси в това населено място', 'kanelov-shipping' ),
				'noLockers'    => __( 'Няма Еконтомати в това населено място', 'kanelov-shipping' ),
				'searchOffice' => __( 'Търсете офис по име или адрес…', 'kanelov-shipping' ),
				'searchLocker' => __( 'Търсете Еконтомат…', 'kanelov-shipping' ),
				'labelOffice'  => __( 'Офис на Еконт', 'kanelov-shipping' ),
				'labelLocker'  => __( 'Еконтомат', 'kanelov-shipping' ),
				'from'         => __( 'от', 'kanelov-shipping' ),
				'geoError'     => __( 'Не можахме да определим местоположението ви.', 'kanelov-shipping' ),
				'nearest'      => __( 'Най-близки до вас', 'kanelov-shipping' ),
				'km'           => __( 'км', 'kanelov-shipping' ),
				'choose'       => __( 'Избери', 'kanelov-shipping' ),
				'close'        => __( 'Затвори', 'kanelov-shipping' ),
				'mapTitle'     => __( 'Офиси и Еконтомати', 'kanelov-shipping' ),
				'noCoords'     => __( 'Няма офиси с координати за това населено място.', 'kanelov-shipping' ),
			],
		];
	}

	public static function enqueue_scripts(): void {
		wp_enqueue_style( 'ks-delivery', KS_URL . 'assets/css/checkout.css', [], KS_VERSION );
		wp_enqueue_script( 'ks-delivery-form', KS_URL . 'assets/js/delivery-form.js', [], KS_VERSION, true );
	}

	/** Икони за трите вида доставка (inline SVG, цветът идва от currentColor). */
	private static function icon( string $type ): string {
		$paths = [
			DeliveryData::TYPE_OFFICE => '<path d="M3 21h18M5 21V8l7-4 7 4v13M9 21v-6h6v6M9 11h.01M15 11h.01M12 11h.01"/>',
			DeliveryData::TYPE_LOCKER => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M3 15h18M9 3v18M15 3v18M6 6h.01M6 12h.01M6 18h.01"/>',
			DeliveryData::TYPE_DOOR   => '<path d="M3 11l9-8 9 8M5 10v11h14V10M10 21v-6h4v6"/>',
		];
		return '<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ( $paths[ $type ] ?? '' ) . '</svg>';
	}

	/** Кратки надписи за иконите (в ставката остават „до офис“ и т.н.). */
	public static function type_titles(): array {
		return [
			DeliveryData::TYPE_OFFICE => __( 'В офис', 'kanelov-shipping' ),
			DeliveryData::TYPE_LOCKER => __( 'От Еконтомат', 'kanelov-shipping' ),
			DeliveryData::TYPE_DOOR   => __( 'На адрес', 'kanelov-shipping' ),
		];
	}

	/**
	 * @param bool $with_type_select true в админа (падащ списък за вида, всичко видимо), false в чекаута (икони и стъпки)
	 */
	public static function render( DeliveryData $saved, bool $with_type_select = false ): void {
		$f     = static fn( string $k ) => self::PREFIX . $k;
		$types = EcontShippingMethod::rate_types();
		?>
		<input type="hidden" name="<?php echo esc_attr( $f( 'city_id' ) ); ?>" value="<?php echo esc_attr( (string) $saved->city_id ); ?>" class="ks-city-id">
		<input type="hidden" name="<?php echo esc_attr( $f( 'post_code' ) ); ?>" value="<?php echo esc_attr( $saved->post_code ); ?>" class="ks-post-code">
		<input type="hidden" name="<?php echo esc_attr( $f( 'office_code' ) ); ?>" value="<?php echo esc_attr( $saved->office_code ); ?>" class="ks-office-code">
		<input type="hidden" name="<?php echo esc_attr( $f( 'office_name' ) ); ?>" value="<?php echo esc_attr( $saved->office_name ); ?>" class="ks-office-name">

		<?php if ( $with_type_select ) : ?>
			<p class="form-row form-row-wide">
				<label for="ks_type"><?php esc_html_e( 'Вид доставка', 'kanelov-shipping' ); ?></label>
				<select name="<?php echo esc_attr( $f( 'type' ) ); ?>" id="ks_type" class="ks-type select">
					<?php foreach ( $types as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $saved->type, $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
		<?php else : ?>
			<fieldset class="ks-step ks-step--type ks-type-picker">
				<legend class="ks-step__label"><?php esc_html_e( 'Как искате да получите пратката?', 'kanelov-shipping' ); ?> <abbr class="required" title="<?php esc_attr_e( 'задължително', 'kanelov-shipping' ); ?>">*</abbr></legend>
				<div class="ks-type-picker__options">
					<?php foreach ( self::type_titles() as $key => $title ) : ?>
						<label class="ks-type-option" data-type="<?php echo esc_attr( $key ); ?>">
							<input type="radio" name="<?php echo esc_attr( $f( 'type' ) ); ?>" value="<?php echo esc_attr( $key ); ?>" class="ks-type" <?php checked( $saved->type, $key ); ?>>
							<span class="ks-type-option__icon"><?php echo self::icon( $key ); // phpcs:ignore WordPress.Security.EscapeOutput -- статичен SVG. ?></span>
							<span class="ks-type-option__title"><?php echo esc_html( $title ); ?></span>
							<span class="ks-type-option__price"></span>
						</label>
					<?php endforeach; ?>
				</div>
			</fieldset>
		<?php endif; ?>

		<div class="ks-step ks-step--city ks-row ks-row--city">
			<?php
			woocommerce_form_field( $f( 'city_name' ), [
				'type'         => 'text',
				'label'        => __( 'Населено място', 'kanelov-shipping' ),
				'required'     => true,
				'class'        => [ 'form-row-wide', 'ks-field-city' ],
				'autocomplete' => 'off',
				'placeholder'  => __( 'Започнете да пишете…', 'kanelov-shipping' ),
				'input_class'  => [ 'ks-city' ],
			], $saved->city_name );
			?>
			<div class="ks-actions">
				<button type="button" class="button ks-nearest"><?php esc_html_e( 'Най-близък до мен', 'kanelov-shipping' ); ?></button>
				<?php if ( ! $with_type_select && ( new EcontSettings() )->map_enabled() ) : ?>
					<button type="button" class="button ks-map-open"><?php esc_html_e( 'Покажи на карта', 'kanelov-shipping' ); ?></button>
				<?php endif; ?>
			</div>
		</div>

		<div class="ks-step ks-step--place ks-section ks-section--office" hidden>
			<?php
			woocommerce_form_field( $f( 'office_search' ), [
				'type'         => 'text',
				'label'        => __( 'Офис на Еконт', 'kanelov-shipping' ),
				'required'     => true,
				'class'        => [ 'form-row-wide', 'ks-field-office' ],
				'autocomplete' => 'off',
				'placeholder'  => __( 'Търсете по име или адрес…', 'kanelov-shipping' ),
				'input_class'  => [ 'ks-office' ],
			], $saved->office_name );
			?>
			<p class="ks-office-selected" <?php echo $saved->office_code ? '' : 'hidden'; ?>><?php echo $saved->office_code ? '✓ ' . esc_html( $saved->office_name ) : ''; ?></p>
			<?php if ( $with_type_select && ( new EcontSettings() )->map_enabled() ) : ?>
				<button type="button" class="button ks-map-open"><?php esc_html_e( 'Покажи на карта', 'kanelov-shipping' ); ?></button>
			<?php endif; ?>
		</div>

		<div class="ks-step ks-step--place ks-section ks-section--door" hidden>
			<?php
			woocommerce_form_field( $f( 'street' ), [ 'type' => 'text', 'label' => __( 'Улица / булевард', 'kanelov-shipping' ), 'class' => [ 'ks-span-4' ], 'autocomplete' => 'off', 'input_class' => [ 'ks-street' ] ], $saved->street );
			woocommerce_form_field( $f( 'street_num' ), [ 'type' => 'text', 'label' => __( '№', 'kanelov-shipping' ), 'class' => [ 'ks-span-2' ] ], $saved->street_num );
			woocommerce_form_field( $f( 'quarter' ), [ 'type' => 'text', 'label' => __( 'Квартал / ж.к.', 'kanelov-shipping' ), 'class' => [ 'ks-span-4' ], 'autocomplete' => 'off', 'input_class' => [ 'ks-quarter' ] ], $saved->quarter );
			woocommerce_form_field( $f( 'block' ), [ 'type' => 'text', 'label' => __( 'Блок', 'kanelov-shipping' ), 'class' => [ 'ks-span-2' ] ], $saved->block );
			woocommerce_form_field( $f( 'entrance' ), [ 'type' => 'text', 'label' => __( 'Вход', 'kanelov-shipping' ), 'class' => [ 'ks-span-2' ] ], $saved->entrance );
			woocommerce_form_field( $f( 'floor' ), [ 'type' => 'text', 'label' => __( 'Етаж', 'kanelov-shipping' ), 'class' => [ 'ks-span-2' ] ], $saved->floor );
			woocommerce_form_field( $f( 'apartment' ), [ 'type' => 'text', 'label' => __( 'Апартамент', 'kanelov-shipping' ), 'class' => [ 'ks-span-2' ] ], $saved->apartment );
			woocommerce_form_field( $f( 'note' ), [ 'type' => 'text', 'label' => __( 'Бележка за куриера', 'kanelov-shipping' ), 'class' => [ 'ks-span-6' ], 'placeholder' => __( 'Ориентир, звънец, фирма…', 'kanelov-shipping' ) ], $saved->note );
			$settings = new EcontSettings();
			if ( $with_type_select || $settings->holiday_choice_checkout() ) :
				$day = $saved->delivery_day ?: $settings->holiday_delivery_day();
				?>
				<fieldset class="form-row ks-span-6 ks-delivery-day">
					<legend class="ks-step__label"><?php esc_html_e( 'Ако доставката се падне в почивен ден', 'kanelov-shipping' ); ?></legend>
					<span class="ks-radio-row">
						<label><input type="radio" name="<?php echo esc_attr( $f( 'delivery_day' ) ); ?>" value="workday" <?php checked( $day, 'workday' ); ?>> <?php esc_html_e( 'в първия работен ден', 'kanelov-shipping' ); ?></label>
						<label><input type="radio" name="<?php echo esc_attr( $f( 'delivery_day' ) ); ?>" value="halfday" <?php checked( $day, 'halfday' ); ?>> <?php esc_html_e( 'в събота', 'kanelov-shipping' ); ?></label>
					</span>
				</fieldset>
			<?php endif; ?>
		</div>
		<?php
	}

	/** Чете DeliveryData от POST/REQUEST с префикс ks_. */
	public static function from_request( array $req, string $carrier, ?string $type = null ): DeliveryData {
		$get = static fn( string $k ) => sanitize_text_field( wp_unslash( (string) ( $req[ self::PREFIX . $k ] ?? '' ) ) );
		return DeliveryData::from_array( [
			'carrier'     => $carrier,
			'type'        => $type ?? $get( 'type' ),
			'city_id'     => (int) $get( 'city_id' ),
			'city_name'   => $get( 'city_name' ),
			'post_code'   => $get( 'post_code' ),
			'office_code' => $get( 'office_code' ),
			'office_name' => $get( 'office_name' ),
			'street'      => $get( 'street' ),
			'street_num'  => $get( 'street_num' ),
			'quarter'     => $get( 'quarter' ),
			'block'       => $get( 'block' ),
			'entrance'    => $get( 'entrance' ),
			'floor'       => $get( 'floor' ),
			'apartment'   => $get( 'apartment' ),
			'note'        => $get( 'note' ),
			'delivery_day' => in_array( $get( 'delivery_day' ), [ 'workday', 'halfday' ], true ) ? $get( 'delivery_day' ) : '',
		] );
	}
}
