<?php
use Kanelov\Shipping\Carrier\DeliveryData;
use PHPUnit\Framework\TestCase;

final class DeliveryDataTest extends TestCase {

	public function test_from_array_casts_and_trims(): void {
		$d = DeliveryData::from_array( [ 'type' => 'office', 'city_id' => '41', 'office_code' => ' 4000 ', 'unknown' => 'x' ] );
		$this->assertSame( 41, $d->city_id );
		$this->assertSame( '4000', $d->office_code );
		$this->assertTrue( $d->is_to_office() );
		$this->assertFalse( $d->is_empty() );
	}

	public function test_invalid_type_is_reset(): void {
		$d = DeliveryData::from_array( [ 'type' => 'drone', 'city_id' => 1 ] );
		$this->assertSame( '', $d->type );
		$this->assertTrue( $d->is_empty() );
	}

	public function test_round_trip(): void {
		$in = [ 'carrier' => 'econt', 'type' => 'door', 'city_id' => 1, 'street' => 'ул. А', 'street_num' => '2' ];
		$d  = DeliveryData::from_array( $in );
		$this->assertSame( $in['street'], DeliveryData::from_array( $d->to_array() )->street );
	}
}
