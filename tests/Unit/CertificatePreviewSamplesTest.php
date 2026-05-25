<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\CertificatePreviewSamples;

/**
 * Tests for the canonical placeholder sample map that feeds the certificate
 * previews (admin form-editor + public CSV-download).
 *
 * @covers \FreeFormCertificate\Core\CertificatePreviewSamples
 */
class CertificatePreviewSamplesTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( 'get_option' )->justReturn( array() );
        Functions\when( 'get_bloginfo' )->justReturn( 'Test Site' );
        Functions\when( 'wp_date' )->alias( static fn( $fmt, $ts = null, $tz = null ) => '01/01/2026' );
        Functions\when( 'wp_timezone' )->alias( static fn() => new \DateTimeZone( 'UTC' ) );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_map_includes_system_and_ficha_placeholders(): void {
        $map = CertificatePreviewSamples::get_map();

        // System placeholders that are NOT builder fields — the gap the
        // map closes so the preview stops showing raw {{...}}.
        $this->assertArrayHasKey( 'name', $map );
        $this->assertArrayHasKey( 'site_name', $map );
        $this->assertArrayHasKey( 'validation_code', $map );
        $this->assertArrayHasKey( 'reference_year', $map );
        // Ficha / atestado block.
        $this->assertArrayHasKey( 'bairro', $map );
        $this->assertArrayHasKey( 'unidade_lotacao', $map );
        // Appointment receipt.
        $this->assertArrayHasKey( 'appointment_date', $map );
        $this->assertArrayHasKey( 'calendar_title', $map );
    }

    public function test_site_name_is_the_real_blog_name(): void {
        $map = CertificatePreviewSamples::get_map();
        $this->assertSame( 'Test Site', $map['site_name'] );
    }

    public function test_all_values_are_strings(): void {
        $map = CertificatePreviewSamples::get_map();
        foreach ( $map as $key => $value ) {
            $this->assertIsString( $value, "Sample for {$key} must be a string" );
        }
    }

    public function test_date_placeholders_go_through_the_formatter(): void {
        $map = CertificatePreviewSamples::get_map();
        $this->assertSame( '01/01/2026', $map['submission_date'] );
        $this->assertSame( '01/01/2026', $map['print_date'] );
    }

    public function test_reference_year_is_a_four_digit_year(): void {
        $map = CertificatePreviewSamples::get_map();
        $this->assertMatchesRegularExpression( '/^\d{4}$/', $map['reference_year'] );
    }

    public function test_special_js_handled_placeholders_are_excluded(): void {
        // {{qr_code}} and {{validation_url}} are rendered specially in the
        // JS (placeholder SVG / sample link), never via this map.
        $map = CertificatePreviewSamples::get_map();
        $this->assertArrayNotHasKey( 'qr_code', $map );
        $this->assertArrayNotHasKey( 'validation_url', $map );
    }
}
