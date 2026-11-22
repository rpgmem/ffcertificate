<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use FreeFormCertificate\Generators\QrCapacity;
use FreeFormCertificate\Generators\QrSvgRenderer;
use PHPUnit\Framework\TestCase;

/**
 * The capacity meter agrees with the real encoder (#1584).
 *
 * Every expectation is checked against `QrSvgRenderer::matrix()`, which runs
 * the full encoder, so the meter cannot drift from what actually draws.
 *
 * @covers \FreeFormCertificate\Generators\QrCapacity
 */
class QrCapacityTest extends TestCase {

	/**
	 * Whether the real encoder draws the text.
	 *
	 * @param string $text Text.
	 * @param string $ecc  Level.
	 * @return bool
	 */
	private function draws( string $text, string $ecc ): bool {
		return array() !== QrSvgRenderer::matrix( $text, $ecc );
	}

	/**
	 * Content of every encoding mode, at levels where it ends near the limit.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function contents(): array {
		return array(
			'digits at L'         => array( str_repeat( '1234567890', 600 ), 'L' ),
			'upper case at M'     => array( str_repeat( 'ABC 123 ', 300 ), 'M' ),
			'plain text at Q'     => array( str_repeat( 'https://example.com/a?b=c ', 40 ), 'Q' ),
			'accented text at H'  => array( str_repeat( 'Educação ', 50 ), 'H' ),
			'short url at M'      => array( 'https://example.com', 'M' ),
		);
	}

	/**
	 * @dataProvider contents
	 */
	public function test_the_room_reported_is_exactly_what_the_encoder_accepts( string $text, string $ecc ): void {
		$usage = QrCapacity::measure( $text, $ecc, 0 );

		$this->assertSame( 0, $usage['over'] );
		$this->assertTrue( $this->draws( $text . str_repeat( 'a', $usage['remaining'] ), $ecc ), 'The reported room fits.' );
		$this->assertFalse( $this->draws( $text . str_repeat( 'a', $usage['remaining'] + 1 ), $ecc ), 'One character more does not.' );
		$this->assertSame( strlen( $text ) + $usage['remaining'], $usage['capacity'] );
	}

	public function test_digits_are_not_reported_as_over_the_byte_capacity(): void {
		// 3,000 digits are more bytes than level M holds in byte mode (2,331),
		// and the encoder still draws them: the old meter said 100%.
		$usage = QrCapacity::measure( str_repeat( '7', 3000 ), 'M', 0 );

		$this->assertSame( 0, $usage['over'] );
		$this->assertGreaterThan( 0, $usage['remaining'] );
		$this->assertLessThan( 100, $usage['percent'] );
	}

	public function test_content_that_does_not_fit_reports_how_much_to_remove(): void {
		$text  = str_repeat( 'x', 1400 );
		$usage = QrCapacity::measure( $text, 'H', 0 );

		$this->assertSame( 0, $usage['remaining'] );
		$this->assertSame( 100, $usage['percent'] );
		$this->assertTrue( $this->draws( substr( $text, 0, 1400 - $usage['over'] ), 'H' ), 'Removing that much fits.' );
		$this->assertFalse( $this->draws( substr( $text, 0, 1400 - $usage['over'] + 1 ), 'H' ), 'Removing one less does not.' );
	}

	public function test_a_lower_level_holds_more(): void {
		$text = 'https://example.com';

		$this->assertGreaterThan(
			QrCapacity::measure( $text, 'H', 0 )['capacity'],
			QrCapacity::measure( $text, 'L', 0 )['capacity']
		);
	}

	public function test_version_density_and_an_unknown_level(): void {
		$dense = QrCapacity::measure( 'x', 'q', 17 + 4 * 30 );
		$this->assertSame( 30, $dense['version'] );
		$this->assertTrue( $dense['dense'] );
		$this->assertSame( 'Q', $dense['level'] );

		$plain = QrCapacity::measure( 'x', 'Z', 0 );
		$this->assertSame( 0, $plain['version'] );
		$this->assertFalse( $plain['dense'] );
		$this->assertSame( 'M', $plain['level'], 'An unknown level reads as M.' );
	}

	public function test_fits_matches_the_encoder_and_refuses_empty_text(): void {
		$this->assertTrue( QrCapacity::fits( 'hello', 'M' ) );
		$this->assertFalse( QrCapacity::fits( '', 'M' ) );
		$this->assertFalse( QrCapacity::fits( str_repeat( 'x', 3000 ), 'L' ) );
	}

	/**
	 * Texts of every kind the plugin draws, short and near a version edge.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function free_level_texts(): array {
		$out = array();
		foreach ( array( 1, 7, 14, 25, 40, 61, 90, 140, 260, 600 ) as $length ) {
			$out[ 'text of ' . $length ] = array( substr( str_repeat( 'https://exemplo.test/valid/#token=abcdef0123&s=Zz9-', 20 ), 0, $length ) );
		}
		return $out;
	}

	/**
	 * The level raised for free never grows the grid, never lowers the level,
	 * and is the highest one the grid allows (#2648).
	 *
	 * @dataProvider free_level_texts
	 *
	 * @param string $text Text.
	 */
	public function test_the_free_level_keeps_the_grid_and_is_the_highest_it_allows( string $text ): void {
		$order = array( 'L', 'M', 'Q', 'H' );
		foreach ( $order as $asked ) {
			$base = count( QrSvgRenderer::matrix( $text, $asked ) );
			$free = QrCapacity::free_level( $text, $asked );

			$this->assertGreaterThanOrEqual( array_search( $asked, $order, true ), array_search( $free, $order, true ), 'Never lowered from ' . $asked );
			$this->assertSame( $base, count( QrSvgRenderer::matrix( $text, $free ) ), 'Same grid at ' . $free . ' as at ' . $asked );
			$next = $order[ array_search( $free, $order, true ) + 1 ] ?? null;
			if ( null !== $next ) {
				$this->assertNotSame( $base, count( QrSvgRenderer::matrix( $text, $next ) ), $next . ' would have been free too' );
			}
		}
	}

	public function test_the_free_level_rises_when_the_version_has_room(): void {
		// Measured: this short link is version 3 at both M and Q, 4 at H.
		$this->assertSame( 'Q', QrCapacity::free_level( 'https://exemplo.test/s/Ab3dE', 'M' ) );
		$this->assertSame( 'H', QrCapacity::free_level( 'https://exemplo.test/s/Ab3dE', 'H' ) );
	}

	public function test_the_free_level_falls_back_to_the_level_asked_for(): void {
		$this->assertSame( 'M', QrCapacity::free_level( '', 'M' ), 'Nothing to encode.' );
		$this->assertSame( 'L', QrCapacity::free_level( str_repeat( 'x', 4000 ), 'L' ), 'Too long for any version.' );
		$this->assertSame( QrCapacity::free_level( 'abc', 'M' ), QrCapacity::free_level( 'abc', 'Z' ), 'An unknown level reads as M.' );
	}

	public function test_the_fit_level_lowers_the_level_until_the_text_fits(): void {
		// #2660: version-40 byte capacity is 1273 at H, 1663 at Q, 2331 at M, 2953 at L.
		$this->assertSame( 'Q', QrCapacity::fit_level( str_repeat( 'x', 1500 ), 'H' ), 'Too long for H, fits Q.' );
		$this->assertSame( 'L', QrCapacity::fit_level( str_repeat( 'x', 2500 ), 'H' ), 'Only L holds it.' );
		$this->assertSame( 'L', QrCapacity::fit_level( str_repeat( 'x', 2500 ), 'M' ) );
		$this->assertNull( QrCapacity::fit_level( str_repeat( 'x', 4000 ), 'H' ), 'Nothing holds it.' );
	}

	public function test_the_fit_level_keeps_or_raises_a_level_that_already_fits(): void {
		$this->assertSame( QrCapacity::free_level( 'https://exemplo.test/s/Ab3dE', 'M' ), QrCapacity::fit_level( 'https://exemplo.test/s/Ab3dE', 'M' ) );
		$this->assertSame( 'H', QrCapacity::fit_level( 'https://exemplo.test/s/Ab3dE', 'H' ) );
		$this->assertNull( QrCapacity::fit_level( '', 'M' ), 'Empty text never fits.' );
	}
}
