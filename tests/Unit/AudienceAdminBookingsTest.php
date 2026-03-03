<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Audience\AudienceAdminBookings;

/**
 * @covers \FreeFormCertificate\Audience\AudienceAdminBookings
 */
class AudienceAdminBookingsTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'esc_attr' )->returnArg();
        Functions\when( 'esc_url' )->returnArg();
        Functions\when( 'esc_html_e' )->alias( function ( $text ) { echo $text; } );
        Functions\when( 'esc_attr_e' )->alias( function ( $text ) { echo $text; } );
        Functions\when( 'absint' )->alias( function ( $v ) { return abs( (int) $v ); } );
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'wp_unslash' )->returnArg();
        Functions\when( 'admin_url' )->justReturn( 'https://example.com/wp-admin/' );
        Functions\when( 'selected' )->justReturn( '' );
        Functions\when( 'settings_errors' )->justReturn( '' );
        Functions\when( 'date_i18n' )->alias( function ( $f, $t = null ) { return date( $f, $t ?? time() ); } );
        Functions\when( 'get_option' )->justReturn( 'Y-m-d' );
        Functions\when( 'wp_trim_words' )->returnArg();
        Functions\when( 'get_users' )->justReturn( array() );
        Functions\when( 'wp_cache_get' )->justReturn( false );
        Functions\when( 'wp_cache_set' )->justReturn( true );
        Functions\when( 'wp_parse_args' )->alias( function ( $args, $defaults = array() ) {
            return array_merge( $defaults, (array) $args );
        } );
        Functions\when( 'sanitize_sql_orderby' )->returnArg();
        Functions\when( 'sanitize_key' )->returnArg();

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
        unset( $_GET['schedule_id'], $_GET['environment_id'], $_GET['status'], $_GET['date_from'], $_GET['date_to'] );
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // Constructor
    // ==================================================================

    public function test_constructor_creates_instance(): void {
        $page = new AudienceAdminBookings( 'ffc-scheduling' );
        $this->assertInstanceOf( AudienceAdminBookings::class, $page );
    }

    // ==================================================================
    // render_page() — empty bookings
    // ==================================================================

    public function test_render_page_renders_empty_list(): void {
        $page = new AudienceAdminBookings( 'ffc-scheduling' );
        ob_start();
        $page->render_page();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'wrap', $output );
    }
}
