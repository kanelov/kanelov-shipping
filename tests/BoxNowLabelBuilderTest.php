<?php
use Kanelov\Shipping\Carrier\BoxNow\BoxNowApiException;
use Kanelov\Shipping\Carrier\BoxNow\BoxNowLabelBuilder;
use PHPUnit\Framework\TestCase;

final class BoxNowLabelBuilderTest extends TestCase {

	private function base_input( array $over = [] ): array {
		return array_replace_recursive( [
			'origin'       => [ 'location_id' => '2', 'name' => 'Арт Картини', 'phone' => '0888000000', 'email' => 'shop@example.com' ],
			'receiver'     => [ 'name' => 'Мария Иванова', 'phone' => '+359 887 123 456', 'email' => 'maria@example.com' ],
			'locker_id'    => '1234',
			'order_number' => '6340',
			'items'        => [
				[ 'name' => 'Картина „Море“ 60x90', 'qty' => 1, 'weight' => 1.2, 'price' => 89.0 ],
				[ 'name' => 'Постер', 'qty' => 2, 'weight' => null, 'price' => 20.0 ],
			],
			'order_total'  => 111.5,
			'is_cod'       => true,
			'options'      => [ 'weight' => 0, 'compartment' => 0, 'dimensions' => [ 40, 30, 5 ], 'allow_return' => true, 'notify_email' => '', 'description' => '' ],
			'defaults'     => [ 'default_weight' => 0.5, 'default_compartment' => 2, 'description_max_length' => 100 ],
		], $over );
	}

	public function test_cod_request_has_amount_origin_destination_and_item(): void {
		$body = ( new BoxNowLabelBuilder() )->build( $this->base_input() );

		$this->assertSame( '6340', $body['orderNumber'] );
		$this->assertSame( 'cod', $body['paymentMode'] );
		$this->assertSame( '111.50', $body['amountToBeCollected'] );
		$this->assertSame( '111.50', $body['invoiceValue'] );
		$this->assertTrue( $body['allowReturn'] );
		$this->assertSame( '2', $body['origin']['locationId'] );
		$this->assertSame( '+359 88 800 0000', $body['origin']['contactNumber'] );
		$this->assertSame( '1234', $body['destination']['locationId'] );
		$this->assertSame( '+359 88 712 3456', $body['destination']['contactNumber'] );
		$this->assertSame( 'Мария Иванова', $body['destination']['contactName'] );
		$this->assertCount( 1, $body['items'] );
		$item = $body['items'][0];
		$this->assertSame( '6340-1', $item['id'] );
		$this->assertSame( '109.00', $item['value'] );
		$this->assertSame( 2.2, $item['weight'] ); // 1.2 + 2 × 0.5 по подразбиране
		$this->assertSame( 1, $item['compartmentSize'] ); // 40×30×5 се побира в малкото отделение
		$this->assertStringStartsWith( 'Поръчка 6340: Картина „Море“ 60x90, Постер x2', $item['name'] );
		$this->assertArrayNotHasKey( 'notifyOnAccepted', $body );
	}

	public function test_prepaid_has_zero_cod_and_notify_email(): void {
		$body = ( new BoxNowLabelBuilder() )->build( $this->base_input( [ 'is_cod' => false, 'options' => [ 'notify_email' => 'labels@example.com', 'compartment' => 3, 'weight' => 4 ] ] ) );

		$this->assertSame( 'prepaid', $body['paymentMode'] );
		$this->assertSame( '0.00', $body['amountToBeCollected'] );
		$this->assertSame( 'labels@example.com', $body['notifyOnAccepted'] );
		$this->assertSame( 3, $body['items'][0]['compartmentSize'] );
		$this->assertSame( 4.0, $body['items'][0]['weight'] );
	}

	public function test_default_compartment_without_dimensions_and_error_when_too_big(): void {
		$body = ( new BoxNowLabelBuilder() )->build( $this->base_input( [ 'options' => [ 'dimensions' => null ] ] ) );
		$this->assertSame( 2, $body['items'][0]['compartmentSize'] );

		$this->expectException( BoxNowApiException::class );
		( new BoxNowLabelBuilder() )->build( $this->base_input( [ 'options' => [ 'dimensions' => [ 70, 50, 40 ] ] ] ) );
	}

	public function test_cod_above_limit_is_rejected(): void {
		$this->expectException( BoxNowApiException::class );
		( new BoxNowLabelBuilder() )->build( $this->base_input( [ 'order_total' => 5001 ] ) );
	}

	public function test_compartment_for_rotates_the_parcel(): void {
		$this->assertSame( 1, BoxNowLabelBuilder::compartment_for( [ 8, 60, 45 ] ) );
		$this->assertSame( 2, BoxNowLabelBuilder::compartment_for( [ 45, 60, 17 ] ) );
		$this->assertSame( 3, BoxNowLabelBuilder::compartment_for( [ 30, 30, 30 ] ) );
		$this->assertSame( 0, BoxNowLabelBuilder::compartment_for( [ 61, 10, 10 ] ) );
		$this->assertSame( 0, BoxNowLabelBuilder::compartment_for( [ 10, 10 ] ) );
	}

	public function test_phone_normalisation(): void {
		$this->assertSame( '+359 88 812 3456', BoxNowLabelBuilder::phone( '0888123456' ) );
		$this->assertSame( '+359 88 812 3456', BoxNowLabelBuilder::phone( '+359 888 123 456' ) );
		$this->assertSame( '+359 88 812 3456', BoxNowLabelBuilder::phone( '00359888123456' ) );
		$this->assertSame( '+359 88 812 3456', BoxNowLabelBuilder::phone( '359 888-123-456' ) );
		$this->assertSame( '+359 88 812 3456', BoxNowLabelBuilder::phone( '888123456' ) );
		$this->assertSame( '+4917612345678', BoxNowLabelBuilder::phone( '+49 176 12345678' ) );
		$this->assertSame( '', BoxNowLabelBuilder::phone( '' ) );
	}
}
