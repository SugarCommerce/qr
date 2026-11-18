<?php
/**
 * The error correction in a QR code.
 *
 * @package     SugarCommerce\Qr
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Qr;

/**
 * Reed–Solomon over the field QR codes use.
 *
 * The correction codewords of a block are the remainder left when the
 * block's data, read as a polynomial, is divided by a generator polynomial
 * of the wanted length. The arithmetic is in GF(256) with the polynomial
 * x^8 + x^4 + x^3 + x^2 + 1 (0x11D), where adding is XOR and multiplying is
 * the shift-and-reduce in multiply().
 *
 * @since 1.0.0
 */
final class ReedSolomon {

	/**
	 * The generator polynomial for a number of correction codewords.
	 *
	 * @param int $degree How many correction codewords.
	 *
	 * @return int[] Its coefficients, highest power first, without the leading 1.
	 * @since 1.0.0
	 */
	public static function divisor( int $degree ): array {
		$result                = array_fill( 0, $degree, 0 );
		$result[ $degree - 1 ] = 1;
		$root                  = 1;

		// Multiply by (x - r^0), (x - r^1) … in turn, r being 2.
		for ( $i = 0; $i < $degree; $i++ ) {
			for ( $j = 0; $j < $degree; $j++ ) {
				$result[ $j ] = self::multiply( $result[ $j ], $root );

				if ( $j + 1 < $degree ) {
					$result[ $j ] ^= $result[ $j + 1 ];
				}
			}

			$root = self::multiply( $root, 2 );
		}

		return $result;
	}

	/**
	 * The correction codewords for a block of data.
	 *
	 * @param int[] $data    The block.
	 * @param int[] $divisor What divisor() made.
	 *
	 * @return int[]
	 * @since 1.0.0
	 */
	public static function remainder( array $data, array $divisor ): array {
		$result = array_fill( 0, count( $divisor ), 0 );

		foreach ( $data as $byte ) {
			$factor   = $byte ^ array_shift( $result );
			$result[] = 0;

			foreach ( $divisor as $i => $coefficient ) {
				$result[ $i ] ^= self::multiply( $coefficient, $factor );
			}
		}

		return $result;
	}

	/**
	 * Multiply two elements of the field.
	 *
	 * @param int $x One.
	 * @param int $y The other.
	 *
	 * @return int
	 * @since 1.0.0
	 */
	public static function multiply( int $x, int $y ): int {
		$z = 0;

		for ( $i = 7; $i >= 0; $i-- ) {
			$z  = ( $z << 1 ) ^ ( ( $z >> 7 ) * 0x11D );
			$z ^= ( ( $y >> $i ) & 1 ) * $x;
		}

		return $z;
	}
}
