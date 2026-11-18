<?php
/**
 * PHPUnit bootstrap.
 *
 * The encoder is pure PHP; the SVG is the only part that touches
 * WordPress, for its label and its escaping. Those functions are stubbed
 * here, as close to core's behaviour as the tests depend on and no closer.
 *
 * @package SugarCommerce\Qr
 */

declare( strict_types=1 );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}
