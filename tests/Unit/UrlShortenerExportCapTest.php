<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\UserDashboard\CapabilityManager;
use FreeFormCertificate\UserDashboard\CapabilityMigrator;

/**
 * Tests for the dedicated `ffc_export_url_shortener` capability: its
 * registration, the grant map, and the one-shot seeding migration.
 *
 * @covers \FreeFormCertificate\UserDashboard\CapabilityMigrator
 */
class UrlShortenerExportCapTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		class_exists( '\FreeFormCertificate\UserDashboard\CapabilityManager' );
		class_exists( '\FreeFormCertificate\UserDashboard\CapabilityMigrator' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_admin_capabilities_contains_url_shortener_export(): void {
		$this->assertContains( 'ffc_export_url_shortener', CapabilityManager::ADMIN_CAPABILITIES );
	}

	public function test_grant_map_pairs_manage_to_export(): void {
		$map = CapabilityMigrator::url_shortener_export_cap_grant_map();
		$this->assertSame( array( 'ffc_manage_url_shortener' => 'ffc_export_url_shortener' ), $map );
		foreach ( $map as $manage => $export ) {
			$this->assertContains( $manage, CapabilityManager::ADMIN_CAPABILITIES );
			$this->assertContains( $export, CapabilityManager::ADMIN_CAPABILITIES );
		}
	}

	public function test_migration_seeds_export_onto_manage_holders(): void {
		Functions\when( 'get_users' )->justReturn( array( 1 ) );

		// User holds the manage cap but not the export cap → expect export seeded.
		$user       = Mockery::mock( 'WP_User' );
		$user->caps = array( 'ffc_manage_url_shortener' => true );
		$user_added = array();
		$user->shouldReceive( 'add_cap' )->andReturnUsing(
			function ( $cap, $val = true ) use ( &$user_added ) {
				$user_added[ $cap ] = $val;
			}
		);
		$user->shouldReceive( 'remove_cap' )->never();
		Functions\when( 'get_userdata' )->justReturn( $user );

		// Role also holds the manage cap → expect the export cap on the role too.
		$role               = Mockery::mock( 'WP_Role' );
		$role->capabilities = array( 'ffc_manage_url_shortener' => true );
		$role_added         = array();
		$role->shouldReceive( 'add_cap' )->andReturnUsing(
			function ( $cap, $val = true ) use ( &$role_added ) {
				$role_added[ $cap ] = $val;
			}
		);
		$wp_roles        = Mockery::mock();
		$wp_roles->roles = array( 'administrator' => array() );
		Functions\when( 'wp_roles' )->justReturn( $wp_roles );
		Functions\when( 'get_role' )->justReturn( $role );

		CapabilityMigrator::migrate_url_shortener_export_cap_grant();

		$this->assertTrue( $user_added['ffc_export_url_shortener'] ?? null );
		$this->assertTrue( $role_added['ffc_export_url_shortener'] ?? null );
	}
}
