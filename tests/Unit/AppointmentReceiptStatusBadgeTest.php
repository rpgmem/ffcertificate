<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use FreeFormCertificate\Generators\PdfGenerator;
use PHPUnit\Framework\TestCase;

/**
 * The receipt PDF's status badge takes the status's colours (#2732): both
 * default receipts printed every status, Cancelled included, in one fixed
 * colour.
 *
 * @covers \FreeFormCertificate\Generators\PdfGenerator::receipt_status_colors
 */
class AppointmentReceiptStatusBadgeTest extends TestCase {

	private const TEMPLATES = array(
		'templates/documents/default_appointment_receipt_1.html',
		'templates/documents/default_appointment_receipt_custom.html',
	);

	public function test_every_status_has_its_own_colours(): void {
		$statuses = array( 'pending', 'confirmed', 'cancelled', 'completed', 'no_show' );
		$pairs    = array();
		foreach ( $statuses as $status ) {
			$colors = PdfGenerator::receipt_status_colors( $status );
			$this->assertMatchesRegularExpression( '/^#[0-9a-f]{6}$/', $colors['bg'], $status );
			$this->assertMatchesRegularExpression( '/^#[0-9a-f]{6}$/', $colors['text'], $status );
			$pairs[ $status ] = $colors['bg'] . '/' . $colors['text'];
		}

		$this->assertSame( count( $statuses ), count( array_unique( $pairs ) ), 'Two statuses share one badge.' );
		$this->assertNotSame( $pairs['confirmed'], $pairs['cancelled'], 'Cancelled printed in the Confirmed green.' );
	}

	public function test_an_unknown_status_falls_back_to_pending(): void {
		$this->assertSame( PdfGenerator::receipt_status_colors( 'pending' ), PdfGenerator::receipt_status_colors( 'whatever' ) );
	}

	public function test_every_badge_reads_at_the_text_floor(): void {
		foreach ( array( 'pending', 'confirmed', 'cancelled', 'completed', 'no_show' ) as $status ) {
			$colors = PdfGenerator::receipt_status_colors( $status );
			$ratio  = self::contrast( $colors['text'], $colors['bg'] );
			$this->assertGreaterThanOrEqual( 4.5, $ratio, sprintf( '%s badge at %.2f:1', $status, $ratio ) );
		}
	}

	public function test_both_default_receipts_colour_the_badge_by_status(): void {
		foreach ( self::TEMPLATES as $file ) {
			$html = (string) file_get_contents( dirname( __DIR__, 2 ) . '/' . $file );
			$this->assertMatchesRegularExpression(
				'/background: \{\{status_bg\}\};color: \{\{status_color\}\};[^"]*">\{\{status\}\}/',
				$html,
				$file . ' still paints its badge in a fixed colour.'
			);
		}
	}

	/**
	 * WCAG contrast ratio of two #rrggbb colours.
	 */
	private static function contrast( string $a, string $b ): float {
		$la = self::luminance( $a );
		$lb = self::luminance( $b );
		return ( max( $la, $lb ) + 0.05 ) / ( min( $la, $lb ) + 0.05 );
	}

	private static function luminance( string $hex ): float {
		$channels = array_map( 'hexdec', str_split( ltrim( $hex, '#' ), 2 ) );
		$linear   = array_map(
			static function ( int $c ): float {
				$c /= 255;
				return $c <= 0.03928 ? $c / 12.92 : ( ( $c + 0.055 ) / 1.055 ) ** 2.4;
			},
			$channels
		);
		return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
	}
}
