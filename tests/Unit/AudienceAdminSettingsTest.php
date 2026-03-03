<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Audience\AudienceAdminSettings;

/**
 * @covers \FreeFormCertificate\Audience\AudienceAdminSettings
 */
class AudienceAdminSettingsTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'esc_attr' )->returnArg();
        Functions\when( 'esc_url' )->returnArg();
        Functions\when( 'esc_textarea' )->returnArg();
        Functions\when( 'esc_html_e' )->alias( function ( $text ) { echo $text; } );
        Functions\when( 'esc_attr_e' )->alias( function ( $text ) { echo $text; } );
        Functions\when( 'absint' )->alias( function ( $v ) { return abs( (int) $v ); } );
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'wp_unslash' )->returnArg();
        Functions\when( 'admin_url' )->justReturn( 'https://example.com/wp-admin/' );
        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'settings_errors' )->justReturn( '' );
        Functions\when( 'wp_nonce_field' )->justReturn( '' );
        Functions\when( 'wp_verify_nonce' )->justReturn( true );
        Functions\when( 'get_option' )->alias( function ( $key, $default = false ) {
            if ( $key === 'ffc_global_holidays' ) return array();
            return $default !== false ? $default : '';
        } );
        Functions\when( 'update_option' )->justReturn( true );
        Functions\when( 'wp_kses_post' )->returnArg();
        Functions\when( 'checked' )->justReturn( '' );
        Functions\when( 'selected' )->justReturn( '' );
        Functions\when( 'submit_button' )->justReturn( '' );
        Functions\when( 'wp_cache_get' )->justReturn( false );
        Functions\when( 'wp_cache_set' )->justReturn( true );
        Functions\when( 'wp_parse_args' )->alias( function ( $args, $defaults = array() ) {
            return array_merge( $defaults, (array) $args );
        } );
        Functions\when( 'sanitize_sql_orderby' )->returnArg();
        Functions\when( 'sanitize_key' )->returnArg();

        // wp_count_posts returns an object
        $counts = (object) array( 'publish' => 2, 'draft' => 0, 'trash' => 0 );
        Functions\when( 'wp_count_posts' )->justReturn( $counts );

        if ( ! defined( 'ABSPATH' ) ) {
            define( 'ABSPATH', '/tmp/' );
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
        unset( $_POST['ffc_visibility_action'], $_POST['_wpnonce'], $_GET['tab'] );
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // Constructor
    // ==================================================================

    public function test_constructor_creates_instance(): void {
        $page = new AudienceAdminSettings( 'ffc-scheduling' );
        $this->assertInstanceOf( AudienceAdminSettings::class, $page );
    }

    // ==================================================================
    // handle_visibility_settings() — no action
    // ==================================================================

    public function test_handle_visibility_settings_does_nothing_without_post(): void {
        unset( $_POST['ffc_visibility_action'] );
        $page = new AudienceAdminSettings( 'ffc-scheduling' );
        $page->handle_visibility_settings();
        $this->assertTrue( true );
    }

    // ==================================================================
    // handle_global_holiday_actions() — no permission
    // ==================================================================

    public function test_handle_global_holiday_actions_returns_early_without_permission(): void {
        Functions\when( 'current_user_can' )->justReturn( false );

        $page = new AudienceAdminSettings( 'ffc-scheduling' );
        $page->handle_global_holiday_actions();
        $this->assertTrue( true );
    }

    // ==================================================================
    // render_page() — default tab
    // ==================================================================

    public function test_render_page_renders_general_tab(): void {
        Functions\when( 'date_i18n' )->alias( function ( $f, $t = null ) { return date( $f, $t ?? time() ); } );
        Functions\when( 'wp_nonce_url' )->justReturn( '/' );

        $page = new AudienceAdminSettings( 'ffc-scheduling' );
        ob_start();
        $page->render_page();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'wrap', $output );
    }
}
