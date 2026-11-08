<?php
/**
 * The PDF overlay's texts reach every page that prints `ffc_ajax`.
 *
 * @package FreeFormCertificate\Tests
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Frontend\BrowserGate;
use FreeFormCertificate\Frontend\PdfOverlayStrings;
use PHPUnit\Framework\TestCase;

/**
 * PDF overlay strings (#2526).
 *
 * `ffc-pdf-generator.js` reads its texts from `ffc_ajax.strings`. The
 * personal calendar, the appointment receipt and the audience check-in
 * localized `ffc_ajax` through `BrowserGate::request_config()`, which carried
 * no strings, so the overlay fell back to its English literals there.
 *
 * @covers \FreeFormCertificate\Frontend\PdfOverlayStrings
 * @covers \FreeFormCertificate\Frontend\BrowserGate::request_config
 */
class PdfOverlayStringsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'admin_url' )->justReturn( '/wp-admin/admin-ajax.php' );
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Every `ffc_ajax.strings.<key>` the overlay script reads.
	 *
	 * @return list<string>
	 */
	private static function keys_the_script_reads(): array {
		$js = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/ffc-pdf-generator.js' );
		preg_match_all( '/ffc_ajax\.strings\.([A-Za-z0-9_]+)/', $js, $m );
		$keys = array_values( array_unique( $m[1] ) );
		sort( $keys );
		return $keys;
	}

	public function test_the_list_carries_every_key_the_overlay_reads(): void {
		$keys = self::keys_the_script_reads();
		$this->assertContains( 'pdfDownloaded', $keys, 'The scan must reach the overlay script.' );

		$missing = array_values( array_diff( $keys, array_keys( PdfOverlayStrings::all() ) ) );
		$this->assertSame( array(), $missing );
	}

	public function test_every_page_that_prints_ffc_ajax_carries_the_overlay_texts(): void {
		$config = BrowserGate::request_config();

		$this->assertSame( PdfOverlayStrings::all(), $config['strings'] );
		$this->assertSame( 'PDF downloaded successfully.', $config['strings']['pdfDownloaded'] );
	}
}
