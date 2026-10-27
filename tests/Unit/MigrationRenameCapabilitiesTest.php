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

		$this->assertTrue( MigrationRenameCapabilities::is_completed() );
	}

	public function test_is_completed_returns_false_when_option_not_set(): void {
		Functions\when( 'get_option' )->justReturn( false );

		$this->assertFalse( MigrationRenameCapabilities::is_completed() );
	}

	// ==================================================================
	// run() — already completed
	// ==================================================================

	public function test_run_returns_early_when_already_completed(): void {
		Functions\when( 'get_option' )->justReturn( true );

		$result = MigrationRenameCapabilities::run();

		$this->assertTrue( $result['success'] );
		$this->assertSame( 0, $result['updated_users'] );
		$this->assertStringContainsString( 'already completed', $result['message'] );
	}

	// ==================================================================
	// run() — no users with old capability
	// ==================================================================

	public function test_run_with_no_users_still_marks_itself_completed(): void {
		// With nobody to migrate the run must still record that it ran, or it
		// repeats on every load.
		Functions\when( 'get_option' )->justReturn( false );
		Functions\expect( 'update_option' )
			->once()
			->with( 'ffc_migration_rename_capabilities_completed', true )
			->andReturn( true );
		Functions\when( 'get_users' )->justReturn( array() );
		Functions\when( 'get_role' )->justReturn( null );

		$result = MigrationRenameCapabilities::run();

		$this->assertTrue( $result['success'] );
		$this->assertSame( 0, $result['updated_users'] );
	}

	// ==================================================================
	// run() — users without the old capability
	// ==================================================================

	public function test_run_counts_no_user_without_the_old_capability(): void {
		// The migration builds each user with `new \WP_User( $id )`, which the
		// bootstrap stub creates with no capabilities, so no user here holds the
		// old one. A user that does cannot be injected through that constructor;
		// the role path below is where the rename itself is exercised.
		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'get_users' )->justReturn( array( 1, 2 ) );
		Functions\when( 'get_role' )->justReturn( null );

		$result = MigrationRenameCapabilities::run();

		$this->assertTrue( $result['success'] );
		$this->assertSame( 0, $result['updated_users'] );
	}

	// ==================================================================
	// run() — role gets updated
	// ==================================================================

	public function test_run_migrates_role_capabilities(): void {
		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'get_users' )->justReturn( array() );

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

		$result = MigrationRenameCapabilities::run();

		$this->assertTrue( $result['success'] );
	}

	public function test_run_skips_role_without_old_capability(): void {
		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'get_users' )->justReturn( array() );

		// Role without old capability
		$role = Mockery::mock( 'WP_Role' );
		$role->shouldReceive( 'has_cap' )
			->with( 'ffc_view_own_appointments' )
			->andReturn( false );
		$role->shouldNotReceive( 'add_cap' );
		$role->shouldNotReceive( 'remove_cap' );

		Functions\when( 'get_role' )->justReturn( $role );

		$result = MigrationRenameCapabilities::run();

		$this->assertTrue( $result['success'] );
	}

	// ==================================================================
	// run() — no ffc_end_user role
	// ==================================================================

	public function test_run_handles_null_role(): void {
		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'get_users' )->justReturn( array() );
		// The role it looks for is the end-user one, and an install without it
		// is not an error.
		Functions\expect( 'get_role' )->once()->with( 'ffc_end_user' )->andReturn( null );

		$result = MigrationRenameCapabilities::run();

		$this->assertTrue( $result['success'] );
		$this->assertSame( 0, $result['updated_users'] );
	}

	// ==================================================================
	// get_status()
	// ==================================================================

	public function test_get_status_returns_completed_and_mappings(): void {
		Functions\when( 'get_option' )->justReturn( true );

		$status = MigrationRenameCapabilities::get_status();

		$this->assertTrue( $status['completed'] );
		$this->assertArrayHasKey( 'mappings', $status );
		$this->assertArrayHasKey( 'ffc_view_own_appointments', $status['mappings'] );
		$this->assertSame( 'ffc_view_self_scheduling', $status['mappings']['ffc_view_own_appointments'] );
	}

	public function test_get_status_returns_not_completed(): void {
		Functions\when( 'get_option' )->justReturn( false );

		$status = MigrationRenameCapabilities::get_status();

		$this->assertFalse( $status['completed'] );
	}
}
