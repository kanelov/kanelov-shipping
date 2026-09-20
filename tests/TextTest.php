<?php
use Kanelov\Shipping\Support\Text;
use PHPUnit\Framework\TestCase;

final class TextTest extends TestCase {

	public function test_truncate_short_text_unchanged(): void {
		$this->assertSame( 'къс текст', Text::truncate_words( 'къс   текст', 100 ) );
	}

	public function test_truncate_long_text_on_word_boundary(): void {
		$t = Text::truncate_words( 'едно две три четири пет шест седем', 15 );
		$this->assertSame( 'едно две три…', $t );
		$this->assertLessThanOrEqual( 15, mb_strlen( $t ) );
	}

	public function test_truncate_single_long_word_hard_cuts(): void {
		$t = Text::truncate_words( str_repeat( 'а', 50 ), 10 );
		$this->assertSame( 10, mb_strlen( $t ) );
	}

	public function test_clean_for_label_strips_html_and_control_chars(): void {
		$this->assertSame( 'Картина & рамка', Text::clean_for_label( "<b>Картина</b> &amp; \x01рамка\n" ) );
	}
}
