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

    public function test_admin_capabilities_contains_expected_caps(): void {
        $caps = CapabilityManager::ADMIN_CAPABILITIES;
        $this->assertContains( 'ffc_scheduling_bypass', $caps );
        $this->assertContains( 'ffc_manage_reregistration', $caps );
        $this->assertCount( 2, $caps );
    }

    public function test_future_capabilities_contains_expected_caps(): void {
        $caps = CapabilityManager::FUTURE_CAPABILITIES;
        $this->assertContains( 'ffc_reregistration', $caps );
        $this->assertContains( 'ffc_certificate_update', $caps );
        $this->assertCount( 2, $caps );
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
        $this->assertContains( 'ffc_reregistration', $all );
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
        // All FFC caps should be set to false by default
        $this->assertFalse( $captured_caps['view_own_certificates'] );
        $this->assertFalse( $captured_caps['ffc_book_appointments'] );
    }

    public function test_register_role_upgrades_existing_role(): void {
        $mock_role = Mockery::mock( 'WP_Role' );
        $mock_role->capabilities = array( 'read' => true );
        $mock_role->shouldReceive( 'add_cap' )->atLeast()->once();

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
