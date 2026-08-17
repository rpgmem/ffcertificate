<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Generators\PdfHtmlRenderer;

/**
 * Tests for PdfHtmlRenderer::get_appointment_receipt_template()'s pool-override
 * resolution (#945): a filter-supplied template wins over the shipped file,
 * which remains the fallback.
 *
 * @covers \FreeFormCertificate\Generators\PdfHtmlRenderer::get_appointment_receipt_template
 */
class AppointmentReceiptTemplateResolutionTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		class_exists( '\\FreeFormCertificate\\Generators\\PdfHtmlRenderer' );
		if ( ! defined( 'FFC_PLUGIN_DIR' ) ) {
			define( 'FFC_PLUGIN_DIR', \dirname( __DIR__, 2 ) . '/' );
		}
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_pool_override_wins_over_shipped_file(): void {
		// The resolver (via the filter) supplies HTML for the mode → use it verbatim.
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value, $schedule_type = 'regular' ) {
				if ( 'ffcertificate_appointment_receipt_template_html' === $hook ) {
					return 'custom' === $schedule_type ? '<div>POOL CUSTOM</div>' : '<div>POOL REGULAR</div>';
				}
				return $value;
			}
		);

		$renderer = new PdfHtmlRenderer();
		$this->assertSame( '<div>POOL CUSTOM</div>', $renderer->get_appointment_receipt_template( 'custom' ) );
		$this->assertSame( '<div>POOL REGULAR</div>', $renderer->get_appointment_receipt_template( 'regular' ) );
	}

	public function test_falls_back_to_shipped_file_when_no_override(): void {
		// No pool override (filter returns '') → the shipped default file is read.
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				return $value; // passthrough: '' for the _html filter, the file path for _file
			}
		);
		Functions\when( 'wp_normalize_path' )->returnArg();
		Functions\when( 'get_template_directory' )->justReturn( '/themes/active' );
		Functions\when( 'get_stylesheet_directory' )->justReturn( '/themes/active' );

		$renderer = new PdfHtmlRenderer();
		$html     = $renderer->get_appointment_receipt_template( 'regular' );

		// The shipped default receipt carries the verification-code token.
		$this->assertStringContainsString( '{{validation_code}}', $html );
		$this->assertStringContainsString( '{{calendar_title}}', $html );
	}
}
