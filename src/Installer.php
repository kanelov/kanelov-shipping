<?php
namespace Kanelov\Shipping;

use Kanelov\Shipping\Carrier\Econt\EcontNomenclature;
use Kanelov\Shipping\Carrier\Econt\EcontSettings;

defined( 'ABSPATH' ) || exit;

final class Installer {

	const DB_VERSION_OPTION = 'ks_db_version';
	const DB_VERSION        = '1';

	public static function activate(): void {
		self::create_tables();
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
		// Първа синхронизация на номенклатурите малко след активиране (ако има данни за достъп).
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + 60, EcontNomenclature::JOB_SYNC, [], EcontNomenclature::AS_GROUP );
		}
	}

	public static function deactivate(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( EcontNomenclature::JOB_SYNC, [], EcontNomenclature::AS_GROUP );
			as_unschedule_all_actions( EcontNomenclature::JOB_SYNC_CITIES, [], EcontNomenclature::AS_GROUP );
			as_unschedule_all_actions( EcontNomenclature::JOB_SYNC_OFFICES, [], EcontNomenclature::AS_GROUP );
		}
	}

	const VERSION_OPTION = 'ks_version';

	public static function maybe_upgrade(): void {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::create_tables();
			update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
		}
		// Нова версия на плъгина: офисите и Еконтоматите се обновяват сами (напр. нови полета като координати).
		$installed = (string) get_option( self::VERSION_OPTION, '' );
		if ( $installed !== KS_VERSION ) {
			update_option( self::VERSION_OPTION, KS_VERSION );
			self::migrate( $installed );
			if ( ( ! defined( 'KS_DIAG' ) || ! KS_DIAG ) && file_exists( Diagnostics::log_path() ) ) {
				@unlink( Diagnostics::log_path() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- диагностичният лог не е нужен без KS_DIAG
			}
			// Action Scheduler приема задачи чак след init; на plugins_loaded извикването се игнорира.
			add_action( 'init', static function () {
				if ( function_exists( 'as_enqueue_async_action' ) ) {
					( new EcontNomenclature() )->sync_now();
				}
			}, 20 );
		}
	}

	/** Еднократни промени по записаните настройки при обновяване от по-стара версия. */
	private static function migrate( string $from ): void {
		// До 0.4.2 изборът на ден в чекаута беше включен по подразбиране; вече се решава от магазина за всяка пратка.
		if ( $from !== '' && version_compare( $from, '0.4.3', '<' ) ) {
			$settings = (array) get_option( EcontSettings::OPTION, [] );
			if ( ( $settings['holiday_choice_checkout'] ?? '' ) === 'yes' ) {
				$settings['holiday_choice_checkout'] = 'no';
				update_option( EcontSettings::OPTION, $settings );
			}
		}
	}

	public static function create_tables(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$cities  = EcontNomenclature::table_cities();
		$offices = EcontNomenclature::table_offices();

		dbDelta( "CREATE TABLE {$cities} (
			id BIGINT UNSIGNED NOT NULL,
			country_code CHAR(3) NOT NULL DEFAULT 'BGR',
			post_code VARCHAR(16) NOT NULL DEFAULT '',
			type VARCHAR(16) NOT NULL DEFAULT '',
			name VARCHAR(191) NOT NULL,
			name_en VARCHAR(191) NOT NULL DEFAULT '',
			region_name VARCHAR(191) NOT NULL DEFAULT '',
			has_office TINYINT(1) NOT NULL DEFAULT 0,
			has_aps TINYINT(1) NOT NULL DEFAULT 0,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY name (name(32)),
			KEY post_code (post_code)
		) {$charset};" );

		dbDelta( "CREATE TABLE {$offices} (
			id BIGINT UNSIGNED NOT NULL,
			code VARCHAR(32) NOT NULL,
			city_id BIGINT UNSIGNED NOT NULL,
			is_aps TINYINT(1) NOT NULL DEFAULT 0,
			is_mps TINYINT(1) NOT NULL DEFAULT 0,
			name VARCHAR(191) NOT NULL,
			name_en VARCHAR(191) NOT NULL DEFAULT '',
			address VARCHAR(255) NOT NULL DEFAULT '',
			latitude DECIMAL(10,7) NULL,
			longitude DECIMAL(10,7) NULL,
			phones VARCHAR(191) NOT NULL DEFAULT '',
			hours VARCHAR(191) NOT NULL DEFAULT '',
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY code (code),
			KEY city_type (city_id, is_aps)
		) {$charset};" );
	}
}
