<?php
use Kanelov\Shipping\Admin\LabelPrint;
use PHPUnit\Framework\TestCase;

// Минимални заместители на функциите на WordPress, нужни на LabelPrint::url() (тестовете вървят без WordPress).
if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( $path = '' ) { return 'https://shop.test/wp-admin/' . ltrim( (string) $path, '/' ); }
}
if ( ! function_exists( 'wp_create_nonce' ) ) {
	function wp_create_nonce( $action = -1 ) { return 'n0nce'; }
}
if ( ! function_exists( 'add_query_arg' ) ) {
	// Като в WordPress: добавя параметрите с „&“, без да ги превръща в HTML.
	function add_query_arg( array $args, string $url ) {
		return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . implode( '&', array_map( static fn( $k, $v ) => $k . '=' . $v, array_keys( $args ), $args ) );
	}
}

/**
 * Масовото действие „Печат на товарителници“ пренасочва към този адрес. С wp_nonce_url() той излизаше с &amp;
 * и страницата не виждаше избраните поръчки: печаташе всички неотпечатани от 14 дни, а после „няма товарителници“.
 */
final class LabelPrintUrlTest extends TestCase {

	public function test_print_url_keeps_selected_orders_and_nonce(): void {
		$url = LabelPrint::url( [ 103, 101, '102', 101, 0 ] );

		$this->assertStringNotContainsString( '&amp;', $url );
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );
		$this->assertSame( 'ks-print-labels', $query['page'] ?? null );
		$this->assertSame( '103,101,102', $query['orders'] ?? null );
		$this->assertSame( 'n0nce', $query['_wpnonce'] ?? null );
	}

	public function test_print_url_is_not_built_with_wp_nonce_url(): void {
		$src = (string) file_get_contents( dirname( __DIR__ ) . '/src/Admin/LabelPrint.php' );
		preg_match( '/public static function url\(.*?\n\t}/s', $src, $m );
		$this->assertNotEmpty( $m );
		$this->assertStringNotContainsString( 'wp_nonce_url(', $m[0] );
	}
}
