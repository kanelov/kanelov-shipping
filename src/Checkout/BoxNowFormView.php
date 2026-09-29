<?php
namespace Kanelov\Shipping\Checkout;

use Kanelov\Shipping\Carrier\BoxNow\BoxNowCarrier;
use Kanelov\Shipping\Carrier\BoxNow\BoxNowLockers;
use Kanelov\Shipping\Carrier\BoxNow\BoxNowSettings;
use Kanelov\Shipping\Carrier\BoxNow\BoxNowShippingMethod;
use Kanelov\Shipping\Carrier\DeliveryData;
use Kanelov\Shipping\Rest\EcontSearchController;

defined( 'ABSPATH' ) || exit;

/**
 * Полетата за избор на автомат на Box Now: населено място, автомат (списък, най-близък, карта).
 * Същите класове и JavaScript като формата на Еконт, с префикс ksbn_ на полетата, за да са двете форми на една страница.
 */
final class BoxNowFormView {

	const PREFIX = 'ksbn_';

	public static function js_config(): array {
		$settings = new BoxNowSettings();
		return [
			'rest' => esc_url_raw( rest_url( EcontSearchController::NS . '/boxnow/' ) ),
			'sync' => (int) ( ( new BoxNowLockers() )->last_sync()['time'] ?? 0 ),
			'map'  => $settings->map_enabled() ? [
				'js'  => KS_URL . 'assets/vendor/leaflet/leaflet.js',
				'css' => KS_URL . 'assets/vendor/leaflet/leaflet.css',
			] : null,
			'i18n' => [
				'noResults'    => __( 'Няма резултати', 'kanelov-shipping' ),
				'loading'      => __( 'Зареждане…', 'kanelov-shipping' ),
				'chooseCity'   => __( 'Първо изберете населено място', 'kanelov-shipping' ),
				'noOffices'    => __( 'Няма автомати в това населено място', 'kanelov-shipping' ),
				'noLockers'    => __( 'Няма автомати в това населено място', 'kanelov-shipping' ),
				'searchOffice' => __( 'Търсете автомат по име или адрес…', 'kanelov-shipping' ),
				'searchLocker' => __( 'Търсете автомат по име или адрес…', 'kanelov-shipping' ),
				'labelOffice'  => __( 'Автомат на Box Now', 'kanelov-shipping' ),
				'labelLocker'  => __( 'Автомат на Box Now', 'kanelov-shipping' ),
				'from'         => __( 'от', 'kanelov-shipping' ),
				'geoError'     => __( 'Не можахме да определим местоположението ви.', 'kanelov-shipping' ),
				'nearest'      => __( 'Най-близки до вас', 'kanelov-shipping' ),
				'km'           => __( 'км', 'kanelov-shipping' ),
				'choose'       => __( 'Избери', 'kanelov-shipping' ),
				'close'        => __( 'Затвори', 'kanelov-shipping' ),
				'mapTitle'     => __( 'Автомати на Box Now', 'kanelov-shipping' ),
				'noCoords'     => __( 'Няма автомати с координати за това населено място.', 'kanelov-shipping' ),
			],
		];
	}

	/**
	 * @param bool $admin true в кутията на поръчката (всичко видимо), false в чекаута (стъпки)
	 */
	public static function render( DeliveryData $saved, bool $admin = false ): void {
		$f        = static fn( string $k ) => self::PREFIX . $k;
		$settings = new BoxNowSettings();
		$sync     = ( new BoxNowLockers() )->last_sync();
		?>
		<input type="hidden" name="<?php echo esc_attr( $f( 'city_id' ) ); ?>" value="<?php echo esc_attr( (string) $saved->city_id ); ?>" class="ks-city-id">
		<input type="hidden" name="<?php echo esc_attr( $f( 'post_code' ) ); ?>" value="<?php echo esc_attr( $saved->post_code ); ?>" class="ks-post-code">
		<input type="hidden" name="<?php echo esc_attr( $f( 'office_code' ) ); ?>" value="<?php echo esc_attr( $saved->office_code ); ?>" class="ks-office-code">
		<input type="hidden" name="<?php echo esc_attr( $f( 'office_name' ) ); ?>" value="<?php echo esc_attr( $saved->office_name ); ?>" class="ks-office-name">

		<?php if ( empty( $sync['count'] ) ) : ?>
			<p class="ks-notice"><?php echo $admin ? esc_html__( 'Автоматите на Box Now още не са заредени. Натиснете „Обнови автоматите“ в настройките на Box Now.', 'kanelov-shipping' ) : esc_html__( 'Автоматите на Box Now се зареждат. Опитайте след малко или изберете друг куриер.', 'kanelov-shipping' ); ?></p>
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
				<?php if ( $settings->map_enabled() ) : ?>
					<button type="button" class="button ks-map-open"><?php esc_html_e( 'Покажи на карта', 'kanelov-shipping' ); ?></button>
				<?php endif; ?>
			</div>
		</div>

		<div class="ks-step ks-step--place ks-section ks-section--office" <?php echo $admin ? '' : 'hidden'; ?>>
			<?php
			woocommerce_form_field( $f( 'office_search' ), [
				'type'         => 'text',
				'label'        => __( 'Автомат на Box Now', 'kanelov-shipping' ),
				'required'     => true,
				'class'        => [ 'form-row-wide', 'ks-field-office' ],
				'autocomplete' => 'off',
				'placeholder'  => __( 'Търсете автомат по име или адрес…', 'kanelov-shipping' ),
				'input_class'  => [ 'ks-office' ],
			], $saved->office_name );
			?>
			<p class="ks-office-selected" <?php echo $saved->office_code ? '' : 'hidden'; ?>><?php echo $saved->office_code ? '✓ ' . esc_html( $saved->office_name ) : ''; ?></p>
		</div>
		<?php
	}

	/** Чете DeliveryData на Box Now от POST/REQUEST с префикс ksbn_. Видът е винаги „автомат“. */
	public static function from_request( array $req ): DeliveryData {
		$get = static fn( string $k ) => sanitize_text_field( wp_unslash( (string) ( $req[ self::PREFIX . $k ] ?? '' ) ) );
		return DeliveryData::from_array( [
			'carrier'     => BoxNowCarrier::ID,
			'type'        => DeliveryData::TYPE_LOCKER,
			'city_id'     => (int) $get( 'city_id' ),
			'city_name'   => $get( 'city_name' ),
			'post_code'   => $get( 'post_code' ),
			'office_code' => $get( 'office_code' ),
			'office_name' => $get( 'office_name' ),
		] );
	}

	public static function method_id(): string {
		return BoxNowShippingMethod::ID;
	}
}
