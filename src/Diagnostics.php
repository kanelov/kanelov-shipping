<?php
namespace Kanelov\Shipping;

defined( 'ABSPATH' ) || exit;

/**
 * Временна диагностика: записва фатални грешки и следа по ключовите стъпки на админ заявките
 * в wp-content/uploads/kanelov-shipping-diag.log (само за влезли потребители в wp-admin).
 * Без тайни данни в лога. Изключва се с константа KS_DIAG = false в wp-config.php.
 */
final class Diagnostics {

	private static float $start = 0.0;
	private static string $file = '';

	public static function register(): void {
		if ( defined( 'KS_DIAG' ) && ! KS_DIAG ) {
			return;
		}
		self::$start = microtime( true );
		$uploads     = wp_get_upload_dir();
		self::$file  = trailingslashit( (string) ( $uploads['basedir'] ?? WP_CONTENT_DIR ) ) . 'kanelov-shipping-diag.log';

		register_shutdown_function( [ self::class, 'on_shutdown' ] );

		if ( ! is_admin() || wp_doing_ajax() ) {
			return;
		}
		foreach ( [ 'init', 'admin_init', 'admin_menu', 'current_screen', 'admin_enqueue_scripts', 'admin_print_styles', 'admin_print_scripts', 'admin_head', 'in_admin_header', 'admin_notices', 'admin_footer' ] as $hook ) {
			add_action( $hook, static fn() => self::trace( $hook . ' start' ), -9999 );
			add_action( $hook, static fn() => self::trace( $hook . ' end' ), 99999 );
		}
		self::trace( 'plugins_loaded (boot)' );
	}

	public static function trace( string $point ): void {
		if ( $point !== 'plugins_loaded (boot)' && ! is_user_logged_in() ) { // без wp_get_current_user() преди init
			return;
		}
		self::write( sprintf( '%s | %s | %d ms | %.1f MB', (string) ( $_SERVER['REQUEST_URI'] ?? '' ), $point, (int) ( ( microtime( true ) - self::$start ) * 1000 ), memory_get_peak_usage( true ) / 1048576 ) );
	}

	public static function on_shutdown(): void {
		$e = error_get_last();
		if ( $e && in_array( $e['type'], [ E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_RECOVERABLE_ERROR ], true ) ) {
			self::write( sprintf( 'FATAL | %s | %s | %s:%d | %.1f MB', (string) ( $_SERVER['REQUEST_URI'] ?? '' ), $e['message'], $e['file'], $e['line'], memory_get_peak_usage( true ) / 1048576 ) );
		} elseif ( is_admin() && ! wp_doing_ajax() && is_user_logged_in() ) {
			self::trace( 'shutdown ok' );
		}
	}

	private static function write( string $line ): void {
		if ( self::$file === '' ) {
			return;
		}
		if ( file_exists( self::$file ) && filesize( self::$file ) > 300000 ) {
			@unlink( self::$file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		@file_put_contents( self::$file, gmdate( 'Y-m-d H:i:s' ) . ' | ' . $line . "\n", FILE_APPEND | LOCK_EX ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}
}
