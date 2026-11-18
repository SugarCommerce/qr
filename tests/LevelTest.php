<?php
/**
 * Error correction: the default, asking for a level, and what each holds.
 *
 * @package SugarCommerce\Qr
 */

declare( strict_types=1 );

namespace SugarCommerce\Qr\Tests;

use SugarCommerce\Qr\QR;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Class LevelTest
 */
final class LevelTest extends TestCase {

	/**
	 * Bytes of text each version holds at each level, from the standard's capacity table.
	 */
	private const CAPACITY = [
		'L' => [ 17, 32, 53, 78, 106, 134, 154, 192, 230, 271, 321, 367, 425, 458, 520, 586, 644, 718, 792, 858, 929, 1003, 1091, 1171, 1273, 1367, 1465, 1528, 1628, 1732, 1840, 1952, 2068, 2188, 2303, 2431, 2563, 2699, 2809, 2953 ],
		'M' => [ 14, 26, 42, 62, 84, 106, 122, 152, 180, 213, 251, 287, 331, 362, 412, 450, 504, 560, 624, 666, 711, 779, 857, 911, 997, 1059, 1125, 1190, 1264, 1370, 1452, 1538, 1628, 1722, 1809, 1911, 1989, 2099, 2213, 2331 ],
		'Q' => [ 11, 20, 32, 46, 60, 74, 86, 108, 130, 151, 177, 203, 241, 258, 292, 322, 364, 394, 442, 482, 509, 565, 611, 661, 715, 751, 805, 868, 908, 982, 1030, 1112, 1168, 1228, 1283, 1351, 1423, 1499, 1579, 1663 ],
		'H' => [ 7, 14, 24, 34, 44, 58, 64, 84, 98, 119, 137, 155, 177, 194, 220, 250, 280, 310, 338, 382, 403, 439, 461, 511, 535, 593, 625, 658, 698, 742, 790, 842, 898, 958, 983, 1051, 1093, 1139, 1219, 1273 ],
	];

	/**
	 * capacity() is the standard's table, every version at every level.
	 */
	public function test_capacity_is_the_standards_table(): void {
		foreach ( self::CAPACITY as $level => $bytes ) {
			foreach ( $bytes as $i => $expected ) {
				$this->assertSame( $expected, QR::capacity( $level, $i + 1 ), "$level-" . ( $i + 1 ) );
			}
		}

		$this->assertSame( 2331, QR::capacity() );
	}

	/**
	 * A code is the smallest version that holds the text: the most a version holds stays in it, one more does not.
	 */
	public function test_the_smallest_version_that_holds_the_text(): void {
		foreach ( self::CAPACITY as $level => $bytes ) {
			foreach ( [ 1, 9, 10, 27 ] as $version ) {
				$most = $bytes[ $version - 1 ];

				$this->assertSame( $version, QR::version( QR::matrix( str_repeat( 'q', $most ), $level ) ), "$level-$version" );
				$this->assertSame( $version + 1, QR::version( QR::matrix( str_repeat( 'q', $most + 1 ), $level ) ), "$level-$version + 1" );
			}
		}
	}

	/**
	 * With no level asked for, M, and L only when M cannot hold the text.
	 */
	public function test_the_default_is_m_then_l(): void {
		$this->assertSame( 'M', QR::level( QR::matrix( 'HELLO' ) ) );
		$this->assertSame( 'M', QR::level( QR::matrix( str_repeat( 'm', 2331 ) ) ) );
		$this->assertSame( 'L', QR::level( QR::matrix( str_repeat( 'm', 2332 ) ) ) );
	}

	/**
	 * A level asked for is the level used, even where another would be smaller.
	 */
	public function test_a_level_asked_for_is_kept(): void {
		foreach ( [ 'L', 'M', 'Q', 'H' ] as $level ) {
			$this->assertSame( $level, QR::level( QR::matrix( 'HELLO', $level ) ) );
		}

		// Lower case and stray spaces are the same level.
		$this->assertSame( 'H', QR::level( QR::matrix( 'HELLO', ' h ' ) ) );

		// 14 bytes need version 2 at H, where M holds them in version 1.
		$this->assertSame( 1, QR::version( QR::matrix( str_repeat( 'b', 14 ) ) ) );
		$this->assertSame( 2, QR::version( QR::matrix( str_repeat( 'b', 14 ), 'H' ) ) );
	}

	/**
	 * A level asked for does not quietly fall back to a weaker one.
	 */
	public function test_a_level_asked_for_does_not_fall_back(): void {
		$this->expectException( InvalidArgumentException::class );

		QR::matrix( str_repeat( 'm', 2332 ), 'M' );
	}

	/**
	 * Too long for H is refused.
	 */
	public function test_too_long_for_h_is_refused(): void {
		$this->expectException( InvalidArgumentException::class );

		QR::matrix( str_repeat( 'h', 1274 ), 'H' );
	}

	/**
	 * A level that is not one of the four is refused, not guessed at.
	 */
	public function test_an_unknown_level_is_refused(): void {
		$this->expectException( InvalidArgumentException::class );

		QR::matrix( 'HELLO', 'X' );
	}

	/**
	 * capacity() refuses a version there is not.
	 */
	public function test_capacity_refuses_a_version_there_is_not(): void {
		$this->expectException( InvalidArgumentException::class );

		QR::capacity( 'M', 41 );
	}

	/**
	 * Nor version nought.
	 */
	public function test_capacity_refuses_version_nought(): void {
		$this->expectException( InvalidArgumentException::class );

		QR::capacity( 'M', 0 );
	}
}
