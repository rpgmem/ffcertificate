<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Recruitment\RecruitmentCandidatesRestController;

/**
 * Tests for the recruitment candidates REST controller — route registration
 * + the read endpoints. Write endpoints (POST/PATCH/DELETE) pull in
 * Encryption + SensitiveFieldRegistry and are out of scope for the smoke
 * tier; this suite pins the surface contract.
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentCandidatesRestController
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class RecruitmentCandidatesRestControllerTest extends TestCase {

    use MockeryPHPUnitIntegration;

    private RecruitmentCandidatesRestController $controller;

    /** @var \Mockery\MockInterface */
    private $repoMock;

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

        $this->repoMock = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentCandidateRepository' );
        $this->repoMock->shouldReceive( 'get_by_id' )->andReturn( null )->byDefault();
        $this->repoMock->shouldReceive( 'get_by_cpf_hash' )->andReturn( null )->byDefault();
        $this->repoMock->shouldReceive( 'get_by_rf_hash' )->andReturn( null )->byDefault();

        $errMsgMock = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentErrorMessages' );
        $errMsgMock->shouldReceive( 'translate' )->andReturnUsing( fn( $c ) => 'msg:' . $c );
        $errMsgMock->shouldReceive( 'translate_all' )->andReturnUsing( fn( $codes ) => array_map( fn( $c ) => 'msg:' . $c, $codes ) );

        $this->controller = new RecruitmentCandidatesRestController();
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

    public function test_register_routes_registers_candidate_endpoints(): void {
        $this->controller->register_routes();

        $routes = array_column( $this->registered_routes, 'route' );
        $this->assertContains( '/recruitment/candidates', $routes );
        $this->assertContains( '/recruitment/candidates/(?P<id>\d+)', $routes );
    }

    public function test_register_routes_includes_the_me_self_endpoint(): void {
        $this->controller->register_routes();

        $routes = array_column( $this->registered_routes, 'route' );
        // The /me/recruitment route gates on is_user_logged_in instead of admin cap.
        $this->assertContains( '/recruitment/me/recruitment', $routes );
    }

    public function test_me_endpoint_uses_check_logged_in_permission_callback(): void {
        $this->controller->register_routes();

        $me_entry = null;
        foreach ( $this->registered_routes as $entry ) {
            if ( '/recruitment/me/recruitment' === $entry['route'] ) {
                $me_entry = $entry;
                break;
            }
        }
        $this->assertNotNull( $me_entry );

        // Walk the args (it's a single endpoint, not a collection).
        $perm = $me_entry['args']['permission_callback'] ?? null;
        $this->assertSame( $this->controller, $perm[0] );
        $this->assertSame( 'check_logged_in', $perm[1] );
    }

    // ------------------------------------------------------------------
    // get_candidate()
    // ------------------------------------------------------------------

    public function test_get_candidate_returns_404_when_not_found(): void {
        // Default repo returns null.
        $result = $this->controller->get_candidate( $this->make_request( array( 'id' => 999 ) ) );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'recruitment_candidate_not_found', $result->get_error_code() );
        $this->assertSame( 404, $result->get_error_data()['status'] );
    }

    // ------------------------------------------------------------------
    // list_candidates()
    // ------------------------------------------------------------------

    public function test_list_candidates_returns_400_when_no_filter_provided(): void {
        $result = $this->controller->list_candidates( $this->make_request( array() ) );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'recruitment_candidate_list_requires_filter', $result->get_error_code() );
        $this->assertSame( 400, $result->get_error_data()['status'] );
    }
}
