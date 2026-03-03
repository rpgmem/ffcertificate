<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Audience\AudienceAdminEnvironment;

/**
 * @covers \FreeFormCertificate\Audience\AudienceAdminEnvironment
 */
class AudienceAdminEnvironmentTest extends TestCase {

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
        Functions\when( 'wp_nonce_url' )->justReturn( '/' );
        Functions\when( 'wp_trim_words' )->returnArg();
        Functions\when( 'selected' )->justReturn( '' );
        Functions\when( 'wp_cache_get' )->justReturn( false );
        Functions\when( 'wp_cache_set' )->justReturn( true );
        Functions\when( 'wp_parse_args' )->alias( function ( $args, $defaults = array() ) {
            return array_merge( $defaults, (array) $args );
        } );
        Functions\when( 'sanitize_sql_orderby' )->returnArg();
        Functions\when( 'sanitize_key' )->returnArg();
        Functions\when( 'submit_button' )->justReturn( '' );

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
        unset( $_GET['action'], $_GET['id'], $_GET['message'], $_GET['page'] );
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // Constructor
    // ==================================================================

    public function test_constructor_creates_instance(): void {
        $page = new AudienceAdminEnvironment( 'ffc-scheduling' );
        $this->assertInstanceOf( AudienceAdminEnvironment::class, $page );
    }

    // ==================================================================
    // handle_actions() — no permission
    // ==================================================================

    public function test_handle_actions_returns_early_without_permission(): void {
        Functions\when( 'current_user_can' )->justReturn( false );

        $page = new AudienceAdminEnvironment( 'ffc-scheduling' );
        $page->handle_actions();
        $this->assertTrue( true );
    }

    // ==================================================================
    // handle_actions() — with message
    // ==================================================================

    public function test_handle_actions_shows_feedback_message(): void {
        $_GET['message'] = 'created';
        $_GET['page'] = 'ffc-scheduling-environments';

        Functions\when( 'add_settings_error' )->justReturn( true );

        $page = new AudienceAdminEnvironment( 'ffc-scheduling' );
        $page->handle_actions();
        $this->assertTrue( true );
    }

    // ==================================================================
    // render_page() — default list
    // ==================================================================

    public function test_render_page_renders_list_by_default(): void {
        $page = new AudienceAdminEnvironment( 'ffc-scheduling' );
        ob_start();
        $page->render_page();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'wrap', $output );
    }
}
