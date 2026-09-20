<?php
namespace Kanelov\Shipping\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Тънка обвивка над WooCommerce логера. Логовете се виждат в WooCommerce > Статус > Логове, източник "kanelov-shipping".
 */
final class Log {

	const SOURCE = 'kanelov-shipping';

	public static function info( string $message, array $context = [] ): void {
		self::write( 'info', $message, $context );
	}

	public static function error( string $message, array $context = [] ): void {
		self::write( 'error', $message, $context );
	}

	public static function debug( string $message, array $context = [] ): void {
		self::write( 'debug', $message, $context );
	}

	private static function write( string $level, string $message, array $context ): void {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		$context['source'] = self::SOURCE;
		wc_get_logger()->log( $level, $message, $context );
	}
}
