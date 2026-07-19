<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\SettingsActionHandler;
use FreeFormCertificate\Admin\SettingsSaveHandler;

/**
 * Tests for SettingsActionHandler: admin_init / admin_post / wp_ajax request
 * handlers (clear QR cache, run migration, obsolete-shortcode cleanup, short-URL
 * cleanup, public-access disabler, submission-link audit, date-format preview,
 * cache warm/clear), each gated on nonce + capability.
 *
 * Runs in separate processes because it uses Mockery alias mocks for the static
 * collaborators (Capabilities, MaintenanceToolRegistry, FormCache, …) and
 * namespaced WP-function stubs.
 *
 * @covers \FreeFormCertificate\Admin\SettingsActionHandler
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class SettingsActionHandlerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var SettingsActionHandler */
	private $handler;

	/** @var SettingsSaveHandler|Mockery\MockInterface */
	private $save_handler;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		class_exists( '\\FreeFormCertificate\\Admin\\SettingsActionHandler' );

		// Common global WP stubs (unqualified calls in a namespace fall back to global).
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_key' )->alias(
			static function ( $key ) {
				return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
			}
		);
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'absint' )->alias( static function ( $v ) { return abs( (int) $v ); } );
		Functions\when( 'add_query_arg' )->alias(
			static function ( $arg1, $arg2 = null, $arg3 = null ) {
				return 'redirect-url';
			}
		);
		Functions\when( 'admin_url' )->returnArg();
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'date_i18n' )->justReturn( 'Jan 4, 2026' );
		if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
			define( 'MINUTE_IN_SECONDS', 60 );
		}
		// Point the plugin dir at a non-existent path so the email chrome helper
		// (EmailHelperTrait::ffc_render_email_partial) returns '' instead of
		// including the real layout template and its dependency chain.
		if ( ! defined( 'FFC_PLUGIN_DIR' ) ) {
			define( 'FFC_PLUGIN_DIR', '/nonexistent-ffc-plugin-dir/' );
		}

		// Redirects / die / json halt: throw so we can assert via expectException.
		Functions\when( 'wp_safe_redirect' )->alias(
			static function () { throw new \RuntimeException( 'redirect' ); }
		);
		Functions\when( 'wp_die' )->alias(
			static function () { throw new \RuntimeException( 'die' ); }
		);
		// Throw \Error (not \Exception) so the handler's catch(Exception) block
		// does not swallow these halts.
		Functions\when( 'wp_send_json_error' )->alias(
			static function () { throw new \Error( 'json_error' ); }
		);
		Functions\when( 'wp_send_json_success' )->alias(
			static function () { throw new \Error( 'json_success' ); }
		);

		$this->save_handler = Mockery::mock( SettingsSaveHandler::class );
		$this->handler      = new SettingsActionHandler( $this->save_handler );
	}

	protected function tearDown(): void {
		unset(
			$_GET['ffc_clear_qr_cache'],
			$_GET['ffc_run_migration'],
			$_GET['_wpnonce'],
			$_GET['action'],
			$_REQUEST['ffc_obsolete_cleanup'],
			$_REQUEST['ffc_url_cleanup'],
			$_REQUEST['ffc_pubaccess'],
			$_REQUEST['ffc_submission_audit'],
			$_REQUEST['_wpnonce'],
			$_POST['ffc_send_test_email'],
			$_POST['obsolete_shortcode_days'],
			$_POST['url_cleanup_days'],
			$_POST['public_access_disable_days'],
			$_POST['format'],
			$_POST['custom_format']
		);
		Monkey\tearDown();
		parent::tearDown();
	}

	/** Mock Capabilities::current_user_can_admin_or to return $allowed. */
	private function mock_capabilities( bool $allowed ): void {
		$cap = Mockery::mock( 'alias:FreeFormCertificate\\Core\\Capabilities' );
		$cap->shouldReceive( 'current_user_can_admin_or' )->andReturn( $allowed );
	}

	/** Alias-mock RequestInput so get_get_string/get_post_string return canned values. */
	private function mock_request_input( array $get = array(), array $post = array() ): void {
		$ri = Mockery::mock( 'alias:FreeFormCertificate\\Core\\RequestInput' );
		$ri->shouldReceive( 'get_get_string' )->andReturnUsing(
			static function ( $key, $default = '' ) use ( $get ) {
				return $get[ $key ] ?? $default;
			}
		);
		$ri->shouldReceive( 'get_post_string' )->andReturnUsing(
			static function ( $key, $default = '' ) use ( $post ) {
				return $post[ $key ] ?? $default;
			}
		);
	}

	/** Alias-mock MaintenanceToolRegistry::create_default()->get() to return $tool. */
	private function mock_tool_registry( $tool ): void {
		// Inner registry instance: anonymous mock so we don't autoload (and then
		// collide with) the real MaintenanceToolRegistry class name.
		$registry = Mockery::mock();
		$registry->shouldReceive( 'get' )->andReturn( $tool );
		$alias = Mockery::mock( 'alias:FreeFormCertificate\\Maintenance\\MaintenanceToolRegistry' );
		$alias->shouldReceive( 'create_default' )->andReturn( $registry );
	}

	/** A maintenance tool that satisfies the instanceof check and returns $report. */
	private function make_tool( array $report ) {
		$tool = Mockery::mock( 'FreeFormCertificate\\Maintenance\\MaintenanceToolInterface' );
		$tool->shouldReceive( 'run' )->andReturn( $report );
		return $tool;
	}

	// ==================================================================
	// handle_settings_submission()
	// ==================================================================

	public function test_handle_settings_submission_delegates_to_save_handler(): void {
		$this->save_handler->shouldReceive( 'handle_all_submissions' )->once();
		$this->handler->handle_settings_submission();
		$this->assertTrue( true );
	}

	// ==================================================================
	// handle_clear_qr_cache()
	// ==================================================================

	public function test_clear_qr_cache_returns_early_without_params(): void {
		$this->handler->handle_clear_qr_cache();
		$this->assertTrue( true );
	}

	public function test_clear_qr_cache_invalid_nonce_returns(): void {
		$_GET['ffc_clear_qr_cache'] = '1';
		$_GET['_wpnonce']           = 'bad';
		$this->mock_request_input( array( '_wpnonce' => 'bad' ) );
		Functions\when( 'wp_verify_nonce' )->justReturn( false );
		$this->handler->handle_clear_qr_cache();
		$this->assertTrue( true );
	}

	public function test_clear_qr_cache_success_redirects(): void {
		$_GET['ffc_clear_qr_cache'] = '1';
		$_GET['_wpnonce']           = 'ok';
		$this->mock_request_input( array( '_wpnonce' => 'ok' ) );
		Functions\when( 'wp_verify_nonce' )->justReturn( true );

		$repo = Mockery::mock( 'overload:FreeFormCertificate\\Repositories\\SubmissionRepository' );
		$repo->shouldReceive( 'clearQrCodeCache' )->andReturn( 3 );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'redirect' );
		$this->handler->handle_clear_qr_cache();
	}

	// ==================================================================
	// handle_migration_execution()
	// ==================================================================

	public function test_migration_returns_early_without_param(): void {
		$this->handler->handle_migration_execution();
		$this->assertTrue( true );
	}

	public function test_migration_denies_without_capability(): void {
		$_GET['ffc_run_migration'] = 'foo';
		$this->mock_capabilities( false );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'die' );
		$this->handler->handle_migration_execution();
	}

	public function test_migration_bad_nonce_dies(): void {
		$_GET['ffc_run_migration'] = 'foo';
		$_GET['_wpnonce']          = 'bad';
		$this->mock_capabilities( true );
		$this->mock_request_input( array( '_wpnonce' => 'bad' ) );
		Functions\when( 'wp_verify_nonce' )->justReturn( false );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'die' );
		$this->handler->handle_migration_execution();
	}

	public function test_migration_success_redirects(): void {
		$_GET['ffc_run_migration'] = 'foo';
		$_GET['_wpnonce']          = 'ok';
		$this->mock_capabilities( true );
		$this->mock_request_input( array( '_wpnonce' => 'ok' ) );
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'is_wp_error' )->justReturn( false );

		$mgr = Mockery::mock( 'overload:FreeFormCertificate\\Migrations\\MigrationManager' );
		$mgr->shouldReceive( 'run_migration' )->andReturn( array( 'processed' => 5 ) );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'redirect' );
		$this->handler->handle_migration_execution();
	}

	public function test_migration_wp_error_redirects(): void {
		$_GET['ffc_run_migration'] = 'foo';
		$_GET['_wpnonce']          = 'ok';
		$this->mock_capabilities( true );
		$this->mock_request_input( array( '_wpnonce' => 'ok' ) );
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'is_wp_error' )->justReturn( true );

		$err = Mockery::mock();
		$err->shouldReceive( 'get_error_message' )->andReturn( 'boom' );

		$mgr = Mockery::mock( 'overload:FreeFormCertificate\\Migrations\\MigrationManager' );
		$mgr->shouldReceive( 'run_migration' )->andReturn( $err );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'redirect' );
		$this->handler->handle_migration_execution();
	}

	// ==================================================================
	// handle_obsolete_shortcode_cleanup()
	// ==================================================================

	public function test_obsolete_returns_early_without_param(): void {
		$this->handler->handle_obsolete_shortcode_cleanup();
		$this->assertTrue( true );
	}

	public function test_obsolete_denies_without_capability(): void {
		$_REQUEST['ffc_obsolete_cleanup'] = 'preview';
		$this->mock_capabilities( false );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'die' );
		$this->handler->handle_obsolete_shortcode_cleanup();
	}

	public function test_obsolete_invalid_mode_dies(): void {
		$_REQUEST['ffc_obsolete_cleanup'] = 'bogus';
		$this->mock_capabilities( true );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'die' );
		$this->handler->handle_obsolete_shortcode_cleanup();
	}

	public function test_obsolete_bad_nonce_dies(): void {
		$_REQUEST['ffc_obsolete_cleanup'] = 'preview';
		$_REQUEST['_wpnonce']             = 'bad';
		$this->mock_capabilities( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( false );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'die' );
		$this->handler->handle_obsolete_shortcode_cleanup();
	}

	public function test_obsolete_save_days_persists_and_redirects(): void {
		$_REQUEST['ffc_obsolete_cleanup']    = 'save_days';
		$_REQUEST['_wpnonce']                = 'ok';
		$_POST['obsolete_shortcode_days']    = '120';
		$this->mock_capabilities( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( true );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'redirect' );
		$this->handler->handle_obsolete_shortcode_cleanup();
	}

	public function test_obsolete_preview_runs_tool_and_redirects(): void {
		$_REQUEST['ffc_obsolete_cleanup'] = 'preview';
		$_REQUEST['_wpnonce']             = 'ok';
		$this->mock_capabilities( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		$this->mock_tool_registry( $this->make_tool( array( 'scanned' => 2 ) ) );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'redirect' );
		$this->handler->handle_obsolete_shortcode_cleanup();
	}

	public function test_obsolete_preview_tool_missing_redirects(): void {
		$_REQUEST['ffc_obsolete_cleanup'] = 'preview';
		$_REQUEST['_wpnonce']             = 'ok';
		$this->mock_capabilities( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		$this->mock_tool_registry( null );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'redirect' );
		$this->handler->handle_obsolete_shortcode_cleanup();
	}

	public function test_obsolete_apply_without_preview_redirects(): void {
		$_REQUEST['ffc_obsolete_cleanup'] = 'apply';
		$_REQUEST['_wpnonce']             = 'ok';
		$this->mock_capabilities( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'get_transient' )->justReturn( false );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'redirect' );
		$this->handler->handle_obsolete_shortcode_cleanup();
	}

	public function test_obsolete_apply_with_preview_runs_tool(): void {
		$_REQUEST['ffc_obsolete_cleanup'] = 'apply';
		$_REQUEST['_wpnonce']             = 'ok';
		$this->mock_capabilities( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'get_transient' )->justReturn( 1 );
		$this->mock_tool_registry( $this->make_tool( array( 'shortcodes_removed' => 4, 'posts_affected' => 2 ) ) );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'redirect' );
		$this->handler->handle_obsolete_shortcode_cleanup();
	}

	// ==================================================================
	// handle_url_shortener_cleanup()
	// ==================================================================

	public function test_url_cleanup_returns_early_without_param(): void {
		$this->handler->handle_url_shortener_cleanup();
		$this->assertTrue( true );
	}

	public function test_url_cleanup_denies_without_capability(): void {
		$_REQUEST['ffc_url_cleanup'] = 'preview';
		$this->mock_capabilities( false );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'die' );
		$this->handler->handle_url_shortener_cleanup();
	}

	public function test_url_cleanup_invalid_mode_dies(): void {
		$_REQUEST['ffc_url_cleanup'] = 'bogus';
		$this->mock_capabilities( true );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'die' );
		$this->handler->handle_url_shortener_cleanup();
	}

	public function test_url_cleanup_bad_nonce_dies(): void {
		$_REQUEST['ffc_url_cleanup'] = 'preview';
		$_REQUEST['_wpnonce']        = 'bad';
		$this->mock_capabilities( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( false );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'die' );
		$this->handler->handle_url_shortener_cleanup();
	}

	public function test_url_cleanup_tool_missing_redirects(): void {
		$_REQUEST['ffc_url_cleanup'] = 'preview';
		$_REQUEST['_wpnonce']        = 'ok';
		$this->mock_capabilities( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		$this->mock_tool_registry( null );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'redirect' );
		$this->handler->handle_url_shortener_cleanup();
	}

	public function test_url_cleanup_preview_runs_tool(): void {
		$_REQUEST['ffc_url_cleanup']      = 'preview';
		$_REQUEST['_wpnonce']             = 'ok';
		$_POST['url_cleanup_days']        = '30';
		$_POST['url_cleanup_orphaned']    = '1';
		$_POST['url_cleanup_never_clicked'] = '1';
		$_POST['url_cleanup_trashed']     = '1';
		$this->mock_capabilities( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		$this->mock_tool_registry( $this->make_tool( array( 'deleted' => 0 ) ) );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'redirect' );
		$this->handler->handle_url_shortener_cleanup();
	}

	public function test_url_cleanup_apply_without_preview_redirects(): void {
		$_REQUEST['ffc_url_cleanup'] = 'apply';
		$_REQUEST['_wpnonce']        = 'ok';
		$this->mock_capabilities( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'get_transient' )->justReturn( false );
		$this->mock_tool_registry( $this->make_tool( array( 'deleted' => 1 ) ) );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'redirect' );
		$this->handler->handle_url_shortener_cleanup();
	}

	public function test_url_cleanup_apply_with_preview_runs_tool(): void {
		$_REQUEST['ffc_url_cleanup'] = 'apply';
		$_REQUEST['_wpnonce']        = 'ok';
		$this->mock_capabilities( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'get_transient' )->justReturn( 1 );
		Functions\when( 'get_option' )->justReturn(
			array(
				'url_cleanup_days'          => 30,
				'url_cleanup_orphaned'      => 1,
				'url_cleanup_never_clicked' => 1,
				'url_cleanup_trashed'       => 1,
			)
		);
		$this->mock_tool_registry( $this->make_tool( array( 'deleted' => 9 ) ) );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'redirect' );
		$this->handler->handle_url_shortener_cleanup();
	}

	// ==================================================================
	// handle_public_access_disabler()
	// ==================================================================

	public function test_pubaccess_returns_early_without_param(): void {
		$this->handler->handle_public_access_disabler();
		$this->assertTrue( true );
	}

	public function test_pubaccess_denies_without_capability(): void {
		$_REQUEST['ffc_pubaccess'] = 'preview';
		$this->mock_capabilities( false );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'die' );
		$this->handler->handle_public_access_disabler();
	}

	public function test_pubaccess_invalid_mode_dies(): void {
		$_REQUEST['ffc_pubaccess'] = 'bogus';
		$this->mock_capabilities( true );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'die' );
		$this->handler->handle_public_access_disabler();
	}

	public function test_pubaccess_bad_nonce_dies(): void {
		$_REQUEST['ffc_pubaccess'] = 'preview';
		$_REQUEST['_wpnonce']      = 'bad';
		$this->mock_capabilities( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( false );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'die' );
		$this->handler->handle_public_access_disabler();
	}

	public function test_pubaccess_tool_missing_redirects(): void {
		$_REQUEST['ffc_pubaccess'] = 'preview';
		$_REQUEST['_wpnonce']      = 'ok';
		$this->mock_capabilities( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		$this->mock_tool_registry( null );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'redirect' );
		$this->handler->handle_public_access_disabler();
	}

	public function test_pubaccess_preview_runs_tool(): void {
		$_REQUEST['ffc_pubaccess']          = 'preview';
		$_REQUEST['_wpnonce']               = 'ok';
		$_POST['public_access_disable_days'] = '45';
		$this->mock_capabilities( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		$this->mock_tool_registry( $this->make_tool( array( 'disabled' => 0 ) ) );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'redirect' );
		$this->handler->handle_public_access_disabler();
	}

	public function test_pubaccess_apply_without_preview_redirects(): void {
		$_REQUEST['ffc_pubaccess'] = 'apply';
		$_REQUEST['_wpnonce']      = 'ok';
		$this->mock_capabilities( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'get_transient' )->justReturn( false );
		$this->mock_tool_registry( $this->make_tool( array( 'disabled' => 1 ) ) );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'redirect' );
		$this->handler->handle_public_access_disabler();
	}

	public function test_pubaccess_apply_with_preview_runs_tool(): void {
		$_REQUEST['ffc_pubaccess'] = 'apply';
		$_REQUEST['_wpnonce']      = 'ok';
		$this->mock_capabilities( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'get_transient' )->justReturn( 1 );
		Functions\when( 'get_option' )->justReturn( array( 'public_access_disable_days' => 60 ) );
		$this->mock_tool_registry( $this->make_tool( array( 'disabled' => 8 ) ) );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'redirect' );
		$this->handler->handle_public_access_disabler();
	}

	// ==================================================================
	// handle_submission_link_audit()
	// ==================================================================

	public function test_submission_audit_returns_early_without_param(): void {
		$this->handler->handle_submission_link_audit();
		$this->assertTrue( true );
	}

	public function test_submission_audit_denies_without_capability(): void {
		$_REQUEST['ffc_submission_audit'] = 'scan';
		$this->mock_capabilities( false );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'die' );
		$this->handler->handle_submission_link_audit();
	}

	public function test_submission_audit_invalid_mode_dies(): void {
		$_REQUEST['ffc_submission_audit'] = 'bogus';
		$this->mock_capabilities( true );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'die' );
		$this->handler->handle_submission_link_audit();
	}

	public function test_submission_audit_bad_nonce_dies(): void {
		$_REQUEST['ffc_submission_audit'] = 'scan';
		$_REQUEST['_wpnonce']             = 'bad';
		$this->mock_capabilities( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( false );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'die' );
		$this->handler->handle_submission_link_audit();
	}

	public function test_submission_audit_tool_missing_redirects(): void {
		$_REQUEST['ffc_submission_audit'] = 'scan';
		$_REQUEST['_wpnonce']             = 'ok';
		$this->mock_capabilities( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		$this->mock_tool_registry( null );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'redirect' );
		$this->handler->handle_submission_link_audit();
	}

	public function test_submission_audit_scan_runs_tool(): void {
		$_REQUEST['ffc_submission_audit'] = 'scan';
		$_REQUEST['_wpnonce']             = 'ok';
		$this->mock_capabilities( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		$this->mock_tool_registry( $this->make_tool( array( 'orphans' => 0 ) ) );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'redirect' );
		$this->handler->handle_submission_link_audit();
	}

	// ==================================================================
	// ajax_preview_date_format()
	// ==================================================================

	public function test_ajax_preview_denies_without_capability(): void {
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		$this->mock_capabilities( false );
		$this->expectException( \Error::class );
		$this->expectExceptionMessage( 'json_error' );
		$this->handler->ajax_preview_date_format();
	}

	public function test_ajax_preview_success(): void {
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		$this->mock_capabilities( true );
		$this->mock_request_input( array(), array( 'format' => 'F j, Y', 'custom_format' => '' ) );

		$this->expectException( \Error::class );
		$this->expectExceptionMessage( 'json_success' );
		$this->handler->ajax_preview_date_format();
	}

	public function test_ajax_preview_custom_format(): void {
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		$this->mock_capabilities( true );
		$this->mock_request_input( array(), array( 'format' => 'custom', 'custom_format' => 'Y-m-d' ) );

		$this->expectException( \Error::class );
		$this->expectExceptionMessage( 'json_success' );
		$this->handler->ajax_preview_date_format();
	}

	// ==================================================================
	// handle_cache_actions()
	// ==================================================================

	public function test_cache_actions_denies_without_capability(): void {
		$this->mock_capabilities( false );
		$this->handler->handle_cache_actions();
		$this->assertTrue( true );
	}

	public function test_cache_actions_no_action_does_nothing(): void {
		$this->mock_capabilities( true );
		$this->handler->handle_cache_actions();
		$this->assertTrue( true );
	}

	public function test_cache_actions_warm_cache_redirects(): void {
		$_GET['action'] = 'warm_cache';
		$this->mock_capabilities( true );
		Functions\when( 'check_admin_referer' )->justReturn( true );

		$cache = Mockery::mock( 'alias:FreeFormCertificate\\Submissions\\FormCache' );
		$cache->shouldReceive( 'warm_all_forms' )->once()->andReturn( 4 );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'redirect' );
		$this->handler->handle_cache_actions();
	}

	public function test_cache_actions_clear_cache_redirects(): void {
		$_GET['action'] = 'clear_cache';
		$this->mock_capabilities( true );
		Functions\when( 'check_admin_referer' )->justReturn( true );

		$cache = Mockery::mock( 'alias:FreeFormCertificate\\Submissions\\FormCache' );
		$cache->shouldReceive( 'clear_all_cache' )->once();
		$cache->shouldReceive( 'purge_external_caches_for_all_forms' )->once();

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'redirect' );
		$this->handler->handle_cache_actions();
	}

	// ==================================================================
	// handle_send_test_email()
	// ==================================================================

	/** Stub wp_get_current_user() to return an object with the given email. */
	private function mock_current_user_email( string $email ): void {
		Functions\when( 'wp_get_current_user' )->justReturn( (object) array( 'user_email' => $email ) );
	}

	/**
	 * Stub the shared email chrome (EmailHelperTrait::ffc_email_document renders
	 * templates/emails/layout.php) so the handler can build a body without the
	 * template's full dependency chain. We only care that a body is produced and
	 * handed to the transport with the current user's address.
	 */
	private function mock_email_chrome(): void {
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'get_bloginfo' )->justReturn( 'Test Site' );

		$opts = Mockery::mock( 'alias:FreeFormCertificate\\Core\\EmailTemplateOptions' );
		$opts->shouldReceive( 'all' )->andReturn(
			array(
				'header_bg'             => '#2271b1',
				'header_text_color'     => '#ffffff',
				'header_alignment'      => 'center',
				'header_padding'        => 24,
				'header_logo_url'       => '',
				'header_logo_max_width' => 180,
				'body_bg'               => '#ffffff',
				'body_text_color'       => '#333333',
				'body_link_color'       => '#2271b1',
				'body_font_family'      => 'system',
				'body_font_size'        => 14,
				'body_padding'          => 24,
				'body_max_width'        => 600,
				'footer_bg'             => '#f5f5f5',
				'footer_text_color'     => '#666666',
				'footer_text'           => 'Sent by {{site_title}}',
				'wrapper_bg'            => '#f0f0f1',
				'wrapper_border_radius' => 6,
				'wrapper_padding'       => 32,
			)
		);
		$opts->shouldReceive( 'font_stack' )->andReturn( 'Arial, sans-serif' );
		$opts->shouldReceive( 'footer_tokens' )->andReturn( array() );

		$tokens = Mockery::mock( 'alias:FreeFormCertificate\\Core\\TokenResolver' );
		$tokens->shouldReceive( 'resolve' )->andReturn( '' );
	}

	public function test_send_test_email_returns_early_without_param(): void {
		$this->handler->handle_send_test_email();
		$this->assertTrue( true );
	}

	public function test_send_test_email_denies_without_capability(): void {
		$_POST['ffc_send_test_email'] = '1';
		$this->mock_capabilities( false );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'die' );
		$this->handler->handle_send_test_email();
	}

	public function test_send_test_email_bad_nonce_dies(): void {
		$_POST['ffc_send_test_email'] = '1';
		$this->mock_capabilities( true );
		Functions\when( 'check_admin_referer' )->alias(
			static function () { throw new \RuntimeException( 'die' ); }
		);
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'die' );
		$this->handler->handle_send_test_email();
	}

	public function test_send_test_email_sent_uses_current_user_email(): void {
		$_POST['ffc_send_test_email'] = '1';
		$this->mock_capabilities( true );
		Functions\when( 'check_admin_referer' )->justReturn( true );
		Functions\when( 'is_email' )->justReturn( true );
		$this->mock_current_user_email( 'admin@example.com' );
		$this->mock_email_chrome();

		$reader = Mockery::mock( 'alias:FreeFormCertificate\\Settings\\SettingsReader' );
		$reader->shouldReceive( 'emails_disabled' )->andReturn( false );

		// Assert the transport is called with the CURRENT USER's email — never a
		// request-supplied address.
		$svc = Mockery::mock( 'alias:FreeFormCertificate\\Core\\EmailService' );
		$svc->shouldReceive( 'send' )
			->once()
			->with( 'admin@example.com', Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any() )
			->andReturn( true );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'redirect' );
		$this->handler->handle_send_test_email();
	}

	public function test_send_test_email_disabled_does_not_send(): void {
		$_POST['ffc_send_test_email'] = '1';
		$this->mock_capabilities( true );
		Functions\when( 'check_admin_referer' )->justReturn( true );
		Functions\when( 'is_email' )->justReturn( true );
		$this->mock_current_user_email( 'admin@example.com' );

		$reader = Mockery::mock( 'alias:FreeFormCertificate\\Settings\\SettingsReader' );
		$reader->shouldReceive( 'emails_disabled' )->andReturn( true );

		$svc = Mockery::mock( 'alias:FreeFormCertificate\\Core\\EmailService' );
		$svc->shouldReceive( 'send' )->never();

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'redirect' );
		$this->handler->handle_send_test_email();
	}

	public function test_send_test_email_no_address_does_not_send(): void {
		$_POST['ffc_send_test_email'] = '1';
		$this->mock_capabilities( true );
		Functions\when( 'check_admin_referer' )->justReturn( true );
		$this->mock_current_user_email( '' );

		$svc = Mockery::mock( 'alias:FreeFormCertificate\\Core\\EmailService' );
		$svc->shouldReceive( 'send' )->never();

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'redirect' );
		$this->handler->handle_send_test_email();
	}
}
