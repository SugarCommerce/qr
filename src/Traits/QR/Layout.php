<?php
/**
 * Laying the codewords out as modules.
 *
 * @package     SugarCommerce\Qr
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Qr\Traits\QR;

use SugarCommerce\Qr\Tables;

/**
 * The finders, timing lines and alignment squares, the codewords snaked
 * between them, the eight masks, the format bits, and the standard's score
 * for how well a masked code would read.
 *
 * @since 1.0.0
 */
trait Layout {

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
