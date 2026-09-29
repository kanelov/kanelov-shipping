<?php
namespace Kanelov\Shipping\Carrier\BoxNow;

use Kanelov\Shipping\Support\Log;

defined( 'ABSPATH' ) || exit;

/**
 * HTTP клиент за Box Now Partner API (OAuth2 client credentials, Bearer токен, кеширан в transient).
 * Документация: BOX NOW Partner API v1.69. Списъкът с автомати идва от публичен JSON без парола.
 */
final class BoxNowApi {

	const LIVE_URL  = 'https://api-production.boxnow.bg';
	const STAGE_URL = 'https://api-stage.boxnow.bg';

	const LOCATIONS_LIVE  = 'https://locationapi-production.boxnow.bg/v1/apms_bg-BG.json';
	const LOCATIONS_STAGE = 'https://locationapi-stage.boxnow.bg/v1/apms_bg-BG.json';

	/** Известни кодове на грешки на Box Now с четимо обяснение. */
	const ERRORS = [
		'P400' => 'Заявка с грешни данни.',
		'P401' => 'Грешна начална точка (склад). Проверете „Изпращане от“ в настройките на Box Now.',
		'P402' => 'Невалиден автомат за доставка. Изберете автомата отново.',
		'P403' => 'Профилът няма право на доставки от автомат до автомат.',
		'P405' => 'Невалиден телефонен номер. Нужен е формат +359 xx xxx xxxx.',
		'P406' => 'Невалиден размер на пратката. Допустими са размери 1, 2 и 3.',
		'P408' => 'Невалидна сума за наложен платеж (допустимо от 0 до 5000).',
		'P410' => 'Заявка с този номер на поръчка вече съществува в Box Now.',
		'P411' => 'Профилът няма право на наложен платеж. Свържете се с Box Now.',
		'P440' => 'Профилът е свързан с няколко партньора: попълнете Partner ID в настройките.',
		'P441' => 'Невалиден Partner ID.',
	];

	public function __construct(
		private string $client_id,
		private string $client_secret,
		private bool $live = true,
		private string $partner_id = '',
		private int $timeout = 30
	) {}

	public static function from_settings( BoxNowSettings $settings ): self {
		return new self( $settings->client_id(), $settings->client_secret(), $settings->is_live(), $settings->partner_id(), 30 );
	}

	public function has_credentials(): bool {
		return $this->client_id !== '' && $this->client_secret !== '';
	}

	public function base_url(): string {
		return $this->live ? self::LIVE_URL : self::STAGE_URL;
	}

	public static function locations_url( bool $live ): string {
		return $live ? self::LOCATIONS_LIVE : self::LOCATIONS_STAGE;
	}

	private function token_key(): string {
		return 'ks_boxnow_token_' . md5( $this->base_url() . '|' . $this->client_id );
	}

	/** Bearer токен: от кеша или нов през /auth-sessions. */
	public function token( bool $fresh = false ): string {
		if ( ! $this->has_credentials() ) {
			throw new BoxNowApiException( [ __( 'Липсват Client ID и Client Secret за Box Now.', 'kanelov-shipping' ) ], 'NoCredentials' );
		}
		if ( ! $fresh ) {
			$cached = get_transient( $this->token_key() );
			if ( is_string( $cached ) && $cached !== '' ) {
				return $cached;
			}
		}
		$response = wp_remote_post( $this->base_url() . '/api/v1/auth-sessions', [
			'timeout'   => $this->timeout,
			'sslverify' => true,
			'headers'   => [ 'Content-Type' => 'application/json', 'Accept' => 'application/json' ],
			'body'      => wp_json_encode( [ 'grant_type' => 'client_credentials', 'client_id' => $this->client_id, 'client_secret' => $this->client_secret ] ),
		] );
		$data = $this->decode( $response, 'auth-sessions' );
		$tok  = (string) ( $data['access_token'] ?? '' );
		if ( $tok === '' ) {
			throw new BoxNowApiException( [ __( 'Box Now не върна токен за достъп. Проверете Client ID и Client Secret.', 'kanelov-shipping' ) ], 'NoToken' );
		}
		set_transient( $this->token_key(), $tok, max( 60, (int) ( $data['expires_in'] ?? 3600 ) - 120 ) );
		return $tok;
	}

	/**
	 * Заявка към API-то с Bearer токен. При 401 токенът се подновява веднъж.
	 *
	 * @param array|null $body JSON тяло (POST) или null
	 * @param array      $query GET параметри
	 * @return array|string декодиран JSON, или суров отговор при $raw (PDF)
	 */
	public function request( string $method, string $path, ?array $body = null, array $query = [], bool $raw = false, bool $retry = true ) {
		$url = $this->base_url() . '/api/v1/' . ltrim( $path, '/' );
		if ( $query ) {
			$url = add_query_arg( array_map( 'strval', $query ), $url );
		}
		$headers = [ 'Authorization' => 'Bearer ' . $this->token(), 'Accept' => $raw ? 'application/pdf' : 'application/json' ];
		if ( $this->partner_id !== '' ) {
			$headers['X-PartnerID'] = $this->partner_id;
		}
		$args = [ 'method' => $method, 'timeout' => $this->timeout, 'sslverify' => true, 'headers' => $headers ];
		if ( $body !== null ) {
			$headers['Content-Type'] = 'application/json';
			$args['headers']         = $headers;
			$args['body']            = wp_json_encode( $body );
		}
		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			Log::error( 'BoxNow HTTP error', [ 'path' => $path, 'error' => $response->get_error_message() ] );
			throw new BoxNowApiException( [ $response->get_error_message() ], 'Network' );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code === 401 && $retry ) {
			delete_transient( $this->token_key() );
			$this->token( true );
			return $this->request( $method, $path, $body, $query, $raw, false );
		}
		if ( $raw ) {
			if ( $code >= 400 ) {
				$this->decode( $response, $path ); // хвърля с четимо съобщение
			}
			return (string) wp_remote_retrieve_body( $response );
		}
		return $this->decode( $response, $path );
	}

	/** @param array|\WP_Error $response */
	private function decode( $response, string $path ): array {
		if ( is_wp_error( $response ) ) {
			Log::error( 'BoxNow HTTP error', [ 'path' => $path, 'error' => $response->get_error_message() ] );
			throw new BoxNowApiException( [ $response->get_error_message() ], 'Network' );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );
		if ( $code >= 400 ) {
			$messages = self::error_messages( is_array( $data ) ? $data : [], $code );
			Log::error( 'BoxNow API error', [ 'path' => $path, 'code' => $code, 'messages' => $messages, 'body' => mb_substr( $body, 0, 500 ) ] );
			throw new BoxNowApiException( $messages, (string) ( $data['code'] ?? ( 'Http' . $code ) ) );
		}
		if ( ! is_array( $data ) ) {
			if ( $code === 204 || trim( $body ) === '' ) {
				return [];
			}
			Log::error( 'BoxNow invalid JSON', [ 'path' => $path, 'code' => $code, 'body' => mb_substr( $body, 0, 300 ) ] );
			throw new BoxNowApiException( [ sprintf( __( 'Невалиден отговор от Box Now (HTTP %d).', 'kanelov-shipping' ), $code ) ], 'InvalidResponse' );
		}
		return $data;
	}

	/** Четими съобщения от {code, message} на Box Now. Чиста функция (за тестове). */
	public static function error_messages( array $data, int $http ): array {
		$code = (string) ( $data['code'] ?? '' );
		$msg  = trim( (string) ( $data['message'] ?? ( $data['error'] ?? '' ) ) );
		$out  = [];
		if ( $code !== '' && isset( self::ERRORS[ $code ] ) ) {
			$out[] = self::ERRORS[ $code ] . ( $msg !== '' ? ' (' . $msg . ')' : '' );
		} elseif ( $msg !== '' ) {
			$out[] = ( $code !== '' ? $code . ': ' : '' ) . $msg;
		}
		foreach ( (array) ( $data['errors'] ?? $data['details'] ?? [] ) as $e ) {
			$line = is_array( $e ) ? trim( (string) ( $e['message'] ?? wp_json_encode( $e ) ) ) : (string) $e;
			if ( $line !== '' ) {
				$out[] = $line;
			}
		}
		if ( ! $out ) {
			$out[] = match ( $http ) {
				401 => 'Box Now отказа достъпа (401). Проверете Client ID и Client Secret.',
				403 => 'Профилът в Box Now е деактивиран (403). Свържете се с Box Now.',
				default => sprintf( 'Box Now върна грешка (HTTP %d).', $http ),
			};
		}
		return array_values( array_unique( $out ) );
	}

	// Удобни методи.

	public function origins(): array {
		return (array) ( $this->request( 'GET', 'origins' )['data'] ?? [] );
	}

	public function destinations( array $query = [] ): array {
		return (array) ( $this->request( 'GET', 'destinations', null, $query )['data'] ?? [] );
	}

	public function entrusted_partners(): array {
		$data = $this->request( 'GET', 'entrusted-partners' );
		return isset( $data['data'] ) && is_array( $data['data'] ) ? $data['data'] : $data;
	}

	/** @return array{referenceNumber?: string, parcels?: array} */
	public function create_delivery_request( array $body ): array {
		return $this->request( 'POST', 'delivery-requests', $body );
	}

	public function parcels( array $query ): array {
		return (array) ( $this->request( 'GET', 'parcels', null, $query )['data'] ?? [] );
	}

	public function cancel_parcel( string $parcel_id ): array {
		return $this->request( 'POST', 'parcels/' . rawurlencode( $parcel_id ) . ':cancel', [] );
	}

	/** PDF на етикета (суров файл). */
	public function label_pdf( string $parcel_id ): string {
		return (string) $this->request( 'GET', 'parcels/' . rawurlencode( $parcel_id ) . '/label.pdf', null, [], true );
	}

	/** Публичният списък с автомати (без токен). Връща масив от локации или хвърля при мрежова грешка. */
	public static function fetch_locations( bool $live ): array {
		$response = wp_remote_get( self::locations_url( $live ), [ 'timeout' => 60, 'sslverify' => true, 'headers' => [ 'Accept' => 'application/json' ] ] );
		if ( is_wp_error( $response ) ) {
			throw new BoxNowApiException( [ $response->get_error_message() ], 'Network' );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code >= 400 || ! is_array( $data ) ) {
			throw new BoxNowApiException( [ sprintf( __( 'Списъкът с автомати на Box Now не е достъпен (HTTP %d).', 'kanelov-shipping' ), $code ) ], 'Http' . $code );
		}
		return isset( $data['data'] ) && is_array( $data['data'] ) ? $data['data'] : ( array_is_list( $data ) ? $data : [] );
	}
}
