<?php
namespace Kanelov\Shipping\Support;

/**
 * Помощни функции за текст. Без зависимост от WordPress, за да могат да се тестват самостоятелно.
 */
final class Text {

	/**
	 * Съкращава текст до $max знака по граница на дума и добавя многоточие, ако е било нужно.
	 */
	public static function truncate_words( string $text, int $max ): string {
		$text = trim( preg_replace( '/\s+/u', ' ', $text ) ?? '' );
		if ( $max <= 0 || mb_strlen( $text ) <= $max ) {
			return $text;
		}
		$ellipsis = '…';
		$limit    = max( 1, $max - mb_strlen( $ellipsis ) );
		$cut      = mb_substr( $text, 0, $limit );
		$space    = mb_strrpos( $cut, ' ' );
		if ( $space !== false && $space > (int) ( $limit * 0.5 ) ) {
			$cut = mb_substr( $cut, 0, $space );
		}
		return rtrim( $cut, " ,;-" ) . $ellipsis;
	}

	/**
	 * Премахва знаци, които Еконт не приема в описанието (управляващи символи, HTML).
	 */
	public static function clean_for_label( string $text ): string {
		$text = html_entity_decode( strip_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = preg_replace( '/[\x00-\x1F\x7F]/u', ' ', $text ) ?? $text;
		return trim( preg_replace( '/\s+/u', ' ', $text ) ?? $text );
	}
}
