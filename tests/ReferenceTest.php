<?php
/**
 * Codes pinned module for module to ones known to be right.
 *
 * @package SugarCommerce\Qr
 */

declare( strict_types=1 );

namespace SugarCommerce\Qr\Tests;

use SugarCommerce\Qr\QR;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Known vectors.
 *
 * Every fingerprint here is the SHA-1 of a whole code, rows of 1s and 0s
 * joined by newlines. They came from Project Nayuki's reference encoder
 * (qrcodegen 1.8), asked for the same text in byte mode at the same level,
 * version and mask, with no level boosting. The mask an encoder picks is a
 * free choice scored by the standard's penalty rules, and two encoders may
 * break a near-tie differently, so the mask is given to the reference; the
 * version is not, and both chose the same one every time.
 *
 * HELLO at the default level was also read back by a camera decoder.
 */
final class ReferenceTest extends TestCase {

	/**
	 * The codes the reference encoder draws.
	 *
	 * @return array<string, array{0: string, 1: ?string, 2: string, 3: int, 4: int, 5: string}>
	 */
	public static function vectors(): array {
		return [
			'HELLO, default'      => [ 'HELLO', null, 'M', 1, 4, '5fb0fa7a5101d9e966d80495e2189b2ffcdf3735' ],
			'HELLO, L'            => [ 'HELLO', 'L', 'L', 1, 3, 'b1c5a0950ef133478a4e00ac640d721985970761' ],
			'HELLO, Q'            => [ 'HELLO', 'Q', 'Q', 1, 4, '7b9c66c23c9e49b5bfa49eb7b99ec94411e11b1e' ],
			'HELLO, H'            => [ 'HELLO', 'H', 'H', 1, 4, '5d43074c7572810ad0b6166936bd40f058816061' ],
			'a URL, Q'            => [ 'https://example.com/', 'Q', 'Q', 2, 4, '92c0f435eae1ce88d7b82465af3360865bc02c25' ],
			'a URL, H'            => [ 'https://example.com/', 'H', 'H', 3, 3, 'f994b0e31c17ba5373d11b9cfba860bd071df11c' ],
			'a TOTP enrolment, Q' => [ 'otpauth://totp/Example:alice@example.com?secret=JBSWY3DPEHPK3PXP&issuer=Example', 'Q', 'Q', 7, 2, '97ff4f4e82709364bc928cd042497c981f7f8b4e' ],
			'UTF-8, default'      => [ 'Grüße, 東京 ✓', null, 'M', 2, 7, '48fee70b205f26d85eb09267f41f2cbf7f942826' ],
			'UTF-8, H'            => [ 'Grüße, 東京 ✓', 'H', 'H', 3, 1, '1d9c142600a418a266bf8d350908b316bcc86961' ],
			'digits, L'           => [ str_repeat( '0123456789', 15 ), 'L', 'L', 7, 2, '79bac9e5c324d332fe9392ae1420286e2d19382f' ],
			'100 bytes, Q'        => [ str_repeat( 'x', 100 ), 'Q', 'Q', 8, 0, 'cf5c37cc955b19ce2de458deabf7983cf1dd6593' ],
			'300 bytes, H'        => [ str_repeat( 'x', 300 ), 'H', 'H', 18, 0, '790b96a0560e7124778e1b118360f957dc15c693' ],
			'1000 bytes, Q'       => [ str_repeat( 'y', 1000 ), 'Q', 'Q', 31, 1, '7d85b1177323ce611ecb5878a721951e7957d22a' ],
			'the most H holds'    => [ str_repeat( 'z', 1273 ), 'H', 'H', 40, 1, '54b8dba36aa536a09c6f8c08dcef691290d6a4f2' ],
			'the most Q holds'    => [ str_repeat( 'w', 1663 ), 'Q', 'Q', 40, 4, '4078641e7085037d1bf398424a90779edcfbf525' ],
			'the most M holds'    => [ str_repeat( 'v', 2331 ), 'M', 'M', 40, 2, '215c4293f33806c6c673fa53f6b12225fd19769d' ],
			'the most L holds'    => [ str_repeat( 'u', 2953 ), 'L', 'L', 40, 1, '58c89a94fa431ed99ae6056509e411c3ce5ef6f1' ],
			'too long for M'      => [ str_repeat( 'a', 2953 ), null, 'L', 40, 1, '43e084402302979113a0dfdd2d3672a54cc924e5' ],
		];
	}

	/**
	 * Each code is the reference encoder's, at the size the reference chose.
	 *
	 * @param string      $text    What the code says.
	 * @param string|null $asked   The level asked for.
	 * @param string      $level   The level it was made at.
	 * @param int         $version The size.
	 * @param int         $mask    The mask.
	 * @param string      $sha     The fingerprint of the reference's code.
	 */
	#[DataProvider( 'vectors' )]
	public function test_the_code_is_the_reference_encoders( string $text, ?string $asked, string $level, int $version, int $mask, string $sha ): void {
		$matrix = QR::matrix( $text, $asked );

		$this->assertSame( $level, QR::level( $matrix ) );
		$this->assertSame( $version, QR::version( $matrix ) );
		$this->assertSame( $mask, self::mask( $matrix ) );
		$this->assertSame( $sha, self::fingerprint( $matrix ) );
	}

	/**
	 * A code for each of the eight masks.
	 *
	 * The mask a code ends up with depends on its text, so one code
	 * exercises one mask. These eight were found by trying texts until each
	 * mask had been chosen once; every one matches the reference at that mask.
	 */
	public function test_each_mask_draws_the_reference_code(): void {
		$codes = [
			0 => [ 'mask test 18', '0d85312c3b1357a633b4700b6242f0616767d14c' ],
			1 => [ 'mask test 142', '4c40c356599b7c9ed51ca11cd7c515270d761a5a' ],
			2 => [ 'mask test 7', '43561f3e4e195198facbadcae68b927e87e0c719' ],
			3 => [ 'mask test 3', 'fed1c5b0fcde75b542d0490602a5ccd800b3c99e' ],
			4 => [ 'mask test 1', '5e8b71af742cd0ca795c04ead690ab296bdcfe69' ],
			5 => [ 'mask test 369', '5c4f77b9c567ec9642f9052c9078ad399a9690ea' ],
			6 => [ 'mask test 20', '88e75f150de223981fbc73af86f7891f6c186c0e' ],
			7 => [ 'mask test 6', 'b6bc76a95b89e4930aafe31665a4c486f7d09b8c' ],
		];

		foreach ( $codes as $mask => [ $text, $sha ] ) {
			$matrix = QR::matrix( $text );

			$this->assertSame( $mask, self::mask( $matrix ), $text );
			$this->assertSame( $sha, self::fingerprint( $matrix ), $text );
		}
	}

	/**
	 * The mask chosen for two hundred texts has not moved.
	 *
	 * The penalty scoring decides between eight valid codes, so a change to
	 * it draws different, still readable, codes. Nothing else would notice;
	 * this does, so a change of mask choice is a decision rather than an
	 * accident.
	 */
	public function test_the_mask_choice_has_not_moved(): void {
		$chosen = '';

		for ( $n = 1; $n <= 200; $n++ ) {
			$chosen .= self::mask( QR::matrix( "mask test $n" ) );
		}

		$this->assertSame(
			'44343724327222222076426262264742226226776277242627272722262667767267327266622227223072222242276227242242226334272262230226276224222222222222214602222622226266744222666224242226627423026224222322220222',
			$chosen
		);
	}

	/**
	 * HELLO, row by row where it is easiest to see a change.
	 */
	public function test_a_code_that_was_decoded_has_not_moved(): void {
		$rows = array_map( static fn( array $row ): string => implode( '', array_map( 'intval', $row ) ), QR::matrix( 'HELLO' ) );

		$this->assertSame( '100010111111011111001', $rows[8] );
	}

	/**
	 * The fingerprint of a code.
	 *
	 * @param array<int, array<int, bool>> $matrix The code.
	 *
	 * @return string
	 */
	private static function fingerprint( array $matrix ): string {
		return sha1( implode( "\n", array_map( static fn( array $row ): string => implode( '', array_map( 'intval', $row ) ), $matrix ) ) );
	}

	/**
	 * The mask a code says it used.
	 *
	 * @param array<int, array<int, bool>> $matrix The code.
	 *
	 * @return int
	 */
	private static function mask( array $matrix ): int {
		$size   = count( $matrix );
		$format = 0;

		for ( $i = 0; $i < 8; $i++ ) {
			$format |= ( $matrix[8][ $size - 1 - $i ] ? 1 : 0 ) << $i;
		}

		for ( $i = 8; $i < 15; $i++ ) {
			$format |= ( $matrix[ $size - 15 + $i ][8] ? 1 : 0 ) << $i;
		}

		return ( ( $format ^ 0x5412 ) >> 10 ) & 7;
	}
}
