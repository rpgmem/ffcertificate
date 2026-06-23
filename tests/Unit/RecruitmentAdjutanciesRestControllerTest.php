<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Recruitment\RecruitmentAdjutanciesRestController;

/**
 * Tests for the recruitment adjutancies REST controller — route registration
 * + the read-only / failure-path endpoints. Write/attach/detach endpoints
 * depend on the AdjutancyService (which itself wraps repositories +
 * permission-aware activity logging) and are out of scope for this smoke
 * tier.
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentAdjutanciesRestController
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class RecruitmentAdjutanciesRestControllerTest extends TestCase {

    use MockeryPHPUnitIntegration;

    private RecruitmentAdjutanciesRestController $controller;

    /** @var \Mockery\MockInterface */
    private $adjRepoMock;

    /** @var \Mockery\MockInterface */
    private $reasonRepoMock;

    /** @var array<int, array{namespace: string, route: string, args: array}> */
    private array $registered_routes = array();

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'current_user_can' )->justReturn( false );
        Functions\when( 'is_user_logged_in' )->justReturn( false );

        $this->registered_routes = array();
        Functions\when( 'register_rest_route' )->alias(
            function ( $namespace, $route, $args ) {
                $this->registered_routes[] = compact( 'namespace', 'route', 'args' );
                return true;
            }
        );

        $this->adjRepoMock = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentAdjutancyReader' );
        $this->adjRepoMock->shouldReceive( 'get_all' )->andReturn( array() )->byDefault();
        $this->adjRepoMock->shouldReceive( 'get_by_id' )->andReturn( null )->byDefault();

        $this->reasonRepoMock = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentReasonRepository' );
        $this->reasonRepoMock->shouldReceive( 'get_all' )->andReturn( array() )->byDefault();

        $errMsgMock = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentErrorMessages' );
        $errMsgMock->shouldReceive( 'translate' )->andReturnUsing( fn( $c ) => 'msg:' . $c );
        $errMsgMock->shouldReceive( 'translate_all' )->andReturnUsing( fn( $codes ) => array_map( fn( $c ) => 'msg:' . $c, $codes ) );

        $this->controller = new RecruitmentAdjutanciesRestController();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function make_request( array $params ): \WP_REST_Request {
        $req = Mockery::mock( 'WP_REST_Request' );
        $req->shouldReceive( 'get_param' )->andReturnUsing( fn( $k ) => $params[ $k ] ?? null );
        $req->shouldReceive( 'get_params' )->andReturn( $params );
        return $req;
    }

    // ------------------------------------------------------------------
    // register_routes()
    // ------------------------------------------------------------------

    public function test_register_routes_registers_five_route_groups(): void {
        $this->controller->register_routes();

        // Five register_rest_route calls — adjutancies collection + item,
        // reasons collection + item, plus the notice-attach junction.
        $this->assertCount( 5, $this->registered_routes );
    }

    public function test_register_routes_includes_adjutancies_and_reasons_endpoints(): void {
        $this->controller->register_routes();

        $routes = array_column( $this->registered_routes, 'route' );
        $this->assertContains( '/recruitment/adjutancies', $routes );
        $this->assertContains( '/recruitment/adjutancies/(?P<id>\d+)', $routes );
        $this->assertContains( '/recruitment/reasons', $routes );
        $this->assertContains( '/recruitment/reasons/(?P<id>\d+)', $routes );
    }

    public function test_register_routes_includes_notice_adjutancies_junction(): void {
        $this->controller->register_routes();

        $routes = array_column( $this->registered_routes, 'route' );
        // The attach/detach junction routes notices→adjutancies under
        // /recruitment/notices/{id}/adjutancies/{adjutancy_id}.
        $found = false;
        foreach ( $routes as $r ) {
            if ( str_contains( $r, '/notices/' ) && str_contains( $r, '/adjutancies/' ) ) {
                $found = true;
                break;
            }
        }
        $this->assertTrue( $found, 'expected a notices→adjutancies junction route' );
    }

    // ------------------------------------------------------------------
    // list_adjutancies() + list_reasons()
    // ------------------------------------------------------------------

    public function test_list_adjutancies_returns_200_with_repository_payload(): void {
        $rows = array( (object) array( 'id' => 1, 'slug' => 'a' ) );
        $this->adjRepoMock->shouldReceive( 'get_all' )->andReturn( $rows );

        $response = $this->controller->list_adjutancies();

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( $rows, $response->get_data() );
    }

    public function test_list_reasons_returns_200_with_repository_payload(): void {
        $rows = array( (object) array( 'id' => 1, 'slug' => 'denied' ) );
        $this->reasonRepoMock->shouldReceive( 'get_all' )->andReturn( $rows );

        $response = $this->controller->list_reasons();

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( $rows, $response->get_data() );
    }
}
