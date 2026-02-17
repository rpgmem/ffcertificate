<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Generators\PdfGenerator;

/**
 * Tests for PdfGenerator: URL param parsing, filename generation,
 * default HTML, and submission data enrichment.
 *
 * Uses Reflection to access private methods for testing pure business logic.
 */
class PdfGeneratorTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var PdfGenerator */
    private $generator;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'esc_url' )->returnArg();
        Functions\when( 'esc_attr' )->returnArg();

        $this->generator = new PdfGenerator();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Invoke a private method on PdfGenerator.
     */
    private function invoke( string $method, array $args = [] ) {
        $ref = new \ReflectionMethod( PdfGenerator::class, $method );
        $ref->setAccessible( true );
        return $ref->invokeArgs( $this->generator, $args );
    }

    // ==================================================================
    // parse_validation_url_params()
    // ==================================================================

    public function test_parse_params_empty_returns_defaults(): void {
        $result = $this->invoke( 'parse_validation_url_params', array( '' ) );

        $this->assertSame( 'm', $result['to'] );
        $this->assertSame( 'v', $result['text'] );
        $this->assertSame( '', $result['target'] );
        $this->assertSame( '', $result['color'] );
    }

    public function test_parse_params_link_m_to_v(): void {
        $result = $this->invoke( 'parse_validation_url_params', array( 'link:m>v' ) );

        $this->assertSame( 'm', $result['to'] );
        $this->assertSame( 'v', $result['text'] );
    }

    public function test_parse_params_link_v_to_m(): void {
        $result = $this->invoke( 'parse_validation_url_params', array( 'link:v>m' ) );

        $this->assertSame( 'v', $result['to'] );
        $this->assertSame( 'm', $result['text'] );
    }

    public function test_parse_params_link_m_to_m(): void {
        $result = $this->invoke( 'parse_validation_url_params', array( 'link:m>m' ) );

        $this->assertSame( 'm', $result['to'] );
        $this->assertSame( 'm', $result['text'] );
    }

    public function test_parse_params_link_v_to_v(): void {
        $result = $this->invoke( 'parse_validation_url_params', array( 'link:v>v' ) );

        $this->assertSame( 'v', $result['to'] );
        $this->assertSame( 'v', $result['text'] );
    }

    public function test_parse_params_custom_text_single_word(): void {
        $result = $this->invoke( 'parse_validation_url_params', array( 'link:m>"Verify"' ) );

        $this->assertSame( 'm', $result['to'] );
        $this->assertSame( 'Verify', $result['text'] );
    }

    public function test_parse_params_target_blank(): void {
        $result = $this->invoke( 'parse_validation_url_params', array( 'target:_blank' ) );

        $this->assertSame( '_blank', $result['target'] );
    }

    public function test_parse_params_color_named(): void {
        $result = $this->invoke( 'parse_validation_url_params', array( 'color:blue' ) );

        $this->assertSame( 'blue', $result['color'] );
    }

    public function test_parse_params_color_hex(): void {
        $result = $this->invoke( 'parse_validation_url_params', array( 'color:#2271b1' ) );

        $this->assertSame( '#2271b1', $result['color'] );
    }

    public function test_parse_params_combined(): void {
        $result = $this->invoke( 'parse_validation_url_params', array( 'link:v>m target:_blank color:red' ) );

        $this->assertSame( 'v', $result['to'] );
        $this->assertSame( 'm', $result['text'] );
        $this->assertSame( '_blank', $result['target'] );
        $this->assertSame( 'red', $result['color'] );
    }

    public function test_parse_params_custom_text_with_target_and_color(): void {
        $result = $this->invoke( 'parse_validation_url_params', array( 'link:m>"VerifyCert" target:_self color:#333' ) );

        $this->assertSame( 'm', $result['to'] );
        $this->assertSame( 'VerifyCert', $result['text'] );
        $this->assertSame( '_self', $result['target'] );
        $this->assertSame( '#333', $result['color'] );
    }

    public function test_parse_params_ignores_unknown_params(): void {
        $result = $this->invoke( 'parse_validation_url_params', array( 'unknown:value link:m>v' ) );

        // unknown is ignored, link is parsed
        $this->assertSame( 'm', $result['to'] );
        $this->assertSame( 'v', $result['text'] );
    }

    // ==================================================================
    // generate_filename()
    // ==================================================================

    public function test_filename_simple_title(): void {
        $result = $this->invoke( 'generate_filename', array( 'My Certificate' ) );

        // Utils::sanitize_filename converts to lowercase, replaces spaces with dashes
        $this->assertSame( 'my-certificate.pdf', $result );
    }

    public function test_filename_with_auth_code(): void {
        $result = $this->invoke( 'generate_filename', array( 'Certificate', 'ABC123' ) );

        $this->assertSame( 'certificate_ABC123.pdf', $result );
    }

    public function test_filename_empty_title_defaults(): void {
        $result = $this->invoke( 'generate_filename', array( '' ) );

        $this->assertSame( 'certificate.pdf', $result );
    }

    public function test_filename_special_chars_stripped(): void {
        $result = $this->invoke( 'generate_filename', array( 'Cert & Event @2025!' ) );

        // Utils::sanitize_filename strips special chars, collapses dashes
        $this->assertStringEndsWith( '.pdf', $result );
        $this->assertStringNotContainsString( '&', $result );
        $this->assertStringNotContainsString( '@', $result );
        $this->assertStringNotContainsString( '!', $result );
    }

    public function test_filename_auth_code_strips_non_alphanumeric(): void {
        $result = $this->invoke( 'generate_filename', array( 'Test', 'ABC-123-DEF' ) );

        // auth code has non-alphanumeric stripped, uppercased
        $this->assertSame( 'test_ABC123DEF.pdf', $result );
    }

    public function test_filename_empty_auth_code_ignored(): void {
        $result = $this->invoke( 'generate_filename', array( 'Test', '' ) );

        $this->assertSame( 'test.pdf', $result );
    }

    // ==================================================================
    // generate_default_html()
    // ==================================================================

    public function test_default_html_contains_title(): void {
        $result = $this->invoke( 'generate_default_html', array( array(), 'My Event' ) );

        $this->assertStringContainsString( '<h1>My Event</h1>', $result );
    }

    public function test_default_html_contains_name_when_present(): void {
        $data = array( 'name' => 'John Doe' );

        $result = $this->invoke( 'generate_default_html', array( $data, 'Event' ) );

        $this->assertStringContainsString( '<h2>John Doe</h2>', $result );
    }

    public function test_default_html_no_name_when_absent(): void {
        $result = $this->invoke( 'generate_default_html', array( array(), 'Event' ) );

        $this->assertStringNotContainsString( '<h2>', $result );
    }

    public function test_default_html_contains_auth_code_when_present(): void {
        $data = array( 'auth_code' => 'ABCD1234' );

        $result = $this->invoke( 'generate_default_html', array( $data, 'Event' ) );

        // Utils::format_auth_code is real (pure function), should format the code
        $this->assertStringContainsString( 'Authenticity:', $result );
        $this->assertStringContainsString( 'ABCD', $result );
    }

    public function test_default_html_no_auth_code_when_absent(): void {
        $result = $this->invoke( 'generate_default_html', array( array(), 'Event' ) );

        $this->assertStringNotContainsString( 'Authenticity:', $result );
    }

    public function test_default_html_wraps_in_div(): void {
        $result = $this->invoke( 'generate_default_html', array( array(), 'Title' ) );

        $this->assertStringStartsWith( '<div', $result );
        $this->assertStringEndsWith( '</div>', $result );
    }

    // ==================================================================
    // enrich_submission_data()
    // ==================================================================

    public function test_enrich_adds_email_if_missing(): void {
        Functions\when( 'get_option' )->justReturn( array() );
        Functions\when( 'date_i18n' )->justReturn( 'January 1, 2026' );

        $data = array();
        $submission = array(
            'id' => '42',
            'email' => 'john@example.com',
            'submission_date' => '2026-01-01 10:00:00',
            'magic_token' => '',
        );

        $result = $this->invoke( 'enrich_submission_data', array( $data, $submission ) );

        $this->assertSame( 'john@example.com', $result['email'] );
    }

    public function test_enrich_does_not_overwrite_existing_email(): void {
        Functions\when( 'get_option' )->justReturn( array() );
        Functions\when( 'date_i18n' )->justReturn( 'January 1, 2026' );

        $data = array( 'email' => 'original@example.com' );
        $submission = array(
            'id' => '42',
            'email' => 'other@example.com',
            'submission_date' => '2026-01-01 10:00:00',
            'magic_token' => '',
        );

        $result = $this->invoke( 'enrich_submission_data', array( $data, $submission ) );

        $this->assertSame( 'original@example.com', $result['email'] );
    }

    public function test_enrich_adds_fill_date(): void {
        Functions\when( 'get_option' )->justReturn( array( 'date_format' => 'd/m/Y' ) );
        Functions\expect( 'date_i18n' )
            ->once()
            ->andReturn( '01/01/2026' );

        $data = array();
        $submission = array(
            'id' => '1',
            'email' => '',
            'submission_date' => '2026-01-01 10:00:00',
            'magic_token' => '',
        );

        $result = $this->invoke( 'enrich_submission_data', array( $data, $submission ) );

        $this->assertSame( '01/01/2026', $result['fill_date'] );
        $this->assertSame( '01/01/2026', $result['date'] ); // alias
    }

    public function test_enrich_custom_date_format(): void {
        Functions\when( 'get_option' )->justReturn( array(
            'date_format' => 'custom',
            'date_format_custom' => 'Y-m-d',
        ) );
        Functions\expect( 'date_i18n' )
            ->once()
            ->andReturn( '2026-01-01' );

        $data = array();
        $submission = array(
            'id' => '1',
            'email' => '',
            'submission_date' => '2026-01-01 10:00:00',
            'magic_token' => '',
        );

        $result = $this->invoke( 'enrich_submission_data', array( $data, $submission ) );

        $this->assertSame( '2026-01-01', $result['fill_date'] );
    }

    public function test_enrich_does_not_overwrite_fill_date(): void {
        $data = array( 'fill_date' => 'Custom Date' );
        $submission = array(
            'id' => '1',
            'email' => '',
            'submission_date' => '2026-01-01 10:00:00',
            'magic_token' => '',
        );

        $result = $this->invoke( 'enrich_submission_data', array( $data, $submission ) );

        $this->assertSame( 'Custom Date', $result['fill_date'] );
    }

    public function test_enrich_converts_id_to_int(): void {
        Functions\when( 'get_option' )->justReturn( array() );
        Functions\when( 'date_i18n' )->justReturn( 'date' );

        $data = array();
        $submission = array(
            'id' => '123',  // String from wpdb
            'email' => '',
            'submission_date' => '2026-01-01',
            'magic_token' => '',
        );

        $result = $this->invoke( 'enrich_submission_data', array( $data, $submission ) );

        $this->assertSame( 123, $result['submission_id'] );
    }

    public function test_enrich_adds_magic_token(): void {
        Functions\when( 'get_option' )->justReturn( array() );
        Functions\when( 'date_i18n' )->justReturn( 'date' );

        $data = array();
        $submission = array(
            'id' => '1',
            'email' => '',
            'submission_date' => '2026-01-01',
            'magic_token' => 'abc123xyz',
        );

        $result = $this->invoke( 'enrich_submission_data', array( $data, $submission ) );

        $this->assertSame( 'abc123xyz', $result['magic_token'] );
    }

    public function test_enrich_does_not_add_empty_magic_token(): void {
        Functions\when( 'get_option' )->justReturn( array() );
        Functions\when( 'date_i18n' )->justReturn( 'date' );

        $data = array();
        $submission = array(
            'id' => '1',
            'email' => '',
            'submission_date' => '2026-01-01',
            'magic_token' => '',
        );

        $result = $this->invoke( 'enrich_submission_data', array( $data, $submission ) );

        $this->assertArrayNotHasKey( 'magic_token', $result );
    }
}
