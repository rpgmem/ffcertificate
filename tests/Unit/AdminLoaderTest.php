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
 * Also pins the Certificates-module gate on the expired-tickets cleanup cron:
 * its callback is wired only when `module_enabled('certificates')` is true, so
 * a disabled module halts the daily `ffc_form` sweep.
 *
 * @covers \FreeFormCertificate\Admin\AdminLoader
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class AdminLoaderTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Static endpoints wired unconditionally by init() (i.e. every one except
	 * the certificates-gated ExpiredTicketsCleanup).
	 *
	 * @var string[]
	 */
	private const UNGATED_ENDPOINTS = array(
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
		'FormListColumns',
		'AdminUserCustomFields',
	);

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

	/**
	 * Wire the stateful trio + every ungated endpoint, each ::init() once.
	 */
	private function mock_common_wiring(): void {
		Mockery::mock( 'overload:FreeFormCertificate\Admin\CsvExporter' );
		Mockery::mock( 'overload:FreeFormCertificate\Admin\Admin' );
		Mockery::mock( 'overload:FreeFormCertificate\Admin\AdminAjax' );

		foreach ( self::UNGATED_ENDPOINTS as $cls ) {
			Mockery::mock( 'alias:FreeFormCertificate\Admin\\' . $cls )
				->shouldReceive( 'init' )->once();
		}
	}

	public function test_init_wires_every_admin_module_class(): void {
		$this->mock_common_wiring();

		// Certificates enabled → the cleanup cron callback is wired.
		Mockery::mock( 'alias:FreeFormCertificate\Settings\SettingsReader' )
			->shouldReceive( 'module_enabled' )->with( 'certificates' )->andReturn( true );
		Mockery::mock( 'alias:FreeFormCertificate\Admin\ExpiredTicketsCleanup' )
			->shouldReceive( 'init' )->once();

		$handler = Mockery::mock( 'FreeFormCertificate\Submissions\SubmissionHandler' );

		( new AdminLoader( $handler ) )->init();

		// Mockery's ->once() expectations are verified on tearDown; assert here
		// too so the test never counts as risky/assertion-less.
		$this->assertTrue( true );
	}

	public function test_init_skips_expired_tickets_cleanup_when_certificates_disabled(): void {
		$this->mock_common_wiring();

		// Certificates disabled → the cleanup cron callback is NOT wired.
		Mockery::mock( 'alias:FreeFormCertificate\Settings\SettingsReader' )
			->shouldReceive( 'module_enabled' )->with( 'certificates' )->andReturn( false );
		Mockery::mock( 'alias:FreeFormCertificate\Admin\ExpiredTicketsCleanup' )
			->shouldReceive( 'init' )->never();

		$handler = Mockery::mock( 'FreeFormCertificate\Submissions\SubmissionHandler' );

		( new AdminLoader( $handler ) )->init();

		$this->assertTrue( true );
	}
}
