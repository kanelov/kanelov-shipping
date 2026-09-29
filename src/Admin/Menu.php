<?php
namespace Kanelov\Shipping\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Меню „Доставки“ в страничната лента: бърз достъп до настройките на куриерите и до поръчките.
 * Самите настройки остават в WooCommerce > Настройки > Доставка > Еконт / Box Now.
 */
final class Menu {

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add' ], 60 );
	}

	public function add(): void {
		$settings = 'admin.php?page=wc-settings&tab=shipping&section=' . \Kanelov\Shipping\Carrier\Econt\EcontShippingMethod::ID;
		$boxnow   = 'admin.php?page=wc-settings&tab=shipping&section=' . \Kanelov\Shipping\Carrier\BoxNow\BoxNowShippingMethod::ID;
		$orders   = 'admin.php?page=wc-orders';
		add_menu_page( __( 'Доставки', 'kanelov-shipping' ), __( 'Доставки', 'kanelov-shipping' ), 'manage_woocommerce', $settings, '', 'dashicons-car', 56.5 );
		add_submenu_page( $settings, __( 'Настройки на Еконт', 'kanelov-shipping' ), __( 'Еконт', 'kanelov-shipping' ), 'manage_woocommerce', $settings );
		add_submenu_page( $settings, __( 'Настройки на Box Now', 'kanelov-shipping' ), __( 'Box Now', 'kanelov-shipping' ), 'manage_woocommerce', $boxnow );
		add_submenu_page( $settings, __( 'Поръчки', 'kanelov-shipping' ), __( 'Поръчки', 'kanelov-shipping' ), 'edit_shop_orders', $orders );
	}
}
