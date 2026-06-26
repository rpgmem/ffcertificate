<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\AdminLoader;

/**
 * Tests for AdminLoader — the single bootstrap entry point for the Admin
 * module (#563 B3 coupling reduction). Pins that init() constructs the
 * stateful trio (CsvExporter / Admin / AdminAjax) and fires ::init() on every
 * admin-only endpoint exactly once, so a future refactor can't silently drop
 * one when the orchestrator stops newing them up directly.
 *
 * @covers \FreeFormCertificate\Admin\AdminLoader
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class AdminLoaderTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// pcov does not attribute coverage to a class first autoloaded mid-test;
		// preload AdminLoader so its lines attribute to this test.
		class_exists( '\\FreeFormCertificate\\Admin\\AdminLoader' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_init_wires_every_admin_module_class(): void {
		// Stateful trio — overload so construction is a side-effect-free no-op.
		Mockery::mock( 'overload:FreeFormCertificate\Admin\CsvExporter' );
		Mockery::mock( 'overload:FreeFormCertificate\Admin\Admin' );
		Mockery::mock( 'overload:FreeFormCertificate\Admin\AdminAjax' );

		// Static endpoints — each ::init() must fire exactly once.
		$static_endpoints = array(
			'AdminUserColumns',
			'AdminUserCapabilities',
			'RoleCapabilityEditor',
			'AdminMenuVisibility',
			'DeviceThresholdUpgradeNotice',
			'SettingsAjaxEndpoint',
			'FormMetaAjaxEndpoint',
			'LocationsAjaxEndpoint',
			'CacheActionsAjaxEndpoint',
			'FormFeaturesAjaxEndpoint',
			'MigrationActionsAjaxEndpoint',
			'ActivityLogAjaxEndpoint',
			'SubmissionsBulkActionsAjaxEndpoint',
			'ExpiredTicketsCleanup',
			'FormListColumns',
			'AdminUserCustomFields',
		);
		foreach ( $static_endpoints as $cls ) {
			Mockery::mock( 'alias:FreeFormCertificate\Admin\\' . $cls )
				->shouldReceive( 'init' )->once();
		}

		$handler = Mockery::mock( 'FreeFormCertificate\Submissions\SubmissionHandler' );

		( new AdminLoader( $handler ) )->init();

		// Mockery's ->once() expectations are verified on tearDown; assert here
		// too so the test never counts as risky/assertion-less.
		$this->assertTrue( true );
	}
}
