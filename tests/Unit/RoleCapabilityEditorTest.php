<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
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

	protected function setUp(): void {
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
	private function run_ajax(): array {
		$captured = array();
		Functions\when( 'check_ajax_referer' )->justReturn( true );
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
			RoleCapabilityEditor::ajax_set_role_cap();
		} catch ( \Exception $e ) {
			if ( 'ffc_json_halt' !== $e->getMessage() ) {
				throw $e;
			}
		}
		return $captured;
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

		unset( $_POST['role'], $_POST['cap'], $_POST['grant'] );
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
		$this->assertSame( 'forbidden', $res['data']['message'] );

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
				'wp_ajax_ffc_set_role_cap' => array( RoleCapabilityEditor::class, 'ajax_set_role_cap' ),
				'admin_enqueue_scripts'    => array( RoleCapabilityEditor::class, 'enqueue' ),
			),
			$hooks
		);
	}

	public function test_enqueue_loads_only_on_the_user_access_tab(): void {
		Functions\when( 'admin_url' )->returnArg();
		Functions\when( 'wp_create_nonce' )->alias( static fn( $action ) => 'nonce-' . $action );
		Functions\when( 'get_role' )->justReturn( null );
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

		$this->assertSame( array( 'ffc-common', 'ffc-user-permissions', 'ffc-role-editor' ), $assets );
		$this->assertSame( 'nonce-ffc_set_role_cap', $localized['ffcRoleEditor']['nonce'] );
		$this->assertSame( array(), $localized['ffcRoleEditor']['roleCaps'], 'a role missing on this install is left out of the map' );
	}

	/**
	 * Its own process: it declares core's WP_User_Query, which the bootstrap does not.
	 *
	 * @runInSeparateProcess
	 */
	public function test_editable_roles_count_their_members(): void {
		// Declared here, in this test's own process: core's query class with a fixed total.
		// phpcs:ignore Squiz.PHP.Eval.Discouraged -- Declares a WordPress core class inside this test's own process.
		eval(
			'class WP_User_Query {
				public $args;
				public function __construct( $args ) { $this->args = $args; $GLOBALS["ffc_test_user_queries"][] = $args; }
				public function get_total() { return "ffc_end_user" === $this->args["role"] ? 12 : 0; }
			}'
		);
		$GLOBALS['ffc_test_user_queries'] = array();
		Functions\when( 'get_role' )->alias( fn( $slug ) => $this->fake_role() );

		$roles = array_column( RoleCapabilityEditor::editable_roles(), 'users', 'slug' );

		$this->assertSame( 12, $roles['ffc_end_user'] );
		$this->assertSame( 'ID', $GLOBALS['ffc_test_user_queries'][0]['fields'] );
		$this->assertTrue( $GLOBALS['ffc_test_user_queries'][0]['count_total'] );
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
