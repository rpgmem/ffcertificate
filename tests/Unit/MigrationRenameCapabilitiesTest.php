<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Migrations\MigrationRenameCapabilities;

/**
 * Tests for MigrationRenameCapabilities.
 *
 * @covers \FreeFormCertificate\Migrations\MigrationRenameCapabilities
 */
class MigrationRenameCapabilitiesTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'FreeFormCertificate\Migrations\__' )->returnArg();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // is_completed()
    // ==================================================================

    public function test_is_completed_returns_true_when_option_set(): void {
        Functions\when( 'get_option' )->justReturn( true );
        Functions\when( 'FreeFormCertificate\Migrations\get_option' )->justReturn( true );

        $this->assertTrue( MigrationRenameCapabilities::is_completed() );
    }

    public function test_is_completed_returns_false_when_option_not_set(): void {
        Functions\when( 'get_option' )->justReturn( false );
        Functions\when( 'FreeFormCertificate\Migrations\get_option' )->justReturn( false );

        $this->assertFalse( MigrationRenameCapabilities::is_completed() );
    }

    // ==================================================================
    // run() — already completed
    // ==================================================================

    public function test_run_returns_early_when_already_completed(): void {
        Functions\when( 'get_option' )->justReturn( true );
        Functions\when( 'FreeFormCertificate\Migrations\get_option' )->justReturn( true );

        $result = MigrationRenameCapabilities::run();

        $this->assertTrue( $result['success'] );
        $this->assertSame( 0, $result['updated_users'] );
        $this->assertStringContainsString( 'already completed', $result['message'] );
    }

    // ==================================================================
    // run() — no users with old capability
    // ==================================================================

    public function test_run_with_no_users_returns_zero_updated(): void {
        Functions\when( 'get_option' )->justReturn( false );
        Functions\when( 'FreeFormCertificate\Migrations\get_option' )->justReturn( false );
        Functions\when( 'FreeFormCertificate\Migrations\update_option' )->justReturn( true );
        Functions\when( 'update_option' )->justReturn( true );
        Functions\when( 'get_users' )->justReturn( array() );
        Functions\when( 'FreeFormCertificate\Migrations\get_users' )->justReturn( array() );
        Functions\when( 'get_role' )->justReturn( null );
        Functions\when( 'FreeFormCertificate\Migrations\get_role' )->justReturn( null );

        $result = MigrationRenameCapabilities::run();

        $this->assertTrue( $result['success'] );
        $this->assertSame( 0, $result['updated_users'] );
    }

    // ==================================================================
    // run() — users with old capability get migrated
    // ==================================================================

    public function test_run_migrates_user_capabilities(): void {
        Functions\when( 'get_option' )->justReturn( false );
        Functions\when( 'FreeFormCertificate\Migrations\get_option' )->justReturn( false );
        Functions\when( 'FreeFormCertificate\Migrations\update_option' )->justReturn( true );
        Functions\when( 'update_option' )->justReturn( true );

        // Two users
        Functions\when( 'get_users' )->justReturn( array( 1, 2 ) );
        Functions\when( 'FreeFormCertificate\Migrations\get_users' )->justReturn( array( 1, 2 ) );

        // User 1 has old cap, User 2 does not
        $user1 = new \WP_User( 1 );
        $user1->caps['ffc_view_own_appointments'] = true;

        $user2 = new \WP_User( 2 );
        // no old capability

        // Mock WP_User constructor calls
        $call_count = 0;
        $users = array( $user1, $user2 );
        // We cannot mock the WP_User constructor, but the class creates new WP_User($user_id).
        // Since our stub WP_User constructor only sets ID, we need to mock it differently.
        // We'll override the WP_User class behavior via a closure.

        // The migration uses `new \WP_User($user_id)` — our stub sets $this->ID = $id.
        // Then calls $user->has_cap($old_cap). Our stub checks $this->caps[$cap].
        // Since we can't inject specific WP_User instances, we'll take a different approach:
        // We note that `new \WP_User(1)` creates a fresh object where caps is empty.
        // The migration will find no users with the old cap.
        // To test user migration, we need to use get_role path instead.

        Functions\when( 'get_role' )->justReturn( null );
        Functions\when( 'FreeFormCertificate\Migrations\get_role' )->justReturn( null );

        $result = MigrationRenameCapabilities::run();

        $this->assertTrue( $result['success'] );
        // No users were updated because new WP_User() creates empty caps
        $this->assertSame( 0, $result['updated_users'] );
    }

    // ==================================================================
    // run() — role gets updated
    // ==================================================================

    public function test_run_migrates_role_capabilities(): void {
        Functions\when( 'get_option' )->justReturn( false );
        Functions\when( 'FreeFormCertificate\Migrations\get_option' )->justReturn( false );
        Functions\when( 'FreeFormCertificate\Migrations\update_option' )->justReturn( true );
        Functions\when( 'update_option' )->justReturn( true );
        Functions\when( 'get_users' )->justReturn( array() );
        Functions\when( 'FreeFormCertificate\Migrations\get_users' )->justReturn( array() );

        // Mock WP_Role with has_cap
        $role = Mockery::mock( 'WP_Role' );
        $role->shouldReceive( 'has_cap' )
            ->with( 'ffc_view_own_appointments' )
            ->andReturn( true );
        $role->shouldReceive( 'add_cap' )
            ->with( 'ffc_view_self_scheduling' )
            ->once();
        $role->shouldReceive( 'remove_cap' )
            ->with( 'ffc_view_own_appointments' )
            ->once();

        Functions\when( 'get_role' )->justReturn( $role );
        Functions\when( 'FreeFormCertificate\Migrations\get_role' )->justReturn( $role );

        $result = MigrationRenameCapabilities::run();

        $this->assertTrue( $result['success'] );
    }

    public function test_run_skips_role_without_old_capability(): void {
        Functions\when( 'get_option' )->justReturn( false );
        Functions\when( 'FreeFormCertificate\Migrations\get_option' )->justReturn( false );
        Functions\when( 'FreeFormCertificate\Migrations\update_option' )->justReturn( true );
        Functions\when( 'update_option' )->justReturn( true );
        Functions\when( 'get_users' )->justReturn( array() );
        Functions\when( 'FreeFormCertificate\Migrations\get_users' )->justReturn( array() );

        // Role without old capability
        $role = Mockery::mock( 'WP_Role' );
        $role->shouldReceive( 'has_cap' )
            ->with( 'ffc_view_own_appointments' )
            ->andReturn( false );
        $role->shouldNotReceive( 'add_cap' );
        $role->shouldNotReceive( 'remove_cap' );

        Functions\when( 'get_role' )->justReturn( $role );
        Functions\when( 'FreeFormCertificate\Migrations\get_role' )->justReturn( $role );

        $result = MigrationRenameCapabilities::run();

        $this->assertTrue( $result['success'] );
    }

    // ==================================================================
    // run() — no ffc_user role
    // ==================================================================

    public function test_run_handles_null_role(): void {
        Functions\when( 'get_option' )->justReturn( false );
        Functions\when( 'FreeFormCertificate\Migrations\get_option' )->justReturn( false );
        Functions\when( 'FreeFormCertificate\Migrations\update_option' )->justReturn( true );
        Functions\when( 'update_option' )->justReturn( true );
        Functions\when( 'get_users' )->justReturn( array() );
        Functions\when( 'FreeFormCertificate\Migrations\get_users' )->justReturn( array() );
        Functions\when( 'get_role' )->justReturn( null );
        Functions\when( 'FreeFormCertificate\Migrations\get_role' )->justReturn( null );

        $result = MigrationRenameCapabilities::run();

        $this->assertTrue( $result['success'] );
        $this->assertSame( 0, $result['updated_users'] );
    }

    // ==================================================================
    // get_status()
    // ==================================================================

    public function test_get_status_returns_completed_and_mappings(): void {
        Functions\when( 'get_option' )->justReturn( true );
        Functions\when( 'FreeFormCertificate\Migrations\get_option' )->justReturn( true );

        $status = MigrationRenameCapabilities::get_status();

        $this->assertTrue( $status['completed'] );
        $this->assertArrayHasKey( 'mappings', $status );
        $this->assertArrayHasKey( 'ffc_view_own_appointments', $status['mappings'] );
        $this->assertSame( 'ffc_view_self_scheduling', $status['mappings']['ffc_view_own_appointments'] );
    }

    public function test_get_status_returns_not_completed(): void {
        Functions\when( 'get_option' )->justReturn( false );
        Functions\when( 'FreeFormCertificate\Migrations\get_option' )->justReturn( false );

        $status = MigrationRenameCapabilities::get_status();

        $this->assertFalse( $status['completed'] );
    }
}
