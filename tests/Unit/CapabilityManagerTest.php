<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\UserDashboard\CapabilityManager;

/**
 * Tests for CapabilityManager: constants, grant, revoke, access checks, role management.
 */
class CapabilityManagerTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'get_option' )->justReturn( 0 );
        Functions\when( 'absint' )->alias( function( $val ) { return abs( intval( $val ) ); } );
        Functions\when( 'get_current_user_id' )->justReturn( 0 );
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'sanitize_key' )->alias( function( $key ) { return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', $key ) ); } );
        Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Constants
    // ------------------------------------------------------------------

    public function test_context_constants_are_strings(): void {
        $this->assertSame( 'certificate', CapabilityManager::CONTEXT_CERTIFICATE );
        $this->assertSame( 'appointment', CapabilityManager::CONTEXT_APPOINTMENT );
        $this->assertSame( 'audience', CapabilityManager::CONTEXT_AUDIENCE );
    }

    public function test_certificate_capabilities_contains_expected_caps(): void {
        $caps = CapabilityManager::CERTIFICATE_CAPABILITIES;
        $this->assertContains( 'view_own_certificates', $caps );
        $this->assertContains( 'download_own_certificates', $caps );
        $this->assertContains( 'view_certificate_history', $caps );
        $this->assertCount( 3, $caps );
    }

    public function test_appointment_capabilities_contains_expected_caps(): void {
        $caps = CapabilityManager::APPOINTMENT_CAPABILITIES;
        $this->assertContains( 'ffc_book_appointments', $caps );
        $this->assertContains( 'ffc_view_self_scheduling', $caps );
        $this->assertContains( 'ffc_cancel_own_appointments', $caps );
        $this->assertCount( 3, $caps );
    }

    public function test_audience_capabilities_contains_expected_caps(): void {
        $caps = CapabilityManager::AUDIENCE_CAPABILITIES;
        $this->assertContains( 'ffc_view_audience_bookings', $caps );
        $this->assertCount( 1, $caps );
    }

    public function test_admin_capabilities_contains_pre_6_2_caps(): void {
        $caps = CapabilityManager::ADMIN_CAPABILITIES;
        $this->assertContains( 'ffc_scheduling_bypass', $caps );
        $this->assertContains( 'ffc_manage_reregistration', $caps );
        $this->assertContains( 'ffc_manage_recruitment', $caps );
    }

    public function test_admin_capabilities_contains_6_2_module_caps(): void {
        $caps = CapabilityManager::ADMIN_CAPABILITIES;
        $this->assertContains( 'ffc_manage_certificates', $caps );
        $this->assertContains( 'ffc_export_certificates', $caps );
        $this->assertContains( 'ffc_manage_self_scheduling', $caps );
        $this->assertContains( 'ffc_manage_audiences', $caps );
        $this->assertContains( 'ffc_view_activity_log', $caps );
        $this->assertContains( 'ffc_manage_user_custom_fields', $caps );
        $this->assertContains( 'ffc_view_as_user', $caps );
        $this->assertContains( 'ffc_manage_settings', $caps );
    }

    public function test_admin_capabilities_contains_6_2_recruitment_granular_caps(): void {
        $caps = CapabilityManager::ADMIN_CAPABILITIES;
        $this->assertContains( 'ffc_view_recruitment', $caps );
        $this->assertContains( 'ffc_import_recruitment_csv', $caps );
        $this->assertContains( 'ffc_call_recruitment_candidates', $caps );
        $this->assertContains( 'ffc_view_recruitment_pii', $caps );
        $this->assertContains( 'ffc_manage_recruitment_settings', $caps );
        $this->assertContains( 'ffc_manage_recruitment_reasons', $caps );
    }

    public function test_admin_capabilities_contains_reactivated_certificate_update(): void {
        $this->assertContains( 'ffc_certificate_update', CapabilityManager::ADMIN_CAPABILITIES );
    }

    public function test_future_capabilities_constant_is_empty_in_6_2(): void {
        // Both placeholders retired in 6.2.0:
        //   - ffc_reregistration: removed (audience-targeting already covers).
        //   - ffc_certificate_update: promoted to ADMIN_CAPABILITIES.
        $this->assertSame( array(), CapabilityManager::FUTURE_CAPABILITIES );
    }

    public function test_admin_capabilities_does_not_contain_removed_ffc_reregistration(): void {
        $this->assertNotContains( 'ffc_reregistration', CapabilityManager::ADMIN_CAPABILITIES );
        $this->assertNotContains( 'ffc_reregistration', CapabilityManager::get_all_capabilities() );
    }

    // ------------------------------------------------------------------
    // 6.2.0 module-manager + recruitment-tier roles
    // ------------------------------------------------------------------

    public function test_register_module_roles_creates_all_expected_slugs(): void {
        $created = array();
        Functions\when( 'get_role' )->justReturn( null );
        Functions\when( 'add_role' )->alias(
            static function ( string $slug, string $label, array $caps ) use ( &$created ): bool {
                $created[ $slug ] = array( 'label' => $label, 'caps' => $caps );
                return true;
            }
        );

        CapabilityManager::register_module_roles();

        $expected_slugs = array(
            'ffc_certificate_manager',
            'ffc_self_scheduling_manager',
            'ffc_audience_manager',
            'ffc_reregistration_manager',
            'ffc_operator',
            'ffc_recruitment_auditor',
            'ffc_recruitment_operator',
            'ffc_recruitment_admin',
        );
        foreach ( $expected_slugs as $slug ) {
            $this->assertArrayHasKey( $slug, $created, "Missing role: {$slug}" );
            $this->assertTrue( $created[ $slug ]['caps']['read'], "Role {$slug} must include read cap" );
        }
    }

    public function test_register_module_roles_grants_caps_per_role(): void {
        $created = array();
        Functions\when( 'get_role' )->justReturn( null );
        Functions\when( 'add_role' )->alias(
            static function ( string $slug, string $label, array $caps ) use ( &$created ): bool {
                $created[ $slug ] = $caps;
                return true;
            }
        );

        CapabilityManager::register_module_roles();

        // Spot-check a few of the cap maps.
        $this->assertTrue( $created['ffc_certificate_manager']['ffc_manage_certificates'] );
        $this->assertTrue( $created['ffc_certificate_manager']['ffc_export_certificates'] );
        $this->assertTrue( $created['ffc_certificate_manager']['ffc_certificate_update'] );

        $this->assertTrue( $created['ffc_recruitment_auditor']['ffc_view_recruitment'] );
        $this->assertArrayNotHasKey( 'ffc_call_recruitment_candidates', $created['ffc_recruitment_auditor'] );

        $this->assertTrue( $created['ffc_recruitment_operator']['ffc_view_recruitment'] );
        $this->assertTrue( $created['ffc_recruitment_operator']['ffc_call_recruitment_candidates'] );
        $this->assertArrayNotHasKey( 'ffc_import_recruitment_csv', $created['ffc_recruitment_operator'] );

        $this->assertTrue( $created['ffc_recruitment_admin']['ffc_manage_recruitment_settings'] );
        $this->assertTrue( $created['ffc_recruitment_admin']['ffc_manage_recruitment_reasons'] );
        $this->assertTrue( $created['ffc_recruitment_admin']['ffc_view_recruitment_pii'] );
    }

    // ------------------------------------------------------------------
    // get_all_capabilities()
    // ------------------------------------------------------------------

    public function test_get_all_capabilities_returns_merged_list(): void {
        $all = CapabilityManager::get_all_capabilities();

        $expected_count = count( CapabilityManager::CERTIFICATE_CAPABILITIES )
            + count( CapabilityManager::APPOINTMENT_CAPABILITIES )
            + count( CapabilityManager::AUDIENCE_CAPABILITIES )
            + count( CapabilityManager::ADMIN_CAPABILITIES )
            + count( CapabilityManager::FUTURE_CAPABILITIES );

        $this->assertCount( $expected_count, $all );
        $this->assertContains( 'view_own_certificates', $all );
        $this->assertContains( 'ffc_book_appointments', $all );
        $this->assertContains( 'ffc_view_audience_bookings', $all );
        $this->assertContains( 'ffc_scheduling_bypass', $all );
        $this->assertContains( 'ffc_certificate_update', $all );
    }

    // ------------------------------------------------------------------
    // grant_context_capabilities() — dispatch
    // ------------------------------------------------------------------

    public function test_grant_context_capabilities_delegates_to_certificate(): void {
        $mock_user = Mockery::mock( 'WP_User' );
        $mock_user->shouldReceive( 'has_cap' )->andReturn( false );
        $mock_user->shouldReceive( 'add_cap' )->times( 3 ); // 3 certificate caps
        $mock_user->ID = 10;
        $mock_user->user_email = '';
        $mock_user->display_name = 'Test';

        Functions\when( 'get_userdata' )->justReturn( $mock_user );

        CapabilityManager::grant_context_capabilities( 10, 'certificate' );
    }

    public function test_grant_context_capabilities_delegates_to_appointment(): void {
        $mock_user = Mockery::mock( 'WP_User' );
        $mock_user->shouldReceive( 'has_cap' )->andReturn( false );
        $mock_user->shouldReceive( 'add_cap' )->times( 3 ); // 3 appointment caps
        $mock_user->ID = 20;
        $mock_user->user_email = '';
        $mock_user->display_name = 'Test';

        Functions\when( 'get_userdata' )->justReturn( $mock_user );

        CapabilityManager::grant_context_capabilities( 20, 'appointment' );
    }

    public function test_grant_context_capabilities_delegates_to_audience(): void {
        $mock_user = Mockery::mock( 'WP_User' );
        $mock_user->shouldReceive( 'has_cap' )->andReturn( false );
        $mock_user->shouldReceive( 'add_cap' )->times( 1 ); // 1 audience cap
        $mock_user->ID = 30;
        $mock_user->user_email = '';
        $mock_user->display_name = 'Test';

        Functions\when( 'get_userdata' )->justReturn( $mock_user );

        CapabilityManager::grant_context_capabilities( 30, 'audience' );
    }

    // ------------------------------------------------------------------
    // grant_*_capabilities() — skips if user already has cap
    // ------------------------------------------------------------------

    public function test_grant_certificate_capabilities_skips_existing_caps(): void {
        $mock_user = Mockery::mock( 'WP_User' );
        $mock_user->shouldReceive( 'has_cap' )->andReturn( true ); // all caps already exist
        $mock_user->shouldReceive( 'add_cap' )->never();
        $mock_user->ID = 10;
        $mock_user->user_email = '';
        $mock_user->display_name = 'Test';

        Functions\when( 'get_userdata' )->justReturn( $mock_user );

        CapabilityManager::grant_certificate_capabilities( 10 );
    }

    public function test_grant_capabilities_does_nothing_for_invalid_user(): void {
        Functions\expect( 'get_userdata' )->times( 3 )->with( 999 )->andReturn( false );

        CapabilityManager::grant_certificate_capabilities( 999 );
        CapabilityManager::grant_appointment_capabilities( 999 );
        CapabilityManager::grant_audience_capabilities( 999 );
    }

    // ------------------------------------------------------------------
    // has_certificate_access()
    // ------------------------------------------------------------------

    public function test_has_certificate_access_returns_true_for_admin(): void {
        $mock_user = Mockery::mock( 'WP_User' );
        $mock_user->shouldReceive( 'has_cap' )->andReturn( false );

        Functions\when( 'get_userdata' )->justReturn( $mock_user );
        Functions\when( 'user_can' )->alias( function( $uid, $cap ) {
            return $cap === 'manage_options';
        } );

        $this->assertTrue( CapabilityManager::has_certificate_access( 1 ) );
    }

    public function test_has_certificate_access_returns_true_when_user_has_cap(): void {
        $mock_user = Mockery::mock( 'WP_User' );
        $mock_user->shouldReceive( 'has_cap' )->with( 'view_own_certificates' )->andReturn( true );

        Functions\when( 'get_userdata' )->justReturn( $mock_user );
        Functions\when( 'user_can' )->justReturn( false );

        $this->assertTrue( CapabilityManager::has_certificate_access( 5 ) );
    }

    public function test_has_certificate_access_returns_false_without_caps(): void {
        $mock_user = Mockery::mock( 'WP_User' );
        $mock_user->shouldReceive( 'has_cap' )->andReturn( false );

        Functions\when( 'get_userdata' )->justReturn( $mock_user );
        Functions\when( 'user_can' )->justReturn( false );

        $this->assertFalse( CapabilityManager::has_certificate_access( 5 ) );
    }

    public function test_has_certificate_access_returns_false_for_invalid_user(): void {
        Functions\when( 'get_userdata' )->justReturn( false );

        $this->assertFalse( CapabilityManager::has_certificate_access( 999 ) );
    }

    // ------------------------------------------------------------------
    // has_appointment_access()
    // ------------------------------------------------------------------

    public function test_has_appointment_access_returns_true_for_admin(): void {
        $mock_user = Mockery::mock( 'WP_User' );
        $mock_user->shouldReceive( 'has_cap' )->andReturn( false );

        Functions\when( 'get_userdata' )->justReturn( $mock_user );
        Functions\when( 'user_can' )->alias( function( $uid, $cap ) {
            return $cap === 'manage_options';
        } );

        $this->assertTrue( CapabilityManager::has_appointment_access( 1 ) );
    }

    public function test_has_appointment_access_returns_true_when_user_has_cap(): void {
        $mock_user = Mockery::mock( 'WP_User' );
        $mock_user->shouldReceive( 'has_cap' )->with( 'ffc_book_appointments' )->andReturn( true );

        Functions\when( 'get_userdata' )->justReturn( $mock_user );
        Functions\when( 'user_can' )->justReturn( false );

        $this->assertTrue( CapabilityManager::has_appointment_access( 5 ) );
    }

    public function test_has_appointment_access_returns_false_without_caps(): void {
        $mock_user = Mockery::mock( 'WP_User' );
        $mock_user->shouldReceive( 'has_cap' )->andReturn( false );

        Functions\when( 'get_userdata' )->justReturn( $mock_user );
        Functions\when( 'user_can' )->justReturn( false );

        $this->assertFalse( CapabilityManager::has_appointment_access( 5 ) );
    }

    // ------------------------------------------------------------------
    // get_user_ffc_capabilities()
    // ------------------------------------------------------------------

    public function test_get_user_ffc_capabilities_returns_map(): void {
        $mock_user = Mockery::mock( 'WP_User' );
        $mock_user->shouldReceive( 'has_cap' )->with( 'view_own_certificates' )->andReturn( true );
        $mock_user->shouldReceive( 'has_cap' )->andReturn( false ); // everything else

        Functions\when( 'get_userdata' )->justReturn( $mock_user );

        $caps = CapabilityManager::get_user_ffc_capabilities( 5 );

        $this->assertIsArray( $caps );
        $this->assertTrue( $caps['view_own_certificates'] );
        $this->assertFalse( $caps['ffc_book_appointments'] );
    }

    public function test_get_user_ffc_capabilities_returns_empty_for_invalid_user(): void {
        Functions\when( 'get_userdata' )->justReturn( false );

        $this->assertSame( array(), CapabilityManager::get_user_ffc_capabilities( 999 ) );
    }

    // ------------------------------------------------------------------
    // set_user_capability()
    // ------------------------------------------------------------------

    public function test_set_user_capability_grants_valid_cap(): void {
        $mock_user = Mockery::mock( 'WP_User' );
        $mock_user->shouldReceive( 'add_cap' )->with( 'view_own_certificates', true )->once();

        Functions\when( 'get_userdata' )->justReturn( $mock_user );

        $result = CapabilityManager::set_user_capability( 5, 'view_own_certificates', true );
        $this->assertTrue( $result );
    }

    public function test_set_user_capability_revokes_valid_cap(): void {
        $mock_user = Mockery::mock( 'WP_User' );
        $mock_user->shouldReceive( 'add_cap' )->with( 'ffc_book_appointments', false )->once();

        Functions\when( 'get_userdata' )->justReturn( $mock_user );

        $result = CapabilityManager::set_user_capability( 5, 'ffc_book_appointments', false );
        $this->assertTrue( $result );
    }

    public function test_set_user_capability_rejects_unknown_cap(): void {
        $mock_user = Mockery::mock( 'WP_User' );
        Functions\when( 'get_userdata' )->justReturn( $mock_user );

        $result = CapabilityManager::set_user_capability( 5, 'nonexistent_cap', true );
        $this->assertFalse( $result );
    }

    public function test_set_user_capability_returns_false_for_invalid_user(): void {
        Functions\when( 'get_userdata' )->justReturn( false );

        $result = CapabilityManager::set_user_capability( 999, 'view_own_certificates', true );
        $this->assertFalse( $result );
    }

    // ------------------------------------------------------------------
    // reset_user_ffc_capabilities()
    // ------------------------------------------------------------------

    public function test_reset_user_ffc_capabilities_revokes_all(): void {
        $all_count = count( CapabilityManager::get_all_capabilities() );

        $mock_user = Mockery::mock( 'WP_User' );
        $mock_user->shouldReceive( 'add_cap' )->times( $all_count );

        Functions\when( 'get_userdata' )->justReturn( $mock_user );

        CapabilityManager::reset_user_ffc_capabilities( 5 );
    }

    public function test_reset_user_ffc_capabilities_does_nothing_for_invalid_user(): void {
        Functions\expect( 'get_userdata' )->once()->with( 999 )->andReturn( false );

        CapabilityManager::reset_user_ffc_capabilities( 999 );
    }

    // ------------------------------------------------------------------
    // register_role() / remove_role()
    // ------------------------------------------------------------------

    public function test_register_role_creates_new_role_when_not_exists(): void {
        Functions\when( 'get_role' )->justReturn( null );

        $captured_caps = null;
        Functions\when( 'add_role' )->alias( function( $slug, $name, $caps ) use ( &$captured_caps ) {
            $captured_caps = $caps;
        } );

        CapabilityManager::register_role();

        $this->assertNotNull( $captured_caps );
        $this->assertTrue( $captured_caps['read'] );
        // FFC caps are intentionally absent (issue #86): an explicit `=> false`
        // here would break multi-role users (admin + ffc_user) by overriding
        // admin-granted `true` via array_merge in WP cap resolution.
        $this->assertArrayNotHasKey( 'view_own_certificates', $captured_caps );
        $this->assertArrayNotHasKey( 'ffc_book_appointments', $captured_caps );
        $this->assertArrayNotHasKey( 'ffc_manage_recruitment', $captured_caps );
    }

    public function test_register_role_upgrades_existing_role_strips_legacy_false_caps(): void {
        // Pre-6.0.3 the role was saved with FFC caps as `=> false`; upgrade_role()
        // strips them so multi-role users (admin + ffc_user) can resolve to true
        // via the admin role.
        $mock_role               = Mockery::mock( 'WP_Role' );
        $mock_role->capabilities = array(
            'read'                   => true,
            'view_own_certificates'  => false,
            'ffc_manage_recruitment' => false,
        );

        $removed = array();
        $mock_role->shouldReceive( 'remove_cap' )
            ->atLeast()->once()
            ->andReturnUsing( function ( $cap ) use ( &$removed ) {
                $removed[] = $cap;
            } );

        Functions\when( 'get_role' )->justReturn( $mock_role );

        CapabilityManager::register_role();

        $this->assertContains( 'view_own_certificates', $removed );
        $this->assertContains( 'ffc_manage_recruitment', $removed );
    }

    public function test_register_role_upgrade_does_not_touch_user_meta_true_grants(): void {
        // If a previous run left a cap as `=> true` on the role (manual admin
        // edit, etc.), upgrade_role() must NOT remove it — only `=> false`
        // entries get stripped.
        $mock_role               = Mockery::mock( 'WP_Role' );
        $mock_role->capabilities = array(
            'read'                  => true,
            'view_own_certificates' => true,
        );

        $mock_role->shouldNotReceive( 'remove_cap' );

        Functions\when( 'get_role' )->justReturn( $mock_role );

        CapabilityManager::register_role();
    }

    public function test_remove_role_calls_wp_remove_role(): void {
        $removed = false;
        Functions\when( 'remove_role' )->alias( function( $role ) use ( &$removed ) {
            $removed = ( $role === 'ffc_user' );
        } );

        CapabilityManager::remove_role();
        $this->assertTrue( $removed );
    }
}
