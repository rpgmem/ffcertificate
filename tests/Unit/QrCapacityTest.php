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
}
