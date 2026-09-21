<?php
/**
 * Plugin Name: Kanelov Shipping (Еконт и други куриери)
 * Plugin URI: https://github.com/kanelov
 * Description: Доставка с Еконт (офис, Еконтомат, адрес) за WooCommerce с генериране на товарителници. Подготвен за добавяне на други куриери.
 * Version: 0.4.1
 * Author: Kanelov
 * Text Domain: kanelov-shipping
 * Domain Path: /languages
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * WC requires at least: 9.0
 * WC tested up to: 11.1
 * License: GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

define( 'KS_VERSION', '0.4.1' );
define( 'KS_FILE', __FILE__ );
define( 'KS_DIR', __DIR__ );
define( 'KS_URL', plugin_dir_url( __FILE__ ) );

require_once KS_DIR . '/src/autoload.php';

register_activation_hook( __FILE__, [ \Kanelov\Shipping\Installer::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ \Kanelov\Shipping\Installer::class, 'deactivate' ] );

add_action( 'before_woocommerce_init', static function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, false ); // фаза 3
	}
} );

add_action( 'plugins_loaded', static function () {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', static function () {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Kanelov Shipping изисква активен WooCommerce.', 'kanelov-shipping' ) . '</p></div>';
		} );
		return;
	}
	\Kanelov\Shipping\Plugin::instance()->boot();
}, 20 );
