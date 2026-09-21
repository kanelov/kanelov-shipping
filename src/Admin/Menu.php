<?php
namespace Kanelov\Shipping\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Меню „Еконт“ в страничната лента: бърз достъп до настройките на плъгина и до поръчките.
 * Самите настройки остават в WooCommerce > Настройки > Доставка > Еконт.
 */
final class Menu {

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add' ], 60 );
	}

	public function add(): void {
		$settings = 'admin.php?page=wc-settings&tab=shipping&section=' . \Kanelov\Shipping\Carrier\Econt\EcontShippingMethod::ID;
		$orders   = 'admin.php?page=wc-orders';
		add_menu_page( __( 'Еконт', 'kanelov-shipping' ), __( 'Еконт', 'kanelov-shipping' ), 'manage_woocommerce', $settings, '', 'dashicons-car', 56.5 );
		add_submenu_page( $settings, __( 'Настройки на Еконт', 'kanelov-shipping' ), __( 'Настройки', 'kanelov-shipping' ), 'manage_woocommerce', $settings );
		add_submenu_page( $settings, __( 'Поръчки', 'kanelov-shipping' ), __( 'Поръчки', 'kanelov-shipping' ), 'edit_shop_orders', $orders );
	}
}
