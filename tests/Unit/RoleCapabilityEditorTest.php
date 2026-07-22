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
 * @covers \FreeFormCertificate\Admin\RoleCapabilityEditor
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class RoleCapabilityEditorTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( '_n' )->alias(
			static function ( $single, $plural, $number ) {
				return 1 === $number ? $single : $plural;
			}
		);
	}

	protected function tearDown(): void {
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
		// ffc_user registered; one module role missing; non-FFC roles never appear.
		Functions\when( 'get_role' )->alias(
			function ( $slug ) {
				if ( 'ffc_audience_manager' === $slug ) {
					return null; // not registered on this install
				}
				return $this->fake_role();
			}
		);

		$roles = RoleCapabilityEditor::editable_roles();
		$slugs = array_column( $roles, 'slug' );

		$this->assertContains( 'ffc_end_user', $slugs );
		$this->assertContains( 'ffc_recruitment_manager', $slugs );
		$this->assertNotContains( 'ffc_audience_manager', $slugs ); // unregistered dropped
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
}
