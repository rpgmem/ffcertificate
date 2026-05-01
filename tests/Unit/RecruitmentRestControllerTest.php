<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Recruitment\RecruitmentRestController;

/**
 * Tests for RecruitmentRestController — focused on permission gating and
 * route registration. Endpoint dispatch logic is exercised indirectly by
 * the service-layer tests (CallService, StateMachine, DeleteService) which
 * cover every error code the controller surfaces. The controller is a thin
 * adapter; full end-to-end integration tests for the REST surface land in
 * sprint 13.
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentRestController
 */
class RecruitmentRestControllerTest extends TestCase {

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

	public function test_check_admin_cap_returns_true_when_user_can_manage_recruitment(): void {
		$captured_cap = '';
		Functions\when( 'current_user_can' )->alias(
			function ( $cap ) use ( &$captured_cap ) {
				$captured_cap = $cap;
				return true;
			}
		);

		$controller = new RecruitmentRestController();
		$this->assertTrue( $controller->check_admin_cap() );
		$this->assertSame( 'ffc_manage_recruitment', $captured_cap );
	}

	public function test_check_admin_cap_returns_false_without_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$controller = new RecruitmentRestController();
		$this->assertFalse( $controller->check_admin_cap() );
	}

	public function test_check_logged_in_returns_true_when_authenticated(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );

		$controller = new RecruitmentRestController();
		$this->assertTrue( $controller->check_logged_in() );
	}

	public function test_check_logged_in_returns_false_for_anonymous(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( false );

		$controller = new RecruitmentRestController();
		$this->assertFalse( $controller->check_logged_in() );
	}

	public function test_register_routes_calls_register_rest_route(): void {
		$registered = array();
		Functions\when( 'register_rest_route' )->alias(
			function ( $namespace, $route, $args ) use ( &$registered ) {
				$registered[] = array(
					'namespace' => $namespace,
					'route'     => $route,
					'args'      => $args,
				);
			}
		);

		$controller = new RecruitmentRestController();
		$controller->register_routes();

		$this->assertNotEmpty( $registered, 'register_routes() must call register_rest_route at least once' );

		// Every registered route should be under the recruitment namespace.
		foreach ( $registered as $reg ) {
			$this->assertSame( 'ffcertificate/v1', $reg['namespace'] );
			$this->assertStringStartsWith( '/recruitment', $reg['route'] );
		}
	}

	public function test_register_routes_includes_admin_endpoints(): void {
		$registered = array();
		Functions\when( 'register_rest_route' )->alias(
			function ( $namespace, $route, $args ) use ( &$registered ) {
				$registered[] = $route;
			}
		);

		$controller = new RecruitmentRestController();
		$controller->register_routes();

		// Spot-check the major admin routes documented in §14 of the plan.
		$this->assertContains( '/recruitment/notices', $registered );
		$this->assertContains( '/recruitment/notices/(?P<id>\d+)', $registered );
		$this->assertContains( '/recruitment/notices/(?P<id>\d+)/import', $registered );
		$this->assertContains( '/recruitment/notices/(?P<id>\d+)/promote-preview', $registered );
		$this->assertContains( '/recruitment/classifications/bulk-call', $registered );
		$this->assertContains( '/recruitment/adjutancies', $registered );
		$this->assertContains( '/recruitment/candidates', $registered );
		$this->assertContains( '/recruitment/me/recruitment', $registered );
	}
}
