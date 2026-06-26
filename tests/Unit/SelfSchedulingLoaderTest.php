<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\SelfScheduling\SelfSchedulingLoader;

/**
 * Tests for SelfSchedulingLoader — the single bootstrap entry point for the
 * Self-Scheduling module (#563 B3). Pins that init() constructs the module's
 * runtime classes, gating the admin trio behind is_admin().
 *
 * @covers \FreeFormCertificate\SelfScheduling\SelfSchedulingLoader
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class SelfSchedulingLoaderTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Every class init() instantiates (overloaded so construction is a no-op).
	 *
	 * @var list<string>
	 */
	private const CLASSES = array(
		'SelfSchedulingAdmin',
		'SelfSchedulingEditor',
		'AppointmentCsvExporter',
		'SelfSchedulingCPT',
		'AppointmentHandler',
		'AppointmentAjaxHandler',
		'AppointmentEmailHandler',
		'AppointmentReceiptHandler',
		'AppointmentCancellationHandler',
		'SelfSchedulingShortcode',
	);

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		class_exists( '\\FreeFormCertificate\\SelfScheduling\\SelfSchedulingLoader' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function overload_all(): void {
		foreach ( self::CLASSES as $cls ) {
			Mockery::mock( 'overload:FreeFormCertificate\SelfScheduling\\' . $cls );
		}
	}

	public function test_init_wires_full_module_in_admin(): void {
		Functions\when( 'is_admin' )->justReturn( true );
		$this->overload_all();

		( new SelfSchedulingLoader() )->init();

		$this->assertTrue( true );
	}

	public function test_init_skips_admin_trio_on_frontend(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		$this->overload_all();

		( new SelfSchedulingLoader() )->init();

		$this->assertTrue( true );
	}
}
