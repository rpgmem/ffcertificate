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

	/** Height of the landscape A4 page, in pixels. */
	private const PAGE_HEIGHT = 794;

	/** One 8.5pt footer line, measured in headless Chromium (#2076). */
	private const FOOTER_LINE = 12;

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

	/**
	 * A footer that carries the QR code must end inside the page and inside
	 * the template's own frame (#2076). The QR prints as a block, so the
	 * footer is the text before it, the QR and the text after it, one line
	 * each; model 6 started at 708px under the 730px rule above and its QR
	 * crossed the frame drawn 28px from the page edge.
	 *
	 * @dataProvider defaults_provider
	 */
	public function test_the_qr_footer_ends_inside_the_frame( string $file ): void {
		$html = (string) file_get_contents( $file );
		$this->assertSame( 1, preg_match_all( '/\{\{qr_code:size=(\d+)/', $html ), basename( $file ) . ' should carry one sized QR placeholder.' );

		// The innermost frame: a positioned block with a full border, inset from the bottom.
		$limit = self::PAGE_HEIGHT;
		if ( preg_match_all( '/bottom:\s*(\d+)px;\s*border:\s*\d+px/', $html, $frames ) ) {
			$limit -= max( array_map( 'intval', $frames[1] ) );
		}

		if ( ! preg_match( '/<div style="position:\s*absolute;\s*top:\s*(\d+)px[^"]*">\s*((?:(?!<div|<p[ >]).)*?)\{\{qr_code:size=(\d+)[^}]*\}\}(.*?)<\/div>/s', $html, $m ) ) {
			// A QR in a box of its own fills that box, no more and no less
			// (model 5 printed a 50px code in a 170px box).
			$this->assertSame( 1, preg_match( '/width:\s*(\d+)px;\s*height:\s*(\d+)px">\s*\{\{qr_code:size=(\d+)/', $html, $box ), basename( $file ) . ' places its QR code neither on a footer line nor in a box of its own.' );
			$this->assertSame( array( $box[1], $box[1] ), array( $box[2], $box[3] ), basename( $file ) . ' should size its QR code to its square box.' );
			return;
		}

		$lines = ( '' !== trim( self::text_of( $m[2] ) ) ? 1 : 0 ) + ( '' !== trim( self::text_of( $m[4] ) ) ? 1 : 0 );
		$end   = (int) $m[1] + (int) $m[3] + $lines * self::FOOTER_LINE;

		$this->assertLessThanOrEqual( $limit, $end, basename( $file ) . " ends its QR footer at about {$end}px, past the frame at {$limit}px." );
	}

	/**
	 * The text of an HTML fragment, without its tags and entities.
	 *
	 * @param string $html Fragment.
	 * @return string
	 */
	private static function text_of( string $html ): string {
		return html_entity_decode( (string) preg_replace( '/<[^>]+>/', '', $html ) );
	}

	public function test_the_scan_reads_every_default(): void {
		$this->assertCount( 6, self::defaults_provider(), 'six certificate defaults are shipped' );
	}
}

