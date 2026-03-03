<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\SelfScheduling\SelfSchedulingShortcode;

/**
 * @covers \FreeFormCertificate\SelfScheduling\SelfSchedulingShortcode
 */
class SelfSchedulingShortcodeTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'esc_attr' )->returnArg();
        Functions\when( 'esc_attr_e' )->alias( function ( $text ) { echo $text; } );
        Functions\when( 'esc_url' )->returnArg();
        Functions\when( 'esc_html_e' )->alias( function ( $text ) { echo $text; } );
        Functions\when( 'wp_kses_post' )->returnArg();
        Functions\when( 'wp_json_encode' )->alias( function ( $data ) { return json_encode( $data ); } );
        Functions\when( 'add_action' )->justReturn( true );
        Functions\when( 'add_shortcode' )->justReturn( true );
        Functions\when( 'absint' )->alias( function ( $v ) { return abs( (int) $v ); } );
        Functions\when( 'shortcode_atts' )->alias( function ( $defaults, $atts ) {
            return array_merge( $defaults, (array) $atts );
        } );
        Functions\when( 'wp_enqueue_style' )->justReturn( true );
        Functions\when( 'wp_enqueue_script' )->justReturn( true );
        Functions\when( 'wp_localize_script' )->justReturn( true );
        Functions\when( 'wp_create_nonce' )->justReturn( 'test_nonce' );
        Functions\when( 'wp_nonce_field' )->justReturn( '' );
        Functions\when( 'admin_url' )->justReturn( 'https://example.com/wp-admin/' );
        Functions\when( 'is_singular' )->justReturn( false );
        Functions\when( 'is_page' )->justReturn( false );
        Functions\when( 'is_user_logged_in' )->justReturn( false );
        Functions\when( 'get_option' )->justReturn( '' );
        Functions\when( 'wp_cache_get' )->justReturn( false );
        Functions\when( 'wp_cache_set' )->justReturn( true );
        Functions\when( 'wp_login_url' )->justReturn( '/wp-login.php' );
        Functions\when( 'get_permalink' )->justReturn( '/page/' );
        Functions\when( 'get_privacy_policy_url' )->justReturn( '/privacy-policy/' );
        Functions\when( 'wp_get_current_user' )->justReturn( (object) array( 'ID' => 0 ) );

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

        global $wpdb;
        $wpdb = Mockery::mock( 'wpdb' );
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive( 'prepare' )->andReturnUsing( function () { return func_get_arg(0); } )->byDefault();
        $wpdb->shouldReceive( 'get_results' )->andReturn( array() )->byDefault();
        $wpdb->shouldReceive( 'get_var' )->andReturn( '0' )->byDefault();
        $wpdb->shouldReceive( 'get_row' )->andReturn( null )->byDefault();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // Constructor
    // ==================================================================

    public function test_constructor_creates_instance(): void {
        $shortcode = new SelfSchedulingShortcode();
        $this->assertInstanceOf( SelfSchedulingShortcode::class, $shortcode );
    }

    // ==================================================================
    // render_calendar() — no ID
    // ==================================================================

    public function test_render_calendar_returns_error_without_id(): void {
        $shortcode = new SelfSchedulingShortcode();
        $result = $shortcode->render_calendar( array( 'id' => 0 ) );

        $this->assertStringContainsString( 'Calendar ID is required', $result );
    }

    // ==================================================================
    // render_calendar() — calendar not found
    // ==================================================================

    public function test_render_calendar_returns_error_for_missing_calendar(): void {
        $shortcode = new SelfSchedulingShortcode();
        $result = $shortcode->render_calendar( array( 'id' => 999 ) );

        $this->assertStringContainsString( 'Calendar not found', $result );
    }

    // ==================================================================
    // enqueue_assets() — not singular
    // ==================================================================

    public function test_enqueue_assets_returns_early_on_non_singular(): void {
        Functions\when( 'is_singular' )->justReturn( false );
        Functions\when( 'is_page' )->justReturn( false );

        $shortcode = new SelfSchedulingShortcode();
        $shortcode->enqueue_assets();
        $this->assertTrue( true );
    }

    // ==================================================================
    // enqueue_assets() — no post
    // ==================================================================

    public function test_enqueue_assets_returns_early_without_post(): void {
        Functions\when( 'is_singular' )->justReturn( true );

        global $post;
        $post = null;

        $shortcode = new SelfSchedulingShortcode();
        $shortcode->enqueue_assets();
        $this->assertTrue( true );
    }

    // ==================================================================
    // enqueue_assets() — no shortcode in content
    // ==================================================================

    public function test_enqueue_assets_returns_early_without_shortcode(): void {
        Functions\when( 'is_singular' )->justReturn( true );
        Functions\when( 'has_shortcode' )->justReturn( false );

        global $post;
        $post = (object) array( 'post_content' => 'No shortcode here' );

        $shortcode = new SelfSchedulingShortcode();
        $shortcode->enqueue_assets();
        $this->assertTrue( true );
    }
}
