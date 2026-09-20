<?php
namespace Kanelov\Shipping\Checkout;

use Kanelov\Shipping\Carrier\DeliveryData;
use Kanelov\Shipping\Carrier\Econt\EcontShippingMethod;
use Kanelov\Shipping\Rest\EcontSearchController;

defined( 'ABSPATH' ) || exit;

/**
 * Общ изглед на полетата за офис/Еконтомат/адрес. Ползва се в чекаута и в админа (редакция на поръчка).
 */
final class DeliveryFormView {

	const PREFIX = 'ks_';

	public static function js_config(): array {
		return [
			'rest'     => esc_url_raw( rest_url( EcontSearchController::NS . '/econt/' ) ),
			'methodId' => EcontShippingMethod::ID,
			'i18n'     => [
				'noResults'    => __( 'Няма резултати', 'kanelov-shipping' ),
				'loading'      => __( 'Зареждане…', 'kanelov-shipping' ),
				'chooseCity'   => __( 'Първо изберете населено място', 'kanelov-shipping' ),
				'noOffices'    => __( 'Няма офиси в това населено място', 'kanelov-shipping' ),
				'noLockers'    => __( 'Няма Еконтомати в това населено място', 'kanelov-shipping' ),
				'searchOffice' => __( 'Търсете офис по име или адрес…', 'kanelov-shipping' ),
				'searchLocker' => __( 'Търсете Еконтомат…', 'kanelov-shipping' ),
				'geoError'     => __( 'Не можахме да определим местоположението ви.', 'kanelov-shipping' ),
				'nearest'      => __( 'Най-близки до вас', 'kanelov-shipping' ),
				'km'           => __( 'км', 'kanelov-shipping' ),
			],
		];
	}

	public static function enqueue_scripts(): void {
		wp_enqueue_style( 'ks-delivery', KS_URL . 'assets/css/checkout.css', [], KS_VERSION );
		wp_enqueue_script( 'ks-delivery-form', KS_URL . 'assets/js/delivery-form.js', [], KS_VERSION, true );
	}

	/**
	 * @param bool $with_type_select true в админа (избор на вид), false в чекаута (видът идва от ставката)
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
			<input type="hidden" name="<?php echo esc_attr( $f( 'type' ) ); ?>" value="<?php echo esc_attr( $saved->type ); ?>" class="ks-type">
		<?php endif; ?>

		<div class="ks-row ks-row--city">
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
			<button type="button" class="button ks-nearest">📍 <?php esc_html_e( 'Най-близък до мен', 'kanelov-shipping' ); ?></button>
		</div>

		<div class="ks-section ks-section--office" hidden>
			<?php
			woocommerce_form_field( $f( 'office_search' ), [
				'type'         => 'text',
				'label'        => __( 'Офис / Еконтомат', 'kanelov-shipping' ),
				'required'     => true,
				'class'        => [ 'form-row-wide', 'ks-field-office' ],
				'autocomplete' => 'off',
				'placeholder'  => __( 'Търсете по име или адрес…', 'kanelov-shipping' ),
				'input_class'  => [ 'ks-office' ],
			], $saved->office_name );
			?>
			<p class="ks-office-selected" <?php echo $saved->office_code ? '' : 'hidden'; ?>><?php echo $saved->office_code ? '✓ ' . esc_html( $saved->office_name ) : ''; ?></p>
		</div>

		<div class="ks-section ks-section--door" hidden>
			<?php
			woocommerce_form_field( $f( 'street' ), [ 'type' => 'text', 'label' => __( 'Улица / булевард', 'kanelov-shipping' ), 'class' => [ 'form-row-first' ], 'autocomplete' => 'off', 'input_class' => [ 'ks-street' ] ], $saved->street );
			woocommerce_form_field( $f( 'street_num' ), [ 'type' => 'text', 'label' => __( '№', 'kanelov-shipping' ), 'class' => [ 'form-row-last', 'ks-field-num' ] ], $saved->street_num );
			woocommerce_form_field( $f( 'quarter' ), [ 'type' => 'text', 'label' => __( 'Квартал / ж.к.', 'kanelov-shipping' ), 'class' => [ 'form-row-first' ], 'autocomplete' => 'off', 'input_class' => [ 'ks-quarter' ] ], $saved->quarter );
			woocommerce_form_field( $f( 'block' ), [ 'type' => 'text', 'label' => __( 'Блок', 'kanelov-shipping' ), 'class' => [ 'form-row-last', 'ks-field-num' ] ], $saved->block );
			?>
			<div class="ks-row ks-row--small">
				<?php
				woocommerce_form_field( $f( 'entrance' ), [ 'type' => 'text', 'label' => __( 'Вход', 'kanelov-shipping' ), 'class' => [ 'ks-third' ] ], $saved->entrance );
				woocommerce_form_field( $f( 'floor' ), [ 'type' => 'text', 'label' => __( 'Етаж', 'kanelov-shipping' ), 'class' => [ 'ks-third' ] ], $saved->floor );
				woocommerce_form_field( $f( 'apartment' ), [ 'type' => 'text', 'label' => __( 'Апартамент', 'kanelov-shipping' ), 'class' => [ 'ks-third' ] ], $saved->apartment );
				?>
			</div>
			<?php
			woocommerce_form_field( $f( 'note' ), [ 'type' => 'text', 'label' => __( 'Бележка за куриера', 'kanelov-shipping' ), 'class' => [ 'form-row-wide' ], 'placeholder' => __( 'Ориентир, звънец, фирма…', 'kanelov-shipping' ) ], $saved->note );
			?>
		</div>
		<script type="application/json" class="ks-type-labels"><?php echo wp_json_encode( $types ); ?></script>
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
		] );
	}
}
