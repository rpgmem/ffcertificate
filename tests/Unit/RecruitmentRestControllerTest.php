<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Recruitment\RecruitmentNoticesRestController;
use FreeFormCertificate\Recruitment\RecruitmentClassificationsRestController;
use FreeFormCertificate\Recruitment\RecruitmentAdjutanciesRestController;
use FreeFormCertificate\Recruitment\RecruitmentCandidatesRestController;

/**
 * Tests for the recruitment REST surface — focused on permission gating and
 * route registration. Endpoint dispatch logic is exercised indirectly by
 * the service-layer tests (CallService, StateMachine, DeleteService) which
 * cover every error code the controllers surface. The controllers are thin
 * adapters; full end-to-end integration tests for the REST surface land in
 * sprint 13.
 *
 * After sprint S2 of #141 the original god-object `RecruitmentRestController`
 * was split into four domain controllers sharing a common
 * `RecruitmentRestSupport` trait. The cap-check tests instantiate a single
 * controller (the trait methods are shared by all four); the
 * route-registration tests instantiate every controller and accumulate the
 * registrations into a shared array so the existing spot-checks against
 * the full route set still hold.
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentNoticesRestController
 * @covers \FreeFormCertificate\Recruitment\RecruitmentClassificationsRestController
 * @covers \FreeFormCertificate\Recruitment\RecruitmentAdjutanciesRestController
 * @covers \FreeFormCertificate\Recruitment\RecruitmentCandidatesRestController
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

		$controller = new RecruitmentNoticesRestController();
		$this->assertTrue( $controller->check_admin_cap() );
		$this->assertSame( 'ffc_manage_recruitment', $captured_cap );
	}

	public function test_check_admin_cap_returns_false_without_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$controller = new RecruitmentNoticesRestController();
		$this->assertFalse( $controller->check_admin_cap() );
	}

	public function test_check_logged_in_returns_true_when_authenticated(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );

		$controller = new RecruitmentNoticesRestController();
		$this->assertTrue( $controller->check_logged_in() );
	}

	public function test_check_logged_in_returns_false_for_anonymous(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( false );

		$controller = new RecruitmentNoticesRestController();
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

		( new RecruitmentNoticesRestController() )->register_routes();
		( new RecruitmentClassificationsRestController() )->register_routes();
		( new RecruitmentAdjutanciesRestController() )->register_routes();
		( new RecruitmentCandidatesRestController() )->register_routes();

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

		( new RecruitmentNoticesRestController() )->register_routes();
		( new RecruitmentClassificationsRestController() )->register_routes();
		( new RecruitmentAdjutanciesRestController() )->register_routes();
		( new RecruitmentCandidatesRestController() )->register_routes();

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
