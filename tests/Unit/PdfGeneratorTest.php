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

        // Namespaced stubs: prevent "is not defined" errors when Sprint 27 tests run first.
        // PdfGenerator is in Generators namespace.
        Functions\when( 'FreeFormCertificate\Generators\esc_html' )->returnArg();
        Functions\when( 'FreeFormCertificate\Generators\esc_html__' )->returnArg();
        Functions\when( 'FreeFormCertificate\Generators\get_option' )->alias( function ( $key, $default = false ) {
            return \get_option( $key, $default );
        } );
        Functions\when( 'FreeFormCertificate\Generators\home_url' )->alias( function ( $path = '' ) {
            return 'https://example.com' . $path;
        } );
        Functions\when( 'FreeFormCertificate\Generators\wp_parse_url' )->alias( function ( $url, $component = -1 ) {
            return parse_url( $url, $component );
        } );
        Functions\when( 'FreeFormCertificate\Generators\trailingslashit' )->alias( function ( $url ) {
            return rtrim( $url, '/' ) . '/';
        } );
        Functions\when( 'FreeFormCertificate\Generators\esc_url' )->returnArg();
        Functions\when( 'FreeFormCertificate\Generators\esc_attr' )->returnArg();
        Functions\when( 'wp_date' )->alias( function ( $format, $ts = null, $tz = null ) {
            return gmdate( $format, $ts ?? time() );
        } );
        Functions\when( 'wp_timezone' )->alias( function () {
            return new \DateTimeZone( 'UTC' );
        } );

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

    // 6.6.11 — removed `generate_filename()` private method tests. The
    // helper logic moved to `\FreeFormCertificate\Core\Utils::build_pdf_filename()`
    // (covered by `UtilsTest::test_build_pdf_filename_*`). The pattern of
    // calling reflection on a removed private method is no longer valid.

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
        Functions\when( 'wp_date' )->justReturn( '01/01/2026' );

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
        Functions\when( 'wp_date' )->justReturn( '2026-01-01' );

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

    // ==================================================================
    // resolve_effective_schedule() — #366 Sprint 7
    // ==================================================================

    /**
     * Drive `resolve_effective_schedule()` with a stubbed geofence
     * config + submission row; return its `[start, end]` tuple.
     *
     * @param array<string, mixed> $geofence  `_ffc_geofence_config` to return.
     * @param array<string, mixed> $sub_array Submission row fragments.
     * @return array{0: string, 1: string}
     */
    private function resolve_with( array $geofence, array $sub_array ): array {
        Functions\when( 'get_post_meta' )->alias( static function ( $id, $key ) use ( $geofence ) {
            if ( '_ffc_geofence_config' === $key ) {
                return $geofence;
            }
            return '';
        } );

        return $this->invoke( 'resolve_effective_schedule', array( 42, $sub_array ) );
    }

    public function test_resolve_uses_override_when_present(): void {
        // Override beats both class_time and geofence.
        list( $s, $e ) = $this->resolve_with(
            array(
                'class_time_start' => '08:30',
                'class_time_end'   => '17:30',
                'time_start'       => '08:00',
                'time_end'         => '18:00',
            ),
            array(
                'schedule_start_override' => '09:00',
                'schedule_end_override'   => '17:00',
            )
        );

        $this->assertSame( '09:00', $s );
        $this->assertSame( '17:00', $e );
    }

    public function test_resolve_falls_back_to_class_time_when_no_override(): void {
        list( $s, $e ) = $this->resolve_with(
            array(
                'class_time_start' => '08:30',
                'class_time_end'   => '17:30',
                'time_start'       => '08:00',
                'time_end'         => '18:00',
            ),
            array()
        );

        $this->assertSame( '08:30', $s );
        $this->assertSame( '17:30', $e );
    }

    public function test_resolve_falls_back_to_geofence_when_no_class_time(): void {
        list( $s, $e ) = $this->resolve_with(
            array(
                'time_start' => '08:00',
                'time_end'   => '18:00',
            ),
            array()
        );

        $this->assertSame( '08:00', $s );
        $this->assertSame( '18:00', $e );
    }

    public function test_resolve_returns_empty_when_nothing_configured(): void {
        list( $s, $e ) = $this->resolve_with( array(), array() );

        $this->assertSame( '', $s );
        $this->assertSame( '', $e );
    }

    public function test_resolve_picks_each_side_independently(): void {
        // Half the override, half the class_time — common when
        // operator only fixed the end via the "Now" mode.
        list( $s, $e ) = $this->resolve_with(
            array(
                'class_time_start' => '08:30',
                'class_time_end'   => '17:30',
                'time_start'       => '08:00',
                'time_end'         => '18:00',
            ),
            array(
                'schedule_start_override' => null,
                'schedule_end_override'   => '17:00',
            )
        );

        $this->assertSame( '08:30', $s, 'start falls through to class_time when override is null' );
        $this->assertSame( '17:00', $e, 'end takes the override' );
    }
}
