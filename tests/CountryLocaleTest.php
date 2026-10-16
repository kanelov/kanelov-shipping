<?php
use Kanelov\Shipping\Checkout\ClassicCheckout;
use PHPUnit\Framework\TestCase;

/** Локалът за BG не бива да прави скритите адресни полета задължителни (иначе WooPayments не създава плащането). */
final class CountryLocaleTest extends TestCase {

	public function test_bg_address_fields_become_optional(): void {
		$locale = ClassicCheckout::relax_locale( [
			'BG' => [
				'address_1' => [ 'required' => true, 'priority' => 50 ],
				'city'      => [ 'required' => true ],
				'postcode'  => [ 'required' => true, 'label' => 'Пощенски код' ],
			],
		] );
		foreach ( ClassicCheckout::LOCALE_FIELDS as $key ) {
			$this->assertFalse( $locale['BG'][ $key ]['required'], $key );
		}
		$this->assertSame( 50, $locale['BG']['address_1']['priority'] );
		$this->assertSame( 'Пощенски код', $locale['BG']['postcode']['label'] );
	}

	public function test_other_countries_untouched(): void {
		$ro     = [ 'city' => [ 'required' => true ] ];
		$locale = ClassicCheckout::relax_locale( [ 'RO' => $ro ] );
		$this->assertSame( $ro, $locale['RO'] );
	}
}
