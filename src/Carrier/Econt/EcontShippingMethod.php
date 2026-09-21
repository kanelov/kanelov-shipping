<?php
namespace Kanelov\Shipping\Carrier\Econt;

use Kanelov\Shipping\Admin\SettingsActions;
use Kanelov\Shipping\Carrier\DeliveryData;

defined( 'ABSPATH' ) || exit;

/**
 * Метод за доставка „Еконт“. Добавя се в зона за доставка и дава една ставка „Еконт“, чиято цена зависи от
 * избрания в чекаута вид доставка (офис, Еконтомат, адрес), всеки със своя фиксирана цена и праг за безплатна.
 * Видът идва в пакета като $package['ks_type'] (ClassicCheckout го слага от сесията).
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
			DeliveryData::TYPE_OFFICE => __( 'до офис', 'kanelov-shipping' ),
			DeliveryData::TYPE_LOCKER => __( 'до Еконтомат', 'kanelov-shipping' ),
			DeliveryData::TYPE_DOOR   => __( 'до адрес', 'kanelov-shipping' ),
		];
	}

	/** Дали id на ставка ("ks_econt:3") е на този метод. */
	public static function is_econt_rate( string $rate_id ): bool {
		return explode( ':', $rate_id )[0] === self::ID;
	}

	public function calculate_shipping( $package = [] ): void {
		$settings = new EcontSettings();
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
		}
		$subtotal = (float) apply_filters( 'kanelov_shipping/free_shipping_basis', $subtotal, $package );

		$options = [];
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
			$options[ $type ] = [
				'label' => $this->get_instance_option( $type . '_label', '' ) ?: $label,
				'cost'  => $price,
			];
		}
		if ( ! $options ) {
			return;
		}

		$type = (string) ( $package['ks_type'] ?? '' );
		if ( ! isset( $options[ $type ] ) ) {
			$type = '';
		}
		$this->add_rate( [
			'id'        => $this->get_rate_id(),
			'label'     => $type ? $this->title . ' ' . $options[ $type ]['label'] : $this->title,
			'cost'      => $type ? $options[ $type ]['cost'] : min( array_column( $options, 'cost' ) ),
			'package'   => $package,
			'meta_data' => [ 'ks_carrier' => EcontCarrier::ID, 'ks_type' => $type, 'ks_options' => $options ],
		] );
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
				'placeholder' => $label,
				'description' => __( 'Показва се след името на куриера, напр. „Еконт до офис“.', 'kanelov-shipping' ),
				'desc_tip'    => true,
			];
		}

		// Глобалните полета (със заявки към базата и профила) са нужни само в админа.
		$this->form_fields = is_admin() ? $this->global_fields() : [];
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
			'admins_only' => [
				'title'       => __( 'Тестов режим', 'kanelov-shipping' ),
				'type'        => 'checkbox',
				'label'       => __( 'Методът Еконт се вижда в чекаута само за администратори на магазина', 'kanelov-shipping' ),
				'default'     => 'no',
				'description' => __( 'Удобно за тест на жив сайт: клиентите не виждат Еконт, докато не изключите режима.', 'kanelov-shipping' ),
			],
			'default_type' => [
				'title'       => __( 'Вид доставка по подразбиране', 'kanelov-shipping' ),
				'type'        => 'select',
				'default'     => DeliveryData::TYPE_OFFICE,
				'options'     => [ '' => __( 'Без (клиентът избира)', 'kanelov-shipping' ) ] + self::rate_types(),
				'description' => __( 'Предварително избран вид в чекаута, ако клиентът няма запомнен избор.', 'kanelov-shipping' ),
				'desc_tip'    => true,
			],
			'map_enabled' => [
				'title'   => __( 'Карта на офисите', 'kanelov-shipping' ),
				'type'    => 'checkbox',
				'label'   => __( 'Бутон „Покажи на карта“ при избора на офис/Еконтомат (отваря се в прозорец)', 'kanelov-shipping' ),
				'default' => 'yes',
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
					'description' => __( 'Наложеният платеж се събира от Еконт по избраното споразумение и се превежда по банка. Ако получателят плаща доставката на куриера (по-долу), наложеният платеж е само стойността на стоката, а фиксираната цена за доставка от поръчката се събира от куриера отделно („споделени разноски“). Иначе наложеният платеж е цялата сума на поръчката, включително доставката.', 'kanelov-shipping' ),
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
					'description' => __( 'Съвпада с EcontSettings::sender_payment_method() по подразбиране.', 'kanelov-shipping' ),
					'desc_tip'    => true,
				],
				'receiver_pays_shipping' => [
					'title'       => __( 'Получателят плаща доставката на куриера', 'kanelov-shipping' ),
					'type'        => 'checkbox',
					'label'       => __( 'Да, фиксираната цена за доставка от поръчката се събира от получателя при предаване, отделно от наложения платеж', 'kanelov-shipping' ),
					'default'     => 'yes',
					'description' => __( 'Пример: стока 19,90 €, доставка в чекаута 2,50 €, цена на Еконт 3,59 €. Куриерът събира 19,90 € наложен платеж и 2,50 € за доставка, а разликата 1,09 € е за ваша сметка по споразумението. Изключете, ако предпочитате доставката да е в наложения платеж.', 'kanelov-shipping' ),
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

				'invoice_num_from_order' => [
					'title'       => __( 'Номер на фактура', 'kanelov-shipping' ),
					'type'        => 'checkbox',
					'label'       => __( 'Подавай номера на поръчката и датата ѝ като фактура (напр. 6333/21.09.2026)', 'kanelov-shipping' ),
					'default'     => 'no',
					'description' => __( 'При споразумение за НП Еконт изисква фактура или опис. Описът по-долу е достатъчен; включете фактурата само ако издавате фактура с номера на поръчката.', 'kanelov-shipping' ),
				],
				'packing_list' => [
					'title'   => __( 'Опис на стоките', 'kanelov-shipping' ),
					'type'    => 'checkbox',
					'label'   => __( 'Към товарителницата се прилага електронен опис с продуктите, броя и цените', 'kanelov-shipping' ),
					'default' => 'yes',
				],

				'services_section' => [
					'title'       => __( 'Услуги при доставка', 'kanelov-shipping' ),
					'type'        => 'title',
					'description' => __( 'Стойности по подразбиране. В кутията „Еконт“ на поръчката могат да се променят за конкретна пратка.', 'kanelov-shipping' ),
				],
				'pay_after' => [
					'title'   => __( 'Преди плащане на НП', 'kanelov-shipping' ),
					'type'    => 'select',
					'default' => '',
					'options' => [
						''       => __( 'Без преглед', 'kanelov-shipping' ),
						'accept' => __( 'Преглед на пратката', 'kanelov-shipping' ),
						'test'   => __( 'Тест на стоката (не се предлага във всеки офис)', 'kanelov-shipping' ),
					],
				],
				'holiday_delivery_day' => [
					'title'       => __( 'Доставка в почивен ден', 'kanelov-shipping' ),
					'type'        => 'select',
					'default'     => 'workday',
					'options'     => [ 'workday' => __( 'В първия работен ден', 'kanelov-shipping' ), 'halfday' => __( 'В събота', 'kanelov-shipping' ) ],
					'description' => __( 'Ако пратката пристигне за почивен ден (напр. изпратена в петък до адрес).', 'kanelov-shipping' ),
					'desc_tip'    => true,
				],
				'holiday_choice_checkout' => [
					'title'   => __( 'Избор на ден в чекаута', 'kanelov-shipping' ),
					'type'    => 'checkbox',
					'label'   => __( 'Клиентът избира събота или първи работен ден при доставка до адрес', 'kanelov-shipping' ),
					'default' => 'yes',
				],
				'instructions' => [
					'title'       => __( 'Инструкции към куриера', 'kanelov-shipping' ),
					'type'        => 'multiselect',
					'default'     => [],
					'options'     => $profile->instruction_choices(),
					'class'       => 'wc-enhanced-select',
					'description' => $profile->instruction_choices() ? __( 'Шаблоните се създават в ee.econt.com и се зареждат с „Обнови профила“.', 'kanelov-shipping' ) : __( 'В профила няма шаблони за инструкции. Създайте ги в ee.econt.com и натиснете „Обнови профила“.', 'kanelov-shipping' ),
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
			$out[''] = __( '(няма заредени офиси; натиснете „Обнови офисите и Еконтоматите“)', 'kanelov-shipping' );
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
				<a class="button" href="<?php echo esc_url( SettingsActions::url( 'sync' ) ); ?>"><?php esc_html_e( 'Обнови офисите и Еконтоматите', 'kanelov-shipping' ); ?></a>
				<p class="description">
					<?php echo esc_html( sprintf( __( 'Профил обновен: %s · Градове: %s · Офиси и Еконтомати: %s. Обновяват се автоматично всяка нощ.', 'kanelov-shipping' ), $profile_time ? wp_date( 'd.m.Y H:i', $profile_time ) : '—', $fmt( (array) ( $sync['cities'] ?? [] ) ), $fmt( (array) ( $sync['offices'] ?? [] ) ) ) ); ?>
					<?php esc_html_e( 'Запазете промените, преди да натиснете бутон.', 'kanelov-shipping' ); ?>
				</p>
			</td>
		</tr>
		<?php
		return (string) ob_get_clean();
	}
}
