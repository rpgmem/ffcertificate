<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\Settings;

/**
 * Tests for Settings: plugin settings coordinator.
 *
 * Covers constructor hook registration, default settings, get_option fallback
 * chain, save delegation, QR cache clearing guard clauses, migration execution
 * guard clauses, AJAX date preview, and cache action dispatch.
 *
 * Note: Methods that call exit; after wp_safe_redirect (handle_clear_qr_cache,
 * handle_migration_execution, handle_cache_actions happy paths) are tested only
 * up to the guard-clause returns. The exit; language construct cannot be stubbed,
 * so the full redirect+exit path is intentionally not tested to avoid killing
 * the PHPUnit process.
 *
 * @covers \FreeFormCertificate\Admin\Settings
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class SettingsTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var Settings */
    private $settings;

    /** @var Mockery\MockInterface overload mock for SettingsSaveHandler */
    private $save_handler_mock;

    /** @var Mockery\MockInterface */
    private $wpdb;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // Mock $wpdb
        global $wpdb;
        $wpdb         = Mockery::mock( 'wpdb' );
        $wpdb->prefix = 'wp_';
        $this->wpdb   = $wpdb;

        $this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( function () {
            return func_get_args()[0];
        } )->byDefault();
        $this->wpdb->shouldReceive( 'query' )->andReturn( 0 )->byDefault();

        // SettingsSaveHandler overload mock — instantiated by constructor via new
        $this->save_handler_mock = Mockery::mock( 'overload:FreeFormCertificate\Admin\SettingsSaveHandler' );
        $this->save_handler_mock->shouldReceive( 'handle_all_submissions' )
            ->byDefault();

        // Common WP stubs
        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'sanitize_key' )->alias( function ( $key ) {
            return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
        } );
        Functions\when( 'wp_unslash' )->returnArg();
        Functions\when( 'absint' )->alias( function ( $val ) {
            return abs( (int) $val );
        } );
        Functions\when( 'add_query_arg' )->justReturn( 'https://example.com/wp-admin/redirect' );
        Functions\when( 'admin_url' )->alias( function ( $path = '' ) {
            return 'https://example.com/wp-admin/' . $path;
        } );
        Functions\when( 'get_option' )->justReturn( array() );
        Functions\when( 'current_user_can' )->justReturn( true );

        // Stub add_action to capture registrations (constructor calls it)
        Functions\when( 'add_action' )->justReturn( true );

        // Create Settings instance — constructor triggers add_action calls
        $handler = Mockery::mock( 'FreeFormCertificate\Submissions\SubmissionHandler' );
        $this->settings = new Settings( $handler );
    }

    protected function tearDown(): void {
        unset( $_GET['ffc_clear_qr_cache'], $_GET['_wpnonce'], $_GET['ffc_run_migration'], $_GET['action'] );
        unset( $_POST['format'], $_POST['custom_format'] );
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // Constructor — hook registration
    // ==================================================================

    public function test_constructor_registers_hooks(): void {
        // Reset Brain\Monkey to capture fresh expectations
        Monkey\tearDown();
        Monkey\setUp();

        // Re-stub everything needed for construction
        Functions\when( '__' )->returnArg();
        Functions\when( 'get_option' )->justReturn( array() );

        $save_mock = Mockery::mock( 'overload:FreeFormCertificate\Admin\SettingsSaveHandler' );
        $save_mock->shouldReceive( 'handle_all_submissions' )->byDefault();

        Functions\expect( 'add_action' )
            ->once()
            ->with( 'admin_menu', Mockery::type( 'array' ), 20 );

        Functions\expect( 'add_action' )
            ->once()
            ->with( 'admin_init', Mockery::on( function ( $cb ) {
                return is_array( $cb ) && $cb[1] === 'handle_settings_submission';
            } ) );

        Functions\expect( 'add_action' )
            ->once()
            ->with( 'admin_init', Mockery::on( function ( $cb ) {
                return is_array( $cb ) && $cb[1] === 'handle_clear_qr_cache';
            } ) );

        Functions\expect( 'add_action' )
            ->once()
            ->with( 'admin_init', Mockery::on( function ( $cb ) {
                return is_array( $cb ) && $cb[1] === 'handle_migration_execution';
            } ) );

        Functions\expect( 'add_action' )
            ->once()
            ->with( 'wp_ajax_ffc_preview_date_format', Mockery::type( 'array' ) );

        Functions\expect( 'add_action' )
            ->once()
            ->with( 'admin_init', Mockery::on( function ( $cb ) {
                return is_array( $cb ) && $cb[1] === 'handle_cache_actions';
            } ) );

        $handler = Mockery::mock( 'FreeFormCertificate\Submissions\SubmissionHandler' );
        new Settings( $handler );
    }

    // ==================================================================
    // get_default_settings()
    // ==================================================================

    public function test_get_default_settings_returns_expected_keys(): void {
        $defaults = $this->settings->get_default_settings();

        $this->assertIsArray( $defaults );
        $this->assertArrayHasKey( 'cleanup_days', $defaults );
        $this->assertArrayHasKey( 'smtp_mode', $defaults );
        $this->assertArrayHasKey( 'smtp_host', $defaults );
        $this->assertArrayHasKey( 'smtp_port', $defaults );
        $this->assertArrayHasKey( 'qr_cache_enabled', $defaults );
        $this->assertArrayHasKey( 'qr_default_size', $defaults );
        $this->assertArrayHasKey( 'date_format', $defaults );
        $this->assertArrayHasKey( 'cache_enabled', $defaults );
        $this->assertArrayHasKey( 'cache_expiration', $defaults );
        $this->assertArrayHasKey( 'cache_auto_warm', $defaults );
        $this->assertSame( 365, $defaults['cleanup_days'] );
        $this->assertSame( 'wp', $defaults['smtp_mode'] );
        $this->assertSame( 'F j, Y', $defaults['date_format'] );
    }

    // ==================================================================
    // get_option()
    // ==================================================================

    public function test_get_option_returns_saved_value(): void {
        Functions\when( 'get_option' )->justReturn( array(
            'smtp_host'   => 'smtp.saved.com',
            'cleanup_days' => 90,
        ) );

        $result = $this->settings->get_option( 'smtp_host' );
        $this->assertSame( 'smtp.saved.com', $result );
    }

    public function test_get_option_returns_default_when_not_saved(): void {
        Functions\when( 'get_option' )->justReturn( array() );

        $result = $this->settings->get_option( 'cleanup_days' );
        $this->assertSame( 365, $result );
    }

    public function test_get_option_returns_empty_string_for_unknown_key(): void {
        Functions\when( 'get_option' )->justReturn( array() );

        $result = $this->settings->get_option( 'nonexistent_setting' );
        $this->assertSame( '', $result );
    }

    // ==================================================================
    // handle_settings_submission()
    // ==================================================================

    public function test_handle_settings_submission_delegates_to_save_handler(): void {
        $this->save_handler_mock->shouldReceive( 'handle_all_submissions' )
            ->once();

        $this->settings->handle_settings_submission();
    }

    // ==================================================================
    // handle_clear_qr_cache() — guard clauses
    // ==================================================================

    public function test_handle_clear_qr_cache_does_nothing_without_get_params(): void {
        unset( $_GET['ffc_clear_qr_cache'], $_GET['_wpnonce'] );

        // wpdb->query should NOT be called
        $this->wpdb->shouldReceive( 'query' )->never();

        $this->settings->handle_clear_qr_cache();
    }

    public function test_handle_clear_qr_cache_does_nothing_with_invalid_nonce(): void {
        $_GET['ffc_clear_qr_cache'] = '1';
        $_GET['_wpnonce'] = 'invalid_nonce';

        Functions\when( 'wp_verify_nonce' )->justReturn( false );

        // wpdb->query should NOT be called
        $this->wpdb->shouldReceive( 'query' )->never();

        $this->settings->handle_clear_qr_cache();
    }

    /**
     * Test that handle_clear_qr_cache executes the UPDATE query when
     * GET params are present and nonce is valid.
     *
     * Note: The method calls exit; after wp_safe_redirect, which would
     * kill the PHPUnit process. We verify the query runs and the redirect
     * is called, then catch the exit via a custom wp_safe_redirect stub
     * that throws an exception.
     */
    public function test_handle_clear_qr_cache_clears_and_redirects(): void {
        $_GET['ffc_clear_qr_cache'] = '1';
        $_GET['_wpnonce'] = 'valid_nonce';

        Functions\when( 'wp_verify_nonce' )->justReturn( 1 );

        $this->wpdb->shouldReceive( 'query' )
            ->once()
            ->andReturn( 5 );

        Functions\when( 'wp_safe_redirect' )->alias( function () {
            throw new \RuntimeException( 'redirect_called' );
        } );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'redirect_called' );

        $this->settings->handle_clear_qr_cache();
    }

    // ==================================================================
    // handle_migration_execution() — guard clauses
    // ==================================================================

    public function test_handle_migration_does_nothing_without_get_param(): void {
        unset( $_GET['ffc_run_migration'] );

        // Should return early — no permission check needed
        Functions\expect( 'current_user_can' )->never();

        $this->settings->handle_migration_execution();
    }

    public function test_handle_migration_dies_without_permission(): void {
        $_GET['ffc_run_migration'] = 'test_migration';

        Functions\when( 'current_user_can' )->justReturn( false );
        Functions\when( 'wp_die' )->alias( function ( $msg ) {
            throw new \RuntimeException( 'wp_die: ' . $msg );
        } );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'wp_die' );

        $this->settings->handle_migration_execution();
    }

    public function test_handle_migration_runs_and_redirects(): void {
        $_GET['ffc_run_migration'] = 'test_migration';
        $_GET['_wpnonce'] = 'valid_nonce';

        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
        Functions\when( 'is_wp_error' )->alias( function ( $thing ) {
            return $thing instanceof \WP_Error;
        } );

        // MigrationManager overload mock
        $migration_mock = Mockery::mock( 'overload:FreeFormCertificate\Migrations\MigrationManager' );
        $migration_mock->shouldReceive( 'run_migration' )
            ->once()
            ->with( 'test_migration' )
            ->andReturn( array( 'processed' => 42 ) );

        Functions\when( 'wp_safe_redirect' )->alias( function () {
            throw new \RuntimeException( 'redirect_called' );
        } );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'redirect_called' );

        $this->settings->handle_migration_execution();
    }

    // ==================================================================
    // ajax_preview_date_format()
    // ==================================================================

    public function test_ajax_preview_date_format_returns_formatted_date(): void {
        $_POST['format'] = 'd/m/Y';
        $_POST['custom_format'] = '';

        Functions\when( 'check_ajax_referer' )->justReturn( true );
        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'date_i18n' )->alias( function ( $format, $timestamp ) {
            return date( $format, $timestamp );
        } );

        // Don't throw from wp_send_json_success — the source catches Exception
        // and calls wp_send_json_error, which would complicate things.
        $captured_data = null;
        Functions\when( 'wp_send_json_success' )->alias( function ( $data ) use ( &$captured_data ) {
            $captured_data = $data;
        } );
        Functions\when( 'wp_send_json_error' )->alias( function ( $data ) {
            throw new \RuntimeException( 'unexpected wp_send_json_error' );
        } );

        $this->settings->ajax_preview_date_format();

        $this->assertNotNull( $captured_data );
        $this->assertArrayHasKey( 'formatted', $captured_data );
        // Sample date is '2026-01-04 15:30:45' formatted as 'd/m/Y' = '04/01/2026'
        $this->assertSame( '04/01/2026', $captured_data['formatted'] );
    }

    // ==================================================================
    // handle_cache_actions()
    // ==================================================================

    public function test_handle_cache_actions_warm_cache(): void {
        $_GET['action'] = 'warm_cache';

        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'check_admin_referer' )->justReturn( true );

        $form_cache_mock = Mockery::mock( 'alias:FreeFormCertificate\Submissions\FormCache' );
        $form_cache_mock->shouldReceive( 'warm_all_forms' )
            ->once()
            ->andReturn( 5 );

        Functions\when( 'wp_safe_redirect' )->alias( function () {
            throw new \RuntimeException( 'redirect_called' );
        } );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'redirect_called' );

        $this->settings->handle_cache_actions();
    }

    public function test_handle_cache_actions_clear_cache(): void {
        $_GET['action'] = 'clear_cache';

        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'check_admin_referer' )->justReturn( true );

        $form_cache_mock = Mockery::mock( 'alias:FreeFormCertificate\Submissions\FormCache' );
        $form_cache_mock->shouldReceive( 'clear_all_cache' )
            ->once();

        Functions\when( 'wp_safe_redirect' )->alias( function () {
            throw new \RuntimeException( 'redirect_called' );
        } );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'redirect_called' );

        $this->settings->handle_cache_actions();
    }

    public function test_handle_cache_actions_skips_without_permission(): void {
        $_GET['action'] = 'warm_cache';

        Functions\when( 'current_user_can' )->justReturn( false );

        // check_admin_referer should never be called
        Functions\expect( 'check_admin_referer' )->never();

        $this->settings->handle_cache_actions();
    }
}
