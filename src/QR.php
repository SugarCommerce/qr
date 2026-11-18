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
 * Tables holds the numbers the standard gives as tables.
 *
 * @since 1.0.0
 */
final class QR {

	/**
	 * What svg() and data_uri() draw with when they are not told otherwise.
	 *
	 * @since 1.0.0
	 */
	private const DEFAULTS = [
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
	 * @throws InvalidArgumentException See matrix().
	 * @since 1.0.0
	 */
	public static function svg( string $data, array $args = [] ): string {
		$args   = array_merge( self::DEFAULTS, $args );
		$matrix = self::matrix( $data, $args['level'] );
		$module = max( 1, (int) $args['module'] );
		$quiet  = max( 0, (int) $args['quiet'] );
		$across = count( $matrix ) + 2 * $quiet;
		$label  = $args['label'] ?? __( 'QR code', 'sugarcommerce' );
		$ground = '' === (string) $args['light'] ? '' : '<rect width="100%" height="100%" fill="' . esc_attr( (string) $args['light'] ) . '"/>';
		$path   = '';

		foreach ( $matrix as $y => $row ) {
			foreach ( $row as $x => $dark ) {
				if ( $dark ) {
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
			esc_attr( (string) $args['dark'] ),
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
	 * The smallest size, and the level, that hold this many bytes.
	 *
	 * @param int         $bytes The length of the text.
	 * @param string|null $level The level asked for, or null for M then L.
	 *
	 * @return array{0: int, 1: string} The version and the level.
	 * @throws InvalidArgumentException When nothing holds it.
	 * @since 1.0.0
	 */
	private static function fit( int $bytes, ?string $level ): array {
		$levels = null === $level ? [ 'M', 'L' ] : [ self::level_from( $level ) ];

		foreach ( $levels as $level ) {
			foreach ( range( 1, 40 ) as $version ) {
				// Four bits to say "bytes", then the count, then the bytes.
				$needed = 4 + ( $version < 10 ? 8 : 16 ) + 8 * $bytes;

				if ( $needed <= 8 * Tables::data_codewords( $version, $level ) ) {
					return [ $version, $level ];
				}
			}
		}

		throw new InvalidArgumentException( 'That is too long for a QR code.' );
	}

	/**
	 * How many modules across a version is.
	 *
	 * @param int $version The version.
	 *
	 * @return int
	 * @since 1.0.0
	 */
	private static function size( int $version ): int {
		return 17 + 4 * $version;
	}

	/**
	 * The text as data codewords: its header, its bytes, and the padding.
	 *
	 * @param string $data    The text.
	 * @param int    $version The version.
	 * @param string $level   The level.
	 *
	 * @return int[]
	 * @since 1.0.0
	 */
	private static function codewords( string $data, int $version, string $level ): array {
		$capacity = Tables::data_codewords( $version, $level );
		$length   = strlen( $data );
		$bits     = '0100' . str_pad( decbin( $length ), $version < 10 ? 8 : 16, '0', STR_PAD_LEFT );

		for ( $i = 0; $i < $length; $i++ ) {
			$bits .= str_pad( decbin( ord( $data[ $i ] ) ), 8, '0', STR_PAD_LEFT );
		}

		// The terminator: up to four noughts, fewer if there is no room.
		$bits .= str_repeat( '0', min( 4, 8 * $capacity - strlen( $bits ) ) );

		// Up to the next whole byte.
		$bits .= str_repeat( '0', ( 8 - strlen( $bits ) % 8 ) % 8 );

		$out = array_map( 'bindec', str_split( $bits, 8 ) );

		// And the two bytes the standard pads with, turn about, to the end.
		for ( $pad = 0xEC; count( $out ) < $capacity; $pad ^= 0xEC ^ 0x11 ) {
			$out[] = $pad;
		}

		return $out;
	}

	/**
	 * The data split into blocks, each given its correction, and shuffled together.
	 *
	 * A large code is several Reed–Solomon blocks, so that a smudge in one
	 * place damages a little of each rather than all of one; the codewords
	 * are dealt out in turn, one from each block, for the same reason.
	 *
	 * @param int[]  $data    The data codewords.
	 * @param int    $version The version.
	 * @param string $level   The level.
	 *
	 * @return int[] Every codeword, in the order they are drawn.
	 * @since 1.0.0
	 */
	private static function interleave( array $data, int $version, string $level ): array {
		$count   = Tables::blocks( $version, $level );
		$ecc     = Tables::correction( $version, $level );
		$total   = intdiv( Tables::raw_modules( $version ), 8 );
		$short   = $count - $total % $count;
		$length  = intdiv( $total, $count );
		$divisor = ReedSolomon::divisor( $ecc );
		$blocks  = [];
		$at      = 0;

		for ( $i = 0; $i < $count; $i++ ) {
			$take  = $length - $ecc + ( $i < $short ? 0 : 1 );
			$block = array_slice( $data, $at, $take );
			$at   += $take;

			$blocks[] = [ $block, ReedSolomon::remainder( $block, $divisor ) ];
		}

		$out = [];

		// The data, a codeword from each block in turn. The short blocks
		// run out one before the long ones.
		for ( $i = 0; $i <= $length - $ecc; $i++ ) {
			foreach ( $blocks as [ $block ] ) {
				if ( isset( $block[ $i ] ) ) {
					$out[] = $block[ $i ];
				}
			}
		}

		// Then the correction, the same way.
		for ( $i = 0; $i < $ecc; $i++ ) {
			foreach ( $blocks as [ , $remainder ] ) {
				$out[] = $remainder[ $i ];
			}
		}

		return $out;
	}

	/**
	 * Draw everything that is not data.
	 *
	 * @param array<int, array<int, bool>> $modules  The modules.
	 * @param array<int, array<int, bool>> $reserved Which are spoken for.
	 * @param int                          $version  The version.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private static function furniture( array &$modules, array &$reserved, int $version ): void {
		$size = self::size( $version );
		$set  = static function ( int $x, int $y, bool $dark ) use ( &$modules, &$reserved, $size ): void {
			if ( $x >= 0 && $x < $size && $y >= 0 && $y < $size ) {
				$modules[ $y ][ $x ]  = $dark;
				$reserved[ $y ][ $x ] = true;
			}
		};

		// The timing lines: alternate modules along row and column six,
		// which is how a reader counts the grid.
		for ( $i = 0; $i < $size; $i++ ) {
			$set( 6, $i, 0 === $i % 2 );
			$set( $i, 6, 0 === $i % 2 );
		}

		// The three finders, each with its white border.
		foreach ( [ [ 3, 3 ], [ $size - 4, 3 ], [ 3, $size - 4 ] ] as [ $cx, $cy ] ) {
			for ( $dy = -4; $dy <= 4; $dy++ ) {
				for ( $dx = -4; $dx <= 4; $dx++ ) {
					$ring = max( abs( $dx ), abs( $dy ) );

					$set( $cx + $dx, $cy + $dy, 2 !== $ring && 4 !== $ring );
				}
			}
		}

		// The small alignment squares, everywhere but on a finder.
		$positions = Tables::alignment( $version );
		$last      = count( $positions ) - 1;

		foreach ( $positions as $i => $cy ) {
			foreach ( $positions as $j => $cx ) {
				if ( ( 0 === $i && 0 === $j ) || ( 0 === $i && $last === $j ) || ( $last === $i && 0 === $j ) ) {
					continue;
				}

				for ( $dy = -2; $dy <= 2; $dy++ ) {
					for ( $dx = -2; $dx <= 2; $dx++ ) {
						$set( $cx + $dx, $cy + $dy, 1 !== max( abs( $dx ), abs( $dy ) ) );
					}
				}
			}
		}

		// Where the format bits go: reserved now, written once a mask is
		// chosen. And the one module that is always dark.
		for ( $i = 0; $i <= 8; $i++ ) {
			$reserved[ $i ][8] = true;
			$reserved[8][ $i ] = true;
		}

		for ( $i = 0; $i < 8; $i++ ) {
			$reserved[8][ $size - 1 - $i ] = true;
			$reserved[ $size - 1 - $i ][8] = true;
		}

		$set( 8, $size - 8, true );

		// From version seven a code says which version it is, twice.
		if ( $version >= 7 ) {
			$bits = Tables::version_bits( $version );

			for ( $i = 0; $i < 18; $i++ ) {
				$dark = 1 === ( ( $bits >> $i ) & 1 );
				$a    = $size - 11 + $i % 3;
				$b    = intdiv( $i, 3 );

				$set( $a, $b, $dark );
				$set( $b, $a, $dark );
			}
		}
	}

	/**
	 * Lay the codewords into the modules that are free.
	 *
	 * In pairs of columns from the right, snaking up and then down, as the
	 * standard has it; the column the vertical timing line is in is
	 * stepped over.
	 *
	 * @param array<int, array<int, bool>> $modules   The modules.
	 * @param array<int, array<int, bool>> $reserved  Which are spoken for.
	 * @param int[]                        $codewords Every codeword.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private static function place( array &$modules, array $reserved, array $codewords ): void {
		$size  = count( $modules );
		$total = count( $codewords ) * 8;
		$i     = 0;

		for ( $right = $size - 1; $right >= 1; $right -= 2 ) {
			if ( 6 === $right ) {
				$right = 5;
			}

			for ( $step = 0; $step < $size; $step++ ) {
				for ( $j = 0; $j < 2; $j++ ) {
					$x = $right - $j;
					$y = 0 === ( ( $right + 1 ) & 2 ) ? $size - 1 - $step : $step;

					if ( $reserved[ $y ][ $x ] || $i >= $total ) {
						continue;
					}

					$modules[ $y ][ $x ] = 1 === ( ( $codewords[ $i >> 3 ] >> ( 7 - ( $i & 7 ) ) ) & 1 );

					++$i;
				}
			}
		}
	}

	/**
	 * The modules with one of the eight masks applied to the data.
	 *
	 * A mask flips modules in a regular pattern so the code does not come
	 * out with large blank areas or with shapes that look like a finder,
	 * either of which a reader trips over.
	 *
	 * @param array<int, array<int, bool>> $modules  The modules.
	 * @param array<int, array<int, bool>> $reserved Which are spoken for.
	 * @param int                          $mask     Which mask, 0 to 7.
	 *
	 * @return array<int, array<int, bool>>
	 * @since 1.0.0
	 */
	private static function mask( array $modules, array $reserved, int $mask ): array {
		foreach ( $modules as $y => $row ) {
			foreach ( $row as $x => $dark ) {
				if ( $reserved[ $y ][ $x ] ) {
					continue;
				}

				$flip = match ( $mask ) {
					0 => 0 === ( $x + $y ) % 2,
					1 => 0 === $y % 2,
					2 => 0 === $x % 3,
					3 => 0 === ( $x + $y ) % 3,
					4 => 0 === ( intdiv( $x, 3 ) + intdiv( $y, 2 ) ) % 2,
					5 => 0 === $x * $y % 2 + $x * $y % 3,
					6 => 0 === ( $x * $y % 2 + $x * $y % 3 ) % 2,
					default => 0 === ( ( $x + $y ) % 2 + $x * $y % 3 ) % 2,
				};

				if ( $flip ) {
					$modules[ $y ][ $x ] = ! $dark;
				}
			}
		}

		return $modules;
	}

	/**
	 * Write the format bits: the level and the mask, twice.
	 *
	 * @param array<int, array<int, bool>> $modules The modules.
	 * @param string                       $level   The level.
	 * @param int                          $mask    The mask.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private static function format( array &$modules, string $level, int $mask ): void {
		$size = count( $modules );
		$bits = Tables::format_bits( $level, $mask );
		$bit  = static fn( int $i ): bool => 1 === ( ( $bits >> $i ) & 1 );

		// Around the top-left finder.
		for ( $i = 0; $i <= 5; $i++ ) {
			$modules[ $i ][8] = $bit( $i );
		}

		$modules[7][8] = $bit( 6 );
		$modules[8][8] = $bit( 7 );
		$modules[8][7] = $bit( 8 );

		for ( $i = 9; $i < 15; $i++ ) {
			$modules[8][ 14 - $i ] = $bit( $i );
		}

		// And again, split between the other two.
		for ( $i = 0; $i < 8; $i++ ) {
			$modules[8][ $size - 1 - $i ] = $bit( $i );
		}

		for ( $i = 8; $i < 15; $i++ ) {
			$modules[ $size - 15 + $i ][8] = $bit( $i );
		}
	}

	/**
	 * How badly a masked code would read, by the standard's four rules.
	 *
	 * @param array<int, array<int, bool>> $modules The modules.
	 *
	 * @return int Lower is better.
	 * @since 1.0.0
	 */
	private static function penalty( array $modules ): int {
		$size    = count( $modules );
		$penalty = 0;
		$dark    = 0;

		// Rows, then columns: the same two rules along each line.
		foreach ( [ false, true ] as $turned ) {
			for ( $a = 0; $a < $size; $a++ ) {
				$line = '';

				for ( $b = 0; $b < $size; $b++ ) {
					$module = $turned ? $modules[ $b ][ $a ] : $modules[ $a ][ $b ];
					$line  .= $module ? '1' : '0';
				}

				// Five or more of one colour in a row.
				if ( preg_match_all( '/0{5,}|1{5,}/', $line, $runs ) ) {
					foreach ( $runs[0] as $run ) {
						$penalty += 3 + strlen( $run ) - 5;
					}
				}

				// Anything that looks like a finder seen edge-on.
				$penalty += 40 * ( substr_count( $line, '10111010000' ) + substr_count( $line, '00001011101' ) );

				if ( ! $turned ) {
					$dark += substr_count( $line, '1' );
				}
			}
		}

		// Two-by-two squares of one colour.
		for ( $y = 0; $y < $size - 1; $y++ ) {
			for ( $x = 0; $x < $size - 1; $x++ ) {
				$colour   = $modules[ $y ][ $x ];
				$right    = $modules[ $y ][ $x + 1 ];
				$below    = $modules[ $y + 1 ][ $x ];
				$diagonal = $modules[ $y + 1 ][ $x + 1 ];

				if ( $colour === $right && $colour === $below && $colour === $diagonal ) {
					$penalty += 3;
				}
			}
		}

		// How far from half dark the whole thing is, in steps of 5%.
		$total    = $size * $size;
		$penalty += 10 * (int) ( ( ceil( abs( $dark * 20 - $total * 10 ) / $total ) ) - 1 );

		return $penalty;
	}
}
