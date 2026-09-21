<?php
use PHPUnit\Framework\TestCase;

/**
 * Защита срещу изтрити методи: всеки закачен callback ([ $this, 'x' ], [ Клас::class, 'x' ], 'Клас::x')
 * и всяко статично извикване Клас::x() към наш клас трябва да има дефиниция.
 * Липсващ callback на WordPress hook не гърми при регистрация, а чак при изпълнение (бял екран в админа).
 */
final class HookCallbacksTest extends TestCase {

	/** Методи, наследени от WooCommerce/WordPress класове, които не са в нашия код. */
	private const INHERITED = [ 'process_admin_options' ];

	public function test_every_hooked_or_statically_called_method_exists(): void {
		$root  = dirname( __DIR__ );
		$files = array_merge( $this->php_files( $root . '/src' ), [ $root . '/kanelov-shipping.php' ] );

		// Клас (кратко име) => методи, за всички наши класове.
		$classes = [];
		$sources = [];
		foreach ( $files as $file ) {
			$src              = (string) file_get_contents( $file );
			$sources[ $file ] = $src;
			if ( preg_match( '/\b(?:class|interface|trait)\s+(\w+)/', $src, $c ) ) {
				preg_match_all( '/function\s+(\w+)\s*\(/', $src, $m );
				$classes[ $c[1] ] = array_flip( $m[1] );
			}
		}

		$missing = [];
		$check   = static function ( string $class, string $method, string $where ) use ( $classes, &$missing ): void {
			$short = ltrim( substr( $class, (int) strrpos( '\\' . $class, '\\' ) ), '\\' );
			if ( isset( $classes[ $short ] ) && ! isset( $classes[ $short ][ $method ] ) && ! in_array( $method, self::INHERITED, true ) ) {
				$missing[] = $where . ': ' . $short . '::' . $method;
			}
		};
		foreach ( $sources as $file => $src ) {
			$self = preg_match( '/\b(?:class|interface|trait)\s+(\w+)/', $src, $c ) ? $c[1] : '';
			$q    = '[\'"]';
			// [ $this, 'x' ], [ self::class, 'x' ], [ static::class, 'x' ]
			preg_match_all( "/\\[\\s*(?:\\\$this|self::class|static::class)\\s*,\\s*{$q}(\\w+){$q}\\s*\\]/", $src, $own );
			foreach ( $own[1] as $method ) {
				$check( $self, $method, basename( $file ) );
			}
			// [ \Ns\Клас::class, 'x' ] и 'Ns\Клас::x'
			preg_match_all( "/\\[\\s*\\\\?([\\w\\\\]+)::class\\s*,\\s*{$q}(\\w+){$q}\\s*\\]/", $src, $fq );
			foreach ( $fq[1] as $i => $class ) {
				$check( $class, $fq[2][ $i ], basename( $file ) );
			}
			preg_match_all( "/{$q}\\\\?([\\w\\\\]+)::(\\w+){$q}/", $src, $str );
			foreach ( $str[1] as $i => $class ) {
				$check( $class, $str[2][ $i ], basename( $file ) );
			}
			// self::x(), static::x(), Клас::x(), \Ns\Клас::x()
			preg_match_all( '/(?<![\\w$])\\\\?([\\w\\\\]+)::(\\w+)\\s*\\(/', $src, $calls );
			foreach ( $calls[1] as $i => $class ) {
				$class = in_array( $class, [ 'self', 'static' ], true ) ? $self : $class;
				if ( $class !== '' && $class !== 'parent' ) {
					$check( $class, $calls[2][ $i ], basename( $file ) );
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
