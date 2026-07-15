<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Generators\PdfGenerator;
use FreeFormCertificate\Generators\PdfHtmlRenderer;

/**
 * Tests for PdfGenerator: URL param parsing, filename generation,
 * default HTML, and submission data enrichment.
 *
 * Uses Reflection to access private methods for testing pure business logic.
 *
 * @covers \FreeFormCertificate\Generators\PdfGenerator
 * @covers \FreeFormCertificate\Generators\PdfHtmlRenderer
 */
class PdfGeneratorTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var PdfGenerator */
    private $generator;

    /** @var PdfHtmlRenderer */
    private $renderer;

    /**
     * HTML-rendering / placeholder methods that moved to PdfHtmlRenderer
     * in the #589 phase-2 split — invoke() reflects these on $this->renderer.
     *
     * @var array<int, string>
     */
    private $renderer_methods = array(
        'generate_html',
        'generate_default_html',
        'process_qrcode_placeholders',
        'get_qr_code_target_url',
        'process_validation_url_placeholders',
        'parse_validation_url_params',
        'get_appointment_receipt_template',
    );

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // pcov does not record lines for files first autoloaded mid-test-method,
        // so the renderer's coverage would attribute to nothing. Preload the
        // extracted class here so pcov attributes its lines to this test.
        class_exists( '\\FreeFormCertificate\\Generators\\PdfHtmlRenderer' );

        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'esc_url' )->returnArg();
        Functions\when( 'esc_attr' )->returnArg();

        // Namespaced stubs: prevent "is not defined" errors when Sprint 27 tests run first.
        // PdfGenerator is in Generators namespace.
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'home_url' )->alias( function ( $path = '' ) {
            return 'https://example.com' . $path;
        } );
        Functions\when( 'wp_parse_url' )->alias( function ( $url, $component = -1 ) {
            return parse_url( $url, $component );
        } );
        Functions\when( 'trailingslashit' )->alias( function ( $url ) {
            return rtrim( $url, '/' ) . '/';
        } );
        Functions\when( 'esc_url' )->returnArg();
        Functions\when( 'esc_attr' )->returnArg();
        Functions\when( 'wp_date' )->alias( function ( $format, $ts = null, $tz = null ) {
            return gmdate( $format, $ts ?? time() );
        } );
        Functions\when( 'wp_timezone' )->alias( function () {
            return new \DateTimeZone( 'UTC' );
        } );

        $this->generator = new PdfGenerator();
        $this->renderer  = new PdfHtmlRenderer();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Invoke a private method on PdfGenerator.
     */
    private function invoke( string $method, array $args = [] ) {
        // Methods moved to PdfHtmlRenderer (#589 phase-2) reflect on the
        // renderer instance; data-assembly methods stay on PdfGenerator.
        $is_renderer = in_array( $method, $this->renderer_methods, true );
        $target      = $is_renderer ? $this->renderer : $this->generator;
        $class       = $is_renderer ? PdfHtmlRenderer::class : PdfGenerator::class;
        $ref         = new \ReflectionMethod( $class, $method );
        $ref->setAccessible( true );
        return $ref->invokeArgs( $target, $args );
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
    // helper logic moved to `\FreeFormCertificate\Core\FilenameHelper::build_pdf_filename()`
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

    // ==================================================================
    // generate_html()
    // ==================================================================

    /** Stub the WP funcs that generate_html() / its placeholder helpers need. */
    private function stub_html_funcs(): void {
        Functions\when( 'wp_kses' )->alias( fn ( $v ) => (string) $v );
        Functions\when( 'get_bloginfo' )->justReturn( 'Test Site' );
        Functions\when( 'get_home_url' )->justReturn( 'https://example.com' );
        Functions\when( 'untrailingslashit' )->alias( fn ( $u ) => rtrim( (string) $u, '/' ) );
        Functions\when( 'site_url' )->alias( fn ( $p = '' ) => 'https://example.com/' . ltrim( (string) $p, '/' ) );
        Functions\when( 'apply_filters' )->alias( fn ( $tag, $value = '' ) => $value );
        Functions\when( 'get_option' )->justReturn( array() );
        Functions\when( 'wp_normalize_path' )->alias( fn ( $p ) => str_replace( '\\', '/', (string) $p ) );
        Functions\when( 'get_template_directory' )->justReturn( '/tmp/theme' );
        Functions\when( 'get_stylesheet_directory' )->justReturn( '/tmp/theme' );
        // Generators-namespaced fallbacks (the PdfGenerator class calls these unqualified).
        Functions\when( 'wp_normalize_path' )->alias( fn ( $p ) => str_replace( '\\', '/', (string) $p ) );
        Functions\when( 'get_template_directory' )->justReturn( '/tmp/theme' );
        Functions\when( 'get_stylesheet_directory' )->justReturn( '/tmp/theme' );
        Functions\when( 'apply_filters' )->alias( fn ( $tag, $value = '' ) => $value );
        Functions\when( 'site_url' )->alias( fn ( $p = '' ) => 'https://example.com/' . ltrim( (string) $p, '/' ) );
        Functions\when( 'untrailingslashit' )->alias( fn ( $u ) => rtrim( (string) $u, '/' ) );
        Functions\when( 'get_bloginfo' )->justReturn( 'Test Site' );
        Functions\when( 'get_home_url' )->justReturn( 'https://example.com' );
        Functions\when( 'wp_kses' )->alias( fn ( $v ) => (string) $v );
        Functions\when( 'esc_url' )->returnArg();
        // build_pdf_filename() (Utils, Core namespace) uses _x().
        Functions\when( '_x' )->returnArg();
        Functions\when( '_x' )->returnArg();
    }

    public function test_generate_html_falls_back_to_default_when_no_layout(): void {
        $this->stub_html_funcs();
        $html = $this->invoke( 'generate_html', array( array( 'name' => 'Alice' ), 'My Event', array() ) );
        $this->assertStringContainsString( 'My Event', $html );
        $this->assertStringContainsString( 'Alice', $html );
    }

    public function test_generate_html_replaces_field_placeholders(): void {
        $this->stub_html_funcs();
        $config = array( 'pdf_layout' => '<p>Hello {{name}} - {{form_title}}</p>' );
        $html   = $this->invoke( 'generate_html', array( array( 'name' => 'Bob' ), 'Cert', $config ) );
        $this->assertStringContainsString( 'Bob', $html );
        $this->assertStringContainsString( 'Cert', $html );
        $this->assertStringNotContainsString( '{{name}}', $html );
    }

    public function test_generate_html_maps_email_and_quiz_aliases(): void {
        $this->stub_html_funcs();
        $config = array( 'pdf_layout' => '<p>{{email}} {{score}} {{score_percent}}</p>' );
        $data   = array(
            'user_email'    => 'x@y.com',
            '_quiz_score'   => 8,
            '_quiz_percent' => 80,
        );
        $html = $this->invoke( 'generate_html', array( $data, 'T', $config ) );
        $this->assertStringContainsString( 'x@y.com', $html );
        $this->assertStringContainsString( '8', $html );
        $this->assertStringContainsString( '80', $html );
    }

    public function test_generate_html_processes_validation_url_placeholder(): void {
        $this->stub_html_funcs();
        Functions\when( 'esc_url' )->returnArg();
        $config = array( 'pdf_layout' => '<p>{{validation_url link:v>v}}</p>' );
        $html   = $this->invoke( 'generate_html', array( array(), 'T', $config ) );
        $this->assertStringContainsString( '<a href=', $html );
        $this->assertStringContainsString( 'ffc-validation-link', $html );
    }

    // ==================================================================
    // process_validation_url_placeholders()
    // ==================================================================

    public function test_validation_url_default_uses_valid_url_fallback(): void {
        $this->stub_html_funcs();
        $layout = '{{validation_url}}';
        $html   = $this->invoke( 'process_validation_url_placeholders', array( $layout, array() ) );
        // No magic token → both href and text fall back to the /valid URL.
        $this->assertStringContainsString( 'https://example.com/valid', $html );
        $this->assertStringNotContainsString( '{{validation_url', $html );
    }

    public function test_validation_url_custom_text_target_and_color(): void {
        $this->stub_html_funcs();
        $layout = '{{validation_url link:v>"ClickHere" target:_blank color:red}}';
        $html   = $this->invoke( 'process_validation_url_placeholders', array( $layout, array() ) );
        $this->assertStringContainsString( 'ClickHere', $html );
        $this->assertStringContainsString( 'target="_blank"', $html );
        $this->assertStringContainsString( 'color: red', $html );
    }

    // ==================================================================
    // get_qr_code_target_url()
    // ==================================================================

    public function test_qr_target_url_falls_back_to_valid_without_token(): void {
        $this->stub_html_funcs();
        $url = $this->invoke( 'get_qr_code_target_url', array( array() ) );
        $this->assertSame( 'https://example.com/valid', $url );
    }

    // ==================================================================
    // generate_appointment_pdf_data()
    // ==================================================================

    public function test_generate_appointment_pdf_data_assembles_array(): void {
        $this->stub_html_funcs();
        Functions\when( 'esc_url' )->returnArg();

        if ( ! defined( 'FFC_PLUGIN_DIR' ) ) {
            define( 'FFC_PLUGIN_DIR', \dirname( __DIR__, 2 ) . '/' );
        }

        $appointment = array(
            'name'             => 'Carlos',
            'appointment_date' => '2030-05-20',
            'start_time'       => '09:00:00',
            'end_time'         => '10:00:00',
            'created_at'       => '2030-05-01 12:00:00',
            'status'           => 'confirmed',
            'validation_code'  => 'ABC123',
            'id'               => 5,
        );
        $calendar = array( 'id' => 7, 'title' => 'Consulta' );

        $result = $this->invoke( 'generate_appointment_pdf_data', array( $appointment, $calendar ) );

        $this->assertIsArray( $result );
        $this->assertArrayHasKey( 'html', $result );
        $this->assertArrayHasKey( 'filename', $result );
        $this->assertSame( 'appointment_receipt', $result['type'] );
        $this->assertSame( 'Consulta', $result['form_title'] );
        $this->assertNotEmpty( $result['html'] );
    }

    public function test_generate_appointment_pdf_data_handles_missing_optional_fields(): void {
        $this->stub_html_funcs();
        Functions\when( 'esc_url' )->returnArg();

        if ( ! defined( 'FFC_PLUGIN_DIR' ) ) {
            define( 'FFC_PLUGIN_DIR', \dirname( __DIR__, 2 ) . '/' );
        }

        // No date/time/status/code → falls back to N/A labels, empty codes.
        $result = $this->invoke( 'generate_appointment_pdf_data', array( array(), array() ) );

        $this->assertIsArray( $result );
        $this->assertSame( 'appointment_receipt', $result['type'] );
        $this->assertArrayHasKey( 'html', $result );
    }

    /**
     * Appointment with a confirmation_token → the magic_token branch (line
     * 392-393) is exercised, so the QR/validation URL uses the token.
     */
    public function test_generate_appointment_pdf_data_includes_confirmation_token(): void {
        $this->stub_html_funcs();
        Functions\when( 'esc_url' )->returnArg();

        if ( ! defined( 'FFC_PLUGIN_DIR' ) ) {
            define( 'FFC_PLUGIN_DIR', \dirname( __DIR__, 2 ) . '/' );
        }

        $appointment = array(
            'name'               => 'Ana',
            'appointment_date'   => '2030-05-20',
            'status'             => 'pending',
            'validation_code'    => 'V-9',
            'confirmation_token' => 'tok-xyz-123',
            'id'                 => 3,
        );
        $calendar = array( 'id' => 4, 'title' => 'Cal' );

        $result = $this->invoke( 'generate_appointment_pdf_data', array( $appointment, $calendar ) );

        $this->assertIsArray( $result );
        // The magic_token flows into the HTML through the QR/validation helpers.
        $this->assertNotEmpty( $result['html'] );
        $this->assertSame( 'appointment_receipt', $result['type'] );
    }

    // ==================================================================
    // generate_pdf_data() — full submission → PDF data assembly
    // ==================================================================

    /**
     * Build a fake submission handler whose get_submission() returns $row
     * (or false when $row is null).
     *
     * @param object|false $row Submission row object (or false).
     */
    private function make_submission_handler( $row ): object {
        return new class( $row ) {
            /** @var object|false */
            private $row;
            /** @param object|false $row */
            public function __construct( $row ) {
                $this->row = $row;
            }
            /** @return object|false */
            public function get_submission( int $id ) {
                return $this->row;
            }
        };
    }

    /** Stub the WP funcs generate_pdf_data() itself calls beyond stub_html_funcs(). */
    private function stub_generate_pdf_data_funcs(): void {
        $this->stub_html_funcs();
        if ( ! defined( 'FFC_PLUGIN_DIR' ) ) {
            define( 'FFC_PLUGIN_DIR', \dirname( __DIR__, 2 ) . '/' );
        }
        Functions\when( 'wp_unslash' )->alias(
            static function ( $v ) {
                return is_string( $v ) ? stripslashes( $v ) : $v;
            }
        );
        Functions\when( '_n' )->alias(
            static function ( $single, $plural, $number ) {
                return 1 === (int) $number ? $single : $plural;
            }
        );
        Functions\when( 'number_format_i18n' )->alias(
            static function ( $n ) {
                return (string) $n;
            }
        );
        Functions\when( 'get_the_title' )->justReturn( 'My Certificate Form' );
        Functions\when( 'get_post_meta' )->alias(
            static function ( $id, $key ) {
                if ( '_ffc_form_config' === $key ) {
                    return array( 'pdf_layout' => '<p>{{name}} {{email}} {{schedule}}</p>' );
                }
                if ( '_ffc_form_bg' === $key ) {
                    return 'https://example.com/bg.png';
                }
                if ( '_ffc_geofence_config' === $key ) {
                    return array( 'class_time_start' => '08:00', 'class_time_end' => '12:00' );
                }
                return '';
            }
        );
    }

    public function test_generate_pdf_data_returns_wp_error_when_submission_missing(): void {
        $handler = $this->make_submission_handler( false );

        $result = $this->generator->generate_pdf_data( 99, $handler );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'submission_not_found', $result->get_error_code() );
    }

    public function test_generate_pdf_data_assembles_full_pdf_array(): void {
        $this->stub_generate_pdf_data_funcs();

        $row = (object) array(
            'id'                    => '55',
            'email'                 => 'user@example.com',
            'auth_code'             => 'AUTH-77',
            'cpf_rf'                => '12345678900',
            'data'                  => json_encode( array( 'name' => 'Marina', 'extra' => 'v1' ) ),
            'form_id'               => '10',
            'submission_date'       => (string) 1_700_000_000,
            'magic_token'           => 'mtok',
            'schedule_start_override' => '',
            'schedule_end_override'   => '',
        );
        $handler = $this->make_submission_handler( $row );

        $result = $this->generator->generate_pdf_data( 55, $handler );

        $this->assertIsArray( $result );
        $this->assertArrayHasKey( 'html', $result );
        $this->assertSame( 'My Certificate Form', $result['form_title'] );
        $this->assertSame( 'AUTH-77', $result['auth_code'] );
        $this->assertSame( 55, $result['submission_id'] );
        $this->assertSame( 'https://example.com/bg.png', $result['bg_image'] );
        // JSON `name` merged into template data and rendered.
        $this->assertStringContainsString( 'Marina', $result['html'] );
        $this->assertStringContainsString( 'user@example.com', $result['html'] );
        // enrich adds the schedule placeholders resolved from the geofence.
        $this->assertArrayHasKey( 'schedule', $result['submission'] );
    }

    public function test_generate_pdf_data_handles_slashed_json_and_missing_optionals(): void {
        $this->stub_generate_pdf_data_funcs();

        // First json_decode fails (slashed quotes), fallback via wp_unslash.
        $slashed = '{\"name\":\"Bruno\"}';
        $row     = (object) array(
            'id'              => '1',
            'email'           => 'b@example.com',
            'data'            => $slashed,
            'form_id'         => '10',
            'submission_date' => (string) 1_700_000_000,
        );
        $handler = $this->make_submission_handler( $row );

        $result = $this->generator->generate_pdf_data( 1, $handler );

        $this->assertIsArray( $result );
        // No auth_code/cpf_rf on the row → auth_code falls back to ''.
        $this->assertSame( '', $result['auth_code'] );
        $this->assertStringContainsString( 'Bruno', $result['html'] );
    }

    // ==================================================================
    // generate_pdf_data_from_form() — frontend path
    // ==================================================================

    public function test_generate_pdf_data_from_form_returns_error_when_form_missing(): void {
        Functions\when( '__' )->returnArg();
        Functions\when( 'get_post' )->justReturn( null );

        $result = $this->generator->generate_pdf_data_from_form( array(), 999 );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'form_not_found', $result->get_error_code() );
    }

    public function test_generate_pdf_data_from_form_assembles_array_with_date(): void {
        $this->stub_html_funcs();
        if ( ! defined( 'FFC_PLUGIN_DIR' ) ) {
            define( 'FFC_PLUGIN_DIR', \dirname( __DIR__, 2 ) . '/' );
        }
        Functions\when( 'get_post' )->justReturn( (object) array( 'post_title' => 'Frontend Form' ) );
        Functions\when( 'get_post_meta' )->alias(
            static function ( $id, $key ) {
                if ( '_ffc_form_config' === $key ) {
                    return array( 'pdf_layout' => '<p>{{name}} {{fill_date}}</p>' );
                }
                if ( '_ffc_form_bg' === $key ) {
                    return '';
                }
                return '';
            }
        );

        $result = $this->generator->generate_pdf_data_from_form(
            array( 'name' => 'Clara', 'auth_code' => 'FC-1' ),
            10,
            1_700_000_000
        );

        $this->assertIsArray( $result );
        $this->assertSame( 'Frontend Form', $result['form_title'] );
        $this->assertStringContainsString( 'Clara', $result['html'] );
        // Date was injected into submission data.
        $this->assertArrayHasKey( 'fill_date', $result['submission'] );
        $this->assertArrayHasKey( 'date', $result['submission'] );
    }

    public function test_generate_pdf_data_from_form_without_date(): void {
        $this->stub_html_funcs();
        if ( ! defined( 'FFC_PLUGIN_DIR' ) ) {
            define( 'FFC_PLUGIN_DIR', \dirname( __DIR__, 2 ) . '/' );
        }
        Functions\when( 'get_post' )->justReturn( (object) array( 'post_title' => 'NoDate Form' ) );
        Functions\when( 'get_post_meta' )->alias(
            static function ( $id, $key ) {
                // `_ffc_form_config` empty (falls back to default HTML);
                // return an empty array so the array type-hint is satisfied.
                return '_ffc_form_bg' === $key ? '' : array();
            }
        );

        $result = $this->generator->generate_pdf_data_from_form( array( 'name' => 'Zed' ), 11, null );

        $this->assertIsArray( $result );
        // No date passed → no fill_date injected.
        $this->assertArrayNotHasKey( 'fill_date', $result['submission'] );
        $this->assertSame( '', $result['auth_code'] ?? '' );
    }

    // ==================================================================
    // generate_magic_link_qr() — static delegator to PdfHtmlRenderer
    // ==================================================================

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_generate_magic_link_qr_returns_empty_when_no_token(): void {
        // Overload the repo the renderer news up so findMagicTokenById → null.
        $repo = \Mockery::mock( 'overload:FreeFormCertificate\Repositories\SubmissionRepository' );
        $repo->shouldReceive( 'findMagicTokenById' )->andReturn( null );

        $result = PdfGenerator::generate_magic_link_qr( 42 );

        $this->assertSame( '', $result );
    }
}
