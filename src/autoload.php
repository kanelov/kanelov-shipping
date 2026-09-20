<?php
/**
 * Minimal PSR-4 autoloader for the Kanelov\Shipping namespace (no Composer needed in production).
 */

defined( 'ABSPATH' ) || exit;

spl_autoload_register( static function ( string $class ): void {
	$prefix = 'Kanelov\\Shipping\\';
	if ( strncmp( $class, $prefix, strlen( $prefix ) ) !== 0 ) {
		return;
	}
	$relative = substr( $class, strlen( $prefix ) );
	$file     = __DIR__ . '/' . str_replace( '\\', '/', $relative ) . '.php';
	if ( is_file( $file ) ) {
		require_once $file;
	}
} );
