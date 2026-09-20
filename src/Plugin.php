<?php
namespace Kanelov\Shipping;

use Kanelov\Shipping\Admin\SettingsActions;
use Kanelov\Shipping\Carrier\CarrierRegistry;
use Kanelov\Shipping\Carrier\Econt\EcontCarrier;
use Kanelov\Shipping\Carrier\Econt\EcontNomenclature;
use Kanelov\Shipping\Carrier\Econt\EcontShippingMethod;
use Kanelov\Shipping\Checkout\ClassicCheckout;
use Kanelov\Shipping\Rest\EcontSearchController;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	private static ?Plugin $instance = null;

	private CarrierRegistry $carriers;

	public static function instance(): Plugin {
		return self::$instance ??= new self();
	}

	private function __construct() {
		$this->carriers = new CarrierRegistry();
	}

	public function carriers(): CarrierRegistry {
		return $this->carriers;
	}

	public function boot(): void {
		load_plugin_textdomain( 'kanelov-shipping', false, dirname( plugin_basename( KS_FILE ) ) . '/languages' );

		Installer::maybe_upgrade();

		$this->carriers->register( new EcontCarrier() );

		add_filter( 'woocommerce_shipping_methods', static function ( array $methods ): array {
			$methods[ EcontShippingMethod::ID ] = EcontShippingMethod::class;
			return $methods;
		} );

		( new EcontNomenclature() )->register_jobs();
		( new EcontSearchController() )->register();
		( new ClassicCheckout() )->register();

		if ( is_admin() ) {
			( new SettingsActions() )->register();
		}
	}
}
