<?php
namespace Kanelov\Shipping\Carrier\Econt;

defined( 'ABSPATH' ) || exit;

/**
 * Профилът на подателя от Еконт: клиент, адреси, споразумения за наложен платеж (cdPayOptions),
 * шаблони за инструкции. Кешира се в option и се обновява с бутон от настройките.
 */
final class EcontProfile {

	const OPTION = 'ks_econt_profile';

	public function __construct( private EcontSettings $settings ) {}

	public function refresh(): array {
		$data = $this->settings->api()->get_client_profiles();
		update_option( self::OPTION, [
			'fetched_at' => time(),
			'env'        => $this->settings->is_live() ? 'live' : 'demo',
			'data'       => $data,
		], false );
		return $data;
	}

	public function cached(): ?array {
		$opt = get_option( self::OPTION );
		if ( ! is_array( $opt ) || empty( $opt['data']['profiles'] ) ) {
			return null;
		}
		if ( ( $opt['env'] ?? '' ) !== ( $this->settings->is_live() ? 'live' : 'demo' ) ) {
			return null; // профилът е от другата среда
		}
		return $opt['data'];
	}

	public function fetched_at(): int {
		$opt = get_option( self::OPTION );
		return (int) ( $opt['fetched_at'] ?? 0 );
	}

	/** Избраният профил (първият, освен ако не е зададен друг). */
	public function profile(): ?array {
		$data = $this->cached();
		if ( ! $data ) {
			return null;
		}
		$index = (int) $this->settings->get( 'profile_index', 0 );
		return $data['profiles'][ $index ] ?? ( $data['profiles'][0] ?? null );
	}

	public function client(): array {
		return (array) ( $this->profile()['client'] ?? [] );
	}

	/** @return array<int, array> адресите на подателя, както ги връща Еконт */
	public function addresses(): array {
		return array_values( (array) ( $this->profile()['addresses'] ?? [] ) );
	}

	public function address( int $index ): ?array {
		return $this->addresses()[ $index ] ?? null;
	}

	/** @return array<int, array> споразумения за наложен платеж */
	public function cd_pay_options(): array {
		return array_values( (array) ( $this->profile()['cdPayOptions'] ?? [] ) );
	}

	public function instruction_templates(): array {
		return array_values( (array) ( $this->profile()['instructionTemplates'] ?? [] ) );
	}

	/** Опции за падащи менюта в настройките. */
	public function profile_choices(): array {
		$out = [];
		foreach ( (array) ( $this->cached()['profiles'] ?? [] ) as $i => $p ) {
			$out[ (string) $i ] = (string) ( $p['client']['name'] ?? ( 'Профил ' . ( $i + 1 ) ) );
		}
		return $out;
	}

	public function address_choices(): array {
		$out = [];
		foreach ( $this->addresses() as $i => $a ) {
			$out[ (string) $i ] = self::format_address( $a );
		}
		return $out;
	}

	public function cd_pay_option_choices(): array {
		$out = [ '' => __( 'Без споразумение (наложеният платеж се получава в брой/по стандартния начин)', 'kanelov-shipping' ) ];
		foreach ( $this->cd_pay_options() as $o ) {
			$num   = (string) ( $o['num'] ?? '' );
			$parts = array_filter( [
				$num,
				$o['method'] ?? '',
				$o['bankAccount']['iban'] ?? ( $o['bankAccount']['name'] ?? '' ),
				$o['client']['name'] ?? '',
			] );
			$out[ $num ] = implode( ' · ', array_map( 'strval', $parts ) );
		}
		return $out;
	}

	public static function format_address( array $a ): string {
		return trim( implode( ' ', array_filter( [
			$a['city']['name'] ?? '',
			$a['quarter'] ?? '',
			$a['street'] ?? '',
			$a['num'] ?? '',
			$a['other'] ?? '',
		] ) ) );
	}
}
