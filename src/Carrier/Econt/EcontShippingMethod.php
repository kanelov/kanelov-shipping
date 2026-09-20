<?php
namespace Kanelov\Shipping\Carrier\Econt;

use Kanelov\Shipping\Admin\SettingsActions;
use Kanelov\Shipping\Carrier\DeliveryData;

defined( 'ABSPATH' ) || exit;

/**
 * Метод за доставка „Еконт“. Добавя се в зона за доставка и предлага до три ставки:
 * до офис, до Еконтомат и до адрес, всяка със своя фиксирана цена и праг за безплатна доставка.
 * Глобалните настройки (достъп до API, подател, споразумения, пратка) са в WooCommerce > Доставка > Еконт.
 */
final class EcontShippingMethod extends \WC_Shipping_Method {

	const ID = 'ks_econt';

	public function __construct( $instance_id = 0 ) {
		$this->id                 = self::ID;
		$this->instance_id        = absint( $instance_id );
		$this->method_title       = __( 'Еконт', 'kanelov-shipping' );
		$this->method_description = __( 'Доставка с Еконт до офис, Еконтомат или адрес. Глобалните настройки (API, подател, споразумения) са в раздела „Еконт“ на страницата Доставка.', 'kanelov-shipping' );
		$this->supports           = [ 'shipping-zones', 'instance-settings', 'instance-settings-modal', 'settings' ];

		$this->init_form_fields();
		$this->init_instance_settings();
		$this->init_settings();

		$this->enabled = 'yes';
		$this->title   = $this->get_instance_option( 'title', __( 'Еконт', 'kanelov-shipping' ) );

		add_action( 'woocommerce_update_options_shipping_' . $this->id, [ $this, 'process_admin_options' ] );
	}

	// Ставки.

	public static function rate_types(): array {
		return [
			DeliveryData::TYPE_OFFICE => __( 'до офис на Еконт', 'kanelov-shipping' ),
			DeliveryData::TYPE_LOCKER => __( 'до Еконтомат', 'kanelov-shipping' ),
			DeliveryData::TYPE_DOOR   => __( 'до адрес', 'kanelov-shipping' ),
		];
	}

	/** Връща типа доставка от id на ставка ("ks_econt:3:office" → "office") или null. */
	public static function type_from_rate_id( string $rate_id ): ?string {
		$parts = explode( ':', $rate_id );
		if ( ( $parts[0] ?? '' ) !== self::ID ) {
			return null;
		}
		$type = end( $parts );
		return in_array( $type, DeliveryData::TYPES, true ) ? $type : null;
	}

	public function calculate_shipping( $package = [] ): void {
		$settings = new EcontSettings();
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
		}
		$subtotal = (float) apply_filters( 'kanelov_shipping/free_shipping_basis', $subtotal, $package );

		foreach ( self::rate_types() as $type => $label ) {
			if ( $this->get_instance_option( $type . '_enabled', 'yes' ) !== 'yes' ) {
				continue;
			}
			if ( $type === DeliveryData::TYPE_LOCKER && $weight > $settings->locker_max_weight() ) {
				continue;
			}
			$price     = (float) str_replace( ',', '.', (string) $this->get_instance_option( $type . '_price', '0' ) );
			$free_from = (float) str_replace( ',', '.', (string) $this->get_instance_option( $type . '_free_from', '0' ) );
			if ( $free_from > 0 && $subtotal >= $free_from ) {
				$price = 0.0;
			}
			$this->add_rate( [
				'id'        => $this->get_rate_id( $type ),
				'label'     => $this->get_instance_option( $type . '_label', '' ) ?: ( $this->title . ' ' . $label ),
				'cost'      => $price,
				'package'   => $package,
				'meta_data' => [ 'ks_carrier' => EcontCarrier::ID, 'ks_type' => $type ],
			] );
		}
	}

	// Настройки на инстанцията (в зоната).

	public function init_form_fields(): void {
		$this->instance_form_fields = [
			'title' => [
				'title'   => __( 'Име на куриера в чекаута', 'kanelov-shipping' ),
				'type'    => 'text',
				'default' => __( 'Еконт', 'kanelov-shipping' ),
			],
		];
		$defaults = [
			DeliveryData::TYPE_OFFICE => [ '2.50', '0' ],
			DeliveryData::TYPE_LOCKER => [ '2.50', '0' ],
			DeliveryData::TYPE_DOOR   => [ '3.50', '0' ],
		];
		foreach ( self::rate_types() as $type => $label ) {
			$this->instance_form_fields[ $type . '_section' ] = [
				'title' => sprintf( __( 'Доставка %s', 'kanelov-shipping' ), $label ),
				'type'  => 'title',
			];
			$this->instance_form_fields[ $type . '_enabled' ] = [
				'title'   => __( 'Активна', 'kanelov-shipping' ),
				'type'    => 'checkbox',
				'label'   => sprintf( __( 'Предлагай доставка %s', 'kanelov-shipping' ), $label ),
				'default' => 'yes',
			];
			$this->instance_form_fields[ $type . '_price' ] = [
				'title'       => __( 'Цена за клиента (€)', 'kanelov-shipping' ),
				'type'        => 'price',
				'default'     => $defaults[ $type ][0],
				'description' => __( 'Фиксирана сума, която клиентът плаща. Разликата до реалната цена на Еконт е за ваша сметка.', 'kanelov-shipping' ),
				'desc_tip'    => true,
			];
			$this->instance_form_fields[ $type . '_free_from' ] = [
				'title'       => __( 'Безплатна при поръчка над (€)', 'kanelov-shipping' ),
				'type'        => 'price',
				'default'     => $defaults[ $type ][1],
				'description' => __( '0 = никога безплатна.', 'kanelov-shipping' ),
				'desc_tip'    => true,
			];
			$this->instance_form_fields[ $type . '_label' ] = [
				'title'       => __( 'Собствен надпис (по избор)', 'kanelov-shipping' ),
				'type'        => 'text',
				'default'     => '',
				'placeholder' => sprintf( '%s %s', __( 'Еконт', 'kanelov-shipping' ), $label ),
			];
		}

		$this->form_fields = $this->global_fields();
	}

	private function global_fields(): array {
		$settings = new EcontSettings();
		$profile  = new EcontProfile( $settings );
		$has_api  = $settings->api()->has_credentials();

		$fields = [
			'api_section' => [
				'title'       => __( 'Достъп до Еконт', 'kanelov-shipping' ),
				'type'        => 'title',
				'description' => __( 'Потребител и парола се създават в ee.econt.com > Профил > Интеграция за онлайн магазини. За демо средата ползвайте demo / demo.', 'kanelov-shipping' ),
			],
			'environment' => [
				'title'   => __( 'Среда', 'kanelov-shipping' ),
				'type'    => 'select',
				'default' => 'demo',
				'options' => [ 'demo' => __( 'Демо (demo.econt.com)', 'kanelov-shipping' ), 'live' => __( 'Реална (ee.econt.com)', 'kanelov-shipping' ) ],
			],
			'username' => [ 'title' => __( 'Потребител', 'kanelov-shipping' ), 'type' => 'text', 'default' => '' ],
			'password' => [ 'title' => __( 'Парола', 'kanelov-shipping' ), 'type' => 'password', 'default' => '' ],
			'actions'  => [
				'title' => __( 'Действия', 'kanelov-shipping' ),
				'type'  => 'ks_actions',
			],
		];

		if ( $has_api ) {
			$fields += [
				'sender_section' => [
					'title'       => __( 'Подател', 'kanelov-shipping' ),
					'type'        => 'title',
					'description' => $profile->client()
						? sprintf( __( 'Профил от Еконт: %s', 'kanelov-shipping' ), esc_html( (string) ( $profile->client()['name'] ?? '' ) ) )
						: __( 'Натиснете „Тест на връзката“, за да се заредят профилът, адресите и споразуменията.', 'kanelov-shipping' ),
				],
				'profile_index' => [
					'title'   => __( 'Профил', 'kanelov-shipping' ),
					'type'    => 'select',
					'default' => '0',
					'options' => $profile->profile_choices() ?: [ '0' => '—' ],
				],
				'sender_name'  => [ 'title' => __( 'Лице за контакт', 'kanelov-shipping' ), 'type' => 'text', 'default' => '', 'description' => __( 'Ако е празно, се ползва името от профила.', 'kanelov-shipping' ), 'desc_tip' => true ],
				'sender_phone' => [ 'title' => __( 'Телефон на подателя', 'kanelov-shipping' ), 'type' => 'text', 'default' => '' ],
				'send_from'    => [
					'title'   => __( 'Изпращане от', 'kanelov-shipping' ),
					'type'    => 'select',
					'default' => 'office',
					'options' => [ 'office' => __( 'Офис на Еконт', 'kanelov-shipping' ), 'address' => __( 'Адрес (куриер взима пратката)', 'kanelov-shipping' ) ],
				],
				'sender_office_code' => [
					'title'   => __( 'Офис на подателя', 'kanelov-shipping' ),
					'type'    => 'select',
					'default' => '',
					'options' => $this->office_choices(),
					'class'   => 'wc-enhanced-select',
				],
				'sender_address' => [
					'title'   => __( 'Адрес на подателя', 'kanelov-shipping' ),
					'type'    => 'select',
					'default' => '0',
					'options' => $profile->address_choices() ?: [ '0' => __( '(няма адреси в профила)', 'kanelov-shipping' ) ],
				],

				'payment_section' => [
					'title'       => __( 'Плащане и споразумения', 'kanelov-shipping' ),
					'type'        => 'title',
					'description' => __( 'Наложеният платеж се събира от Еконт по избраното споразумение и се превежда по банка. Сумата на наложения платеж е общата сума на поръчката, включително платената от клиента доставка.', 'kanelov-shipping' ),
				],
				'cd_pay_options_template' => [
					'title'   => __( 'Споразумение за наложен платеж', 'kanelov-shipping' ),
					'type'    => 'select',
					'default' => '',
					'options' => $profile->cd_pay_option_choices(),
				],
				'sender_payment_method' => [
					'title'       => __( 'Как плащате куриерската услуга', 'kanelov-shipping' ),
					'type'        => 'select',
					'default'     => 'credit',
					'options'     => [ 'credit' => __( 'По споразумение (кредит)', 'kanelov-shipping' ), 'cash' => __( 'В брой при предаване', 'kanelov-shipping' ) ],
				],
				'receiver_pays_shipping' => [
					'title'       => __( 'Получателят плаща доставката на куриера', 'kanelov-shipping' ),
					'type'        => 'checkbox',
					'label'       => __( 'Да, сумата за доставка от поръчката се събира от получателя отделно от наложения платеж', 'kanelov-shipping' ),
					'default'     => 'no',
					'description' => __( 'Оставете изключено, ако доставката е включена в наложения платеж (препоръчително при споразумение за НП).', 'kanelov-shipping' ),
				],
				'sms_notification' => [
					'title'   => __( 'SMS известие до получателя', 'kanelov-shipping' ),
					'type'    => 'checkbox',
					'label'   => __( 'Включено', 'kanelov-shipping' ),
					'default' => 'yes',
				],
				'invoice_before_pay_cd' => [
					'title'   => __( 'Фактура преди плащане на НП', 'kanelov-shipping' ),
					'type'    => 'checkbox',
					'label'   => __( 'Куриерът показва фактурата/описа преди плащане', 'kanelov-shipping' ),
					'default' => 'no',
				],
				'declared_value_threshold' => [
					'title'       => __( 'Обявена стойност при поръчка над (€)', 'kanelov-shipping' ),
					'type'        => 'price',
					'default'     => '0',
					'description' => __( '0 = без обявена стойност. Не се прилага за Еконтомат.', 'kanelov-shipping' ),
					'desc_tip'    => true,
				],

				'shipment_section' => [ 'title' => __( 'Пратка', 'kanelov-shipping' ), 'type' => 'title' ],
				'shipment_type'    => [
					'title'   => __( 'Вид пратка', 'kanelov-shipping' ),
					'type'    => 'select',
					'default' => 'pack',
					'options' => [ 'pack' => __( 'Пакет', 'kanelov-shipping' ), 'document' => __( 'Документ', 'kanelov-shipping' ), 'pallet' => __( 'Палет', 'kanelov-shipping' ) ],
				],
				'default_weight' => [
					'title'       => __( 'Тегло по подразбиране за продукт без тегло (кг)', 'kanelov-shipping' ),
					'type'        => 'decimal',
					'default'     => '0.5',
				],
				'min_weight' => [
					'title'   => __( 'Минимално тегло на пратка (кг)', 'kanelov-shipping' ),
					'type'    => 'decimal',
					'default' => '0.1',
				],
				'description_mode' => [
					'title'   => __( 'Описание на пратката', 'kanelov-shipping' ),
					'type'    => 'select',
					'default' => 'both',
					'options' => [
						'both'         => __( 'Номер на поръчка + продукти', 'kanelov-shipping' ),
						'products'     => __( 'Само продукти', 'kanelov-shipping' ),
						'order_number' => __( 'Само номер на поръчка', 'kanelov-shipping' ),
					],
				],
				'description_max_length' => [
					'title'   => __( 'Максимална дължина на описанието', 'kanelov-shipping' ),
					'type'    => 'number',
					'default' => '100',
				],

				'locker_section'    => [ 'title' => __( 'Еконтомат', 'kanelov-shipping' ), 'type' => 'title' ],
				'locker_max_weight' => [
					'title'   => __( 'Максимално тегло на кошницата за Еконтомат (кг)', 'kanelov-shipping' ),
					'type'    => 'decimal',
					'default' => '20',
				],
				'locker_allow_cod' => [
					'title'   => __( 'Наложен платеж при Еконтомат', 'kanelov-shipping' ),
					'type'    => 'checkbox',
					'label'   => __( 'Разрешен', 'kanelov-shipping' ),
					'default' => 'yes',
				],
			];
		}

		return $fields;
	}

	private function office_choices(): array {
		global $wpdb;
		$table = EcontNomenclature::table_offices();
		$c     = EcontNomenclature::table_cities();
		$rows  = $wpdb->get_results( "SELECT o.code, o.name, c.name AS city FROM {$table} o LEFT JOIN {$c} c ON c.id = o.city_id WHERE o.is_aps = 0 ORDER BY c.name, o.name LIMIT 3000", ARRAY_A );
		$out   = [ '' => __( '— изберете офис —', 'kanelov-shipping' ) ];
		foreach ( (array) $rows as $r ) {
			$out[ $r['code'] ] = trim( ( $r['city'] ? $r['city'] . ' – ' : '' ) . $r['name'] . ' [' . $r['code'] . ']' );
		}
		if ( count( $out ) === 1 ) {
			$out[''] = __( '(няма синхронизирани офиси; натиснете „Синхронизирай номенклатурите“)', 'kanelov-shipping' );
		}
		return $out;
	}

	/** Custom поле с бутоните за действия в настройките. */
	public function generate_ks_actions_html( string $key, array $data ): string {
		$sync = ( new EcontNomenclature() )->last_sync();
		$fmt  = static fn( array $s ) => empty( $s['time'] ) ? '—' : sprintf( '%s (%d)', wp_date( 'd.m.Y H:i', (int) $s['time'] ), (int) ( $s['count'] ?? 0 ) );
		$profile_time = ( new EcontProfile( new EcontSettings() ) )->fetched_at();
		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><?php echo esc_html( $data['title'] ); ?></th>
			<td class="forminp">
				<a class="button" href="<?php echo esc_url( SettingsActions::url( 'test' ) ); ?>"><?php esc_html_e( 'Тест на връзката', 'kanelov-shipping' ); ?></a>
				<a class="button" href="<?php echo esc_url( SettingsActions::url( 'profile' ) ); ?>"><?php esc_html_e( 'Обнови профила и споразуменията', 'kanelov-shipping' ); ?></a>
				<a class="button" href="<?php echo esc_url( SettingsActions::url( 'sync' ) ); ?>"><?php esc_html_e( 'Синхронизирай номенклатурите', 'kanelov-shipping' ); ?></a>
				<p class="description">
					<?php echo esc_html( sprintf( __( 'Профил обновен: %s · Градове: %s · Офиси и Еконтомати: %s. Номенклатурите се обновяват автоматично всяка нощ.', 'kanelov-shipping' ), $profile_time ? wp_date( 'd.m.Y H:i', $profile_time ) : '—', $fmt( (array) ( $sync['cities'] ?? [] ) ), $fmt( (array) ( $sync['offices'] ?? [] ) ) ) ); ?>
					<?php esc_html_e( 'Запазете промените, преди да натиснете бутон.', 'kanelov-shipping' ); ?>
				</p>
			</td>
		</tr>
		<?php
		return (string) ob_get_clean();
	}
}
