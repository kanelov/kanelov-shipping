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
		// Кой прекъсва заявката: редирект или wp_die с кратка следа на стека.
		add_filter( 'wp_redirect', static function ( $location ) {
			self::write( 'REDIRECT | ' . (string) ( $_SERVER['REQUEST_URI'] ?? '' ) . ' | to ' . (string) $location . ' | ' . self::backtrace() );
			return $location;
		}, 1 );
		add_filter( 'wp_die_handler', static function ( $handler ) {
			self::write( 'WP_DIE | ' . (string) ( $_SERVER['REQUEST_URI'] ?? '' ) . ' | ' . self::backtrace() );
			return $handler;
		}, 1 );
		// Кои функции са закачени на admin_enqueue_scripts и докъде стигат (по приоритети).
		add_action( 'admin_enqueue_scripts', static function () {
			global $wp_filter;
			$list = [];
			if ( isset( $wp_filter['admin_enqueue_scripts'] ) ) {
				foreach ( $wp_filter['admin_enqueue_scripts']->callbacks as $prio => $cbs ) {
					foreach ( $cbs as $cb ) {
						$list[] = $prio . ':' . self::callback_name( $cb['function'] );
					}
				}
			}
			self::write( 'ENQUEUE CALLBACKS | ' . implode( ', ', $list ) );
			// Обвива всяка функция на приоритети 10..20: записва влизане/излизане и погълнати изключения.
			if ( isset( $wp_filter['admin_enqueue_scripts'] ) ) {
				foreach ( $wp_filter['admin_enqueue_scripts']->callbacks as $prio => $cbs ) {
					if ( $prio < 10 || $prio > 20 ) {
						continue;
					}
					foreach ( $cbs as $key => $cb ) {
						$fn   = $cb['function'];
						$name = $prio . ':' . self::callback_name( $fn );
						$wp_filter['admin_enqueue_scripts']->callbacks[ $prio ][ $key ]['function'] = static function ( ...$args ) use ( $fn, $name ) {
							self::trace( 'enter ' . $name );
							try {
								$r = $fn( ...$args );
							} catch ( \Throwable $t ) {
								self::write( 'THROWN in ' . $name . ' | ' . get_class( $t ) . ': ' . $t->getMessage() . ' | ' . basename( $t->getFile() ) . ':' . $t->getLine() );
								throw $t;
							}
							self::trace( 'leave ' . $name );
							return $r;
						};
					}
				}
			}
		}, -9998 );
		foreach ( [ 0, 5, 9, 10, 11, 15, 20, 30, 50, 100, 999 ] as $prio ) {
			add_action( 'admin_enqueue_scripts', static fn() => self::trace( 'admin_enqueue_scripts after prio ' . $prio ), $prio );
		}
		self::trace( 'plugins_loaded (boot)' );
	}

	private static function callback_name( $fn ): string {
		if ( is_string( $fn ) ) {
			return $fn;
		}
		if ( is_array( $fn ) ) {
			return ( is_object( $fn[0] ) ? get_class( $fn[0] ) : (string) $fn[0] ) . '::' . (string) $fn[1];
		}
		return 'closure';
	}

	private static function backtrace(): string {
		$out = [];
		foreach ( array_slice( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 14 ), 2, 12 ) as $f ) {
			$out[] = basename( (string) ( $f['file'] ?? '' ) ) . ':' . (string) ( $f['line'] ?? '' ) . ' ' . (string) ( $f['function'] ?? '' );
		}
		return implode( ' < ', $out );
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
