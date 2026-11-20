<?php
/**
 * A QR code, drawn here.
 *
 * @package     SugarCommerce\Qr
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Qr;

use InvalidArgumentException;
use SugarCommerce\Qr\Traits\QR\Encoding;
use SugarCommerce\Qr\Traits\QR\Layout;

/**
 * Turns a string into a QR code without asking anybody else to.
 *
 * The usual reason to want this is that the string is a secret: a pairing
 * link with a password in it, a sign-in token, a TOTP enrolment URI. Every
 * QR service on the internet takes the text in a URL, which would mean
 * posting the credential to a stranger and into their logs; a script from a
 * CDN is the same stranger with one more step. So the code is made in PHP,
 * on this server, and reaches the page as an inline SVG.
 *
 * It is the standard (ISO/IEC 18004) and no more of it than is needed:
 *
 * - byte mode only, so any string goes in as its bytes -- UTF-8 included --
 *   and comes out of a phone's camera as the same bytes;
 * - error correction M by default, about 15% of the code may be
 *   unreadable, which is right for a screen: there is no dirt or tearing
 *   to survive, and a lower level means a smaller, easier code. L is used
 *   only when the text is too long for M at the largest size. Ask for a
 *   level (L, M, Q or H) and that level is used and no other: H for a code
 *   that is printed, or that has a logo laid over its middle;
 * - the smallest of the forty sizes that holds the text;
 * - all eight masks tried and the one the standard scores best is kept.
 *
 * Tables holds the numbers the standard gives as tables; the Encoding trait
 * turns the text into codewords, and the Layout trait lays them out.
 *
 * @since 1.0.0
 */
final class QR {

	use Encoding;
	use Layout;

	/**
	 * What svg() and data_uri() draw with when they are not told otherwise.
	 *
	 * @since 1.0.0
	 */
	private const array DEFAULTS = [
		'module' => 6,
		'quiet'  => 4,
		'level'  => null,
		'label'  => null,
		'dark'   => '#000',
		'light'  => '#fff',
	];

	/**
	 * The modules of the code for a string.
	 *
	 * @param string      $data  What the code should say. Its bytes are what is encoded.
	 * @param string|null $level L, M, Q or H to use that level and only that one. Null
	 *                           for M, falling back to L when the text is too long for M.
	 *
	 * @return array<int, array<int, bool>> Rows of modules, true for dark. Square,
	 *                                      and without the quiet margin around it.
	 * @throws InvalidArgumentException When the level is not one of the four, or the
	 *                                  string is longer than a code at that level holds
	 *                                  (2,953 bytes at L).
	 * @since 1.0.0
	 */
	public static function matrix( string $data, ?string $level = null ): array {
		[ $version, $level ] = self::fit( strlen( $data ), $level );

		$size      = self::size( $version );
		$codewords = self::interleave( self::codewords( $data, $version, $level ), $version, $level );

		// Two grids the same size: what colour each module is, and whether
		// it belongs to the furniture -- the finders, the timing lines,
		// the format bits -- which the data flows around and no mask may
		// touch.
		$modules  = array_fill( 0, $size, array_fill( 0, $size, false ) );
		$reserved = $modules;

		self::furniture( $modules, $reserved, $version );
		self::place( $modules, $reserved, $codewords );

		$fewest = PHP_INT_MAX;
		$masked = [];

		foreach ( range( 0, 7 ) as $mask ) {
			$trial = self::mask( $modules, $reserved, $mask );

			self::format( $trial, $level, $mask );

			$penalty = self::penalty( $trial );

			if ( $penalty < $fewest ) {
				$fewest = $penalty;
				$masked = $trial;
			}
		}

		return $masked;
	}

	/**
	 * The code for a string, as an SVG to put straight into a page.
	 *
	 * One path for every dark module, on a light square, with the quiet
	 * margin the standard asks for so a camera can find the edge. Nothing
	 * in it refers to anything outside itself.
	 *
	 * @param string $data What the code should say.
	 * @param array{
	 *     module?: int,
	 *     quiet?: int,
	 *     level?: string|null,
	 *     label?: string|null,
	 *     dark?: string,
	 *     light?: string,
	 * } $args {
	 *     How to draw it.
	 *
	 *     @type int         $module How many pixels wide a module is drawn. Default 6.
	 *     @type int         $quiet  How many modules of margin on each side. The
	 *                               standard says four, the default.
	 *     @type string|null $level  L, M, Q or H; see matrix(). Default null.
	 *     @type string|null $label  What a screen reader says. Default "QR code".
	 *     @type string      $dark   The colour of the dark modules. Default #000.
	 *     @type string      $light  The colour behind them. An empty string leaves
	 *                               the ground transparent. Default #fff.
	 * }
	 *
	 * @return string
	 * @throws InvalidArgumentException See matrix(); and when a colour is not
	 *                                  one (see colour()).
	 * @since 1.0.0
	 */
	public static function svg( string $data, array $args = [] ): string {
		$args   = array_merge( self::DEFAULTS, $args );
		$dark   = self::colour( (string) $args['dark'] );
		$light  = '' === (string) $args['light'] ? '' : self::colour( (string) $args['light'] );
		$matrix = self::matrix( $data, $args['level'] );
		$module = max( 1, (int) $args['module'] );
		$quiet  = max( 0, (int) $args['quiet'] );
		$across = count( $matrix ) + 2 * $quiet;
		$label  = $args['label'] ?? __( 'QR code', 'sugarcommerce' );
		$ground = '' === $light ? '' : '<rect width="100%" height="100%" fill="' . esc_attr( $light ) . '"/>';
		$path   = '';

		foreach ( $matrix as $y => $row ) {
			foreach ( $row as $x => $on ) {
				if ( $on ) {
					$path .= 'M' . ( $x + $quiet ) . ',' . ( $y + $quiet ) . 'h1v1h-1z';
				}
			}
		}

		return sprintf(
			'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %1$d %1$d" width="%2$d" height="%2$d" shape-rendering="crispEdges" role="img" aria-label="%3$s">%4$s<path fill="%5$s" d="%6$s"/></svg>',
			$across,
			$across * $module,
			esc_attr( (string) $label ),
			$ground,
			esc_attr( $dark ),
			$path
		);
	}

	/**
	 * The code for a string, as a data: URI for an <img> or a CSS background.
	 *
	 * The same picture svg() draws, for the places that want an address
	 * rather than markup: an image tag, an email that strips inline SVG
	 * (some clients refuse data: images too, so test yours), a download link.
	 *
	 * @param string $data What the code should say.
	 * @param array  $args As svg() takes them.
	 *
	 * @return string
	 * @throws InvalidArgumentException See matrix().
	 * @since 1.0.0
	 */
	public static function data_uri( string $data, array $args = [] ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- The picture, not code: a data: URI is base64 by definition.
		return 'data:image/svg+xml;base64,' . base64_encode( self::svg( $data, $args ) );
	}

	/**
	 * How many bytes of text a code can hold.
	 *
	 * @param string $level   L, M, Q or H.
	 * @param int    $version The size, 1 to 40. Default the largest.
	 *
	 * @return int
	 * @throws InvalidArgumentException When the level or the version is not one there is.
	 * @since 1.0.0
	 */
	public static function capacity( string $level = 'M', int $version = 40 ): int {
		$level = self::level_from( $level );

		if ( $version < 1 || $version > 40 ) {
			throw new InvalidArgumentException( 'A QR code version is 1 to 40.' );
		}

		// Four bits to say "bytes" and the count, then whole bytes.
		return intdiv( 8 * Tables::data_codewords( $version, $level ) - 4 - ( $version < 10 ? 8 : 16 ), 8 );
	}

	/**
	 * Which size a code is.
	 *
	 * @param array<int, array<int, bool>> $matrix What matrix() made.
	 *
	 * @return int The version, 1 to 40.
	 * @since 1.0.0
	 */
	public static function version( array $matrix ): int {
		return (int) ( ( count( $matrix ) - 17 ) / 4 );
	}

	/**
	 * Which level a code was made at, read back from its format bits.
	 *
	 * @param array<int, array<int, bool>> $matrix What matrix() made.
	 *
	 * @return string L, M, Q or H.
	 * @since 1.0.0
	 */
	public static function level( array $matrix ): string {
		$size = count( $matrix );
		$bits = 0;

		// The copy split between the top-right and bottom-left finders.
		for ( $i = 0; $i < 8; $i++ ) {
			$bits |= ( $matrix[8][ $size - 1 - $i ] ? 1 : 0 ) << $i;
		}

		for ( $i = 8; $i < 15; $i++ ) {
			$bits |= ( $matrix[ $size - 15 + $i ][8] ? 1 : 0 ) << $i;
		}

		return Tables::level_named( ( $bits ^ 0x5412 ) >> 13 );
	}

	/**
	 * A level as the tables name it.
	 *
	 * @param string $level What was asked for, in either case.
	 *
	 * @return string
	 * @throws InvalidArgumentException When it is not one of the four.
	 * @since 1.0.0
	 */
	private static function level_from( string $level ): string {
		$level = strtoupper( trim( $level ) );

		if ( ! in_array( $level, Tables::levels(), true ) ) {
			throw new InvalidArgumentException( 'A QR code level is L, M, Q or H.' );
		}

		return $level;
	}

	/**
	 * A colour for a fill, checked to be one.
	 *
	 * Escaping keeps a colour inside its attribute, but a fill can also
	 * name an address -- url(https://…) -- which the browser fetches each
	 * time the code is shown, and the picture is meant to ask nobody for
	 * anything. So a colour is taken only in the forms CSS writes colours
	 * in: #hex, a name (currentColor and transparent among them), a colour
	 * function of numbers and units, or a custom property.
	 *
	 * @param string $colour What was asked for.
	 *
	 * @return string
	 * @throws InvalidArgumentException When it is not a colour.
	 * @since 1.0.0
	 */
	private static function colour( string $colour ): string {
		$forms = '/^(?:#(?:[0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})|[a-z]+|(?:rgba?|hsla?|hwb|lab|lch|oklab|oklch|color)\([0-9a-z.,% \/+-]*\)|var\(--[a-z0-9_-]+\))$/iD';

		if ( 1 !== preg_match( $forms, $colour ) ) {
			throw new InvalidArgumentException( 'A QR code colour is #hex, a colour name, a colour function or var(--name).' );
		}

		return $colour;
	}
}
