<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\AdminUserCapabilities;

/**
 * Tests for AdminUserCapabilities: per-user FFC capability management
 * on WordPress user edit / profile pages.
 *
 * Covers hook registration, enqueue gating, render guard clauses,
 * render output (HTML checkboxes), save guard clauses, grant/remove
 * capability logic, and Debug logging.
 *
 * The collaborators run for real -- CapabilityManager, CapabilityCatalog,
 * AudienceReader / AudienceWriter, AssetHelper / AssetRegistry, RequestInput,
 * AdminUI and Debug -- over a `$wpdb` double that answers the audience queries
 * from the rows a test stores, and records every membership write.
 *
 * @covers \FreeFormCertificate\Admin\AdminUserCapabilities
 */
class AdminUserCapabilitiesTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var list<object> Active audiences the audiences table answers. */
	private array $audiences = array();

	/** @var array<int, list<int>> Audience ids each user belongs to, keyed by user id. */
	private array $memberships = array();

	/** @var list<array<string, int>> Rows inserted into the members table. */
	private array $member_inserts = array();

	/** @var list<array<string, int>> Rows deleted from the members table. */
	private array $member_deletes = array();

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->reset_role_snapshot();
		$this->audiences      = array();
		$this->memberships    = array();
		$this->member_inserts = array();
		$this->member_deletes = array();

		// Common WP stubs
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( function ( $text ) {
			echo $text;
		} );
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'admin_url' )->returnArg();
		Functions\when( 'checked' )->alias( function ( $checked, $current = true, $echo = true ) {
			$result = $checked == $current ? ' checked=\'checked\'' : '';
			if ( $echo ) {
				echo $result;
			}
			return $result;
		} );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_nonce_field' )->alias( function () {
			echo '<input type="hidden" name="ffc_capabilities_nonce" value="test_nonce" />';
		} );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'user_can' )->justReturn( false );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		// Debug::is_enabled() and SettingsReader read this: nothing stored, so
		// debug logging, the activity log and the grant e-mail are all off.
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'get_userdata' )->justReturn( false );
		Functions\when( 'absint' )->alias( 'intval' );
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'wp_create_nonce' )->justReturn( 'role-nonce' );
		Functions\when( 'wp_localize_script' )->justReturn( true );
		Functions\when( 'wp_parse_args' )->alias( static fn( $args, $defaults ) => array_merge( $defaults, (array) $args ) );
		Functions\when( 'sanitize_sql_orderby' )->returnArg();
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( '_n' )->alias(
			static function ( $single, $plural, $number ) {
				return 1 === $number ? $single : $plural;
			}
		);

		// wp_roles(): a minimal stand-in exposing ->roles + get_names(), so
		// ffc_preset_roles() can discover the FFC roles (those granting an FFC
		// cap and NOT manage_options).
		$roles_obj = new class() {
			/** @var array<string,array<string,mixed>> */
			public $roles = array(
				'ffc_end_user'             => array(
					'capabilities' => array(
						'read'                      => true,
						'ffc_view_own_certificates' => true,
						'ffc_book_own_appointments' => true,
					),
				),
				'ffc_recruitment_manager'  => array(
					'capabilities' => array(
						'read'                   => true,
						'ffc_manage_recruitment' => true,
						'ffc_view_recruitment'   => true,
					),
				),
				'administrator'            => array(
					'capabilities' => array(
						'manage_options'      => true,
						'ffc_manage_settings' => true,
					),
				),
				'subscriber'               => array(
					'capabilities' => array( 'read' => true ),
				),
			);
			public function get_names() {
				return array(
					'ffc_end_user'            => 'FFC End User',
					'ffc_recruitment_manager' => 'FFC Recruitment - Manager',
					'administrator'           => 'Administrator',
					'subscriber'              => 'Subscriber',
				);
			}
		};
		Functions\when( 'wp_roles' )->justReturn( $roles_obj );

		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']   = $this->wpdb_double();
	}

	/**
	 * A `$wpdb` that answers the audience reader's and writer's queries from
	 * the stored audiences and memberships, records each membership write, and
	 * fails the test on any other query.
	 *
	 * @return Mockery\MockInterface
	 */
	private function wpdb_double() {
		$wpdb            = Mockery::mock( 'wpdb' );
		$wpdb->prefix    = 'wp_';
		$wpdb->insert_id = 0;
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( $sql, ...$args ) {
				$flat = 1 === count( $args ) && is_array( $args[0] ) ? $args[0] : $args;
				return array(
					'sql'  => $sql,
					'args' => $flat,
				);
			}
		);
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $q ) {
				if ( false !== strpos( $q['sql'], 'INNER JOIN %i m ON a.id = m.audience_id' ) ) {
					$member_of = $this->memberships[ (int) $q['args'][2] ] ?? array();
					return array_map( static fn( $id ) => (object) array( 'id' => $id ), $member_of );
				}
				if ( 0 === strpos( $q['sql'], 'SELECT * FROM %i WHERE status = %s ORDER BY name ASC' ) ) {
					$this->assertSame( array( 'wp_ffc_audiences', 'active' ), $q['args'] );
					return $this->audiences;
				}
				$this->fail( 'Unexpected get_results(): ' . $q['sql'] );
			}
		);
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			function ( $q ) {
				if ( 'SELECT COUNT(*) FROM %i WHERE audience_id = %d AND user_id = %d' === $q['sql'] ) {
					$member_of = $this->memberships[ (int) $q['args'][2] ] ?? array();
					return in_array( (int) $q['args'][1], $member_of, true ) ? '1' : '0';
				}
				$this->fail( 'Unexpected get_var(): ' . $q['sql'] );
			}
		);
		$wpdb->shouldReceive( 'insert' )->andReturnUsing(
			function ( $table, $data ) use ( $wpdb ) {
				$this->assertSame( 'wp_ffc_audience_members', $table, 'Only the members table is written.' );
				$this->member_inserts[] = $data;
				$wpdb->insert_id        = count( $this->member_inserts );
				return 1;
			}
		);
		$wpdb->shouldReceive( 'delete' )->andReturnUsing(
			function ( $table, $where ) {
				$this->assertSame( 'wp_ffc_audience_members', $table, 'Only the members table is written.' );
				$this->member_deletes[] = $where;
				return 1;
			}
		);
		return $wpdb;
	}

	/**
	 * Empty the per-request role snapshot the class keeps in a static.
	 */
	private function reset_role_snapshot(): void {
		$ref = new \ReflectionProperty( AdminUserCapabilities::class, 'ffc_role_snapshot' );
		$ref->setAccessible( true );
		$ref->setValue( null, array() );
	}

	protected function tearDown(): void {
		unset( $_POST['ffc_capabilities_nonce'], $_POST['ffc_audience'], $_GET['user_id'] );
		// Reset the per-request role snapshot so it never leaks between tests.
		$this->reset_role_snapshot();
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	// ==================================================================
	// init()
	// ==================================================================

	public function test_init_registers_hooks(): void {
		Functions\expect( 'add_action' )
			->once()
			->with( 'show_user_profile', array( AdminUserCapabilities::class, 'render_capability_fields' ) );

		Functions\expect( 'add_action' )
			->once()
			->with( 'edit_user_profile', array( AdminUserCapabilities::class, 'render_capability_fields' ) );

		Functions\expect( 'add_action' )
			->once()
			->with( 'personal_options_update', array( AdminUserCapabilities::class, 'save_capability_fields' ) );

		Functions\expect( 'add_action' )
			->once()
			->with( 'edit_user_profile_update', array( AdminUserCapabilities::class, 'save_capability_fields' ) );

		Functions\expect( 'add_action' )
			->once()
			->with( 'admin_enqueue_scripts', array( AdminUserCapabilities::class, 'enqueue_scripts' ) );

		// Multi-role preservation across the native profile save (snapshot
		// before core's set_role, restore after).
		Functions\expect( 'add_action' )
			->once()
			->with( 'personal_options_update', array( AdminUserCapabilities::class, 'snapshot_ffc_roles' ), 5 );

		Functions\expect( 'add_action' )
			->once()
			->with( 'edit_user_profile_update', array( AdminUserCapabilities::class, 'snapshot_ffc_roles' ), 5 );

		Functions\expect( 'add_action' )
			->once()
			->with( 'profile_update', array( AdminUserCapabilities::class, 'restore_ffc_roles' ), 5 );

		AdminUserCapabilities::init();
	}

	// ==================================================================
	// enqueue_scripts()
	// ==================================================================

	public function test_enqueue_scripts_on_user_edit_page(): void {
		Functions\expect( 'wp_enqueue_style' )
			->once()
			->with(
				'ffc-common',
				Mockery::pattern( '/ffc-common\.min\.css/' ),
				array(),
				FFC_VERSION
			);
		Functions\expect( 'wp_enqueue_style' )
			->once()
			->with(
				'ffc-user-permissions',
				Mockery::pattern( '/ffc-user-permissions\.min\.css/' ),
				array( 'ffc-common' ),
				FFC_VERSION
			);
		Functions\expect( 'wp_enqueue_script' )
			->once()
			->with(
				'ffc-user-capabilities',
				Mockery::pattern( '/ffc-user-capabilities\.min\.js/' ),
				array(),
				FFC_VERSION,
				true
			);

		AdminUserCapabilities::enqueue_scripts( 'user-edit.php' );
	}

	public function test_enqueue_scripts_skips_other_pages(): void {
		Functions\expect( 'wp_enqueue_script' )->never();

		AdminUserCapabilities::enqueue_scripts( 'edit.php' );
	}

	// ==================================================================
	// render_capability_fields() — guard clauses
	// ==================================================================

	public function test_render_skips_for_non_admin(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$user = new \WP_User( 5 );
		$user->roles = array( 'ffc_end_user' );

		ob_start();
		AdminUserCapabilities::render_capability_fields( $user );
		$output = ob_get_clean();

		$this->assertEmpty( $output );
	}

	public function test_render_shows_only_role_presets_for_admin(): void {
		// #739: admins no longer get FFC caps automatically, so the panel now
		// renders the role-preset chips (to assign ffc_administrator) — but
		// NOT the per-cap checkbox groups, which are noise for a full admin.
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'user_can' )->justReturn( true );

		$user = new \WP_User( 1 );
		$user->roles = array( 'administrator' );

		ob_start();
		AdminUserCapabilities::render_capability_fields( $user );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'FFC Permissions', $output );
		$this->assertStringContainsString( 'ffc-cap-roles', $output );
		$this->assertStringNotContainsString( 'ffc-cap-group', $output );
	}

	public function test_render_offers_only_aggregator_chip_for_wp_admin(): void {
		// #739: a WordPress administrator can only be assigned ffc_administrator.
		// The UI must offer that chip (enabled) and omit the granular FFC roles
		// the admin doesn't hold — clicking them would only ever 409.
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'user_can' )->justReturn( true );
		Functions\when( 'wp_roles' )->justReturn( self::admin_aware_roles_stub() );

		$user        = new \WP_User( 1 );
		$user->roles = array( 'administrator' ); // WP admin, holds no FFC preset.

		ob_start();
		AdminUserCapabilities::render_capability_fields( $user );
		$output = ob_get_clean();

		// The aggregator chip is offered and NOT disabled.
		$this->assertStringContainsString( 'data-ffc-role="ffc_administrator"', $output );
		$this->assertDoesNotMatchRegularExpression(
			'/data-ffc-role="ffc_administrator"[^>]*\sdisabled/',
			$output
		);
		// The granular role the admin does not hold is omitted entirely.
		$this->assertStringNotContainsString( 'data-ffc-role="ffc_recruitment_manager"', $output );
	}

	public function test_render_shows_held_granular_role_disabled_for_wp_admin(): void {
		// A WP admin who legacy-holds a granular FFC role: the chip stays visible
		// (so its state shows) but renders disabled — the server refuses to
		// toggle it (#739), so it must not be a live control.
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'user_can' )->justReturn( true );
		Functions\when( 'wp_roles' )->justReturn( self::admin_aware_roles_stub() );

		$user        = new \WP_User( 1 );
		$user->roles = array( 'administrator', 'ffc_recruitment_manager' );

		ob_start();
		AdminUserCapabilities::render_capability_fields( $user );
		$output = ob_get_clean();

		$this->assertMatchesRegularExpression(
			'/data-ffc-role="ffc_recruitment_manager"[^>]*\sdisabled/',
			$output
		);
		$this->assertStringContainsString( 'is-disabled', $output );
	}

	/**
	 * wp_roles() stand-in that includes the ffc_administrator aggregator, so the
	 * WP-admin-target rendering path (only ffc_administrator assignable) can be
	 * exercised. Distinct from the setUp() stub, which omits it.
	 *
	 * @return object
	 */
	private static function admin_aware_roles_stub(): object {
		return new class() {
			/** @var array<string,array<string,mixed>> */
			public $roles = array(
				'ffc_administrator'       => array(
					'capabilities' => array(
						'read'                      => true,
						'ffc_view_own_certificates' => true,
						'ffc_manage_recruitment'    => true,
					),
				),
				'ffc_recruitment_manager' => array(
					'capabilities' => array(
						'read'                   => true,
						'ffc_manage_recruitment' => true,
					),
				),
			);
			public function get_names() {
				return array(
					'ffc_administrator'       => 'FFC Administrator',
					'ffc_recruitment_manager' => 'FFC Recruitment - Manager',
				);
			}
		};
	}

	public function test_render_skips_for_non_ffc_users(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'user_can' )->justReturn( false );

		// No ffc_end_user role and no FFC capabilities: the real
		// CapabilityManager reads the user and finds neither access kind.
		$user = new \WP_User( 10 );
		$user->roles = array( 'subscriber' );
		Functions\when( 'get_userdata' )->alias( static fn( $id ) => 10 === $id ? $user : false );

		ob_start();
		AdminUserCapabilities::render_capability_fields( $user );
		$output = ob_get_clean();

		$this->assertEmpty( $output );
	}

	// ==================================================================
	// render_capability_fields() — HTML output
	// ==================================================================

	public function test_render_outputs_capability_checkboxes(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'user_can' )->justReturn( false );

		$user = new \WP_User( 5 );
		$user->roles = array( 'ffc_end_user' );

		ob_start();
		AdminUserCapabilities::render_capability_fields( $user );
		$output = ob_get_clean();

		// Heading + panel wrapper.
		$this->assertStringContainsString( 'FFC Permissions', $output );
		$this->assertStringContainsString( 'ffc-cap-panel', $output );

		// Checkbox inputs across the user-level groups…
		$this->assertStringContainsString( 'ffc_cap_ffc_view_own_certificates', $output );
		$this->assertStringContainsString( 'ffc_cap_ffc_download_own_certificates', $output );
		$this->assertStringContainsString( 'ffc_cap_ffc_book_own_appointments', $output );
		$this->assertStringContainsString( 'ffc_cap_ffc_view_own_appointments', $output );
		$this->assertStringContainsString( 'ffc_cap_ffc_manage_reregistration', $output );

		// …and the admin-level caps that the old flat form never rendered
		// (proves the full 26-cap catalog is now shown, fixing the silent
		// remove_cap() on save).
		$this->assertStringContainsString( 'ffc_cap_ffc_manage_recruitment', $output );
		$this->assertStringContainsString( 'ffc_cap_ffc_view_recruitment_pii', $output );
		$this->assertStringContainsString( 'ffc_cap_ffc_manage_settings', $output );

		// New affordances: slug chips, origin badges, search box, presets.
		$this->assertStringContainsString( 'ffc-cap-slug', $output );
		$this->assertStringContainsString( 'ffc-cap-origin', $output );
		$this->assertStringContainsString( 'ffc-cap-search', $output );
		$this->assertStringContainsString( 'data-ffc-preset="all"', $output );
		$this->assertStringContainsString( 'data-ffc-preset="none"', $output );

		// Read-only context summary (role + audiences).
		$this->assertStringContainsString( 'ffc-cap-context', $output );
		$this->assertStringContainsString( 'ffc_end_user', $output );
		$this->assertStringContainsString( 'page=ffc-scheduling-audiences', $output );

		// Nonce field.
		$this->assertStringContainsString( 'ffc_capabilities_nonce', $output );
	}

	public function test_render_outputs_audience_membership_checklist(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'user_can' )->justReturn( false );

		$this->audiences = array(
			(object) array(
				'id'    => 3,
				'name'  => 'Teachers',
				'color' => '#ff0000',
			),
			(object) array(
				'id'    => 7,
				'name'  => 'Staff',
				'color' => '',
			),
		);
		// The user is a member of audience 7 only.
		$this->memberships = array( 5 => array( 7 ) );

		$user        = new \WP_User( 5 );
		$user->roles = array( 'ffc_end_user' );

		ob_start();
		AdminUserCapabilities::render_capability_fields( $user );
		$output = ob_get_clean();

		// Both active audiences render as checkboxes.
		$this->assertStringContainsString( 'name="ffc_audience[]" value="3"', $output );
		$this->assertStringContainsString( 'name="ffc_audience[]" value="7"', $output );
		$this->assertStringContainsString( 'Teachers', $output );

		// The current membership (7) is pre-checked; the non-membership (3) is not.
		$this->assertMatchesRegularExpression( '/value="7"[^>]*checked/', $output );
		$this->assertDoesNotMatchRegularExpression( '/value="3"[^>]*checked/', $output );
	}

	// ==================================================================
	// render_capability_fields() — role preset chips (#Frente A)
	// ==================================================================

	public function test_render_outputs_role_preset_chips_and_locks_role_caps(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'user_can' )->justReturn( false );

		$user        = new \WP_User( 5 );
		$user->roles = array( 'ffc_end_user' );

		ob_start();
		AdminUserCapabilities::render_capability_fields( $user );
		$output = ob_get_clean();

		// FFC roles render as assignable chips; ffc_end_user is currently assigned.
		$this->assertStringContainsString( 'data-ffc-role="ffc_end_user"', $output );
		$this->assertStringContainsString( 'data-ffc-role="ffc_recruitment_manager"', $output );
		$this->assertMatchesRegularExpression( '/data-ffc-role="ffc_end_user"[^>]*aria-pressed="true"/', $output );

		// administrator (manage_options) and subscriber (no FFC cap) are excluded.
		$this->assertStringNotContainsString( 'data-ffc-role="administrator"', $output );
		$this->assertStringNotContainsString( 'data-ffc-role="subscriber"', $output );

		// Caps the ffc_end_user role grants lock as "Role" (byrole toggle + badge).
		$this->assertStringContainsString( 'ffc-cap-toggle--byrole', $output );
		$this->assertStringContainsString( 'ffc-cap-origin--role', $output );
	}

	// ==================================================================
	// ajax_toggle_user_role() — role assignment (#Frente A)
	// ==================================================================

	/**
	 * Invoke the AJAX handler, capturing the wp_send_json_* payload. The
	 * stubs throw so execution halts exactly where the real wp_die would.
	 *
	 * @return array<string, mixed>
	 */
	private function run_ajax_role(): array {
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
			AdminUserCapabilities::ajax_toggle_user_role();
		} catch ( \Exception $e ) {
			if ( 'ffc_json_halt' !== $e->getMessage() ) {
				throw $e;
			}
		}
		return $captured;
	}

	public function test_ajax_assigns_a_preset_role(): void {
		$_POST['user_id'] = '5';
		$_POST['role']    = 'ffc_recruitment_manager';
		$_POST['assign']  = '1';

		$user        = new \WP_User( 5 );
		$user->roles = array( 'ffc_end_user' );
		Functions\when( 'get_userdata' )->justReturn( $user );

		$res = $this->run_ajax_role();

		$this->assertTrue( $res['ok'] );
		$this->assertContains( 'ffc_recruitment_manager', $user->roles );
		$this->assertContains( 'ffc_end_user', $user->roles );

		unset( $_POST['user_id'], $_POST['role'], $_POST['assign'] );
	}

	public function test_ajax_removes_a_preset_role(): void {
		$_POST['user_id'] = '5';
		$_POST['role']    = 'ffc_end_user';
		$_POST['assign']  = '0';

		$user        = new \WP_User( 5 );
		$user->roles = array( 'ffc_end_user', 'ffc_recruitment_manager' );
		Functions\when( 'get_userdata' )->justReturn( $user );

		$res = $this->run_ajax_role();

		$this->assertTrue( $res['ok'] );
		$this->assertNotContains( 'ffc_end_user', $user->roles );
		$this->assertContains( 'ffc_recruitment_manager', $user->roles );

		unset( $_POST['user_id'], $_POST['role'], $_POST['assign'] );
	}

	public function test_ajax_rejects_non_preset_role(): void {
		$_POST['user_id'] = '5';
		$_POST['role']    = 'editor';
		$_POST['assign']  = '1';

		$user        = new \WP_User( 5 );
		$user->roles = array( 'ffc_end_user' );
		Functions\when( 'get_userdata' )->justReturn( $user );

		$res = $this->run_ajax_role();

		$this->assertFalse( $res['ok'] );
		$this->assertSame( 'role_not_assignable', $res['data']['message'] );
		$this->assertNotContains( 'editor', $user->roles );

		unset( $_POST['user_id'], $_POST['role'], $_POST['assign'] );
	}

	public function test_ajax_refuses_to_edit_admin_target(): void {
		$_POST['user_id'] = '5';
		$_POST['role']    = 'ffc_end_user';
		$_POST['assign']  = '0';

		Functions\when( 'user_can' )->justReturn( true ); // target is an administrator
		$user = new \WP_User( 5 );
		Functions\when( 'get_userdata' )->justReturn( $user );

		$res = $this->run_ajax_role();

		$this->assertFalse( $res['ok'] );
		$this->assertSame( 'cannot_edit_admin', $res['data']['message'] );

		unset( $_POST['user_id'], $_POST['role'], $_POST['assign'] );
	}

	public function test_ajax_allows_ffc_administrator_on_admin_target(): void {
		// #739: the aggregator role must be assignable to a WP administrator
		// (partial FFC roles on an admin are still refused above).
		$roles_obj = new class() {
			/** @var array<string, array<string, mixed>> */
			public $roles = array(
				'ffc_administrator' => array(
					'capabilities' => array( 'ffc_manage_certificates' => true ),
				),
			);
			/** @return array<string, string> */
			public function get_names(): array {
				return array( 'ffc_administrator' => 'FFC Administrator' );
			}
		};
		Functions\when( 'wp_roles' )->justReturn( $roles_obj );

		$_POST['user_id'] = '5';
		$_POST['role']    = 'ffc_administrator';
		$_POST['assign']  = '1';

		Functions\when( 'user_can' )->justReturn( true ); // target is an administrator
		$user        = new \WP_User( 5 );
		$user->roles = array( 'administrator' );
		Functions\when( 'get_userdata' )->justReturn( $user );

		$res = $this->run_ajax_role();

		$this->assertTrue( $res['ok'] );
		$this->assertContains( 'ffc_administrator', $user->roles );

		unset( $_POST['user_id'], $_POST['role'], $_POST['assign'] );
	}

	public function test_ajax_requires_manage_options(): void {
		$_POST['user_id'] = '5';
		$_POST['role']    = 'ffc_end_user';
		$_POST['assign']  = '1';

		Functions\when( 'current_user_can' )->justReturn( false );

		$res = $this->run_ajax_role();

		$this->assertFalse( $res['ok'] );
		$this->assertSame( 'forbidden', $res['data']['message'] );

		unset( $_POST['user_id'], $_POST['role'], $_POST['assign'] );
	}

	// ==================================================================
	// save_capability_fields() — guard clauses
	// ==================================================================

	public function test_save_aborts_without_nonce(): void {
		// No $_POST nonce set
		unset( $_POST['ffc_capabilities_nonce'] );

		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		$user = new \WP_User( 5 );
		Functions\when( 'get_userdata' )->justReturn( $user );

		// Should return early — user caps should not change
		AdminUserCapabilities::save_capability_fields( 5 );

		$this->assertEmpty( $user->caps );
	}

	public function test_save_aborts_without_permission(): void {
		$_POST['ffc_capabilities_nonce'] = 'valid_nonce';
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( false );

		$user = new \WP_User( 5 );
		Functions\when( 'get_userdata' )->justReturn( $user );

		AdminUserCapabilities::save_capability_fields( 5 );

		$this->assertEmpty( $user->caps );
	}

	// ==================================================================
	// save_capability_fields() — grant / remove
	// ==================================================================

	public function test_save_grants_capabilities(): void {
		$_POST['ffc_capabilities_nonce'] = 'valid_nonce';
		$_POST['ffc_cap_ffc_view_own_certificates'] = '1';
		$_POST['ffc_cap_ffc_book_own_appointments'] = '1';

		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );

		$user = new \WP_User( 5 );
		Functions\when( 'get_userdata' )->justReturn( $user );

		AdminUserCapabilities::save_capability_fields( 5 );

		// Granted capabilities should be true
		$this->assertTrue( $user->caps['ffc_view_own_certificates'] );
		$this->assertTrue( $user->caps['ffc_book_own_appointments'] );

		// Non-granted capabilities should have been removed (not present)
		$this->assertArrayNotHasKey( 'download_own_certificates', $user->caps );
		$this->assertArrayNotHasKey( 'ffc_view_own_appointments', $user->caps );

		// Clean up
		unset( $_POST['ffc_cap_ffc_view_own_certificates'], $_POST['ffc_cap_ffc_book_own_appointments'] );
	}

	public function test_save_removes_capabilities(): void {
		$_POST['ffc_capabilities_nonce'] = 'valid_nonce';
		// No ffc_cap_* fields set — all capabilities should be removed

		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );

		$user = new \WP_User( 5 );
		// Pre-grant some capabilities
		$user->add_cap( 'ffc_view_own_certificates', true );
		$user->add_cap( 'ffc_book_own_appointments', true );
		Functions\when( 'get_userdata' )->justReturn( $user );

		AdminUserCapabilities::save_capability_fields( 5 );

		// All capabilities should have been removed
		$this->assertArrayNotHasKey( 'ffc_view_own_certificates', $user->caps );
		$this->assertArrayNotHasKey( 'ffc_book_own_appointments', $user->caps );
	}

	// ==================================================================
	// save_capability_fields() — audience membership sync
	// ==================================================================

	public function test_save_adds_audience_membership(): void {
		$_POST['ffc_capabilities_nonce'] = 'valid_nonce';
		$_POST['ffc_audience']           = array( '3', '7' );

		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );

		$this->audiences = array(
			(object) array( 'id' => 3, 'name' => 'Teachers' ),
			(object) array( 'id' => 7, 'name' => 'Staff' ),
		);
		// Already a member of 7 → only 3 should be added, nothing removed.
		$this->memberships = array( 5 => array( 7 ) );

		$user = new \WP_User( 5 );
		Functions\when( 'get_userdata' )->justReturn( $user );

		AdminUserCapabilities::save_capability_fields( 5 );

		$this->assertSame(
			array( array( 'audience_id' => 3, 'user_id' => 5 ) ),
			$this->member_inserts
		);
		$this->assertSame( array(), $this->member_deletes );
	}

	public function test_save_removes_audience_membership_when_unchecked(): void {
		$_POST['ffc_capabilities_nonce'] = 'valid_nonce';
		// No ffc_audience key → every box unchecked → drop current memberships.

		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );

		$this->audiences   = array( (object) array( 'id' => 7, 'name' => 'Staff' ) );
		$this->memberships = array( 5 => array( 7 ) );

		$user = new \WP_User( 5 );
		Functions\when( 'get_userdata' )->justReturn( $user );

		AdminUserCapabilities::save_capability_fields( 5 );

		$this->assertSame(
			array( array( 'audience_id' => 7, 'user_id' => 5 ) ),
			$this->member_deletes
		);
		$this->assertSame( array(), $this->member_inserts );
	}

	public function test_save_does_not_touch_audiences_when_none_active(): void {
		$_POST['ffc_capabilities_nonce'] = 'valid_nonce';

		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );

		// No active audiences → the checklist never rendered → sync must be a
		// no-op so an unrelated save can't wipe memberships.
		// The user still holds a stored membership the sync must not drop.
		$this->audiences   = array();
		$this->memberships = array( 5 => array( 7 ) );

		$user = new \WP_User( 5 );
		Functions\when( 'get_userdata' )->justReturn( $user );

		AdminUserCapabilities::save_capability_fields( 5 );

		$this->assertSame( array(), $this->member_inserts );
		$this->assertSame( array(), $this->member_deletes );
	}

	// ==================================================================
	// save_capability_fields() — Debug logging
	// ==================================================================

	public function test_save_reaches_debug_log_path(): void {
		// The real Debug class runs: verify the save completes without error
		// when it is present (class_exists returns true naturally since the
		// autoloader loaded it).
		$_POST['ffc_capabilities_nonce'] = 'valid_nonce';
		$_POST['ffc_cap_ffc_view_own_certificates'] = '1';

		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );

		$user = new \WP_User( 5 );
		Functions\when( 'get_userdata' )->justReturn( $user );

		// The save method will call Debug::log_user_manager() since
		// the class exists.  The real Debug class calls get_option (stubbed
		// in setUp) and should not throw.
		AdminUserCapabilities::save_capability_fields( 5 );

		// If we reached here without error, the debug log path was executed.
		$this->assertTrue( $user->caps['ffc_view_own_certificates'] );

		// Clean up
		unset( $_POST['ffc_cap_ffc_view_own_certificates'] );
	}

	// ==================================================================
	// snapshot_ffc_roles() / restore_ffc_roles() — survive the native save
	// ==================================================================

	public function test_snapshot_then_restore_readds_role_core_collapsed(): void {
		// The user carries an FFC role (added earlier via the AJAX chip) on top
		// of a plain WordPress role.
		$user        = new \WP_User( 5 );
		$user->roles = array( 'subscriber', 'ffc_recruitment_manager' );
		Functions\when( 'get_userdata' )->justReturn( $user );

		// 1) Snapshot before core processes the profile form.
		AdminUserCapabilities::snapshot_ffc_roles( 5 );

		// 2) Core's single-role dropdown collapses the user to one role.
		$user->set_role( 'subscriber' );
		$this->assertSame( array( 'subscriber' ), $user->roles );

		// 3) Restoring re-adds the dropped FFC role, keeping the primary one.
		AdminUserCapabilities::restore_ffc_roles( 5 );
		$this->assertContains( 'ffc_recruitment_manager', $user->roles );
		$this->assertContains( 'subscriber', $user->roles );
	}

	public function test_restore_is_noop_without_a_snapshot(): void {
		$user        = new \WP_User( 7 );
		$user->roles = array( 'subscriber' );
		Functions\when( 'get_userdata' )->justReturn( $user );

		AdminUserCapabilities::restore_ffc_roles( 7 );

		$this->assertSame( array( 'subscriber' ), $user->roles );
	}

	public function test_snapshot_with_no_ffc_roles_restores_nothing(): void {
		$user        = new \WP_User( 8 );
		$user->roles = array( 'subscriber' );
		Functions\when( 'get_userdata' )->justReturn( $user );

		AdminUserCapabilities::snapshot_ffc_roles( 8 );
		AdminUserCapabilities::restore_ffc_roles( 8 );

		$this->assertSame( array( 'subscriber' ), $user->roles );
	}

	public function test_snapshot_ignores_a_missing_user(): void {
		Functions\when( 'get_userdata' )->justReturn( false );

		AdminUserCapabilities::snapshot_ffc_roles( 999 );
		AdminUserCapabilities::restore_ffc_roles( 999 );

		// "No snapshot recorded" was the claim; read the store and check it.
		// A snapshot for a user that does not exist would later re-grant roles
		// from stale data.
		$store = new \ReflectionProperty( AdminUserCapabilities::class, 'ffc_role_snapshot' );
		$store->setAccessible( true );
		$this->assertArrayNotHasKey( 999, (array) $store->getValue() );
	}
}
