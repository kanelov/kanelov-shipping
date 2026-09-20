<?php
namespace Kanelov\Shipping;

defined( 'ABSPATH' ) || exit;

/**
 * Обновления през GitHub Releases (публично хранилище, без токен).
 * WordPress пита за последния release, сравнява версията с KS_VERSION и показва стандартния бутон „Обнови“.
 * Пакетът е zip файлът, прикачен към release-а от GitHub Action (bin-build.sh); ако липсва, се ползва zipball.
 */
final class Updater {

	const REPO      = 'kanelov/kanelov-shipping';
	const TRANSIENT = 'ks_github_release';
	const TTL       = 6 * HOUR_IN_SECONDS;

	private string $basename;
	private string $slug;

	public function __construct() {
		$this->basename = plugin_basename( KS_FILE );
		$this->slug     = dirname( $this->basename );
	}

	public function register(): void {
		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'inject_update' ] );
		add_filter( 'plugins_api', [ $this, 'plugin_info' ], 10, 3 );
		add_filter( 'upgrader_source_selection', [ $this, 'fix_folder_name' ], 10, 3 );
		add_filter( 'plugin_row_meta', [ $this, 'row_meta' ], 10, 2 );
		add_action( 'admin_init', [ $this, 'maybe_force_check' ] );
	}

	/** Последният release от GitHub: ['version','package','url','body','published'] или null. Кеш 6 часа. */
	public function latest( bool $force = false ): ?array {
		$cached = get_site_transient( self::TRANSIENT );
		if ( ! $force && is_array( $cached ) ) {
			return $cached ?: null;
		}
		$res = wp_remote_get( 'https://api.github.com/repos/' . self::REPO . '/releases/latest', [
			'timeout' => 10,
			'headers' => [ 'Accept' => 'application/vnd.github+json', 'User-Agent' => 'kanelov-shipping/' . KS_VERSION ],
		] );
		$release = null;
		if ( ! is_wp_error( $res ) && wp_remote_retrieve_response_code( $res ) === 200 ) {
			$data = json_decode( wp_remote_retrieve_body( $res ), true );
			if ( is_array( $data ) && ! empty( $data['tag_name'] ) && empty( $data['draft'] ) && empty( $data['prerelease'] ) ) {
				$package = (string) ( $data['zipball_url'] ?? '' );
				foreach ( (array) ( $data['assets'] ?? [] ) as $asset ) {
					if ( str_ends_with( (string) ( $asset['name'] ?? '' ), '.zip' ) ) {
						$package = (string) $asset['browser_download_url'];
						break;
					}
				}
				$release = [
					'version'   => ltrim( (string) $data['tag_name'], 'vV' ),
					'package'   => $package,
					'url'       => (string) ( $data['html_url'] ?? '' ),
					'body'      => (string) ( $data['body'] ?? '' ),
					'published' => (string) ( $data['published_at'] ?? '' ),
				];
			}
		}
		set_site_transient( self::TRANSIENT, $release ?: [], $release ? self::TTL : HOUR_IN_SECONDS );
		return $release;
	}

	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		$release = $this->latest();
		if ( ! $release || ! $release['package'] ) {
			return $transient;
		}
		$item = (object) [
			'id'          => 'github.com/' . self::REPO,
			'slug'        => $this->slug,
			'plugin'      => $this->basename,
			'new_version' => $release['version'],
			'url'         => 'https://github.com/' . self::REPO,
			'package'     => $release['package'],
			'icons'       => [],
			'banners'     => [],
			'tested'      => '',
			'requires'    => '6.5',
			'requires_php' => '8.1',
		];
		if ( version_compare( $release['version'], KS_VERSION, '>' ) ) {
			$transient->response[ $this->basename ] = $item;
			unset( $transient->no_update[ $this->basename ] );
		} else {
			$transient->no_update[ $this->basename ] = $item;
			unset( $transient->response[ $this->basename ] );
		}
		return $transient;
	}

	/** Прозорецът „Виж подробности“ в списъка с плъгини. */
	public function plugin_info( $result, string $action, $args ) {
		if ( $action !== 'plugin_information' || ! isset( $args->slug ) || $args->slug !== $this->slug ) {
			return $result;
		}
		$release = $this->latest();
		if ( ! $release ) {
			return $result;
		}
		return (object) [
			'name'          => 'Kanelov Shipping',
			'slug'          => $this->slug,
			'version'       => $release['version'],
			'author'        => '<a href="https://github.com/kanelov">Kanelov</a>',
			'homepage'      => 'https://github.com/' . self::REPO,
			'download_link' => $release['package'],
			'requires'      => '6.5',
			'requires_php'  => '8.1',
			'last_updated'  => $release['published'],
			'sections'      => [
				'description' => esc_html__( 'Доставка с Еконт (офис, Еконтомат, адрес) за WooCommerce с генериране на товарителници.', 'kanelov-shipping' ),
				'changelog'   => wp_kses_post( wpautop( $release['body'] ?: '—' ) ),
			],
		];
	}

	/** GitHub zipball се разархивира като „kanelov-kanelov-shipping-abc123“; папката трябва да е „kanelov-shipping“. */
	public function fix_folder_name( $source, $remote_source, $upgrader ) {
		global $wp_filesystem;
		$plugin = $upgrader->skin->plugin ?? ( $upgrader->skin->options['plugin'] ?? '' );
		if ( $plugin !== $this->basename && ! ( isset( $upgrader->skin->plugin_info ) && basename( $source ) !== $this->slug && str_contains( basename( $source ), 'kanelov-shipping' ) ) ) {
			return $source;
		}
		$target = trailingslashit( $remote_source ) . $this->slug . '/';
		if ( untrailingslashit( $source ) === untrailingslashit( $target ) ) {
			return $source;
		}
		if ( $wp_filesystem && $wp_filesystem->move( $source, $target ) ) {
			return $target;
		}
		return $source;
	}

	public function row_meta( array $links, string $file ): array {
		if ( $file === $this->basename ) {
			$links[] = '<a href="' . esc_url( wp_nonce_url( admin_url( 'plugins.php?ks_check_update=1' ), 'ks_check_update' ) ) . '">' . esc_html__( 'Провери за обновления', 'kanelov-shipping' ) . '</a>';
		}
		return $links;
	}

	/** Линкът „Провери за обновления“: изчиства кеша и WordPress пита GitHub отново. */
	public function maybe_force_check(): void {
		if ( empty( $_GET['ks_check_update'] ) || ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		check_admin_referer( 'ks_check_update' );
		delete_site_transient( self::TRANSIENT );
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();
		wp_safe_redirect( admin_url( 'plugins.php?plugin_status=upgrade' ) );
		exit;
	}
}
