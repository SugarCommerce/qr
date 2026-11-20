<?php
/**
 * The numbers the QR standard gives as tables.
 *
 * @package     SugarCommerce\Qr
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Qr;

/**
 * What cannot be worked out and has to be looked up, and what can.
 *
 * Two tables are copied from ISO/IEC 18004: for each of the forty versions,
 * at each of the four levels, how many correction codewords a block carries
 * and how many blocks there are. Everything else -- how many modules are
 * free for data, where the alignment squares sit, the checked format and
 * version bits -- follows from the version by arithmetic, and is worked
 * out, because a formula cannot have a typing mistake in its thirty-first
 * entry.
 *
 * @since 1.0.0
 */
final class Tables {

	/**
	 * Correction codewords in each block, by level, for versions 1 to 40.
	 *
	 * @since 1.0.0
	 */
	private const array CORRECTION = [
		'L' => [ 7, 10, 15, 20, 26, 18, 20, 24, 30, 18, 20, 24, 26, 30, 22, 24, 28, 30, 28, 28, 28, 28, 30, 30, 26, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30 ],
		'M' => [ 10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26, 26, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28 ],
		'Q' => [ 13, 22, 18, 26, 18, 24, 18, 22, 20, 24, 28, 26, 24, 20, 30, 24, 28, 28, 26, 30, 28, 30, 30, 30, 30, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30 ],
		'H' => [ 17, 28, 22, 16, 22, 28, 26, 26, 24, 28, 24, 28, 22, 24, 24, 30, 28, 28, 26, 28, 30, 24, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30 ],
	];

	/**
	 * How many blocks, by level, for versions 1 to 40.
	 *
	 * @since 1.0.0
	 */
	private const array BLOCKS = [
		'L' => [ 1, 1, 1, 1, 1, 2, 2, 2, 2, 4, 4, 4, 4, 4, 6, 6, 6, 6, 7, 8, 8, 9, 9, 10, 12, 12, 12, 13, 14, 15, 16, 17, 18, 19, 19, 20, 21, 22, 24, 25 ],
		'M' => [ 1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10, 10, 11, 13, 14, 16, 17, 17, 18, 20, 21, 23, 25, 26, 28, 29, 31, 33, 35, 37, 38, 40, 43, 45, 47, 49 ],
		'Q' => [ 1, 1, 2, 2, 4, 4, 6, 6, 8, 8, 8, 10, 12, 16, 12, 17, 16, 18, 21, 20, 23, 23, 25, 27, 29, 34, 34, 35, 38, 40, 43, 45, 48, 51, 53, 56, 59, 62, 65, 68 ],
		'H' => [ 1, 1, 2, 4, 4, 4, 5, 6, 8, 8, 11, 11, 16, 16, 18, 16, 19, 21, 25, 25, 25, 34, 30, 32, 35, 37, 40, 42, 45, 48, 51, 54, 57, 60, 63, 66, 70, 74, 77, 81 ],
	];

	/**
	 * The two bits that name a level in the format information.
	 *
	 * Not in the order of strength: the standard numbers them M, L, H, Q.
	 *
	 * @since 1.0.0
	 */
	private const array LEVEL_BITS = [
		'L' => 1,
		'M' => 0,
		'Q' => 3,
		'H' => 2,
	];

	/**
	 * The levels, weakest first.
	 *
	 * @return string[]
	 * @since 1.0.0
	 */
	public static function levels(): array {
		return [ 'L', 'M', 'Q', 'H' ];
	}

	/**
	 * The level two format bits name.
	 *
	 * @param int $bits The two bits, 0 to 3.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public static function level_named( int $bits ): string {
		return (string) array_search( $bits & 3, self::LEVEL_BITS, true );
	}

	/**
	 * Correction codewords in each block.
	 *
	 * @param int    $version The version.
	 * @param string $level   The level.
	 *
	 * @return int
	 * @since 1.0.0
	 */
	public static function correction( int $version, string $level ): int {
		return self::CORRECTION[ $level ][ $version - 1 ];
	}

	/**
	 * How many blocks the codewords are split into.
	 *
	 * @param int    $version The version.
	 * @param string $level   The level.
	 *
	 * @return int
	 * @since 1.0.0
	 */
	public static function blocks( int $version, string $level ): int {
		return self::BLOCKS[ $level ][ $version - 1 ];
	}

	/**
	 * How many modules are left for codewords once the furniture is drawn.
	 *
	 * @param int $version The version.
	 *
	 * @return int
	 * @since 1.0.0
	 */
	public static function raw_modules( int $version ): int {
		$modules = ( 16 * $version + 128 ) * $version + 64;

		if ( $version >= 2 ) {
			$squares  = intdiv( $version, 7 ) + 2;
			$modules -= ( 25 * $squares - 10 ) * $squares - 55;

			if ( $version >= 7 ) {
				$modules -= 36;
			}
		}

		return $modules;
	}

	/**
	 * How many codewords of data a version holds at a level.
	 *
	 * @param int    $version The version.
	 * @param string $level   The level.
	 *
	 * @return int
	 * @since 1.0.0
	 */
	public static function data_codewords( int $version, string $level ): int {
		return intdiv( self::raw_modules( $version ), 8 ) - self::correction( $version, $level ) * self::blocks( $version, $level );
	}

	/**
	 * The rows, and the columns, the alignment squares are centred on.
	 *
	 * @param int $version The version.
	 *
	 * @return int[] Ascending. Empty for version 1, which has none.
	 * @since 1.0.0
	 */
	public static function alignment( int $version ): array {
		if ( 1 === $version ) {
			return [];
		}

		$count = intdiv( $version, 7 ) + 2;
		$step  = 32 === $version ? 26 : (int) ceil( ( $version * 4 + 4 ) / ( $count * 2 - 2 ) ) * 2;
		$out   = [ 6 ];

		for ( $position = 17 + 4 * $version - 7; count( $out ) < $count; $position -= $step ) {
			array_splice( $out, 1, 0, [ $position ] );
		}

		return $out;
	}

	/**
	 * The fifteen format bits: level and mask, with their check bits.
	 *
	 * Five bits of information protected by ten of a BCH code, then the
	 * whole XORed with a fixed pattern so it is never all noughts.
	 *
	 * @param string $level The level.
	 * @param int    $mask  The mask.
	 *
	 * @return int
	 * @since 1.0.0
	 */
	public static function format_bits( string $level, int $mask ): int {
		$data      = ( self::LEVEL_BITS[ $level ] << 3 ) | $mask;
		$remainder = $data;

		for ( $i = 0; $i < 10; $i++ ) {
			$remainder = ( $remainder << 1 ) ^ ( ( $remainder >> 9 ) * 0x537 );
		}

		return ( ( $data << 10 ) | $remainder ) ^ 0x5412;
	}

	/**
	 * The eighteen version bits: the version, with its check bits.
	 *
	 * @param int $version The version, 7 or more.
	 *
	 * @return int
	 * @since 1.0.0
	 */
	public static function version_bits( int $version ): int {
		$remainder = $version;

		for ( $i = 0; $i < 12; $i++ ) {
			$remainder = ( $remainder << 1 ) ^ ( ( $remainder >> 11 ) * 0x1F25 );
		}

		return ( $version << 12 ) | $remainder;
	}
}
