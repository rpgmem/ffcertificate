<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Frontend\Frontend;

/**
 * @covers \FreeFormCertificate\Frontend\Frontend
 * @runClassInSeparateProcess
 * @preserveGlobalState disabled
 */
class FrontendTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'esc_attr' )->returnArg();
        Functions\when( 'esc_url' )->returnArg();
        Functions\when( 'add_action' )->justReturn( true );
        Functions\when( 'add_shortcode' )->justReturn( true );
        Functions\when( 'get_option' )->justReturn( array() );
        Functions\when( 'get_post_meta' )->justReturn( '' );
        Functions\when( 'wp_enqueue_style' )->justReturn( true );
        Functions\when( 'wp_enqueue_script' )->justReturn( true );
        Functions\when( 'wp_localize_script' )->justReturn( true );
        Functions\when( 'wp_create_nonce' )->justReturn( 'test_nonce' );
        Functions\when( 'admin_url' )->justReturn( 'https://example.com/wp-admin/admin-ajax.php' );
        Functions\when( 'wp_parse_args' )->alias( function ( $args, $defaults = array() ) {
            if ( is_array( $args ) ) {
                return array_merge( $defaults, $args );
            }
            return $defaults;
        } );
        // Stubs for the WP shortcode helpers used by
        // localize_geofence_config() since the 6.6.8 fix. Default
        // regex pattern matches `[ffc_form ...]` and captures the
        // attribute string in group 3 (WP's actual layout).
        Functions\when( 'get_shortcode_regex' )->justReturn(
            '\[(\[?)(ffc_form)(?![\w-])([^\]\/]*(?:\/(?!\])[^\]\/]*)*?)(?:(\/)\]|\](?:([^\[]*+(?:\[(?!\/\2\])[^\[]*+)*+)\[\/\2\])?)(\]?)'
        );
        Functions\when( 'shortcode_parse_atts' )->alias( function ( $text ) {
            $atts    = array();
            $pattern = '/(\w+)\s*=\s*"([^"]*)"|(\w+)\s*=\s*\'([^\']*)\'|(\w+)\s*=\s*([^\s\]]+)/';
            if ( preg_match_all( $pattern, (string) $text, $m, PREG_SET_ORDER ) ) {
                foreach ( $m as $row ) {
                    $key            = $row[1] !== '' ? $row[1] : ( $row[3] !== '' ? $row[3] : $row[5] );
                    $val            = $row[1] !== '' ? $row[2] : ( $row[3] !== '' ? $row[4] : $row[6] );
                    $atts[ $key ] = $val;
                }
            }
            return $atts;
        } );

        // PublicCsvDownload::register_hooks() instantiates PublicCsvExporter,
        // whose SubmissionRepository constructor reads $wpdb->prefix.
        global $wpdb;
        if ( ! $wpdb ) {
            $wpdb         = Mockery::mock( 'wpdb' );
            $wpdb->prefix = 'wp_';
        }

        if ( ! defined( 'ABSPATH' ) ) {
            define( 'ABSPATH', '/tmp/' );
        }
        if ( ! defined( 'FFC_PLUGIN_URL' ) ) {
            define( 'FFC_PLUGIN_URL', 'https://example.com/wp-content/plugins/ffcertificate/' );
        }
        if ( ! defined( 'FFC_VERSION' ) ) {
            define( 'FFC_VERSION', '4.12.0' );
        }
        if ( ! defined( 'FFC_HTML2CANVAS_VERSION' ) ) {
            define( 'FFC_HTML2CANVAS_VERSION', '1.4.1' );
        }
        if ( ! defined( 'FFC_JSPDF_VERSION' ) ) {
            define( 'FFC_JSPDF_VERSION', '2.5.1' );
        }
    }

    protected function tearDown(): void {
        global $wpdb;
        $wpdb = null;
        Monkey\tearDown();
        parent::tearDown();
    }

    private function makeSubmissionHandlerMock(): object {
        return Mockery::mock( 'FreeFormCertificate\Submissions\SubmissionHandler' );
    }

    // ==================================================================
    // Constructor
    // ==================================================================

    public function test_constructor_registers_hooks(): void {
        $handler = $this->makeSubmissionHandlerMock();
        $frontend = new Frontend( $handler );
        $this->assertInstanceOf( Frontend::class, $frontend );
    }

    // ==================================================================
    // frontend_assets() — no global $post
    // ==================================================================

    public function test_frontend_assets_returns_early_when_no_post(): void {
        global $post;
        $post = null;

        $handler = $this->makeSubmissionHandlerMock();
        $frontend = new Frontend( $handler );

        // Should not throw or enqueue anything
        $frontend->frontend_assets();
        $this->assertTrue( true ); // Reached without error
    }

    // ==================================================================
    // frontend_assets() — post is not WP_Post
    // ==================================================================

    public function test_frontend_assets_returns_early_when_post_is_not_wp_post(): void {
        global $post;
        $post = 'not_a_post';

        $handler = $this->makeSubmissionHandlerMock();
        $frontend = new Frontend( $handler );

        $frontend->frontend_assets();
        $this->assertTrue( true );
    }

    // ==================================================================
    // frontend_assets() — post without shortcodes
    // ==================================================================

    public function test_frontend_assets_returns_early_when_no_shortcodes(): void {
        global $post;
        $post = Mockery::mock( 'WP_Post' );
        $post->post_content = 'Just a regular post.';

        Functions\when( 'has_shortcode' )->justReturn( false );

        $handler = $this->makeSubmissionHandlerMock();
        $frontend = new Frontend( $handler );

        $frontend->frontend_assets();
        $this->assertTrue( true );
    }

    // ==================================================================
    // frontend_assets() — post with ffc_form shortcode
    // ==================================================================

    public function test_frontend_assets_enqueues_when_form_shortcode_present(): void {
        global $post;
        $post = Mockery::mock( 'WP_Post' );
        $post->post_content = '[ffc_form id="42"]';

        Functions\when( 'has_shortcode' )->alias( function ( $content, $tag ) {
            return $tag === 'ffc_form' && strpos( $content, '[ffc_form' ) !== false;
        } );

        $enqueued_styles = array();
        $enqueued_scripts = array();
        Functions\when( 'wp_enqueue_style' )->alias( function () use ( &$enqueued_styles ) {
            $enqueued_styles[] = func_get_arg( 0 );
        } );
        Functions\when( 'wp_enqueue_script' )->alias( function () use ( &$enqueued_scripts ) {
            $enqueued_scripts[] = func_get_arg( 0 );
        } );

        $handler = $this->makeSubmissionHandlerMock();
        $frontend = new Frontend( $handler );

        $frontend->frontend_assets();

        $this->assertContains( 'ffc-frontend-css', $enqueued_styles );
        $this->assertContains( 'ffc-frontend-js', $enqueued_scripts );
        $this->assertContains( 'html2canvas', $enqueued_scripts );
        $this->assertContains( 'jspdf', $enqueued_scripts );
    }

    // ==================================================================
    // frontend_assets() — post with ffc_verification shortcode
    // ==================================================================

    public function test_frontend_assets_enqueues_when_verification_shortcode_present(): void {
        global $post;
        $post = Mockery::mock( 'WP_Post' );
        $post->post_content = '[ffc_verification]';

        Functions\when( 'has_shortcode' )->alias( function ( $content, $tag ) {
            return $tag === 'ffc_verification' && strpos( $content, '[ffc_verification' ) !== false;
        } );

        $enqueued_scripts = array();
        Functions\when( 'wp_enqueue_script' )->alias( function () use ( &$enqueued_scripts ) {
            $enqueued_scripts[] = func_get_arg( 0 );
        } );

        $handler = $this->makeSubmissionHandlerMock();
        $frontend = new Frontend( $handler );

        $frontend->frontend_assets();

        $this->assertContains( 'ffc-frontend-js', $enqueued_scripts );
        $this->assertContains( 'ffc-geofence-frontend', $enqueued_scripts );
    }

    // ==================================================================
    // frontend_assets() — geofence config localized
    // ==================================================================

    public function test_frontend_assets_localizes_geofence_config(): void {
        global $post;
        $post = Mockery::mock( 'WP_Post' );
        $post->post_content = '[ffc_form id="99"]';

        Functions\when( 'has_shortcode' )->justReturn( true );

        $localized = array();
        Functions\when( 'wp_localize_script' )->alias( function ( $handle, $name, $data ) use ( &$localized ) {
            $localized[ $name ] = $data;
        } );

        $handler = $this->makeSubmissionHandlerMock();
        $frontend = new Frontend( $handler );

        $frontend->frontend_assets();

        // ffc_ajax localization should contain ajax_url and nonce
        $this->assertArrayHasKey( 'ffc_ajax', $localized );
        $this->assertArrayHasKey( 'ajax_url', $localized['ffc_ajax'] );
        $this->assertArrayHasKey( 'nonce', $localized['ffc_ajax'] );
        $this->assertArrayHasKey( 'strings', $localized['ffc_ajax'] );

        // Geofence config should be localized
        $this->assertArrayHasKey( 'ffcGeofenceConfig', $localized );
        $this->assertArrayHasKey( '_global', $localized['ffcGeofenceConfig'] );
    }

    /**
     * Stub `get_shortcode_regex` + `shortcode_parse_atts` with simplified
     * WP-shape implementations so the production regex switch (#XXX 6.6.8)
     * can be driven through both quoted and unquoted attribute formats.
     * Returns the captured `wp_localize_script` payload bag.
     *
     * @param string $post_content Raw `$post->post_content` to drive.
     * @return array<string, mixed>
     */
    private function capture_geofence_for_content( string $post_content ): array {
        global $post;
        $post = Mockery::mock( 'WP_Post' );
        $post->post_content = $post_content;

        Functions\when( 'has_shortcode' )->justReturn( true );

        // Alias Geofence so we don't have to wire up get_post_meta /
        // settings / option reads — we're testing the regex+atts path,
        // not the config builder.
        $geofence_mock = Mockery::mock( 'alias:\FreeFormCertificate\Security\Geofence' );
        $geofence_mock->shouldReceive( 'get_frontend_config' )
            ->andReturnUsing( static fn( $id ) => array( 'formId' => (int) $id, 'datetime' => array( 'enabled' => true ) ) );

        $localized = array();
        Functions\when( 'wp_localize_script' )->alias( function ( $handle, $name, $data ) use ( &$localized ) {
            $localized[ $name ] = $data;
        } );

        $handler  = $this->makeSubmissionHandlerMock();
        $frontend = new Frontend( $handler );
        $frontend->frontend_assets();

        return $localized;
    }

    public function test_localize_geofence_accepts_quoted_id(): void {
        $localized = $this->capture_geofence_for_content( '[ffc_form id="42"]' );

        $this->assertArrayHasKey( 'ffcGeofenceConfig', $localized );
        // Form id was extracted — `_global` PLUS the per-form key share the array.
        $form_keys = array_filter(
            array_keys( $localized['ffcGeofenceConfig'] ),
            static fn( $k ) => is_int( $k ) || ( is_string( $k ) && ctype_digit( $k ) )
        );
        $this->assertContains( 42, array_map( 'intval', $form_keys ) );
    }

    public function test_localize_geofence_accepts_unquoted_id(): void {
        // The bug fixed in 6.6.8: pre-fix regex required quotes around
        // the id attribute, so `[ffc_form id=42]` (WP-valid unquoted
        // shorthand) silently failed to localize and the JS geofence
        // never received its config. The form rendered as if no
        // datetime restriction existed.
        $localized = $this->capture_geofence_for_content( '[ffc_form id=42]' );

        $this->assertArrayHasKey( 'ffcGeofenceConfig', $localized );
        $form_keys = array_filter(
            array_keys( $localized['ffcGeofenceConfig'] ),
            static fn( $k ) => is_int( $k ) || ( is_string( $k ) && ctype_digit( $k ) )
        );
        $this->assertContains( 42, array_map( 'intval', $form_keys ), 'unquoted id MUST localize too' );
    }

    public function test_localize_geofence_accepts_extra_attributes(): void {
        // Same root cause, second failing shape: pre-fix regex required
        // `id=...` to be the LAST attribute before the closing bracket.
        $localized = $this->capture_geofence_for_content( '[ffc_form id="42" class="ffc-custom"]' );

        $this->assertArrayHasKey( 'ffcGeofenceConfig', $localized );
        $form_keys = array_filter(
            array_keys( $localized['ffcGeofenceConfig'] ),
            static fn( $k ) => is_int( $k ) || ( is_string( $k ) && ctype_digit( $k ) )
        );
        $this->assertContains( 42, array_map( 'intval', $form_keys ), 'extra attributes MUST NOT block localize' );
    }
}
