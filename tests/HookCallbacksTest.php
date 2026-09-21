<?php
use PHPUnit\Framework\TestCase;

/**
 * Защита срещу изтрити методи: всяко [ $this, 'име' ] / [ self::class, 'име' ] и всяко self::име() трябва да има дефиниция.
 * Липсващ callback на WordPress hook не гърми при регистрация, а чак при изпълнение (бял екран в админа).
 */
final class HookCallbacksTest extends TestCase {

	/** Методи, наследени от WooCommerce/WordPress класове, които не са в нашия код. */
	private const INHERITED = [ 'process_admin_options' ];

	public function test_every_hooked_method_exists(): void {
		$missing = [];
		foreach ( $this->php_files( dirname( __DIR__ ) . '/src' ) as $file ) {
			$src     = (string) file_get_contents( $file );
			$methods = [];
			preg_match_all( '/function\s+(\w+)\s*\(/', $src, $m );
			$methods = array_flip( $m[1] );
			preg_match_all( "/\\[\\s*(?:\\\$this|self::class|static::class)\\s*,\\s*'(\\w+)'\\s*\\]/", $src, $hooked );
			preg_match_all( '/(?:self|static)::(\w+)\s*\(/', $src, $static );
			foreach ( array_merge( $hooked[1], $static[1] ) as $name ) {
				if ( ! isset( $methods[ $name ] ) && ! in_array( $name, self::INHERITED, true ) ) {
					$missing[] = basename( $file ) . '::' . $name;
				}
			}
		}
		$this->assertSame( [], array_values( array_unique( $missing ) ), 'Закачени или извикани методи без дефиниция' );
	}

	public function test_econt_not_found_messages_are_recognised(): void {
		$this->assertTrue( \Kanelov\Shipping\Carrier\Econt\EcontCarrier::is_not_found( [ 'Пратка 1055000000001 не е открита' ] ) );
		$this->assertFalse( \Kanelov\Shipping\Carrier\Econt\EcontCarrier::is_not_found( [ 'Пратката вече е приета и не може да бъде изтрита' ] ) );
	}

	/** @return string[] */
	private function php_files( string $dir ): array {
		$out = [];
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir ) ) as $f ) {
			if ( $f->isFile() && substr( $f->getFilename(), -4 ) === '.php' ) {
				$out[] = $f->getPathname();
			}
		}
		return $out;
	}
}
