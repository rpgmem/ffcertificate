<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\SettingsActionHandler;
use FreeFormCertificate\Admin\SettingsSaveHandler;
use FreeFormCertificate\Core\EmailSource;
use FreeFormCertificate\Maintenance\IdentityAuditExportSource;
use FreeFormCertificate\Migrations\Strategies\MigrationStrategyInterface;
use FreeFormCertificate\Tests\Support\CallLog;

/**
 * Tests for SettingsActionHandler: admin_init / admin_post / wp_ajax request
 * handlers (clear QR cache, run migration, obsolete-shortcode cleanup, short-URL
 * cleanup, public-access disabler, submission-link audit, cache warm/clear,
 * test email), each gated on nonce + capability.
 *
 * The collaborators run for real — Capabilities and RequestInput, the
 * submission repository, MigrationManager with its registry and status
 * calculator, the default MaintenanceToolRegistry and its four tools, FormCache,
 * the identity-audit export source, and the email pipeline down to a stubbed
 * `wp_mail()` — over a `$wpdb` double that answers each query by its SQL text
 * and fails on any it does not know. A migration strategy comes in through the
 * `ffcertificate_migration_strategies` filter, the extension point the status
 * calculator offers; posts come from the bootstrap's WP_Query queue and post
 * meta from a stub. A tool that "fails" is a real tool whose storage throws.
 *
 * The five tests of the tool-missing branch run in their own process: every
 * tool is registered by the real registry, so that branch is reachable only by
 * replacing MaintenanceToolRegistry.
 *
 * @covers \FreeFormCertificate\Admin\SettingsActionHandler
 */
class SettingsActionHandlerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var SettingsActionHandler */
	private $handler;

	/** @var SettingsSaveHandler|Mockery\MockInterface */
	private $save_handler;

	/** @var list<string> Capabilities the current user holds. */
	private array $caps = array( 'manage_options' );

	/** @var list<string> Every capability current_user_can() was asked about. */
	private array $cap_checks = array();

	/** @var array<int, array<string, mixed>> Post meta, by post and key. */
	private array $post_meta = array();

	/** @var string|null When set, reading post meta throws this message (a locked table). */
	private ?string $meta_failure = null;

	/** @var string|null When set, every SELECT the double runs throws this message. */
	private ?string $db_failure = null;

	/** @var bool Whether any SELECT is answered with an empty result (a read-only scan of an empty install). */
	private bool $empty_reads = false;

	/** @var list<array<string, mixed>> Rows the short-URL cleanup query answers. */
	private array $short_url_candidates = array();

	/** @var list<string> Every SQL string the double ran. */
	private array $queries = array();

	/** @var array<string, mixed> Transients written, by key. */
	private array $transients = array();

	/** @var array<string, mixed> The `$_GET` an earlier test left, restored afterwards. */
	private array $get_backup = array();

	/** @var array<string, mixed> The `$_POST` an earlier test left, restored afterwards. */
	private array $post_backup = array();

	/** @var array<string, mixed> The `$_REQUEST` an earlier test left, restored afterwards. */
	private array $request_backup = array();

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	/** @var array<string, mixed> The WP_Query stub's globals an earlier test left. */
	private array $query_globals_backup = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->get_backup     = $_GET ?? array();
		$this->post_backup    = $_POST ?? array();
		$this->request_backup = $_REQUEST ?? array();
		$_GET                 = array();
		$_POST                = array();
		$_REQUEST             = array();

		$this->caps                 = array( 'manage_options' );
		$this->cap_checks           = array();
		$this->post_meta            = array();
		$this->meta_failure         = null;
		$this->db_failure           = null;
		$this->empty_reads          = false;
		$this->short_url_candidates = array();
		$this->queries              = array();
		$this->transients           = array();

		foreach ( array( 'ffc_test_wp_query_queue', 'ffc_test_wp_query_calls' ) as $key ) {
			$this->query_globals_backup[ $key ] = $GLOBALS[ $key ] ?? null;
			$GLOBALS[ $key ]                    = array();
		}

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
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
		Functions\when( 'add_query_arg' )->alias(
			static function ( $arg1, $arg2 = null, $arg3 = null ) {
				return 'redirect-url';
			}
		);
		Functions\when( 'admin_url' )->returnArg();
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		// Nothing stored: `ffc_settings` reads as an empty array, every other option as its default.
		Functions\when( 'get_option' )->alias( static fn( $key, $default = false ) => 'ffc_settings' === $key ? array() : $default );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value ) {
				$this->transients[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'is_wp_error' )->alias( static fn( $v ) => $v instanceof \WP_Error );
		Functions\when( 'wp_timezone' )->alias( static fn() => new \DateTimeZone( 'UTC' ) );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'wp_cache_flush' )->justReturn( true );
		Functions\when( 'get_post' )->justReturn( null );
		Functions\when( 'get_post_meta' )->alias(
			function ( $post_id, $key = '', $single = false ) {
				if ( null !== $this->meta_failure ) {
					throw new \RuntimeException( $this->meta_failure );
				}
				return $this->post_meta[ (int) $post_id ][ $key ] ?? '';
			}
		);

		Functions\when( 'current_user_can' )->alias(
			function ( $cap ) {
				$this->cap_checks[] = (string) $cap;
				return in_array( $cap, $this->caps, true );
			}
		);

		// Redirects / die / json halt: throw so we can assert via expectException.
		Functions\when( 'wp_safe_redirect' )->alias(
			static function () {
				throw new \RuntimeException( 'redirect' );
			}
		);
		Functions\when( 'wp_die' )->alias(
			static function () {
				throw new \RuntimeException( 'die' );
			}
		);

		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']   = $this->database();

		$this->save_handler = Mockery::mock( SettingsSaveHandler::class );
		$this->handler      = new SettingsActionHandler( $this->save_handler );
	}

	protected function tearDown(): void {
		$_GET     = $this->get_backup;
		$_POST    = $this->post_backup;
		$_REQUEST = $this->request_backup;
		foreach ( $this->query_globals_backup as $key => $value ) {
			if ( null === $value ) {
				unset( $GLOBALS[ $key ] );
			} else {
				$GLOBALS[ $key ] = $value;
			}
		}
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A `$wpdb` double: `prepare()` interpolates, so each statement dispatches
	 * on the query it ran.
	 *
	 * @return Mockery\MockInterface
	 */
	private function database() {
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->posts  = 'wp_posts';
		$wpdb->users  = 'wp_users';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( $sql, ...$args ) {
				$values = ( 1 === count( $args ) && is_array( $args[0] ) ) ? $args[0] : $args;
				return (string) preg_replace_callback(
					'/%[ids]/',
					static function () use ( &$values ) {
						return (string) array_shift( $values );
					},
					(string) $sql
				);
			}
		);
		$wpdb->shouldReceive( 'query' )->andReturnUsing(
			function ( $sql ) {
				$this->queries[] = $sql;
				if ( 'UPDATE wp_ffc_submissions SET qr_code_cache = NULL WHERE qr_code_cache IS NOT NULL' === $sql ) {
					return 3;
				}
				$this->fail( 'Unexpected query(): ' . $sql );
			}
		);
		$read = function ( string $method, array $empty ) {
			return function ( $sql = null ) use ( $method, $empty ) {
				$this->queries[] = (string) $sql;
				if ( null !== $this->db_failure ) {
					throw new \RuntimeException( $this->db_failure );
				}
				if ( 'get_results' === $method && false !== strpos( (string) $sql, 'FROM wp_ffc_short_urls s' ) ) {
					return $this->short_url_candidates;
				}
				if ( $this->empty_reads && 1 === preg_match( '/^\s*(SELECT|SHOW)\b/i', (string) $sql ) ) {
					return $empty[0];
				}
				$this->fail( "Unexpected {$method}(): " . $sql );
			};
		};
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing( $read( 'get_results', array( array() ) ) );
		$wpdb->shouldReceive( 'get_col' )->andReturnUsing( $read( 'get_col', array( array() ) ) );
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing( $read( 'get_var', array( null ) ) );
		$wpdb->shouldReceive( 'get_row' )->andReturnUsing( $read( 'get_row', array( null ) ) );
		return $wpdb;
	}

	/** Holds only these capabilities. */
	private function grant( string ...$caps ): void {
		$this->caps = $caps;
	}

	/**
	 * A form whose collection period ended 31 days ago: expired under a
	 * 30-day grace window, not under the 90-day default.
	 *
	 * @param int $form_id Form id.
	 */
	private function form_ended_31_days_ago( int $form_id ): void {
		$this->post_meta[ $form_id ]['_ffc_geofence_config'] = array(
			'date_end' => gmdate( 'Y-m-d', time() - 31 * DAY_IN_SECONDS ),
		);
	}

	/**
	 * A migration strategy the `ffcertificate_migration_strategies` filter adds.
	 *
	 * @param string $key       Migration key it registers under.
	 * @param array  $execute   What execute() returns.
	 * @param int    $rearm     What rearm() returns.
	 * @return object The strategy, whose `calls` lists what it received.
	 */
	private function strategy( string $key, array $execute = array(), int $rearm = 0 ): object {
		$strategy = new class( $execute, $rearm ) implements MigrationStrategyInterface {
			/** @var list<string> */
			public array $calls = array();
			private array $execute;
			private int $rearm;
			public function __construct( array $execute, int $rearm ) {
				$this->execute = $execute;
				$this->rearm   = $rearm;
			}
			public function calculate_status( string $migration_key, array $migration_config ): array {
				return array();
			}
			public function execute( string $migration_key, array $migration_config, int $batch_number = 0 ): array {
				$this->calls[] = 'execute:' . $migration_key;
				return $this->execute;
			}
			public function can_run( string $migration_key, array $migration_config ) {
				return true;
			}
			public function get_name(): string {
				return 'Test';
			}
			public function rearm(): int {
				$this->calls[] = 'rearm';
				return $this->rearm;
			}
		};
		Filters\expectApplied( 'ffcertificate_migration_strategies' )
			->once()
			->andReturnUsing( static fn( $strategies ) => array( $key => $strategy ) + $strategies );
		return $strategy;
	}

	/** Alias-mock MaintenanceToolRegistry::create_default()->get() to return no tool. */
	private function mock_tool_registry_without_tools(): void {
		// Inner registry instance: anonymous mock so we don't autoload (and then
		// collide with) the real MaintenanceToolRegistry class name.
		$registry = Mockery::mock();
		$registry->shouldReceive( 'get' )->andReturn( null );
		$alias = Mockery::mock( 'alias:FreeFormCertificate\\Maintenance\\MaintenanceToolRegistry' );
		$alias->shouldReceive( 'create_default' )->andReturn( $registry );
	}

	// ==================================================================
	// handle_settings_submission()
	// ==================================================================

	public function test_handle_settings_submission_delegates_to_save_handler(): void {
		$this->save_handler->shouldReceive( 'handle_all_submissions' )->once();
		$this->handler->handle_settings_submission();
	}

	// ==================================================================
	// handle_clear_qr_cache()
	// ==================================================================

	public function test_clear_qr_cache_returns_early_without_params(): void {
		// The statement after the param guard verifies the nonce, so a nonce
		// that is never verified is the proof that the guard returned.
		Functions\expect( 'wp_verify_nonce' )->never();

		$this->handler->handle_clear_qr_cache();
	}

	public function test_clear_qr_cache_invalid_nonce_returns(): void {
		$_GET['ffc_clear_qr_cache'] = '1';
		$_GET['_wpnonce']           = 'bad';
		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		$this->handler->handle_clear_qr_cache();

		// Past the nonce check the handler clears the QR cache through the
		// repository — the same statement the success test asserts on.
		$this->assertSame( array(), $this->queries );
	}

	public function test_clear_qr_cache_success_redirects(): void {
		$_GET['ffc_clear_qr_cache'] = '1';
		$_GET['_wpnonce']           = 'ok';
		Functions\when( 'wp_verify_nonce' )->justReturn( true );

		$url = $this->redirect_of( 'handle_clear_qr_cache' );

		$this->assertSame( array( 'UPDATE wp_ffc_submissions SET qr_code_cache = NULL WHERE qr_code_cache IS NOT NULL' ), $this->queries );
		$this->assertStringContainsString( 'cleared=3', $url );
	}

	// ==================================================================
	// handle_migration_execution()
	// ==================================================================

	public function test_migration_returns_early_without_param(): void {
		// #1030: this asserted nothing. The statement immediately after the
		// param guard is the destructive-capability check, so a capability
		// that is never consulted is the proof that the guard returned.
		$this->handler->handle_migration_execution();

		$this->assertSame( array(), $this->cap_checks );
	}

	public function test_migration_denies_without_capability(): void {
		$_GET['ffc_run_migration'] = 'foo';
		$this->grant();
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'die' );
		$this->handler->handle_migration_execution();
	}

	public function test_migration_bad_nonce_dies(): void {
		$_GET['ffc_run_migration'] = 'foo';
		$_GET['_wpnonce']          = 'bad';
		Functions\when( 'wp_verify_nonce' )->justReturn( false );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'die' );
		$this->handler->handle_migration_execution();
	}

	public function test_migration_success_redirects(): void {
		$_GET['ffc_run_migration'] = 'foo';
		$_GET['_wpnonce']          = 'ok';
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		$strategy = $this->strategy( 'foo', array( 'processed' => 5 ) );

		$url = $this->redirect_of( 'handle_migration_execution' );

		$this->assertSame( array( 'execute:foo' ), $strategy->calls );
		$this->assertStringContainsString( 'migration_success=Migration executed: 5 records processed.', $url );
	}

	public function test_migration_wp_error_redirects(): void {
		// No strategy is registered under this key, so the manager answers a WP_Error.
		$_GET['ffc_run_migration'] = 'foo';
		$_GET['_wpnonce']          = 'ok';
		Functions\when( 'wp_verify_nonce' )->justReturn( true );

		$url = $this->redirect_of( 'handle_migration_execution' );

		$this->assertStringContainsString( 'migration_error=No strategy found for migration: foo', $url );
	}

	// ==================================================================
	// handle_migration_rearm()
	// ==================================================================

	public function test_rearm_returns_early_without_param(): void {
		// Same proof as its sibling above: the statement right after the param
		// guard is the destructive-capability check, so a capability that is
		// never consulted is what shows the guard returned.
		$this->handler->handle_migration_rearm();

		$this->assertSame( array(), $this->cap_checks );
	}

	public function test_rearm_denies_without_capability(): void {
		$_GET['ffc_rearm_migration'] = 'foo';
		$this->grant();
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'die' );
		$this->handler->handle_migration_rearm();
	}

	public function test_rearm_bad_nonce_dies(): void {
		$_GET['ffc_rearm_migration'] = 'foo';
		$_GET['_wpnonce']            = 'bad';
		Functions\when( 'wp_verify_nonce' )->justReturn( false );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'die' );
		$this->handler->handle_migration_rearm();
	}

	public function test_rearm_verifies_its_own_nonce_action(): void {
		// The action string is the whole separation between this control and
		// the run beside it: a shared key would let a link minted for one
		// press the other. Asserted on the ARGUMENTS, since a `justReturn`
		// stub cannot tell which key it was asked about.
		$_GET['ffc_rearm_migration'] = 'foo';
		$_GET['_wpnonce']            = 'ok';

		Functions\expect( 'wp_verify_nonce' )
			->once()
			->with( 'ok', 'ffc_rearm_foo' )
			->andReturn( true );

		$this->strategy( 'foo', array(), 12 );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'redirect' );
		$this->handler->handle_migration_rearm();
	}

	public function test_rearm_passes_the_sanitized_key_and_redirects(): void {
		$_GET['ffc_rearm_migration'] = 'identity_index_backfill';
		$_GET['_wpnonce']            = 'ok';
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		$strategy = $this->strategy( 'identity_index_backfill', array(), 12 );

		$url = $this->redirect_of( 'handle_migration_rearm' );

		$this->assertSame( array( 'rearm' ), $strategy->calls );
		$this->assertStringContainsString( 'Walk re-armed: 12 accounts', $url );
	}

	public function test_rearm_wp_error_redirects(): void {
		// No strategy is registered under this key, so the manager answers a WP_Error.
		$_GET['ffc_rearm_migration'] = 'foo';
		$_GET['_wpnonce']            = 'ok';
		Functions\when( 'wp_verify_nonce' )->justReturn( true );

		$url = $this->redirect_of( 'handle_migration_rearm' );

		$this->assertStringContainsString( 'migration_error=No strategy found for migration: foo', $url );
	}

	// ==================================================================
	// handle_obsolete_shortcode_cleanup()
	// ==================================================================

	public function test_obsolete_returns_early_without_param(): void {
		// #1030: this asserted nothing. The statement immediately after the
		// param guard is the destructive-capability check, so a capability
		// that is never consulted is the proof that the guard returned.
		$this->handler->handle_obsolete_shortcode_cleanup();

		$this->assertSame( array(), $this->cap_checks );
	}

	public function test_obsolete_denies_without_capability(): void {
		$_REQUEST['ffc_obsolete_cleanup'] = 'preview';
		$this->grant();
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'die' );
		$this->handler->handle_obsolete_shortcode_cleanup();
	}

	public function test_obsolete_invalid_mode_dies(): void {
		$_REQUEST['ffc_obsolete_cleanup'] = 'bogus';
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'die' );
		$this->handler->handle_obsolete_shortcode_cleanup();
	}

	public function test_obsolete_bad_nonce_dies(): void {
		$_REQUEST['ffc_obsolete_cleanup'] = 'preview';
		$_REQUEST['_wpnonce']             = 'bad';
		Functions\when( 'wp_verify_nonce' )->justReturn( false );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'die' );
		$this->handler->handle_obsolete_shortcode_cleanup();
	}

	public function test_obsolete_save_days_persists_and_redirects(): void {
		$_REQUEST['ffc_obsolete_cleanup'] = 'save_days';
		$_REQUEST['_wpnonce']             = 'ok';
		$_POST['obsolete_shortcode_days'] = '120';
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		$written = $this->capture_option_write();

		$this->redirect_of( 'handle_obsolete_shortcode_cleanup' );

		$this->assertSame( 120, $written['ffc_settings']['obsolete_shortcode_days'] );
	}

	public function test_obsolete_preview_runs_tool_and_redirects(): void {
		$_REQUEST['ffc_obsolete_cleanup'] = 'preview';
		$_REQUEST['_wpnonce']             = 'ok';
		Functions\when( 'wp_verify_nonce' )->justReturn( true );

		$url = $this->redirect_of( 'handle_obsolete_shortcode_cleanup' );

		$this->assertStringContainsString( 'obsolete_cleanup_msg=Preview generated.', $url );
		$this->assertTrue( $this->transients['ffc_obsolete_cleanup_report_7']['dry_run'] );
		$this->assertSame( 1, $this->transients['ffc_obsolete_cleanup_preview_ok_7'] );
	}

	/**
	 * Its own process: the real registry always has the tool, so only a replaced one lacks it.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_obsolete_preview_tool_missing_redirects(): void {
		$_REQUEST['ffc_obsolete_cleanup'] = 'preview';
		$_REQUEST['_wpnonce']             = 'ok';
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		$this->mock_tool_registry_without_tools();

		$url = $this->redirect_of( 'handle_obsolete_shortcode_cleanup' );

		$this->assertStringContainsString( 'obsolete_cleanup_error=Maintenance tool not available.', $url );
	}

	public function test_obsolete_apply_without_preview_redirects(): void {
		$_REQUEST['ffc_obsolete_cleanup'] = 'apply';
		$_REQUEST['_wpnonce']             = 'ok';
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'get_transient' )->justReturn( false );

		$url = $this->redirect_of( 'handle_obsolete_shortcode_cleanup' );

		$this->assertStringContainsString( 'obsolete_cleanup_error=Please run a preview first', $url );
	}

	public function test_obsolete_apply_with_preview_runs_tool(): void {
		$_REQUEST['ffc_obsolete_cleanup'] = 'apply';
		$_REQUEST['_wpnonce']             = 'ok';
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'get_transient' )->justReturn( 1 );

		$url = $this->redirect_of( 'handle_obsolete_shortcode_cleanup' );

		$this->assertStringContainsString( 'obsolete_cleanup_msg=Cleanup complete. Removed 0 shortcode(s) from 0 post(s).', $url );
		$this->assertFalse( $this->transients['ffc_obsolete_cleanup_report_7']['dry_run'] );
	}

	// ==================================================================
	// handle_url_shortener_cleanup()
	// ==================================================================

	public function test_url_cleanup_returns_early_without_param(): void {
		// #1030: this asserted nothing. The statement immediately after the
		// param guard is the destructive-capability check, so a capability
		// that is never consulted is the proof that the guard returned.
		$this->handler->handle_url_shortener_cleanup();

		$this->assertSame( array(), $this->cap_checks );
	}

	public function test_url_cleanup_denies_without_capability(): void {
		$_REQUEST['ffc_url_cleanup'] = 'preview';
		$this->grant();
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'die' );
		$this->handler->handle_url_shortener_cleanup();
	}

	public function test_url_cleanup_invalid_mode_dies(): void {
		$_REQUEST['ffc_url_cleanup'] = 'bogus';
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'die' );
		$this->handler->handle_url_shortener_cleanup();
	}

	public function test_url_cleanup_bad_nonce_dies(): void {
		$_REQUEST['ffc_url_cleanup'] = 'preview';
		$_REQUEST['_wpnonce']        = 'bad';
		Functions\when( 'wp_verify_nonce' )->justReturn( false );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'die' );
		$this->handler->handle_url_shortener_cleanup();
	}

	/**
	 * Its own process: the real registry always has the tool, so only a replaced one lacks it.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_url_cleanup_tool_missing_redirects(): void {
		$_REQUEST['ffc_url_cleanup'] = 'preview';
		$_REQUEST['_wpnonce']        = 'ok';
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		$this->mock_tool_registry_without_tools();

		$url = $this->redirect_of( 'handle_url_shortener_cleanup' );

		$this->assertStringContainsString( 'url_cleanup_error=Maintenance tool not available.', $url );
	}

	public function test_url_cleanup_preview_runs_tool(): void {
		$_REQUEST['ffc_url_cleanup']        = 'preview';
		$_REQUEST['_wpnonce']               = 'ok';
		$_POST['url_cleanup_days']          = '30';
		$_POST['url_cleanup_orphaned']      = '1';
		$_POST['url_cleanup_never_clicked'] = '1';
		$_POST['url_cleanup_trashed']       = '1';
		Functions\when( 'wp_verify_nonce' )->justReturn( true );

		$url = $this->redirect_of( 'handle_url_shortener_cleanup' );

		$this->assertStringContainsString( 'url_cleanup_msg=Preview generated.', $url );
		$this->assertCount( 1, $this->queries, 'One candidate query.' );
		$this->assertStringContainsString( 'INTERVAL 30 DAY', $this->queries[0] );
		$this->assertTrue( $this->transients['ffc_url_cleanup_report_7']['dry_run'] );
	}

	public function test_url_cleanup_apply_without_preview_redirects(): void {
		$_REQUEST['ffc_url_cleanup'] = 'apply';
		$_REQUEST['_wpnonce']        = 'ok';
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'get_transient' )->justReturn( false );

		$url = $this->redirect_of( 'handle_url_shortener_cleanup' );

		$this->assertStringContainsString( 'url_cleanup_error=Please run a preview first', $url );
		$this->assertSame( array(), $this->queries, 'Nothing is deleted without a preview.' );
	}

	public function test_url_cleanup_apply_with_preview_runs_tool(): void {
		$_REQUEST['ffc_url_cleanup'] = 'apply';
		$_REQUEST['_wpnonce']        = 'ok';
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

		$url = $this->redirect_of( 'handle_url_shortener_cleanup' );

		$this->assertStringContainsString( 'url_cleanup_msg=Cleanup complete. Deleted 0 short URL(s).', $url );
		$this->assertFalse( $this->transients['ffc_url_cleanup_report_7']['dry_run'] );
	}

	// ==================================================================
	// handle_public_access_disabler()
	// ==================================================================

	public function test_pubaccess_returns_early_without_param(): void {
		// #1030: this asserted nothing. The statement immediately after the
		// param guard is the destructive-capability check, so a capability
		// that is never consulted is the proof that the guard returned.
		$this->handler->handle_public_access_disabler();

		$this->assertSame( array(), $this->cap_checks );
	}

	public function test_pubaccess_denies_without_capability(): void {
		$_REQUEST['ffc_pubaccess'] = 'preview';
		$this->grant();
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'die' );
		$this->handler->handle_public_access_disabler();
	}

	public function test_pubaccess_invalid_mode_dies(): void {
		$_REQUEST['ffc_pubaccess'] = 'bogus';
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'die' );
		$this->handler->handle_public_access_disabler();
	}

	public function test_pubaccess_bad_nonce_dies(): void {
		$_REQUEST['ffc_pubaccess'] = 'preview';
		$_REQUEST['_wpnonce']      = 'bad';
		Functions\when( 'wp_verify_nonce' )->justReturn( false );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'die' );
		$this->handler->handle_public_access_disabler();
	}

	/**
	 * Its own process: the real registry always has the tool, so only a replaced one lacks it.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_pubaccess_tool_missing_redirects(): void {
		$_REQUEST['ffc_pubaccess'] = 'preview';
		$_REQUEST['_wpnonce']      = 'ok';
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		$this->mock_tool_registry_without_tools();

		$url = $this->redirect_of( 'handle_public_access_disabler' );

		$this->assertStringContainsString( 'pubaccess_error=Maintenance tool not available.', $url );
	}

	public function test_pubaccess_preview_runs_tool(): void {
		$_REQUEST['ffc_pubaccess']           = 'preview';
		$_REQUEST['_wpnonce']                = 'ok';
		$_POST['public_access_disable_days'] = '45';
		Functions\when( 'wp_verify_nonce' )->justReturn( true );

		$url = $this->redirect_of( 'handle_public_access_disabler' );

		$this->assertStringContainsString( 'pubaccess_msg=Preview generated.', $url );
		$this->assertSame( 45, $this->transients['ffc_pubaccess_report_7']['days'] );
		$this->assertTrue( $this->transients['ffc_pubaccess_report_7']['dry_run'] );
	}

	public function test_pubaccess_apply_without_preview_redirects(): void {
		$_REQUEST['ffc_pubaccess'] = 'apply';
		$_REQUEST['_wpnonce']      = 'ok';
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'get_transient' )->justReturn( false );

		$url = $this->redirect_of( 'handle_public_access_disabler' );

		$this->assertStringContainsString( 'pubaccess_error=Please run a preview first', $url );
		$this->assertSame( array(), $GLOBALS['ffc_test_wp_query_calls'], 'No form is even looked up without a preview.' );
	}

	public function test_pubaccess_apply_with_preview_runs_tool(): void {
		$_REQUEST['ffc_pubaccess'] = 'apply';
		$_REQUEST['_wpnonce']      = 'ok';
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'get_transient' )->justReturn( 1 );
		Functions\when( 'get_option' )->justReturn( array( 'public_access_disable_days' => 60 ) );

		$url = $this->redirect_of( 'handle_public_access_disabler' );

		$this->assertStringContainsString( 'pubaccess_msg=Done. Disabled Public Operator Access on 0 form(s).', $url );
		$this->assertSame( 60, $this->transients['ffc_pubaccess_report_7']['days'] );
		$this->assertFalse( $this->transients['ffc_pubaccess_report_7']['dry_run'] );
	}

	// ==================================================================
	// handle_submission_link_audit()
	// ==================================================================

	public function test_submission_audit_returns_early_without_param(): void {
		// #1030: this asserted nothing. The statement immediately after the
		// param guard is the destructive-capability check, so a capability
		// that is never consulted is the proof that the guard returned.
		$this->handler->handle_submission_link_audit();

		$this->assertSame( array(), $this->cap_checks );
	}

	public function test_submission_audit_denies_without_capability(): void {
		$_REQUEST['ffc_submission_audit'] = 'scan';
		$this->grant();
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'die' );
		$this->handler->handle_submission_link_audit();
	}

	public function test_submission_audit_invalid_mode_dies(): void {
		$_REQUEST['ffc_submission_audit'] = 'bogus';
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'die' );
		$this->handler->handle_submission_link_audit();
	}

	public function test_submission_audit_bad_nonce_dies(): void {
		$_REQUEST['ffc_submission_audit'] = 'scan';
		$_REQUEST['_wpnonce']             = 'bad';
		Functions\when( 'wp_verify_nonce' )->justReturn( false );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'die' );
		$this->handler->handle_submission_link_audit();
	}

	/**
	 * Its own process: the real registry always has the tool, so only a replaced one lacks it.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_submission_audit_tool_missing_redirects(): void {
		$_REQUEST['ffc_submission_audit'] = 'scan';
		$_REQUEST['_wpnonce']             = 'ok';
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		$this->mock_tool_registry_without_tools();

		$url = $this->redirect_of( 'handle_submission_link_audit' );

		$this->assertStringContainsString( 'submission_audit_error=Maintenance tool not available.', $url );
	}

	public function test_submission_audit_scan_runs_tool(): void {
		$_REQUEST['ffc_submission_audit'] = 'scan';
		$_REQUEST['_wpnonce']             = 'ok';
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		// The audit is report-only: an empty install answers every read, and
		// any write fails the test.
		$this->empty_reads = true;

		$url = $this->redirect_of( 'handle_submission_link_audit' );

		$this->assertStringContainsString( 'submission_audit_msg=Audit complete.', $url );
		$this->assertNotSame( array(), $this->queries, 'The audit read the stores.' );
		$this->assertSame( 0, $this->transients['ffc_submission_audit_report_7']['total'] );
	}

	// ==================================================================
	// handle_cache_actions()
	// ==================================================================

	public function test_cache_actions_denies_without_capability(): void {
		// Ask for a real action, so the capability check is the only thing that
		// can stop it; check_admin_referer() is the first statement inside that
		// branch, so it never running is what proves the denial.
		$_GET['action'] = 'warm_cache';
		$this->grant();
		Functions\expect( 'check_admin_referer' )->never();

		$this->handler->handle_cache_actions();
	}

	public function test_cache_actions_no_action_does_nothing(): void {
		// Capability granted, so only the missing action can stop it.
		Functions\expect( 'check_admin_referer' )->never();

		$this->handler->handle_cache_actions();
	}

	public function test_cache_actions_warm_cache_redirects(): void {
		$_GET['action'] = 'warm_cache';
		Functions\when( 'check_admin_referer' )->justReturn( true );
		$posts = CallLog::record( 'get_posts', array() );

		$url = $this->redirect_of( 'handle_cache_actions' );

		// The real FormCache looked the published forms up once, and warmed none.
		$this->assertSame( 1, $posts->count() );
		$this->assertStringContainsString( 'msg=cache_warmed', $url );
		$this->assertStringContainsString( 'count=0', $url );
	}

	public function test_cache_actions_clear_cache_redirects(): void {
		$_GET['action'] = 'clear_cache';
		Functions\when( 'check_admin_referer' )->justReturn( true );
		Functions\when( 'wp_cache_supports' )->justReturn( true );
		$flush = CallLog::record( 'wp_cache_flush_group', true );
		$posts = CallLog::record( 'get_posts', array() );

		$url = $this->redirect_of( 'handle_cache_actions' );

		// The plugin's cache group is flushed once, and the published forms are looked
		// up once to purge their external caches.
		$this->assertSame( 1, $flush->count() );
		$this->assertSame( 1, $posts->count() );
		$this->assertStringContainsString( 'msg=cache_cleared', $url );
	}

	// ==================================================================
	// handle_send_test_email()
	// ==================================================================

	/** Stub wp_get_current_user() to return an object with the given email. */
	private function mock_current_user_email( string $email ): void {
		Functions\when( 'wp_get_current_user' )->justReturn( (object) array( 'user_email' => $email ) );
	}

	/**
	 * Stub what the shared email chrome (templates/emails/layout.php, read
	 * through the real EmailTemplateOptions and TokenResolver) asks of
	 * WordPress, and record every wp_mail() call.
	 *
	 * @return \ArrayObject Each sent message, as its wp_mail() arguments.
	 */
	private function capture_mail(): \ArrayObject {
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'sanitize_hex_color' )->returnArg();
		Functions\when( 'get_bloginfo' )->justReturn( 'Test Site' );
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( $text ) => strip_tags( (string) $text ) );
		Functions\when( 'wp_date' )->alias( static fn( $format, $ts = null ) => gmdate( $format, (int) $ts ) );

		$sent = new \ArrayObject();
		Functions\when( 'wp_mail' )->alias(
			static function ( ...$args ) use ( $sent ) {
				$sent[] = $args;
				return true;
			}
		);
		return $sent;
	}

	public function test_send_test_email_returns_early_without_param(): void {
		// #1030: this asserted nothing. The statement immediately after the
		// param guard is the destructive-capability check, so a capability
		// that is never consulted is the proof that the guard returned.
		$this->handler->handle_send_test_email();

		$this->assertSame( array(), $this->cap_checks );
	}

	public function test_send_test_email_denies_without_capability(): void {
		$_POST['ffc_send_test_email'] = '1';
		$this->grant();
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'die' );
		$this->handler->handle_send_test_email();
	}

	public function test_send_test_email_requires_smtp_subcap(): void {
		// #739 §4.4 — holding every settings cap EXCEPT the SMTP sub-cap is not
		// enough; the test-send exercises the SMTP transport.
		$_POST['ffc_send_test_email'] = '1';
		$this->grant( 'ffc_manage_settings', 'ffc_manage_settings_dangerzone', 'ffc_view_settings' );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'die' );
		$this->handler->handle_send_test_email();
	}

	public function test_send_test_email_is_allowed_by_the_test_cap_alone(): void {
		// #1860 — the FFC Settings Operator holds `ffc_test_settings_smtp`
		// and nothing that changes SMTP: the capability gate lets it through,
		// so the next thing the handler asks for is the nonce.
		$_POST['ffc_send_test_email'] = '1';
		$this->grant( 'ffc_test_settings_smtp' );
		Functions\when( 'check_admin_referer' )->alias(
			static function ( $action ) {
				throw new \RuntimeException( 'nonce:' . $action );
			}
		);
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'nonce:ffc_send_test_email' );
		$this->handler->handle_send_test_email();
	}

	public function test_send_test_email_bad_nonce_dies(): void {
		$_POST['ffc_send_test_email'] = '1';
		Functions\when( 'check_admin_referer' )->alias(
			static function () {
				throw new \RuntimeException( 'die' );
			}
		);
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'die' );
		$this->handler->handle_send_test_email();
	}

	public function test_send_test_email_sent_uses_current_user_email(): void {
		$_POST['ffc_send_test_email'] = '1';
		Functions\when( 'check_admin_referer' )->justReturn( true );
		Functions\when( 'is_email' )->justReturn( true );
		$this->mock_current_user_email( 'admin@example.com' );
		$sent = $this->capture_mail();
		// The source travels as headers when the mail queue asks for them.
		Filters\expectApplied( 'ffcertificate_emit_source_headers' )
			->once()
			->with( false, EmailSource::ADMIN )
			->andReturn( true );

		$url = $this->redirect_of( 'handle_send_test_email' );

		// The transport is called with the CURRENT USER's email — never a
		// request-supplied address — and the admin source.
		$this->assertCount( 1, $sent );
		$this->assertSame( 'admin@example.com', $sent[0][0] );
		$this->assertContains( 'X-TMQ-Source-Key: ' . EmailSource::ADMIN, $sent[0][3] );
		$this->assertStringContainsString( 'This is a test email from Free Form Certificate.', $sent[0][2] );
		$this->assertStringContainsString( 'ffc_test_email=sent', $url );
	}

	public function test_send_test_email_disabled_does_not_send(): void {
		$_POST['ffc_send_test_email'] = '1';
		Functions\when( 'check_admin_referer' )->justReturn( true );
		Functions\when( 'is_email' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( array( 'disable_all_emails' => 1 ) );
		$this->mock_current_user_email( 'admin@example.com' );
		$sent = $this->capture_mail();

		$url = $this->redirect_of( 'handle_send_test_email' );

		$this->assertCount( 0, $sent );
		$this->assertStringContainsString( 'ffc_test_email=disabled', $url );
	}

	public function test_send_test_email_no_address_does_not_send(): void {
		$_POST['ffc_send_test_email'] = '1';
		Functions\when( 'check_admin_referer' )->justReturn( true );
		$this->mock_current_user_email( '' );
		$sent = $this->capture_mail();

		$url = $this->redirect_of( 'handle_send_test_email' );

		$this->assertCount( 0, $sent );
		$this->assertStringContainsString( 'ffc_test_email=no_address', $url );
	}

	// ==================================================================
	// Out-of-range input, unreadable settings and tools that throw
	// ==================================================================

	/**
	 * Build real redirect URLs and run a handler to its redirect.
	 *
	 * @param string $method Handler.
	 * @return string The URL it redirected to, decoded.
	 */
	private function redirect_of( string $method ): string {
		Functions\when( 'add_query_arg' )->alias(
			static function ( $key, $value = null, $url = null ) {
				return is_array( $key )
					? (string) $value . '?' . http_build_query( $key )
					: (string) $url . '&' . $key . '=' . $value;
			}
		);
		$redirect = '';
		Functions\when( 'wp_safe_redirect' )->alias(
			static function ( $url ) use ( &$redirect ) {
				$redirect = (string) $url;
				throw new \RuntimeException( 'redirect' );
			}
		);
		try {
			$this->handler->$method();
			$this->fail( "{$method} did not redirect." );
		} catch ( \RuntimeException $e ) {
			if ( 'redirect' !== $e->getMessage() ) {
				throw $e;
			}
		}
		$this->assertNotSame( '', $redirect );
		return rawurldecode( $redirect );
	}

	/**
	 * Capture what update_option() writes.
	 *
	 * @return \ArrayObject Holder keyed by option name.
	 */
	private function capture_option_write(): \ArrayObject {
		$written = new \ArrayObject();
		Functions\when( 'update_option' )->alias(
			static function ( $name, $value ) use ( $written ) {
				$written[ $name ] = $value;
				return true;
			}
		);
		return $written;
	}

	public function test_obsolete_save_days_raises_a_zero_to_one_day(): void {
		$_REQUEST['ffc_obsolete_cleanup'] = 'save_days';
		$_REQUEST['_wpnonce']             = 'ok';
		$_POST['obsolete_shortcode_days'] = '0';
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		$written = $this->capture_option_write();

		$this->redirect_of( 'handle_obsolete_shortcode_cleanup' );

		$this->assertSame( 1, $written['ffc_settings']['obsolete_shortcode_days'] );
	}

	public function test_obsolete_save_days_caps_at_ten_years_over_unreadable_settings(): void {
		$_REQUEST['ffc_obsolete_cleanup'] = 'save_days';
		$_REQUEST['_wpnonce']             = 'ok';
		$_POST['obsolete_shortcode_days'] = '99999';
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( 'corrupt' );
		$written = $this->capture_option_write();

		$this->redirect_of( 'handle_obsolete_shortcode_cleanup' );

		$this->assertSame( array( 'obsolete_shortcode_days' => 3650 ), $written['ffc_settings'] );
	}

	public function test_obsolete_preview_uses_the_stored_days_and_reports_a_tool_failure(): void {
		$_REQUEST['ffc_obsolete_cleanup'] = 'preview';
		$_REQUEST['_wpnonce']             = 'ok';
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( array( 'obsolete_shortcode_days' => 30 ) );
		// Form 10 ended 31 days ago: expired only under the stored 30 days, so
		// the tool reaches the posts that embed it — and that read fails. Under
		// the 90-day default it would find nothing to scan and report success.
		$GLOBALS['ffc_test_wp_query_queue'] = array( array( 10 ), array( 20 ) );
		$this->form_ended_31_days_ago( 10 );
		Functions\when( 'get_post' )->alias(
			static function () {
				throw new \RuntimeException( 'Table is locked' );
			}
		);

		$url = $this->redirect_of( 'handle_obsolete_shortcode_cleanup' );

		$this->assertStringContainsString( 'obsolete_cleanup_error=Table is locked', $url );
		$this->assertArrayNotHasKey( 'ffc_obsolete_cleanup_preview_ok_7', $this->transients );
	}

	public function test_obsolete_apply_reports_a_tool_failure(): void {
		$_REQUEST['ffc_obsolete_cleanup'] = 'apply';
		$_REQUEST['_wpnonce']             = 'ok';
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'get_transient' )->justReturn( 1 );
		$GLOBALS['ffc_test_wp_query_queue'] = array( array( 10 ) );
		$this->meta_failure                 = 'Table is locked';

		$url = $this->redirect_of( 'handle_obsolete_shortcode_cleanup' );

		$this->assertStringContainsString( 'obsolete_cleanup_error=Table is locked', $url );
		$this->assertStringNotContainsString( 'obsolete_cleanup_msg', $url );
	}

	/**
	 * Its own process: the real registry always has the tool, so only a replaced one lacks it.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_obsolete_apply_without_the_tool_says_so(): void {
		$_REQUEST['ffc_obsolete_cleanup'] = 'apply';
		$_REQUEST['_wpnonce']             = 'ok';
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'get_transient' )->justReturn( 1 );
		$this->mock_tool_registry_without_tools();

		$url = $this->redirect_of( 'handle_obsolete_shortcode_cleanup' );

		$this->assertStringContainsString( 'obsolete_cleanup_error=Maintenance tool not available.', $url );
	}

	public function test_url_cleanup_preview_reports_a_tool_failure_over_unreadable_settings(): void {
		$_REQUEST['ffc_url_cleanup']   = 'preview';
		$_REQUEST['_wpnonce']          = 'ok';
		$_POST['url_cleanup_orphaned'] = '1';
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( 'corrupt' );
		$written          = $this->capture_option_write();
		$this->db_failure = 'Table is locked';

		$url = $this->redirect_of( 'handle_url_shortener_cleanup' );

		$this->assertStringContainsString( 'url_cleanup_error=Table is locked', $url );
		$this->assertSame( 90, $written['ffc_settings']['url_cleanup_days'], 'the posted criteria are still saved' );
	}

	public function test_url_cleanup_apply_reports_a_tool_failure(): void {
		$_REQUEST['ffc_url_cleanup'] = 'apply';
		$_REQUEST['_wpnonce']        = 'ok';
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'get_transient' )->justReturn( 1 );
		Functions\when( 'get_option' )->justReturn( array( 'url_cleanup_orphaned' => 1 ) );
		$this->db_failure = 'Table is locked';

		$url = $this->redirect_of( 'handle_url_shortener_cleanup' );

		$this->assertStringContainsString( 'url_cleanup_error=Table is locked', $url );
	}

	public function test_pubaccess_preview_reports_a_tool_failure_over_unreadable_settings(): void {
		$_REQUEST['ffc_pubaccess']           = 'preview';
		$_REQUEST['_wpnonce']                = 'ok';
		$_POST['public_access_disable_days'] = '5000';
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( 'corrupt' );
		$written                            = $this->capture_option_write();
		$GLOBALS['ffc_test_wp_query_queue'] = array( array( 10 ) );
		$this->meta_failure                 = 'Table is locked';

		$url = $this->redirect_of( 'handle_public_access_disabler' );

		$this->assertStringContainsString( 'pubaccess_error=Table is locked', $url );
		$this->assertSame( array( 'public_access_disable_days' => 3650 ), $written['ffc_settings'] );
	}

	public function test_pubaccess_apply_reports_a_tool_failure(): void {
		$_REQUEST['ffc_pubaccess'] = 'apply';
		$_REQUEST['_wpnonce']      = 'ok';
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'get_transient' )->justReturn( 1 );
		$GLOBALS['ffc_test_wp_query_queue'] = array( array( 10 ) );
		$this->meta_failure                 = 'Table is locked';

		$url = $this->redirect_of( 'handle_public_access_disabler' );

		$this->assertStringContainsString( 'pubaccess_error=Table is locked', $url );
	}

	public function test_submission_audit_scan_reports_a_tool_failure(): void {
		$_REQUEST['ffc_submission_audit'] = 'scan';
		$_REQUEST['_wpnonce']             = 'ok';
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		$this->db_failure = 'Table is locked';

		$url = $this->redirect_of( 'handle_submission_link_audit' );

		$this->assertStringContainsString( 'submission_audit_error=Table is locked', $url );
	}

	public function test_submission_audit_export_is_gated_by_the_identity_audit_source(): void {
		$_REQUEST['ffc_submission_audit'] = 'export';
		$_GET['_wpnonce']                 = 'stale';
		// The export's own gate lives in the source; the scan nonce is never
		// read. A stale export nonce stops it there, before anything streams.
		$nonces = array();
		Functions\when( 'wp_verify_nonce' )->alias(
			static function ( $nonce, $action ) use ( &$nonces ) {
				$nonces[] = $action;
				return false;
			}
		);

		try {
			$this->handler->handle_submission_link_audit();
			$this->fail( 'Expected the source to refuse the stale nonce.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'die', $e->getMessage() );
		}
		$this->assertSame( array( IdentityAuditExportSource::NONCE ), $nonces );
		$this->assertSame( array(), $this->queries, 'Nothing was read or streamed.' );
	}
}
