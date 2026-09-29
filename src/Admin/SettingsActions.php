<?php
namespace Kanelov\Shipping\Admin;

use Kanelov\Shipping\Carrier\BoxNow\BoxNowApiException;
use Kanelov\Shipping\Carrier\BoxNow\BoxNowCarrier;
use Kanelov\Shipping\Carrier\BoxNow\BoxNowLockers;
use Kanelov\Shipping\Carrier\BoxNow\BoxNowSettings;
use Kanelov\Shipping\Carrier\BoxNow\BoxNowShippingMethod;
use Kanelov\Shipping\Carrier\Econt\EcontApiException;
use Kanelov\Shipping\Carrier\Econt\EcontNomenclature;
use Kanelov\Shipping\Carrier\Econt\EcontProfile;
use Kanelov\Shipping\Carrier\Econt\EcontSettings;
use Kanelov\Shipping\Carrier\Econt\EcontShippingMethod;

defined( 'ABSPATH' ) || exit;

/**
 * Бутоните в настройките: тест на връзката, обновяване на профила, обновяване на офисите и Еконтоматите (Еконт),
 * тест на връзката и обновяване на автоматите (Box Now, действия с префикс bn_). Работят през admin-post с nonce.
 */
final class SettingsActions {

	const ACTION = 'ks_econt_action';

	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, [ $this, 'handle' ] );
		add_action( 'admin_notices', [ $this, 'notices' ] );
	}

	public static function url( string $what ): string {
		return wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION . '&what=' . $what ), self::ACTION );
	}

	public static function settings_url( string $section = EcontShippingMethod::ID ): string {
		return admin_url( 'admin.php?page=wc-settings&tab=shipping&section=' . $section );
	}

	public function handle(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Нямате права.', 'kanelov-shipping' ) );
		}
		check_admin_referer( self::ACTION );

		$what     = sanitize_key( $_GET['what'] ?? '' );
		$settings = new EcontSettings();
		$notice   = [ 'type' => 'success', 'text' => '' ];

		try {
			switch ( $what ) {
				case 'test':
					$profile = ( new EcontProfile( $settings ) )->refresh();
					$name    = (string) ( $profile['profiles'][0]['client']['name'] ?? '' );
					$notice['text'] = sprintf( __( 'Връзката с Еконт е успешна. Профил: %s. Данните на профила са обновени.', 'kanelov-shipping' ), $name );
					break;
				case 'profile':
					( new EcontProfile( $settings ) )->refresh();
					$notice['text'] = __( 'Профилът, адресите и споразуменията са обновени от Еконт.', 'kanelov-shipping' );
					break;
				case 'sync':
					( new EcontNomenclature() )->sync_now();
					$notice['text'] = __( 'Синхронизацията на градове и офиси е стартирана във фонов режим. Обновете страницата след минута.', 'kanelov-shipping' );
					break;
				case 'bn_test':
					$profile = ( new BoxNowCarrier() )->refresh_profile();
					$origins = ( new BoxNowSettings() )->origin_choices();
					$perm    = (array) ( $profile['permissions'] ?? [] );
					$notice['text'] = sprintf(
						__( 'Връзката с Box Now е успешна. Складове: %1$s. Наложен платеж: %2$s.', 'kanelov-shipping' ),
						$origins ? implode( '; ', $origins ) : __( 'няма', 'kanelov-shipping' ),
						$perm ? ( ! empty( $perm['codPayment'] ) ? __( 'разрешен', 'kanelov-shipping' ) : __( 'НЕ е разрешен (пишете на Box Now)', 'kanelov-shipping' ) ) : __( 'неизвестно', 'kanelov-shipping' )
					);
					if ( empty( ( new BoxNowLockers() )->last_sync()['count'] ) ) {
						$count = ( new BoxNowLockers() )->sync();
						$notice['text'] .= ' ' . sprintf( __( 'Заредени автомати: %d.', 'kanelov-shipping' ), $count );
					}
					break;
				case 'bn_sync':
					$count = ( new BoxNowLockers() )->sync();
					$notice['text'] = sprintf( __( 'Автоматите на Box Now са обновени: %d.', 'kanelov-shipping' ), $count );
					break;
				default:
					$notice = [ 'type' => 'error', 'text' => __( 'Непознато действие.', 'kanelov-shipping' ) ];
			}
		} catch ( EcontApiException $e ) {
			$notice = [ 'type' => 'error', 'text' => __( 'Грешка от Еконт: ', 'kanelov-shipping' ) . implode( '; ', $e->messages() ) ];
		} catch ( BoxNowApiException $e ) {
			$notice = [ 'type' => 'error', 'text' => __( 'Грешка от Box Now: ', 'kanelov-shipping' ) . implode( '; ', $e->messages() ) ];
		}

		set_transient( 'ks_admin_notice_' . get_current_user_id(), $notice, 60 );
		wp_safe_redirect( self::settings_url( str_starts_with( $what, 'bn_' ) ? BoxNowShippingMethod::ID : EcontShippingMethod::ID ) );
		exit;
	}

	public function notices(): void {
		$key    = 'ks_admin_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) || empty( $notice['text'] ) ) {
			return;
		}
		delete_transient( $key );
		printf(
			'<div class="notice notice-%s is-dismissible"><p><strong>Kanelov Shipping:</strong> %s</p></div>',
			esc_attr( $notice['type'] ),
			esc_html( $notice['text'] )
		);
	}
}
