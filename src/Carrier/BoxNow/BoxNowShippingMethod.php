<?php
namespace Kanelov\Shipping\Carrier\BoxNow;

use Kanelov\Shipping\Admin\SettingsActions;
use Kanelov\Shipping\Carrier\DeliveryData;

defined( 'ABSPATH' ) || exit;

/**
 * Метод за доставка „Box Now“: една ставка „до автомат“ с фиксирана цена и праг за безплатна доставка
 * (настройки на инстанцията в зоната). Глобалните настройки (API, склад, подател) са в WooCommerce > Доставка > Box Now.
 */
final class BoxNowShippingMethod extends \WC_Shipping_Method {

	const ID = 'ks_boxnow';

	public function __construct( $instance_id = 0 ) {
		$this->id                 = self::ID;
		$this->instance_id        = absint( $instance_id );
		$this->method_title       = __( 'Box Now', 'kanelov-shipping' );
		$this->method_description = __( 'Доставка до автомат на Box Now. Глобалните настройки (API, склад, подател) са в раздела „Box Now“ на страницата Доставка.', 'kanelov-shipping' );
		$this->supports           = [ 'shipping-zones', 'instance-settings', 'instance-settings-modal', 'settings' ];

		$this->init_form_fields();
		$this->init_instance_settings();
		$this->init_settings();

		$this->enabled = 'yes';
		$this->title   = $this->get_instance_option( 'title', __( 'Box Now', 'kanelov-shipping' ) );

		add_action( 'woocommerce_update_options_shipping_' . $this->id, [ $this, 'process_admin_options' ] );
	}

	public static function is_boxnow_rate( string $rate_id ): bool {
		return explode( ':', $rate_id )[0] === self::ID;
	}

	public function calculate_shipping( $package = [] ): void {
		$settings = new BoxNowSettings();
		if ( ! $settings->is_configured() ) {
			return;
		}
		if ( $settings->admins_only() && ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$subtotal = 0.0;
		$weight   = 0.0;
		foreach ( (array) ( $package['contents'] ?? [] ) as $item ) {
			$product = $item['data'] ?? null;
			if ( ! $product instanceof \WC_Product || $product->is_virtual() ) {
				continue;
			}
			$qty       = (int) ( $item['quantity'] ?? 1 );
			$subtotal += (float) ( $item['line_total'] ?? 0 ) + (float) ( $item['line_tax'] ?? 0 );
			$w         = $product->get_weight() !== '' ? wc_get_weight( (float) $product->get_weight(), 'kg' ) : $settings->default_weight();
			$weight   += $w * $qty;
			// Продукт, който не се побира в най-голямото отделение, спира Box Now за цялата кошница.
			if ( $product->has_dimensions() ) {
				$dims = [ (float) wc_get_dimension( (float) $product->get_length(), 'cm' ), (float) wc_get_dimension( (float) $product->get_width(), 'cm' ), (float) wc_get_dimension( (float) $product->get_height(), 'cm' ) ];
				if ( min( $dims ) > 0 && BoxNowLabelBuilder::compartment_for( $dims ) === 0 ) {
					return;
				}
			}
		}
		if ( $weight > $settings->max_weight() ) {
			return;
		}
		$subtotal  = (float) apply_filters( 'kanelov_shipping/free_shipping_basis', $subtotal, $package );
		$price     = (float) str_replace( ',', '.', (string) $this->get_instance_option( 'price', '2.50' ) );
		$free_from = (float) str_replace( ',', '.', (string) $this->get_instance_option( 'free_from', '0' ) );
		if ( $free_from > 0 && $subtotal >= $free_from ) {
			$price = 0.0;
		}
		$label   = $this->get_instance_option( 'label', '' ) ?: __( 'до автомат', 'kanelov-shipping' );
		$options = [ DeliveryData::TYPE_LOCKER => [ 'label' => $label, 'cost' => $price ] ];
		$this->add_rate( [
			'id'        => $this->get_rate_id(),
			'label'     => $this->title . ' ' . $label,
			'cost'      => $price,
			'package'   => $package,
			'meta_data' => [ 'ks_carrier' => BoxNowCarrier::ID, 'ks_type' => DeliveryData::TYPE_LOCKER, 'ks_options' => $options ],
		] );
	}

	public function init_form_fields(): void {
		$this->instance_form_fields = [
			'title'     => [ 'title' => __( 'Име на куриера в чекаута', 'kanelov-shipping' ), 'type' => 'text', 'default' => __( 'Box Now', 'kanelov-shipping' ) ],
			'price'     => [
				'title'       => __( 'Цена за клиента (€)', 'kanelov-shipping' ),
				'type'        => 'price',
				'default'     => '2.50',
				'description' => __( 'Фиксирана сума, която клиентът плаща за доставка до автомат.', 'kanelov-shipping' ),
				'desc_tip'    => true,
			],
			'free_from' => [
				'title'       => __( 'Безплатна при поръчка над (€)', 'kanelov-shipping' ),
				'type'        => 'price',
				'default'     => '0',
				'description' => __( '0 = никога безплатна.', 'kanelov-shipping' ),
				'desc_tip'    => true,
			],
			'label'     => [
				'title'       => __( 'Собствен надпис (по избор)', 'kanelov-shipping' ),
				'type'        => 'text',
				'default'     => '',
				'placeholder' => __( 'до автомат', 'kanelov-shipping' ),
				'desc_tip'    => true,
				'description' => __( 'Показва се след името на куриера, напр. „Box Now до автомат“.', 'kanelov-shipping' ),
			],
		];
		$this->form_fields = is_admin() ? $this->global_fields() : [];
	}

	private function global_fields(): array {
		$settings = new BoxNowSettings();
		$origins  = $settings->origin_choices();
		$fields   = [
			'api_section' => [
				'title'       => __( 'Достъп до Box Now', 'kanelov-shipping' ),
				'type'        => 'title',
				'description' => __( 'Client ID, Client Secret, Partner ID и Warehouse ID се получават от Box Now (integrationsupport@boxnow.bg). Client Secret е като парола: не го споделяйте.', 'kanelov-shipping' ),
			],
			'environment'   => [
				'title'   => __( 'Среда', 'kanelov-shipping' ),
				'type'    => 'select',
				'default' => 'production',
				'options' => [ 'production' => __( 'Реална (api-production.boxnow.bg)', 'kanelov-shipping' ), 'stage' => __( 'Тестова (api-stage.boxnow.bg)', 'kanelov-shipping' ) ],
			],
			'client_id'     => [ 'title' => 'Client ID', 'type' => 'text', 'default' => '' ],
			'client_secret' => [ 'title' => 'Client Secret', 'type' => 'password', 'default' => '' ],
			'partner_id'    => [ 'title' => 'Partner ID', 'type' => 'text', 'default' => '', 'description' => __( 'Номерът на партньора от писмото на Box Now (напр. 18246). Нужен за картата и когато профилът има няколко партньора.', 'kanelov-shipping' ), 'desc_tip' => true ],
			'actions'       => [ 'title' => __( 'Действия', 'kanelov-shipping' ), 'type' => 'ks_bn_actions' ],
			'admins_only'   => [
				'title'       => __( 'Тестов режим', 'kanelov-shipping' ),
				'type'        => 'checkbox',
				'label'       => __( 'Методът Box Now се вижда в чекаута само за администратори на магазина', 'kanelov-shipping' ),
				'default'     => 'yes',
				'description' => __( 'Удобно за тест на жив сайт: клиентите не виждат Box Now, докато не изключите режима.', 'kanelov-shipping' ),
			],
			'map_enabled'   => [
				'title'   => __( 'Карта на автоматите', 'kanelov-shipping' ),
				'type'    => 'checkbox',
				'label'   => __( 'Бутон „Покажи на карта“ при избора на автомат', 'kanelov-shipping' ),
				'default' => 'yes',
			],

			'sender_section' => [
				'title'       => __( 'Подател и склад', 'kanelov-shipping' ),
				'type'        => 'title',
				'description' => $origins ? __( 'Складовете са заредени от Box Now.', 'kanelov-shipping' ) : __( 'Запазете данните за достъп и натиснете „Тест на връзката“, за да се заредят складовете от Box Now.', 'kanelov-shipping' ),
			],
			'origin_id'      => [
				'title'       => __( 'Изпращане от', 'kanelov-shipping' ),
				'type'        => $origins ? 'select' : 'text',
				'default'     => '',
				'options'     => $origins ? [ '' => __( '— изберете склад —', 'kanelov-shipping' ) ] + $origins : [],
				'description' => __( 'Мястото, от което Box Now взима пратките (Warehouse ID от писмото). При избор на „any-apm“ вие оставяте пратката в автомат.', 'kanelov-shipping' ),
				'desc_tip'    => true,
			],
			'sender_name'    => [ 'title' => __( 'Име на подателя', 'kanelov-shipping' ), 'type' => 'text', 'default' => '', 'placeholder' => (string) get_bloginfo( 'name' ) ],
			'sender_phone'   => [ 'title' => __( 'Телефон на подателя', 'kanelov-shipping' ), 'type' => 'text', 'default' => '', 'placeholder' => '+359 88 123 4567' ],
			'sender_email'   => [ 'title' => __( 'Имейл на подателя', 'kanelov-shipping' ), 'type' => 'text', 'default' => '', 'placeholder' => (string) get_option( 'admin_email' ) ],
			'notify_email'   => [
				'title'       => __( 'Етикет по имейл', 'kanelov-shipping' ),
				'type'        => 'text',
				'default'     => '',
				'description' => __( 'Ако е попълнен, Box Now праща PDF етикета на този имейл при всяка приета заявка. Етикетът се отваря и от поръчката.', 'kanelov-shipping' ),
				'desc_tip'    => true,
			],

			'shipment_section'    => [ 'title' => __( 'Пратка', 'kanelov-shipping' ), 'type' => 'title', 'description' => __( 'Отделенията на автоматите са 60×45 см с височина 8 см (размер 1), 17 см (размер 2) и 36 см (размер 3), до 20 кг.', 'kanelov-shipping' ) ],
			'allow_cod'           => [
				'title'   => __( 'Наложен платеж', 'kanelov-shipping' ),
				'type'    => 'checkbox',
				'label'   => __( 'Разрешен (клиентът плаща на автомата с карта). Профилът в Box Now трябва да има разрешение за наложен платеж.', 'kanelov-shipping' ),
				'default' => 'yes',
			],
			'allow_return'        => [
				'title'   => __( 'Връщане от клиента', 'kanelov-shipping' ),
				'type'    => 'checkbox',
				'label'   => __( 'Клиентът може да върне пратката през автомат (allowReturn)', 'kanelov-shipping' ),
				'default' => 'yes',
			],
			'default_compartment' => [
				'title'       => __( 'Размер на отделението по подразбиране', 'kanelov-shipping' ),
				'type'        => 'select',
				'default'     => '2',
				'options'     => [ '1' => __( '1 – малко (до 8 см височина)', 'kanelov-shipping' ), '2' => __( '2 – средно (до 17 см)', 'kanelov-shipping' ), '3' => __( '3 – голямо (до 36 см)', 'kanelov-shipping' ) ],
				'description' => __( 'Ползва се, когато продуктите нямат размери. Иначе размерът се изчислява от размерите на продуктите.', 'kanelov-shipping' ),
				'desc_tip'    => true,
			],
			'default_weight'      => [ 'title' => __( 'Тегло по подразбиране за продукт без тегло (кг)', 'kanelov-shipping' ), 'type' => 'decimal', 'default' => '0.5' ],
			'max_weight'          => [ 'title' => __( 'Максимално тегло на кошницата (кг)', 'kanelov-shipping' ), 'type' => 'decimal', 'default' => '20' ],
			'description_max_length' => [ 'title' => __( 'Максимална дължина на описанието', 'kanelov-shipping' ), 'type' => 'number', 'default' => '100' ],
		];
		return $fields;
	}

	/** Custom поле с бутоните за действия в настройките. */
	public function generate_ks_bn_actions_html( string $key, array $data ): string {
		$settings = new BoxNowSettings();
		$sync     = ( new BoxNowLockers() )->last_sync();
		$profile  = $settings->profile();
		$perm     = $settings->permissions();
		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><?php echo esc_html( $data['title'] ); ?></th>
			<td class="forminp">
				<a class="button" href="<?php echo esc_url( SettingsActions::url( 'bn_test' ) ); ?>"><?php esc_html_e( 'Тест на връзката', 'kanelov-shipping' ); ?></a>
				<a class="button" href="<?php echo esc_url( SettingsActions::url( 'bn_sync' ) ); ?>"><?php esc_html_e( 'Обнови автоматите', 'kanelov-shipping' ); ?></a>
				<p class="description">
					<?php
					echo esc_html( sprintf(
						__( 'Профил обновен: %1$s · Автомати: %2$s. Автоматите се обновяват автоматично всяка нощ.', 'kanelov-shipping' ),
						! empty( $profile['time'] ) ? wp_date( 'd.m.Y H:i', (int) $profile['time'] ) : '—',
						empty( $sync['time'] ) ? '—' : sprintf( '%s (%d)', wp_date( 'd.m.Y H:i', (int) $sync['time'] ), (int) ( $sync['count'] ?? 0 ) )
					) );
					if ( $perm ) {
						echo ' ' . esc_html( sprintf( __( 'Наложен платеж в Box Now: %s.', 'kanelov-shipping' ), ! empty( $perm['codPayment'] ) ? __( 'разрешен', 'kanelov-shipping' ) : __( 'НЕ е разрешен', 'kanelov-shipping' ) ) );
					}
					?>
					<?php esc_html_e( 'Запазете промените, преди да натиснете бутон.', 'kanelov-shipping' ); ?>
				</p>
			</td>
		</tr>
		<?php
		return (string) ob_get_clean();
	}
}
