<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\DateMessages\DateMessagesLoader;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * The date-messages screens are wired on admin requests only (#1538).
 *
 * @covers \FreeFormCertificate\DateMessages\DateMessagesLoader
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class DateMessagesLoaderTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_admin_requests_wire_the_page_and_the_ajax(): void {
		Functions\when( 'is_admin' )->justReturn( true );
		Mockery::mock( 'overload:FreeFormCertificate\DateMessages\DateMessagesAdminPage' )->shouldReceive( 'init' )->once();
		Mockery::mock( 'alias:FreeFormCertificate\DateMessages\DateMessagesAjaxEndpoint' )->shouldReceive( 'init' )->once();

		( new DateMessagesLoader() )->init();
	}

	public function test_front_end_requests_wire_nothing(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Mockery::mock( 'overload:FreeFormCertificate\DateMessages\DateMessagesAdminPage' )->shouldReceive( 'init' )->never();
		Mockery::mock( 'alias:FreeFormCertificate\DateMessages\DateMessagesAjaxEndpoint' )->shouldReceive( 'init' )->never();

		( new DateMessagesLoader() )->init();
	}
}
