<?php
/**
 * Turning the text into codewords.
 *
 * @package     SugarCommerce\Qr
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Qr\Traits\QR;

use InvalidArgumentException;
use SugarCommerce\Qr\ReedSolomon;
use SugarCommerce\Qr\Tables;

/**
 * The size and level a text needs, its bytes as data codewords, and the
 * correction added to them block by block.
 *
 * @since 1.0.0
 */
trait Encoding {

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
}
