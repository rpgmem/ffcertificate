<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\Capabilities;

/**
 * #563 Sprint 5 (B1/B3) — unit tests for the Capabilities facade extracted
 * from Core\Utils (the inline manage_options-or-granular-cap gate).
 */
class CapabilitiesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_can_manage_delegates_to_manage_options(): void {
		Functions\when( 'current_user_can' )->alias( static fn( $cap ) => 'manage_options' === $cap );
		$this->assertTrue( Capabilities::current_user_can_manage() );
	}

	public function test_can_manage_false_without_manage_options(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		$this->assertFalse( Capabilities::current_user_can_manage() );
	}

	public function test_admin_or_true_for_site_admin(): void {
		Functions\when( 'current_user_can' )->alias( static fn( $cap ) => 'manage_options' === $cap );
		$this->assertTrue( Capabilities::current_user_can_admin_or( 'ffc_view_activity_log' ) );
	}

	public function test_admin_or_true_for_granular_cap_holder(): void {
		Functions\when( 'current_user_can' )->alias( static fn( $cap ) => 'ffc_view_activity_log' === $cap );
		$this->assertTrue( Capabilities::current_user_can_admin_or( 'ffc_view_activity_log' ) );
	}

	public function test_admin_or_false_without_either(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		$this->assertFalse( Capabilities::current_user_can_admin_or( 'ffc_view_activity_log' ) );
	}

	public function test_admin_or_false_for_empty_cap_when_not_admin(): void {
		Functions\when( 'current_user_can' )->alias( static fn( $cap ) => 'manage_options' === $cap ? false : true );
		// Empty granular cap must not pass on its own when not an admin.
		$this->assertFalse( Capabilities::current_user_can_admin_or( '' ) );
	}
}
