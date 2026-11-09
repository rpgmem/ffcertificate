<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\RoleCapabilityEditor;

/**
 * Tests for RoleCapabilityEditor — the global role→capability editor on
 * Settings → User Access.
 *
 * Covers the editable-role discovery (FFC roles only), the role→caps map,
 * a render smoke test, and the AJAX persistence handler with its guards
 * (cap- + role-whitelist, manage_options).
 *
 * One test runs in its own process: it declares core's `WP_User_Query`
 * through `eval()`, which a process cannot undo. The rest share the suite's
 * process, where `WP_User_Query` does not exist; it leaked into that process
 * from a file-scope declaration in another test until #2104. The enqueue test
 * runs the real `Core\AssetHelper` and `Core\AssetRegistry` down to the
 * stubbed `wp_enqueue_*` calls.
 *
 * @covers \FreeFormCertificate\Admin\RoleCapabilityEditor
 * @preserveGlobalState disabled
 */
class RoleCapabilityEditorTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array<string, mixed> `$_GET` as an earlier test left it, restored afterwards. */
	private array $get_backup = array();

	/**
	 * The stored role overrides, as the editor writes them.
	 *
	 * @var mixed
	 */
	private $overrides = array();

	protected function setUp(): void {
		\WP_User_Query::reset();
		parent::setUp();
		Monkey\setUp();
		$this->get_backup = $_GET ?? array();

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_option' )->justReturn( array() );
		// LabelSorter guards on function_exists( 'get_locale' ), so in the
		// shared process its branch would depend on which test ran before.
		Functions\when( 'get_locale' )->justReturn( 'en_US' );
		Functions\when( 'wp_admin_notice' )->alias(
			static function ( $message, $args = array() ) {
				$classes = implode( ' ', $args['additional_classes'] ?? array() );
				echo '<div class="notice ' . $classes . '"><p>' . $message . '</p></div>';
			}
		);
		Functions\when( '_n' )->alias(
			static function ( $single, $plural, $number ) {
				return 1 === $number ? $single : $plural;
			}
		);
	}

	protected function tearDown(): void {
		\WP_User_Query::reset();
		$_GET = $this->get_backup;
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A WP_Role-like stand-in that records add_cap/remove_cap.
	 *
	 * @param array<string,bool> $caps Initial capabilities.
	 * @return object
	 */
	private function fake_role( array $caps = array() ): object {
		return new class( $caps ) {
			/** @var array<string,bool> */
			public $capabilities;
			/** @var list<array{cap:string,grant:bool}> */
			public $added = array();
			/** @var list<string> */
			public $removed = array();
			public function __construct( $caps ) {
				$this->capabilities = $caps;
			}
			public function add_cap( $cap, $grant = true ) {
				$this->capabilities[ $cap ] = $grant;
				$this->added[] = array(
					'cap'   => $cap,
					'grant' => $grant,
				);
			}
			public function remove_cap( $cap ) {
				unset( $this->capabilities[ $cap ] );
				$this->removed[] = $cap;
			}
		};
	}

	public function test_editable_roles_lists_registered_ffc_roles_only(): void {
		// ffc_end_user registered; one module role missing; non-FFC roles never appear.
		Functions\when( 'get_role' )->alias(
			function ( $slug ) {
				if ( 'ffc_audiences_manager' === $slug ) {
					return null; // not registered on this install
				}
				return $this->fake_role();
			}
		);

		$roles = RoleCapabilityEditor::editable_roles();
		$slugs = array_column( $roles, 'slug' );

		$this->assertContains( 'ffc_end_user', $slugs );
		$this->assertContains( 'ffc_recruitment_manager', $slugs );
		$this->assertNotContains( 'ffc_audiences_manager', $slugs ); // unregistered dropped
		$this->assertNotContains( 'administrator', $slugs );
		// Shape.
		$this->assertSame( 0, $roles[0]['users'] ); // WP_User_Query absent in tests
		$this->assertNotEmpty( $roles[0]['label'] );
	}

	public function test_role_caps_map_lists_only_cataloged_caps_a_role_grants(): void {
		Functions\when( 'get_role' )->alias(
			function ( $slug ) {
				if ( 'ffc_end_user' === $slug ) {
					return $this->fake_role(
						array(
							'read'                      => true, // non-catalog: ignored
							'ffc_view_own_certificates' => true,
							'ffc_book_own_appointments'     => true,
						)
					);
				}
				return $this->fake_role();
			}
		);

		$map = RoleCapabilityEditor::role_caps_map();

		$this->assertArrayHasKey( 'ffc_end_user', $map );
		$this->assertContains( 'ffc_view_own_certificates', $map['ffc_end_user'] );
		$this->assertContains( 'ffc_book_own_appointments', $map['ffc_end_user'] );
		$this->assertNotContains( 'read', $map['ffc_end_user'] ); // non-catalog filtered out
	}

	public function test_render_outputs_role_picker_and_grid(): void {
		Functions\when( 'get_role' )->alias(
			fn( $slug ) => $this->fake_role( array( 'ffc_view_own_certificates' => true ) )
		);

		ob_start();
		RoleCapabilityEditor::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'ffc-role-editor', $output );
		$this->assertStringContainsString( 'ffc-role-select', $output );
		$this->assertStringContainsString( 'ffc-role-impact', $output );
		// The difference bar starts hidden; the script shows it for a changed role.
		$this->assertStringContainsString( '<div class="ffc-role-diff" role="status" hidden>', $output );
		$this->assertMatchesRegularExpression( '/class="[^"]*ffc-btn-caution[^"]*ffc-icon-restore[^"]*ffc-role-restore|class="[^"]*ffc-role-restore[^"]*"/', $output );
		$this->assertStringContainsString( 'ffc-icon-restore', $output );
		$this->assertStringContainsString( '<span class="ffc-role-diff-tag" hidden></span>', $output );
		$this->assertStringContainsString( 'data-ffc-cap-slug="ffc_view_recruitment_pii"', $output );
		// A cap the first role grants renders checked.
		$this->assertMatchesRegularExpression( '/ffc_role_cap_ffc_view_own_certificates[^>]*checked/', $output );
		// Its own card with an icon heading, like every Settings section (#1631),
		// and the card closes: one open, one more close than the panel's own.
		$this->assertStringStartsWith( '<div class="card"><h2 class="ffc-icon-shield">', $output );
		$this->assertSame( substr_count( $output, '<div' ), substr_count( $output, '</div>' ) );
		$this->assertStringNotContainsString( '<hr', $output );
	}

	// ------------------------------------------------------------------
	// ajax_set_role_cap()
	// ------------------------------------------------------------------

	/**
	 * @return array<string, mixed>
	 */
	private function run_ajax( string $method = 'ajax_set_role_cap', bool $log = false ): array {
		$captured = array();
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'sanitize_text_field' )->returnArg();
		if ( ! is_array( $this->overrides ) || ! $log ) {
			$this->overrides = array();
		}
		Functions\when( 'get_option' )->alias(
			fn( $key, $default = false ) => 'ffc_settings' === $key
				? ( $log ? \FreeFormCertificate\Tests\Support\ActivityLogBuffer::ENABLED_SETTINGS : array() )
				: ( \FreeFormCertificate\UserDashboard\RoleRegistrar::OVERRIDES_OPTION === $key ? $this->overrides : $default )
		);
		Functions\when( 'update_option' )->alias(
			function ( $key, $value ) {
				$this->overrides = $value;
				return true;
			}
		);
		Functions\when( 'wp_send_json_success' )->alias(
			static function ( $data = null ) use ( &$captured ) {
				$captured = array( 'ok' => true, 'data' => $data );
				throw new \Exception( 'ffc_json_halt' );
			}
		);
		Functions\when( 'wp_send_json_error' )->alias(
			static function ( $data = null, $code = null ) use ( &$captured ) {
				$captured = array( 'ok' => false, 'data' => $data, 'code' => $code );
				throw new \Exception( 'ffc_json_halt' );
			}
		);
		try {
			RoleCapabilityEditor::$method();
		} catch ( \Exception $e ) {
			if ( 'ffc_json_halt' !== $e->getMessage() ) {
				throw $e;
			}
		}
		return $captured;
	}

	public function test_render_tells_someone_without_manage_options_why_and_draws_no_editor(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		ob_start();
		RoleCapabilityEditor::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Only administrators can edit roles', $output );
		$this->assertStringNotContainsString( 'ffc-role-select', $output );
		$this->assertStringNotContainsString( 'ffc-role-cap', $output );
	}

	public function test_a_powerful_row_asks_before_it_is_granted_and_every_toggle_has_a_name(): void {
		Functions\when( 'get_role' )->alias(
			fn( $slug ) => $this->fake_role( array( 'ffc_view_own_certificates' => true ) )
		);

		ob_start();
		RoleCapabilityEditor::render();
		$output = ob_get_clean();

		$this->assertMatchesRegularExpression( '/data-ffc-cap-slug="ffc_view_recruitment_pii"[^>]*data-ffc-confirm="1"/', $output );
		$this->assertMatchesRegularExpression( '/data-ffc-cap-slug="ffc_view_own_certificates"[^>]*data-ffc-confirm="0"/', $output );
		$this->assertMatchesRegularExpression( '/id="ffc_role_cap_ffc_view_own_certificates"[^>]*aria-label="[^"]+"|aria-label="[^"]+"[^>]*id="ffc_role_cap_ffc_view_own_certificates"/', $output );
	}

	public function test_ajax_grants_a_cataloged_cap_on_an_ffc_role(): void {
		$role = $this->fake_role();
		Functions\when( 'get_role' )->alias( fn( $slug ) => $role );

		$_POST['role']  = 'ffc_end_user';
		$_POST['cap']   = 'ffc_view_own_certificates';
		$_POST['grant'] = '1';

		$res = $this->run_ajax();

		$this->assertTrue( $res['ok'] );
		$this->assertCount( 1, $role->added );
		$this->assertSame( 'ffc_view_own_certificates', $role->added[0]['cap'] );

		unset( $_POST['role'], $_POST['cap'], $_POST['grant'] );
	}

	public function test_ajax_removes_a_cap(): void {
		$role = $this->fake_role( array( 'ffc_view_own_certificates' => true ) );
		Functions\when( 'get_role' )->alias( fn( $slug ) => $role );

		$_POST['role']  = 'ffc_end_user';
		$_POST['cap']   = 'ffc_view_own_certificates';
		$_POST['grant'] = '0';

		$res = $this->run_ajax();

		$this->assertTrue( $res['ok'] );
		$this->assertSame( array( 'ffc_view_own_certificates' ), $role->removed );
		// The end-user role ships no FFC capability, so nothing is recorded.
		$this->assertSame( array(), $this->overrides );

		unset( $_POST['role'], $_POST['cap'], $_POST['grant'] );
	}

	public function test_ajax_records_a_removal_from_a_shipped_role(): void {
		$definition = \FreeFormCertificate\UserDashboard\CapabilityManager::module_roles_definition();
		$slug       = 'ffc_readonly';
		$cap        = $definition[ $slug ]['caps'][0];
		$role       = $this->fake_role( array( $cap => true ) );
		Functions\when( 'get_role' )->alias( fn( $s ) => $role );

		$_POST['role']  = $slug;
		$_POST['cap']   = $cap;
		$_POST['grant'] = '0';

		$res = $this->run_ajax();

		$this->assertTrue( $res['ok'] );
		// Kept, so the next request does not grant it back.
		$this->assertSame( array( $cap ), $this->overrides[ $slug ]['removed'] );

		unset( $_POST['role'], $_POST['cap'], $_POST['grant'] );
	}

	/**
	 * Run a handler with the activity log on, and return its rows.
	 *
	 * @param string $method Handler.
	 * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>}
	 */
	private function run_logged( string $method ): array {
		global $wpdb;
		$saved        = $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn( $sql ) => (string) $sql );
		$wpdb->shouldReceive( 'get_var' )->andReturn( null );
		$wpdb->shouldReceive( 'get_results' )->andReturn( array() );
		$wpdb->shouldReceive( 'get_col' )->andReturn( array() );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'current_time' )->justReturn( '2026-11-06 10:00:00' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 3 );
		$buffer = new \ReflectionProperty( \FreeFormCertificate\Core\ActivityLog::class, 'write_buffer' );
		$buffer->setAccessible( true );
		$buffer->setValue( null, array() );

		$res = $this->run_ajax( $method, true );

		$rows = array_values( (array) $buffer->getValue() );
		$buffer->setValue( null, array() );
		$wpdb = $saved;
		return array( $res, $rows );
	}

	public function test_ajax_logs_a_role_capability_change(): void {
		$role = $this->fake_role();
		Functions\when( 'get_role' )->alias( fn( $s ) => $role );
		$_POST['role']  = 'ffc_end_user';
		$_POST['cap']   = 'ffc_view_own_certificates';
		$_POST['grant'] = '1';

		list( $res, $rows ) = $this->run_logged( 'ajax_set_role_cap' );
		unset( $_POST['role'], $_POST['cap'], $_POST['grant'] );

		$this->assertTrue( $res['ok'] );
		$this->assertSame( 'role_capability_changed', $rows[0]['action'] ?? null );
		$this->assertSame( 3, $rows[0]['user_id'] ?? null );
		$this->assertSame(
			array( 'role' => 'ffc_end_user', 'cap' => 'ffc_view_own_certificates', 'granted' => 1 ),
			json_decode( (string) $rows[0]['context'], true )
		);
	}

	public function test_restore_puts_the_shipped_definition_back_and_forgets_the_changes(): void {
		$slug     = 'ffc_readonly';
		$shipped  = \FreeFormCertificate\UserDashboard\CapabilityManager::module_roles_definition()[ $slug ]['caps'];
		$catalog  = \FreeFormCertificate\UserDashboard\CapabilityCatalog::all_slugs();
		$extra    = current( array_diff( $catalog, $shipped ) );
		$missing  = $shipped[0];
		$held     = array_fill_keys( array_slice( $shipped, 1 ), true );
		$held    += array(
			'read'       => true,
			'custom_cap' => true,
			$extra       => true,
		);
		$role     = $this->fake_role( $held );
		Functions\when( 'get_role' )->alias( fn( $s ) => $role );
		$this->overrides = array(
			$slug          => array( 'added' => array( $extra ), 'removed' => array( $missing ) ),
			'ffc_x_viewer' => array( 'added' => array( 'a' ), 'removed' => array() ),
		);
		$_POST['role'] = $slug;

		list( $res, $rows ) = $this->run_logged( 'ajax_restore_role_default' );
		unset( $_POST['role'] );

		$this->assertTrue( $res['ok'] );
		$this->assertSame( array( $missing ), $res['data']['restored'] );
		$this->assertSame( array( $extra ), $res['data']['removed'] );
		$this->assertSame( array( $missing ), array_column( $role->added, 'cap' ) );
		$this->assertSame( array( $extra ), $role->removed );
		// read and a capability outside the catalog stay as they were.
		$this->assertTrue( $role->capabilities['read'] );
		$this->assertTrue( $role->capabilities['custom_cap'] );
		$this->assertSame( array_values( array_intersect( $catalog, $shipped ) ), $res['data']['caps'] );
		// Only this role's record is forgotten.
		$this->assertArrayNotHasKey( $slug, $this->overrides );
		$this->assertArrayHasKey( 'ffc_x_viewer', $this->overrides );
		$this->assertSame( 'role_default_restored', $rows[0]['action'] ?? null );
		$this->assertSame(
			array( 'role' => $slug, 'restored' => array( $missing ), 'removed' => array( $extra ) ),
			json_decode( (string) $rows[0]['context'], true )
		);
	}

	public function test_restore_refuses_a_role_with_no_shipped_definition(): void {
		$role = $this->fake_role( array( 'ffc_view_own_certificates' => true ) );
		Functions\when( 'get_role' )->alias( fn( $s ) => $role );
		$_POST['role'] = 'ffc_end_user';

		$res = $this->run_ajax( 'ajax_restore_role_default' );
		unset( $_POST['role'] );

		$this->assertFalse( $res['ok'] );
		$this->assertSame( 400, $res['code'] );
		$this->assertSame( 'role_has_no_default', $res['data']['message'] );
		$this->assertSame( array(), $role->removed );
	}

	public function test_restore_requires_manage_options(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		$role = $this->fake_role( array( 'ffc_view_own_certificates' => true ) );
		Functions\when( 'get_role' )->alias( fn( $s ) => $role );
		$_POST['role'] = 'ffc_readonly';

		$res = $this->run_ajax( 'ajax_restore_role_default' );
		unset( $_POST['role'] );

		$this->assertFalse( $res['ok'] );
		$this->assertSame( 403, $res['code'] );
		$this->assertSame( array(), $role->removed );
		$this->assertSame( array(), $role->added );
	}

	public function test_restore_reports_a_missing_role(): void {
		Functions\when( 'get_role' )->justReturn( null );
		$_POST['role'] = 'ffc_readonly';

		$res = $this->run_ajax( 'ajax_restore_role_default' );
		unset( $_POST['role'] );

		$this->assertSame( 404, $res['code'] );
	}

	public function test_ajax_rejects_non_ffc_role(): void {
		Functions\when( 'get_role' )->alias( fn( $slug ) => $this->fake_role() );

		$_POST['role']  = 'editor';
		$_POST['cap']   = 'ffc_view_own_certificates';
		$_POST['grant'] = '1';

		$res = $this->run_ajax();

		$this->assertFalse( $res['ok'] );
		$this->assertSame( 'role_not_editable', $res['data']['message'] );

		unset( $_POST['role'], $_POST['cap'], $_POST['grant'] );
	}

	public function test_ajax_rejects_cap_outside_catalog(): void {
		Functions\when( 'get_role' )->alias( fn( $slug ) => $this->fake_role() );

		$_POST['role']  = 'ffc_end_user';
		$_POST['cap']   = 'manage_options';
		$_POST['grant'] = '1';

		$res = $this->run_ajax();

		$this->assertFalse( $res['ok'] );
		$this->assertSame( 'cap_not_in_catalog', $res['data']['message'] );

		unset( $_POST['role'], $_POST['cap'], $_POST['grant'] );
	}

	public function test_ajax_requires_manage_options(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$_POST['role']  = 'ffc_end_user';
		$_POST['cap']   = 'ffc_view_own_certificates';
		$_POST['grant'] = '1';

		$res = $this->run_ajax();

		$this->assertFalse( $res['ok'] );
		// The refusal says why, so the screen can show it (#2340).
		$this->assertStringStartsWith( 'Only administrators can edit roles', $res['data']['message'] );
		$this->assertSame( 403, $res['code'] );

		unset( $_POST['role'], $_POST['cap'], $_POST['grant'] );
	}

	public function test_ajax_reports_a_role_that_is_not_registered_on_this_install(): void {
		Functions\when( 'get_role' )->justReturn( null );

		$_POST['role']  = 'ffc_end_user';
		$_POST['cap']   = 'ffc_view_own_certificates';
		$_POST['grant'] = '1';

		$res = $this->run_ajax();

		$this->assertFalse( $res['ok'] );
		$this->assertSame( 'role_not_found', $res['data']['message'] );
		$this->assertSame( 404, $res['code'] );

		unset( $_POST['role'], $_POST['cap'], $_POST['grant'] );
	}

	public function test_init_wires_the_ajax_handler_and_the_assets(): void {
		$hooks = array();
		Functions\when( 'add_action' )->alias(
			static function ( $hook, $callback ) use ( &$hooks ) {
				$hooks[ $hook ] = $callback;
				return true;
			}
		);

		RoleCapabilityEditor::init();

		$this->assertSame(
			array(
				'wp_ajax_ffc_set_role_cap'         => array( RoleCapabilityEditor::class, 'ajax_set_role_cap' ),
				'wp_ajax_ffc_restore_role_default' => array( RoleCapabilityEditor::class, 'ajax_restore_role_default' ),
				'admin_enqueue_scripts'            => array( RoleCapabilityEditor::class, 'enqueue' ),
			),
			$hooks
		);
	}

	public function test_enqueue_loads_only_on_the_user_access_tab(): void {
		Functions\when( 'admin_url' )->returnArg();
		Functions\when( 'wp_create_nonce' )->alias( static fn( $action ) => 'nonce-' . $action );
		Functions\when( 'get_role' )->justReturn( null );
		// ffc-core is registered from the shared map before it is enqueued.
		Functions\when( 'wp_register_script' )->justReturn( false );
		$assets = array();
		Functions\when( 'wp_enqueue_style' )->alias(
			static function ( $handle ) use ( &$assets ) {
				$assets[] = $handle;
			}
		);
		Functions\when( 'wp_enqueue_script' )->alias(
			static function ( $handle ) use ( &$assets ) {
				$assets[] = $handle;
			}
		);
		$localized = array();
		Functions\when( 'wp_localize_script' )->alias(
			static function ( $handle, $name, $data ) use ( &$localized ) {
				$localized[ $name ] = $data;
				return true;
			}
		);

		RoleCapabilityEditor::enqueue( 'edit.php' );
		$_GET['tab'] = 'general';
		RoleCapabilityEditor::enqueue( 'toplevel_page_ffc-settings' );
		$this->assertSame( array(), $assets, 'another screen or tab loads nothing' );

		$_GET['tab'] = 'user_access';
		RoleCapabilityEditor::enqueue( 'toplevel_page_ffc-settings' );
		unset( $_GET['tab'] );

		$this->assertSame( array( 'ffc-common', 'ffc-user-permissions', 'ffc-core', 'ffc-role-editor' ), $assets );
		$this->assertSame(
			array_keys( \FreeFormCertificate\UserDashboard\CapabilityManager::module_roles_definition() ),
			array_keys( $localized['ffcRoleEditor']['roleDefaults'] ),
			'every shipped role has a default to compare with'
		);
		$this->assertSame( array(), $localized['ffcRoleEditor']['roles'] );
		// Restoring deletes nothing and can be redone by hand: no countdown.
		$this->assertSame( \FreeFormCertificate\Core\ConfirmCountdown::NONE, $localized['ffcRoleEditor']['i18n']['dialog']['seconds'] );
		$this->assertSame( 'nonce-ffc_set_role_cap', $localized['ffcRoleEditor']['nonce'] );
		$this->assertSame( array(), $localized['ffcRoleEditor']['roleCaps'], 'a role missing on this install is left out of the map' );
	}

	public function test_editable_roles_count_their_members(): void {
		\WP_User_Query::$total = static fn( array $args ): int => 'ffc_end_user' === $args['role'] ? 12 : 0;
		Functions\when( 'get_role' )->alias( fn( $slug ) => $this->fake_role() );

		$roles = array_column( RoleCapabilityEditor::editable_roles(), 'users', 'slug' );

		$this->assertSame( 12, $roles['ffc_end_user'] );
		$this->assertSame( 'ID', \WP_User_Query::$queries[0]['fields'] );
		$this->assertTrue( \WP_User_Query::$queries[0]['count_total'] );
	}

	public function test_render_says_so_when_no_ffc_role_is_registered(): void {
		Functions\when( 'get_role' )->justReturn( null );

		ob_start();
		RoleCapabilityEditor::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'ffc-role-impact', $html );
		$this->assertStringContainsString( '<p>No FFC roles are registered.</p></div>', $html );
		$this->assertStringNotContainsString( 'ffc-role-select', $html );
	}
}
