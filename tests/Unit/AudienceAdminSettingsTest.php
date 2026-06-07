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
        $wpdb = Mockery::mock( 'wpdb' )->makePartial();
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
        $page = new AudienceAdminSettings( 'ffc-scheduling', \Mockery::mock( \FreeFormCertificate\Audience\AudienceAdminImport::class )->shouldIgnoreMissing() );
        $this->assertInstanceOf( AudienceAdminSettings::class, $page );
    }

    // ==================================================================
    // handle_visibility_settings() — no action
    // ==================================================================

    public function test_handle_visibility_settings_does_nothing_without_post(): void {
        unset( $_POST['ffc_visibility_action'] );
        $page = new AudienceAdminSettings( 'ffc-scheduling', \Mockery::mock( \FreeFormCertificate\Audience\AudienceAdminImport::class )->shouldIgnoreMissing() );
        $page->handle_visibility_settings();
        $this->assertTrue( true );
    }

    // ==================================================================
    // handle_global_holiday_actions() — no permission
    // ==================================================================

    public function test_handle_global_holiday_actions_returns_early_without_permission(): void {
        Functions\when( 'current_user_can' )->justReturn( false );

        $page = new AudienceAdminSettings( 'ffc-scheduling', \Mockery::mock( \FreeFormCertificate\Audience\AudienceAdminImport::class )->shouldIgnoreMissing() );
        $page->handle_global_holiday_actions();
        $this->assertTrue( true );
    }

    // ==================================================================
    // render_page() — default tab
    // ==================================================================

    public function test_render_page_renders_general_tab(): void {
        Functions\when( 'date_i18n' )->alias( function ( $f, $t = null ) { return date( $f, $t ?? time() ); } );
        Functions\when( 'wp_nonce_url' )->justReturn( '/' );

        $page = new AudienceAdminSettings( 'ffc-scheduling', \Mockery::mock( \FreeFormCertificate\Audience\AudienceAdminImport::class )->shouldIgnoreMissing() );
        ob_start();
        $page->render_page();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'wrap', $output );
    }

    /**
     * Regression: a holiday saved as 05/06 must render as 05/06, not 04/06.
     * Holiday dates are wall-clock DATE strings; rendering them through the
     * instant API on a UTC-3 site rolled them back one day. Drives a UTC-3
     * site with a timezone-honouring wp_date stub and asserts the list shows
     * the literal date.
     */
    public function test_general_tab_renders_holiday_date_without_timezone_shift(): void {
        $prev_tz = date_default_timezone_get(); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.timezone_change_date_default_timezone_get
        date_default_timezone_set( 'UTC' ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.timezone_change_date_default_timezone_set

        Functions\when( 'date_i18n' )->alias( function ( $f, $t = null ) { return date( $f, $t ?? time() ); } );
        Functions\when( 'wp_nonce_url' )->justReturn( '/' );
        Functions\when( 'get_option' )->alias( function ( $key, $default = false ) {
            if ( 'ffc_global_holidays' === $key ) {
                return array( array( 'date' => '2026-06-05', 'description' => 'Test holiday' ) );
            }
            if ( 'ffc_settings' === $key ) {
                return array();
            }
            return false !== $default ? $default : '';
        } );
        Functions\when( 'wp_timezone' )->alias( static function () {
            return new \DateTimeZone( '-03:00' );
        } );
        Functions\when( 'wp_date' )->alias( static function ( $format, $ts = null, $tz = null ) {
            $ts = null === $ts ? time() : (int) $ts;
            $dt = ( new \DateTimeImmutable( '@' . $ts ) )->setTimezone(
                $tz instanceof \DateTimeZone ? $tz : new \DateTimeZone( 'UTC' )
            );
            return $dt->format( $format );
        } );

        $page = new AudienceAdminSettings( 'ffc-scheduling', \Mockery::mock( \FreeFormCertificate\Audience\AudienceAdminImport::class )->shouldIgnoreMissing() );
        ob_start();
        $page->render_page();
        $output = ob_get_clean();

        date_default_timezone_set( $prev_tz ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.timezone_change_date_default_timezone_set

        $this->assertStringContainsString( '05/06/2026', $output );
        $this->assertStringNotContainsString( '04/06/2026', $output );
    }
}
