<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guard for the shipped certificate defaults: no positioned block starts so low
 * that its footer line, the QR code and the validation link leave the A4 page.
 *
 * The rule is structural and needs no browser. The lowest footer line is about
 * 62px tall once its text wraps; a block that starts at 730px or below therefore
 * ends past the 794px page. The limit was measured in headless Chromium against
 * the default templates with realistic token values (#1962 follow-up).
 *
 * @covers \FreeFormCertificate\Admin\CertTemplateSeeder
 */
class CertificateDefaultsFitTest extends TestCase {

	/** The lowest a block may start on the 794px landscape page. */
	private const MAX_BLOCK_TOP = 730;

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function defaults_provider(): array {
		$files = glob( dirname( __DIR__, 2 ) . '/templates/certificate-defaults/*.html' );
		$cases = array();
		foreach ( $files ? $files : array() as $file ) {
			$cases[ basename( $file ) ] = array( $file );
		}
		return $cases;
	}

	/**
	 * @dataProvider defaults_provider
	 */
	public function test_no_block_starts_below_the_footer_line( string $file ): void {
		$html = (string) file_get_contents( $file );
		preg_match_all( '/position:\s*absolute;\s*top:\s*(\d+)px/', $html, $matches );

		$this->assertNotEmpty( $matches[1], basename( $file ) . ' positions no block: the scan read nothing.' );
		foreach ( $matches[1] as $top ) {
			$this->assertLessThanOrEqual(
				self::MAX_BLOCK_TOP,
				(int) $top,
				basename( $file ) . " has a block at {$top}px; its footer would leave the page."
			);
		}
	}

	public function test_the_scan_reads_every_default(): void {
		$this->assertCount( 6, self::defaults_provider(), 'six certificate defaults are shipped' );
	}
}
