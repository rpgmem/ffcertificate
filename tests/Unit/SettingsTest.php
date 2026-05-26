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
        Functions\when( 'wp_cache_get' )->justReturn( false );
        Functions\when( 'wp_cache_set' )->justReturn( true );
        Functions\when( 'wp_cache_delete' )->justReturn( true );
        Functions\when( 'wp_cache_flush' )->justReturn( true );
        Functions\when( 'wp_cache_flush_group' )->justReturn( true );

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
        // Default changed from 'F j, Y' to 'd/m/Y' in #244 — Brazilian-
        // locale friendly. Installs that explicitly saved 'F j, Y' keep
        // it (get_option returns the persisted value, not the default).
        $this->assertSame( 'd/m/Y', $defaults['date_format'] );
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
        $form_cache_mock->shouldReceive( 'purge_external_caches_for_all_forms' )
            ->once()
            ->with( 'manual_clear_all' )
            ->andReturn( 0 );

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

    // ==================================================================
    // handle_url_shortener_cleanup()
    // ==================================================================

    /**
     * Stub the WP functions the URL-cleanup handler needs and make
     * wp_safe_redirect throw so we capture the end of the happy path
     * without hitting exit;.
     *
     * @return void
     */
    private function arrange_url_cleanup_stubs(): void {
        if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
            define( 'MINUTE_IN_SECONDS', 60 );
        }
        Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
        Functions\when( 'get_current_user_id' )->justReturn( 7 );
        Functions\when( 'update_option' )->justReturn( true );
        Functions\when( 'set_transient' )->justReturn( true );
        Functions\when( 'delete_transient' )->justReturn( true );
        // The lazily-built UrlShortenerRepository reads $wpdb->posts and runs a query.
        $this->wpdb->posts = 'wp_posts';
        $this->wpdb->shouldReceive( 'get_results' )->andReturn( array() )->byDefault();
        Functions\when( 'wp_safe_redirect' )->alias(
            function () {
                throw new \RuntimeException( 'redirect_called' );
            }
        );
    }

    public function test_handle_url_cleanup_does_nothing_without_request(): void {
        unset( $_REQUEST['ffc_url_cleanup'] );
        Functions\expect( 'wp_verify_nonce' )->never();
        $this->settings->handle_url_shortener_cleanup();
        $this->assertTrue( true ); // Reached here without redirect/die.
    }

    public function test_handle_url_cleanup_dies_on_bad_nonce(): void {
        $_REQUEST['ffc_url_cleanup'] = 'preview';
        $_REQUEST['_wpnonce']        = 'bad';
        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'wp_verify_nonce' )->justReturn( false );
        Functions\when( 'wp_die' )->alias(
            function ( $msg ) {
                throw new \RuntimeException( 'wp_die: ' . $msg );
            }
        );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'wp_die' );

        $this->settings->handle_url_shortener_cleanup();
    }

    public function test_handle_url_cleanup_preview_runs_and_redirects(): void {
        $_REQUEST['ffc_url_cleanup']     = 'preview';
        $_REQUEST['_wpnonce']            = 'ok';
        $_POST['url_cleanup_days']       = '120';
        $_POST['url_cleanup_orphaned']   = '1';
        $_POST['url_cleanup_trashed']    = '1';
        $this->arrange_url_cleanup_stubs();

        // Dry-run preview runs the tool through to the redirect (which we trap).
        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'redirect_called' );

        $this->settings->handle_url_shortener_cleanup();
    }

    public function test_handle_url_cleanup_apply_requires_preview_first(): void {
        $_REQUEST['ffc_url_cleanup'] = 'apply';
        $_REQUEST['_wpnonce']        = 'ok';
        $this->arrange_url_cleanup_stubs();
        Functions\when( 'get_transient' )->justReturn( false ); // No prior preview.

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'redirect_called' );

        $this->settings->handle_url_shortener_cleanup();
    }

    public function test_handle_url_cleanup_apply_runs_when_previewed(): void {
        $_REQUEST['ffc_url_cleanup'] = 'apply';
        $_REQUEST['_wpnonce']        = 'ok';
        $this->arrange_url_cleanup_stubs();
        Functions\when( 'get_transient' )->justReturn( 1 ); // Preview OK flag present.
        Functions\when( 'get_option' )->justReturn(
            array(
                'url_cleanup_days'     => 30,
                'url_cleanup_orphaned' => 1,
            )
        );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'redirect_called' );

        $this->settings->handle_url_shortener_cleanup();
    }

    // ==================================================================
    // handle_public_access_disabler()
    // ==================================================================

    /**
     * Shared stubs for the public-access-disabler handler. The tool runs a
     * WP_Query (the bootstrap double returns an empty result set here) so no
     * forms match and no meta is written.
     *
     * @return void
     */
    private function arrange_pubaccess_stubs(): void {
        if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
            define( 'MINUTE_IN_SECONDS', 60 );
        }
        Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
        Functions\when( 'get_current_user_id' )->justReturn( 7 );
        Functions\when( 'update_option' )->justReturn( true );
        Functions\when( 'set_transient' )->justReturn( true );
        Functions\when( 'delete_transient' )->justReturn( true );
        Functions\when( 'wp_safe_redirect' )->alias(
            function () {
                throw new \RuntimeException( 'redirect_called' );
            }
        );
    }

    public function test_handle_pubaccess_does_nothing_without_request(): void {
        unset( $_REQUEST['ffc_pubaccess'] );
        Functions\expect( 'wp_verify_nonce' )->never();
        $this->settings->handle_public_access_disabler();
        $this->assertTrue( true );
    }

    public function test_handle_pubaccess_dies_on_bad_nonce(): void {
        $_REQUEST['ffc_pubaccess'] = 'preview';
        $_REQUEST['_wpnonce']      = 'bad';
        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'wp_verify_nonce' )->justReturn( false );
        Functions\when( 'wp_die' )->alias(
            function ( $msg ) {
                throw new \RuntimeException( 'wp_die: ' . $msg );
            }
        );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'wp_die' );

        $this->settings->handle_public_access_disabler();
    }

    public function test_handle_pubaccess_preview_runs_and_redirects(): void {
        $_REQUEST['ffc_pubaccess']                = 'preview';
        $_REQUEST['_wpnonce']                     = 'ok';
        $_POST['public_access_disable_days']      = '120';
        $this->arrange_pubaccess_stubs();

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'redirect_called' );

        $this->settings->handle_public_access_disabler();
    }

    public function test_handle_pubaccess_apply_requires_preview_first(): void {
        $_REQUEST['ffc_pubaccess'] = 'apply';
        $_REQUEST['_wpnonce']      = 'ok';
        $this->arrange_pubaccess_stubs();
        Functions\when( 'get_transient' )->justReturn( false );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'redirect_called' );

        $this->settings->handle_public_access_disabler();
    }

    public function test_handle_pubaccess_apply_runs_when_previewed(): void {
        $_REQUEST['ffc_pubaccess'] = 'apply';
        $_REQUEST['_wpnonce']      = 'ok';
        $this->arrange_pubaccess_stubs();
        Functions\when( 'get_transient' )->justReturn( 1 );
        Functions\when( 'get_option' )->justReturn( array( 'public_access_disable_days' => 30 ) );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'redirect_called' );

        $this->settings->handle_public_access_disabler();
    }

    // ==================================================================
    // handle_submission_link_audit() (report-only)
    // ==================================================================

    public function test_handle_submission_audit_does_nothing_without_request(): void {
        unset( $_REQUEST['ffc_submission_audit'] );
        Functions\expect( 'wp_verify_nonce' )->never();
        $this->settings->handle_submission_link_audit();
        $this->assertTrue( true );
    }

    public function test_handle_submission_audit_dies_on_bad_nonce(): void {
        $_REQUEST['ffc_submission_audit'] = 'scan';
        $_REQUEST['_wpnonce']             = 'bad';
        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'wp_verify_nonce' )->justReturn( false );
        Functions\when( 'wp_die' )->alias(
            function ( $msg ) {
                throw new \RuntimeException( 'wp_die: ' . $msg );
            }
        );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'wp_die' );

        $this->settings->handle_submission_link_audit();
    }

    public function test_handle_submission_audit_scans_and_redirects(): void {
        if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
            define( 'MINUTE_IN_SECONDS', 60 );
        }
        $_REQUEST['ffc_submission_audit'] = 'scan';
        $_REQUEST['_wpnonce']             = 'ok';
        Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
        Functions\when( 'get_current_user_id' )->justReturn( 7 );
        Functions\when( 'set_transient' )->justReturn( true );
        // The auditor lazily builds a SubmissionRepository and runs read-only
        // queries against the mocked $wpdb.
        $this->wpdb->users = 'wp_users';
        $this->wpdb->shouldReceive( 'get_results' )->andReturn( array() )->byDefault();
        Functions\when( 'wp_safe_redirect' )->alias(
            function () {
                throw new \RuntimeException( 'redirect_called' );
            }
        );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'redirect_called' );

        $this->settings->handle_submission_link_audit();
    }
}
