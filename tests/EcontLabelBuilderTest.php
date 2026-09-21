<?php
use Kanelov\Shipping\Carrier\DeliveryData;
use Kanelov\Shipping\Carrier\Econt\EcontLabelBuilder;
use PHPUnit\Framework\TestCase;

final class EcontLabelBuilderTest extends TestCase {

	private function base_input( array $over = [] ): array {
		return array_replace_recursive( [
			'sender'       => [
				'client'      => [ 'name' => 'Арт Картини ЕООД', 'phones' => [ '0888000000' ] ],
				'agent_name'  => 'Иван Кънев',
				'agent_phone' => '0888000000',
				'office_code' => '4004',
			],
			'receiver'     => [ 'name' => 'Мария Иванова', 'phone' => '+359 887 123 456', 'email' => 'maria@example.com' ],
			'delivery'     => DeliveryData::from_array( [ 'carrier' => 'econt', 'type' => 'office', 'city_id' => 41, 'city_name' => 'Пловдив', 'office_code' => '4000' ] ),
			'items'        => [
				[ 'name' => 'Картина „Море“ 60x90', 'qty' => 1, 'weight' => 1.2, 'price' => 89.0 ],
				[ 'name' => 'Постер', 'qty' => 2, 'weight' => null, 'price' => 20.0 ],
			],
			'order_number' => '1523',
			'order_total'  => 131.5,
			'currency'     => 'EUR',
			'is_cod'       => true,
			'options'      => [
				'sms_notification'        => true,
				'cd_pay_options_template' => 'CD123',
				'sender_payment_method'   => 'credit',
			],
			'defaults'     => [ 'default_weight' => 0.5, 'min_weight' => 0.1, 'description_mode' => 'both', 'description_max_length' => 100 ],
		], $over );
	}

	public function test_office_label_has_office_code_and_cod(): void {
		$label = ( new EcontLabelBuilder() )->build( $this->base_input() );

		$this->assertSame( '4004', $label['senderOfficeCode'] );
		$this->assertArrayNotHasKey( 'senderAddress', $label );
		$this->assertSame( '4000', $label['receiverOfficeCode'] );
		$this->assertArrayNotHasKey( 'receiverAddress', $label );
		$this->assertSame( [ '0887123456' ], $label['receiverClient']['phones'] );
		$this->assertSame( 'maria@example.com', $label['receiverClient']['email'] );
		$this->assertSame( 'get', $label['services']['cdType'] );
		$this->assertSame( 131.5, $label['services']['cdAmount'] );
		$this->assertSame( 'EUR', $label['services']['cdCurrency'] );
		$this->assertSame( 'CD123', $label['services']['cdPayOptionsTemplate'] );
		$this->assertTrue( $label['services']['smsNotification'] );
		$this->assertSame( 'credit', $label['paymentSenderMethod'] );
		$this->assertArrayNotHasKey( 'paymentReceiverMethod', $label );
		$this->assertSame( 'pack', $label['shipmentType'] );
		$this->assertSame( 1, $label['packCount'] );
	}

	public function test_weight_sums_items_and_uses_default_for_missing(): void {
		$label = ( new EcontLabelBuilder() )->build( $this->base_input() );
		// 1.2 + 2 * 0.5
		$this->assertSame( 2.2, $label['weight'] );
	}

	public function test_weight_override_and_min_weight(): void {
		$label = ( new EcontLabelBuilder() )->build( $this->base_input( [ 'options' => [ 'weight' => 0.02 ] ] ) );
		$this->assertSame( 0.1, $label['weight'] );

		$label = ( new EcontLabelBuilder() )->build( $this->base_input( [ 'options' => [ 'weight' => 3.456 ] ] ) );
		$this->assertSame( 3.456, $label['weight'] );
	}

	public function test_description_contains_order_number_and_products(): void {
		$label = ( new EcontLabelBuilder() )->build( $this->base_input() );
		$this->assertSame( '#1523 Картина „Море“ 60x90, Постер x2', $label['shipmentDescription'] );
		$this->assertSame( '1523', $label['orderNumber'] );
	}

	public function test_description_is_truncated_on_word_boundary(): void {
		$items = [];
		for ( $i = 1; $i <= 20; $i++ ) {
			$items[] = [ 'name' => "Продукт номер {$i}", 'qty' => 1, 'weight' => 0.2, 'price' => 1 ];
		}
		$label = ( new EcontLabelBuilder() )->build( $this->base_input( [ 'items' => $items, 'defaults' => [ 'description_max_length' => 60 ] ] ) );
		$this->assertLessThanOrEqual( 60, mb_strlen( $label['shipmentDescription'] ) );
		$this->assertStringEndsWith( '…', $label['shipmentDescription'] );
		$this->assertStringStartsWith( '#1523 Продукт номер 1', $label['shipmentDescription'] );
	}

	public function test_description_modes(): void {
		$label = ( new EcontLabelBuilder() )->build( $this->base_input( [ 'defaults' => [ 'description_mode' => 'order_number' ] ] ) );
		$this->assertSame( '#1523', $label['shipmentDescription'] );
		$label = ( new EcontLabelBuilder() )->build( $this->base_input( [ 'defaults' => [ 'description_mode' => 'products' ] ] ) );
		$this->assertSame( 'Картина „Море“ 60x90, Постер x2', $label['shipmentDescription'] );
		$label = ( new EcontLabelBuilder() )->build( $this->base_input( [ 'options' => [ 'description' => '<b>Ръчно</b> описание' ] ] ) );
		$this->assertSame( 'Ръчно описание', $label['shipmentDescription'] );
	}

	public function test_door_delivery_builds_receiver_address(): void {
		$delivery = DeliveryData::from_array( [
			'carrier' => 'econt', 'type' => 'door', 'city_id' => 41, 'city_name' => 'Пловдив', 'post_code' => '4000',
			'street' => 'бул. Марица', 'street_num' => '12', 'block' => '5', 'entrance' => 'Б', 'floor' => '3', 'apartment' => '9', 'note' => 'звънец 9',
		] );
		$label = ( new EcontLabelBuilder() )->build( $this->base_input( [ 'delivery' => $delivery, 'city' => [ 'id' => 41, 'name' => 'Пловдив', 'post_code' => '4000' ] ] ) );

		$this->assertArrayNotHasKey( 'receiverOfficeCode', $label );
		$addr = $label['receiverAddress'];
		$this->assertSame( 41, $addr['city']['id'] );
		$this->assertSame( 'BGR', $addr['city']['country']['code3'] );
		$this->assertSame( '4000', $addr['city']['postCode'] );
		$this->assertSame( 'бул. Марица', $addr['street'] );
		$this->assertSame( '12', $addr['num'] );
		$this->assertSame( 'бл. 5, вх. Б, ет. 3, ап. 9, звънец 9', $addr['other'] );
	}

	public function test_non_cod_order_has_no_cd_service_and_locker_skips_declared_value(): void {
		$delivery = DeliveryData::from_array( [ 'carrier' => 'econt', 'type' => 'locker', 'city_id' => 41, 'office_code' => '4000-APS' ] );
		$label    = ( new EcontLabelBuilder() )->build( $this->base_input( [
			'is_cod'   => false,
			'delivery' => $delivery,
			'options'  => [ 'declared_value' => 131.5, 'sms_notification' => false ],
		] ) );
		$this->assertArrayNotHasKey( 'cdType', $label['services'] ?? [] );
		$this->assertArrayNotHasKey( 'declaredValueAmount', $label['services'] ?? [] );
		$this->assertSame( '4000-APS', $label['receiverOfficeCode'] );
	}

	public function test_receiver_pays_shipping_moves_shipping_out_of_cod(): void {
		$label = ( new EcontLabelBuilder() )->build( $this->base_input( [ 'options' => [ 'receiver_pays_shipping' => true, 'receiver_amount' => 3.5 ] ] ) );
		$this->assertSame( 'cash', $label['paymentReceiverMethod'] );
		$this->assertSame( 3.5, $label['paymentReceiverAmount'] );
		// 131.50 общо, от които 3.50 доставка се събира отделно: НП = 128.00, не 131.50.
		$this->assertSame( 128.0, $label['services']['cdAmount'] );
	}

	public function test_free_shipping_with_receiver_pays_option_sends_no_receiver_method(): void {
		$label = ( new EcontLabelBuilder() )->build( $this->base_input( [ 'options' => [ 'receiver_pays_shipping' => true, 'receiver_amount' => 0 ] ] ) );
		$this->assertArrayNotHasKey( 'paymentReceiverMethod', $label );
		$this->assertArrayNotHasKey( 'paymentReceiverAmount', $label );
		$this->assertSame( 131.5, $label['services']['cdAmount'] );
	}

	public function test_sender_address_used_when_no_office(): void {
		$addr  = [ 'city' => [ 'id' => 41, 'name' => 'Пловдив' ], 'street' => 'ул. Тест', 'num' => '1' ];
		$label = ( new EcontLabelBuilder() )->build( $this->base_input( [ 'sender' => [ 'office_code' => '', 'address' => $addr ] ] ) );
		$this->assertArrayNotHasKey( 'senderOfficeCode', $label );
		$this->assertSame( $addr, $label['senderAddress'] );
	}

	public function test_packing_list_invoice_and_services(): void {
		$label = ( new EcontLabelBuilder() )->build( $this->base_input( [ 'options' => [
			'packing_list'         => true,
			'invoice_num'          => '1523',
			'pay_after'            => 'accept',
			'holiday_delivery_day' => 'halfday',
			'instructions'         => [ [ 'id' => 704594, 'type' => 'return' ], [ 'id' => 1, 'type' => 'bogus' ], [ 'id' => 0, 'type' => 'take' ] ],
		] ] ) );

		$this->assertSame( 'digital', $label['packingListType'] );
		$this->assertCount( 3, $label['packingList'], 'два артикула + ред за разликата до наложения платеж' );
		$this->assertSame( [ 'inventoryNum' => '1', 'description' => 'Картина „Море“ 60x90', 'weight' => 1.2, 'count' => 1, 'price' => 89.0 ], $label['packingList'][0] );
		$this->assertSame( 2, $label['packingList'][1]['count'] );
		$this->assertSame( 1.0, $label['packingList'][1]['weight'], 'две бройки по тегло по подразбиране 0.5' );
		$this->assertSame( 10.0, $label['packingList'][1]['price'], 'цена за брой' );
		$this->assertSame( '1523', $label['services']['invoiceNum'] );
		$this->assertTrue( $label['payAfterAccept'] );
		$this->assertArrayNotHasKey( 'payAfterTest', $label );
		$this->assertSame( 'halfday', $label['holidayDeliveryDay'] );
		$this->assertSame( [ [ 'id' => 704594, 'type' => 'return' ] ], $label['instructions'], 'невалиден тип и id 0 отпадат' );
	}

	public function test_packing_list_gets_a_delivery_row_so_it_matches_cod(): void {
		// Артикули 89 + 20 (ред от 2 бр.) = 109; наложен платеж 111.50 → ред „Доставка“ 2.50.
		$label = ( new EcontLabelBuilder() )->build( $this->base_input( [ 'order_total' => 111.5, 'options' => [ 'packing_list' => true ] ] ) );

		$rows = $label['packingList'];
		$this->assertCount( 3, $rows );
		$this->assertSame( 'Доставка', $rows[2]['description'] );
		$this->assertSame( 2.5, $rows[2]['price'] );
		$sum = array_sum( array_map( static fn( $r ) => $r['price'] * $r['count'], $rows ) );
		$this->assertEqualsWithDelta( $label['services']['cdAmount'], $sum, 0.001 );
	}

	public function test_packing_list_absorbs_order_level_discount(): void {
		// Артикули 109, наложен платеж 100: отстъпка 9 → последният ред (2×10) се разделя и една бройка поема разликата.
		$label = ( new EcontLabelBuilder() )->build( $this->base_input( [ 'order_total' => 100.0, 'options' => [ 'packing_list' => true ] ] ) );

		$rows = $label['packingList'];
		$this->assertCount( 3, $rows );
		$this->assertSame( 1, $rows[1]['count'] );
		$this->assertSame( 1.0, $rows[2]['price'] );
		$sum = array_sum( array_map( static fn( $r ) => $r['price'] * $r['count'], $rows ) );
		$this->assertEqualsWithDelta( 100.0, $sum, 0.001 );
	}

	public function test_packing_list_is_not_balanced_without_cod(): void {
		$label = ( new EcontLabelBuilder() )->build( $this->base_input( [ 'is_cod' => false, 'options' => [ 'packing_list' => true ] ] ) );

		$this->assertCount( 2, $label['packingList'] );
	}

	public function test_defaults_have_no_packing_list_or_instructions(): void {
		$label = ( new EcontLabelBuilder() )->build( $this->base_input() );

		$this->assertArrayNotHasKey( 'packingList', $label );
		$this->assertArrayNotHasKey( 'instructions', $label );
		$this->assertArrayNotHasKey( 'payAfterAccept', $label );
		$this->assertSame( 'workday', $label['holidayDeliveryDay'] );
	}

	public function test_phone_normalization(): void {
		$this->assertSame( '0887123456', EcontLabelBuilder::normalize_phone( '+359 887 123 456' ) );
		$this->assertSame( '0887123456', EcontLabelBuilder::normalize_phone( '00359887123456' ) );
		$this->assertSame( '0887123456', EcontLabelBuilder::normalize_phone( '0887-123-456' ) );
	}
}
