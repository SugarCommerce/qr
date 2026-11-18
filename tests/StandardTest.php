<?php
/**
 * What the standard fixes about every code.
 *
 * @package SugarCommerce\Qr
 */

declare( strict_types=1 );

namespace SugarCommerce\Qr\Tests;

use SugarCommerce\Qr\QR;
use SugarCommerce\Qr\ReedSolomon;
use SugarCommerce\Qr\Tables;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * What can be checked about a QR code without a camera.
 *
 * Whether a code reads is proved by reading it, or by matching an encoder
 * that is known to be right (ReferenceTest). These are the properties the
 * standard fixes, which hold for any text at any level.
 */
final class StandardTest extends TestCase {

	/**
	 * A code is square and as large as its version says.
	 */
	public function test_the_size_follows_the_version(): void {
		foreach ( [ 1 => 5, 2 => 20, 4 => 60, 13 => 300, 40 => 2331 ] as $version => $bytes ) {
			$matrix = QR::matrix( str_repeat( 'a', $bytes ) );

			$this->assertSame( $version, QR::version( $matrix ) );
			$this->assertCount( 17 + 4 * $version, $matrix );

			foreach ( $matrix as $row ) {
				$this->assertCount( 17 + 4 * $version, $row );
			}
		}
	}

	/**
	 * The three finders are where a reader looks for them, whole.
	 */
	public function test_the_finders_are_in_three_corners(): void {
		$matrix = QR::matrix( 'https://example.com/a-longer-address-than-the-smallest-code-holds' );
		$size   = count( $matrix );
		$finder = [ '1111111', '1000001', '1011101', '1011101', '1011101', '1000001', '1111111' ];

		foreach ( [ [ 0, 0 ], [ $size - 7, 0 ], [ 0, $size - 7 ] ] as [ $left, $top ] ) {
			for ( $y = 0; $y < 7; $y++ ) {
				$row = '';

				for ( $x = 0; $x < 7; $x++ ) {
					$row .= $matrix[ $top + $y ][ $left + $x ] ? '1' : '0';
				}

				$this->assertSame( $finder[ $y ], $row );
			}
		}

		// The fourth corner has none, and the one module that is always dark is.
		$this->assertTrue( $matrix[ $size - 8 ][8] );

		// The timing line alternates between the finders.
		for ( $i = 8; $i < $size - 8; $i++ ) {
			$this->assertSame( 0 === $i % 2, $matrix[6][ $i ] );
			$this->assertSame( 0 === $i % 2, $matrix[ $i ][6] );
		}
	}

	/**
	 * The format bits are a valid codeword, name the level, and are written twice the same.
	 */
	public function test_the_format_bits_check_out(): void {
		foreach ( [ 'L' => 1, 'M' => 0, 'Q' => 3, 'H' => 2 ] as $level => $named ) {
			$matrix = QR::matrix( 'myapp://connect?d=abc', $level );
			$size   = count( $matrix );
			$first  = 0;
			$second = 0;
			$one    = [];
			$two    = [];

			for ( $i = 0; $i <= 5; $i++ ) {
				$one[] = $matrix[ $i ][8];
			}

			$one[] = $matrix[7][8];
			$one[] = $matrix[8][8];
			$one[] = $matrix[8][7];

			for ( $i = 9; $i < 15; $i++ ) {
				$one[] = $matrix[8][ 14 - $i ];
			}

			for ( $i = 0; $i < 8; $i++ ) {
				$two[] = $matrix[8][ $size - 1 - $i ];
			}

			for ( $i = 8; $i < 15; $i++ ) {
				$two[] = $matrix[ $size - 15 + $i ][8];
			}

			foreach ( $one as $i => $bit ) {
				$first |= ( $bit ? 1 : 0 ) << $i;
			}

			foreach ( $two as $i => $bit ) {
				$second |= ( $bit ? 1 : 0 ) << $i;
			}

			$this->assertSame( $first, $second, $level );

			$plain = $first ^ 0x5412;

			$this->assertSame( $named, $plain >> 13, $level );

			// A codeword of the (15,5) BCH code leaves nothing when divided by its generator.
			$remainder = $plain;

			for ( $i = 14; $i >= 10; $i-- ) {
				if ( ( $remainder >> $i ) & 1 ) {
					$remainder ^= 0x537 << ( $i - 10 );
				}
			}

			$this->assertSame( 0, $remainder, $level );
			$this->assertSame( $first, Tables::format_bits( $level, ( $plain >> 10 ) & 7 ), $level );
			$this->assertSame( $level, QR::level( $matrix ) );
		}
	}

	/**
	 * From version seven the version is written twice, and both copies say the same.
	 */
	public function test_the_version_bits_are_written_twice(): void {
		$matrix = QR::matrix( str_repeat( 'v', 200 ), 'Q' );
		$size   = count( $matrix );
		$right  = 0;
		$below  = 0;

		$this->assertGreaterThanOrEqual( 7, QR::version( $matrix ) );

		for ( $i = 0; $i < 18; $i++ ) {
			$a = $size - 11 + $i % 3;
			$b = intdiv( $i, 3 );

			$right |= ( $matrix[ $b ][ $a ] ? 1 : 0 ) << $i;
			$below |= ( $matrix[ $a ][ $b ] ? 1 : 0 ) << $i;
		}

		$this->assertSame( $right, $below );
		$this->assertSame( Tables::version_bits( QR::version( $matrix ) ), $right );
	}

	/**
	 * The numbers that are worked out agree with the ones the standard prints.
	 */
	public function test_the_tables_agree_with_the_standard(): void {
		// Version information for version 7, from the standard's own example.
		$this->assertSame( 0x07C94, Tables::version_bits( 7 ) );

		// Format information for level M, mask 0: 101010000010010.
		$this->assertSame( 0b101010000010010, Tables::format_bits( 'M', 0 ) );

		// And for the other three levels at mask 0.
		$this->assertSame( 0b111011111000100, Tables::format_bits( 'L', 0 ) );
		$this->assertSame( 0b011010101011111, Tables::format_bits( 'Q', 0 ) );
		$this->assertSame( 0b001011010001001, Tables::format_bits( 'H', 0 ) );

		$this->assertSame( [], Tables::alignment( 1 ) );
		$this->assertSame( [ 6, 18 ], Tables::alignment( 2 ) );
		$this->assertSame( [ 6, 22, 38 ], Tables::alignment( 7 ) );
		$this->assertSame( [ 6, 34, 60, 86, 112, 138 ], Tables::alignment( 32 ) );
		$this->assertSame( [ 6, 30, 58, 86, 114, 142, 170 ], Tables::alignment( 40 ) );

		// Data codewords, from the capacity table.
		$this->assertSame( 19, Tables::data_codewords( 1, 'L' ) );
		$this->assertSame( 16, Tables::data_codewords( 1, 'M' ) );
		$this->assertSame( 13, Tables::data_codewords( 1, 'Q' ) );
		$this->assertSame( 9, Tables::data_codewords( 1, 'H' ) );
		$this->assertSame( 124, Tables::data_codewords( 7, 'M' ) );
		$this->assertSame( 2956, Tables::data_codewords( 40, 'L' ) );
		$this->assertSame( 2334, Tables::data_codewords( 40, 'M' ) );
		$this->assertSame( 1666, Tables::data_codewords( 40, 'Q' ) );
		$this->assertSame( 1276, Tables::data_codewords( 40, 'H' ) );
	}

	/**
	 * The correction codewords are the ones the standard's worked example gets.
	 */
	public function test_reed_solomon_matches_the_worked_example(): void {
		// "HELLO WORLD" at 1-M, as every tutorial on the subject encodes it.
		$data = [ 32, 91, 11, 120, 209, 114, 220, 77, 67, 64, 236, 17, 236, 17, 236, 17 ];

		$this->assertSame(
			[ 196, 35, 39, 119, 235, 215, 231, 226, 93, 23 ],
			ReedSolomon::remainder( $data, ReedSolomon::divisor( 10 ) )
		);
	}

	/**
	 * The field's arithmetic: 2 to the 8th wraps round by the QR polynomial.
	 */
	public function test_the_field_is_the_one_qr_codes_use(): void {
		$this->assertSame( 0x1D, ReedSolomon::multiply( 0x80, 2 ) );
		$this->assertSame( 1, ReedSolomon::multiply( 1, 1 ) );
		$this->assertSame( 0, ReedSolomon::multiply( 0, 0xFF ) );
		$this->assertSame( 0xFF, ReedSolomon::multiply( 0xFF, 1 ) );
	}

	/**
	 * Something too long is refused, and the longest that fits is not.
	 */
	public function test_the_longest_that_fits_and_one_more(): void {
		$this->assertSame( 40, QR::version( QR::matrix( str_repeat( 'x', 2953 ) ) ) );

		$this->expectException( InvalidArgumentException::class );

		QR::matrix( str_repeat( 'x', 2954 ) );
	}
}
