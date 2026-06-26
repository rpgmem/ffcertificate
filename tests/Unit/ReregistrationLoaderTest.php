<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Reregistration\ReregistrationLoader;

/**
 * Tests for ReregistrationLoader — the single bootstrap entry point for the
 * Reregistration module (#563 B3). Pins that init() wires the admin screens
 * (when is_admin()), the frontend, and the standard-fields seeder, each once.
 *
 * @covers \FreeFormCertificate\Reregistration\ReregistrationLoader
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class ReregistrationLoaderTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		class_exists( '\\FreeFormCertificate\\Reregistration\\ReregistrationLoader' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_init_wires_admin_frontend_and_seeder(): void {
		Functions\when( 'is_admin' )->justReturn( true );

		Mockery::mock( 'overload:FreeFormCertificate\Reregistration\ReregistrationAdmin' )
			->shouldReceive( 'init' )->once();
		Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationFrontend' )
			->shouldReceive( 'init' )->once();
		Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationStandardFieldsSeeder' )
			->shouldReceive( 'register' )->once();

		( new ReregistrationLoader() )->init();

		$this->assertTrue( true );
	}

	public function test_init_skips_admin_on_frontend(): void {
		Functions\when( 'is_admin' )->justReturn( false );

		// ReregistrationAdmin must NOT be constructed on a frontend request.
		Mockery::mock( 'overload:FreeFormCertificate\Reregistration\ReregistrationAdmin' )
			->shouldNotReceive( 'init' );
		Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationFrontend' )
			->shouldReceive( 'init' )->once();
		Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationStandardFieldsSeeder' )
			->shouldReceive( 'register' )->once();

		( new ReregistrationLoader() )->init();

		$this->assertTrue( true );
	}
}
