<?php
/**
 * The picture: an inline SVG, or the same as a data: URI.
 *
 * @package SugarCommerce\Qr
 */

declare( strict_types=1 );

namespace SugarCommerce\Qr\Tests;

use InvalidArgumentException;
use SugarCommerce\Qr\QR;
use PHPUnit\Framework\TestCase;

/**
 * Class SvgTest
 */
final class SvgTest extends TestCase {

	/**
	 * The picture is self-contained: one SVG, a white ground, a margin, no addresses.
	 */
	public function test_the_svg_asks_nobody_for_anything(): void {
		$svg = QR::svg( 'HELLO' );

		$this->assertStringStartsWith( '<svg ', $svg );
		$this->assertStringContainsString( 'viewBox="0 0 29 29"', $svg );
		$this->assertStringContainsString( 'width="174"', $svg );
		$this->assertStringContainsString( 'height="174"', $svg );
		$this->assertStringContainsString( 'crispEdges', $svg );
		$this->assertStringContainsString( '<rect width="100%" height="100%" fill="#fff"/>', $svg );
		$this->assertStringContainsString( '<path fill="#000" ', $svg );
		$this->assertStringContainsString( 'role="img" aria-label="QR code"', $svg );
		$this->assertSame( 1, preg_match_all( '#https?://#', $svg ), 'Only the SVG namespace.' );
		$this->assertStringNotContainsString( '<image', $svg );
		$this->assertStringNotContainsString( 'href', $svg );

		// A module for every dark one, and none in the margin.
		$dark = array_sum( array_map( static fn( array $row ): int => count( array_filter( $row ) ), QR::matrix( 'HELLO' ) ) );

		$this->assertSame( $dark, substr_count( $svg, 'h1v1h-1z' ) );
		$this->assertStringNotContainsString( 'M0,', $svg );
		$this->assertStringNotContainsString( 'M3,', $svg );
		$this->assertStringContainsString( 'M4,4h1v1h-1z', $svg );
	}

	/**
	 * Module size and margin are the caller's.
	 */
	public function test_module_and_margin(): void {
		$svg = QR::svg( 'HELLO', [ 'module' => 4, 'quiet' => 2 ] );

		$this->assertStringContainsString( 'viewBox="0 0 25 25"', $svg );
		$this->assertStringContainsString( 'width="100"', $svg );
		$this->assertStringContainsString( 'M2,2h1v1h-1z', $svg );

		// No margin at all, and a module never smaller than a pixel.
		$bare = QR::svg( 'HELLO', [ 'module' => 0, 'quiet' => -3 ] );

		$this->assertStringContainsString( 'viewBox="0 0 21 21"', $bare );
		$this->assertStringContainsString( 'width="21"', $bare );
		$this->assertStringContainsString( 'M0,0h1v1h-1z', $bare );
	}

	/**
	 * Colours, a transparent ground, and the label, all escaped.
	 */
	public function test_colours_and_label(): void {
		$svg = QR::svg(
			'HELLO',
			[
				'dark'  => '#1d2327',
				'light' => '#f0f6fc',
				'label' => 'Scan to "pair"',
			]
		);

		$this->assertStringContainsString( 'fill="#f0f6fc"', $svg );
		$this->assertStringContainsString( '<path fill="#1d2327" ', $svg );
		$this->assertStringContainsString( 'aria-label="Scan to &quot;pair&quot;"', $svg );

		$clear = QR::svg( 'HELLO', [ 'light' => '' ] );

		$this->assertStringNotContainsString( '<rect', $clear );

		// The colours CSS names them by all pass.
		foreach ( [ '#abc', '#abcd', '#1D2327', '#1d2327cc', 'rebeccapurple', 'currentColor', 'transparent', 'rgb(29 35 39 / 50%)', 'rgba(0, 0, 0, .8)', 'hsl(210deg 10% 13%)', 'var(--qr-ink)' ] as $colour ) {
			$this->assertStringContainsString( 'fill="' . $colour . '"', QR::svg( 'HELLO', [ 'dark' => $colour ] ), $colour );
		}
	}

	/**
	 * A colour is a colour: never markup, and never an address the picture would fetch.
	 *
	 * Either would break the promise that the picture asks nobody for
	 * anything: url() in a fill is a request to that address each time the
	 * code is shown.
	 */
	public function test_a_colour_that_is_not_a_colour_is_refused(): void {
		$hostile = [
			'"/><script>',
			'url(https://tracker.example/p.svg#a)',
			'URL(//tracker.example/p.svg#a)',
			'red;background:url(https://tracker.example/)',
			'rgb(0,0,0) url(https://tracker.example/)',
			'expression(alert(1))',
			"#000\n",
		];

		foreach ( [ 'dark', 'light' ] as $which ) {
			foreach ( $hostile as $colour ) {
				try {
					QR::svg( 'HELLO', [ $which => $colour ] );
					$this->fail( "{$which}: {$colour} was drawn." );
				} catch ( InvalidArgumentException $e ) {
					$this->assertStringContainsString( 'colour', $e->getMessage() );
				}
			}
		}

		$this->expectException( InvalidArgumentException::class );

		QR::data_uri( 'HELLO', [ 'dark' => 'url(https://tracker.example/p.svg#a)' ] );
	}

	/**
	 * The level asked for reaches the code.
	 */
	public function test_the_level_reaches_the_code(): void {
		// HELLO is version 1 at every level, so the size does not tell; the modules do.
		$this->assertNotSame( QR::svg( 'HELLO' ), QR::svg( 'HELLO', [ 'level' => 'H' ] ) );
		$this->assertSame( QR::svg( 'HELLO' ), QR::svg( 'HELLO', [ 'level' => 'M' ] ) );

		// 14 bytes are version 2 at H: 25 modules and the margin.
		$this->assertStringContainsString( 'viewBox="0 0 33 33"', QR::svg( str_repeat( 'b', 14 ), [ 'level' => 'H' ] ) );
	}

	/**
	 * The data: URI is the same picture, base64.
	 */
	public function test_the_data_uri_is_the_same_picture(): void {
		$uri    = QR::data_uri( 'HELLO', [ 'module' => 3 ] );
		$prefix = 'data:image/svg+xml;base64,';

		$this->assertStringStartsWith( $prefix, $uri );
		$this->assertSame( QR::svg( 'HELLO', [ 'module' => 3 ] ), base64_decode( substr( $uri, strlen( $prefix ) ), true ) );
	}
}
