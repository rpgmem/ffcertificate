<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Recruitment\RecruitmentNoticesRestController;

/**
 * Tests for the recruitment notices REST controller — route registration
 * plus the three endpoint handlers (list / create / update).
 *
 * Static dependencies (RecruitmentNoticeRepository, RecruitmentNoticeStateMachine,
 * RecruitmentErrorMessages) are alias-mocked once per test class. Per-test
 * behaviour overrides are applied via `shouldReceive` on the stored mocks.
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentNoticesRestController
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class RecruitmentNoticesRestControllerTest extends TestCase {

    use MockeryPHPUnitIntegration;

    private RecruitmentNoticesRestController $controller;

    /** @var \Mockery\MockInterface */
    private $repoMock;

    /** @var \Mockery\MockInterface */
    private $smMock;

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

        // Alias-mock the static dependencies. Only one alias per class per
        // process is honoured by Mockery; subsequent shouldReceive() calls
        // in tests use the SAME stored reference.
        $this->repoMock = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentNoticeRepository' );
        $this->repoMock->shouldReceive( 'get_all' )->andReturn( array() )->byDefault();
        $this->repoMock->shouldReceive( 'get_by_id' )->andReturn( null )->byDefault();
        $this->repoMock->shouldReceive( 'create' )->andReturn( false )->byDefault();
        $this->repoMock->shouldReceive( 'update' )->andReturn( true )->byDefault();

        $this->smMock = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentNoticeStateMachine' );
        $this->smMock->shouldReceive( 'transition_to' )
            ->andReturn( array( 'success' => true, 'errors' => array() ) )
            ->byDefault();

        $errMsgMock = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentErrorMessages' );
        $errMsgMock->shouldReceive( 'translate' )->andReturnUsing( fn( $c ) => 'msg:' . $c );
        $errMsgMock->shouldReceive( 'translate_all' )->andReturnUsing( fn( $codes ) => array_map( fn( $c ) => 'msg:' . $c, $codes ) );

        $this->controller = new RecruitmentNoticesRestController();
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

    public function test_register_routes_registers_collection_and_item_endpoints(): void {
        $this->controller->register_routes();

        $this->assertCount( 2, $this->registered_routes );
        $routes = array_column( $this->registered_routes, 'route' );
        $this->assertContains( '/recruitment/notices', $routes );
        $this->assertContains( '/recruitment/notices/(?P<id>\d+)', $routes );
    }

    public function test_register_routes_wires_admin_cap_to_every_endpoint(): void {
        $this->controller->register_routes();

        foreach ( $this->registered_routes as $entry ) {
            $callbacks = $this->collect_permission_callbacks( $entry['args'] );
            foreach ( $callbacks as $cb ) {
                $this->assertSame( $this->controller, $cb[0] );
                $this->assertSame( 'check_admin_cap', $cb[1] );
            }
        }
    }

    /**
     * @return list<callable>
     */
    private function collect_permission_callbacks( array $args ): array {
        $out = array();
        if ( isset( $args['permission_callback'] ) ) {
            $out[] = $args['permission_callback'];
            return $out;
        }
        foreach ( $args as $v ) {
            if ( is_array( $v ) ) {
                $out = array_merge( $out, $this->collect_permission_callbacks( $v ) );
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // list_notices()
    // ------------------------------------------------------------------

    public function test_list_notices_returns_200_with_repository_payload(): void {
        $notices = array(
            (object) array( 'id' => 1, 'code' => 'EDITAL-A' ),
            (object) array( 'id' => 2, 'code' => 'EDITAL-B' ),
        );
        $this->repoMock->shouldReceive( 'get_all' )->with( null )->andReturn( $notices );

        $response = $this->controller->list_notices( $this->make_request( array() ) );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( $notices, $response->get_data() );
    }

    public function test_list_notices_passes_status_filter_through_to_repository(): void {
        $captured = null;
        $this->repoMock->shouldReceive( 'get_all' )->andReturnUsing( function ( $status ) use ( &$captured ) {
            $captured = $status;
            return array();
        } );

        $this->controller->list_notices( $this->make_request( array( 'status' => 'open' ) ) );

        $this->assertSame( 'open', $captured );
    }

    // ------------------------------------------------------------------
    // create_notice()
    // ------------------------------------------------------------------

    public function test_create_notice_returns_201_on_success(): void {
        $created = (object) array( 'id' => 5, 'code' => 'NEW', 'name' => 'New' );
        $this->repoMock->shouldReceive( 'create' )->with( 'NEW', 'New' )->andReturn( 5 );
        $this->repoMock->shouldReceive( 'get_by_id' )->with( 5 )->andReturn( $created );

        $response = $this->controller->create_notice(
            $this->make_request( array( 'code' => 'NEW', 'name' => 'New' ) )
        );

        $this->assertInstanceOf( \WP_REST_Response::class, $response );
        $this->assertSame( 201, $response->get_status() );
        $this->assertSame( $created, $response->get_data() );
    }

    public function test_create_notice_returns_wp_error_with_409_on_duplicate(): void {
        // Default `create` returns false → duplicate path.
        $result = $this->controller->create_notice(
            $this->make_request( array( 'code' => 'DUP', 'name' => 'Dup' ) )
        );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'recruitment_notice_create_failed', $result->get_error_code() );
        $this->assertSame( 409, $result->get_error_data()['status'] );
    }

    // ------------------------------------------------------------------
    // update_notice()
    // ------------------------------------------------------------------

    public function test_update_notice_returns_404_when_notice_missing(): void {
        // Default: update returns true, get_by_id returns null.
        $result = $this->controller->update_notice(
            $this->make_request( array( 'id' => 999, 'name' => 'X' ) )
        );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'recruitment_notice_not_found', $result->get_error_code() );
        $this->assertSame( 404, $result->get_error_data()['status'] );
    }

    public function test_update_notice_returns_409_when_state_machine_rejects_transition(): void {
        $this->smMock->shouldReceive( 'transition_to' )->andReturn(
            array( 'success' => false, 'errors' => array( 'recruitment_invalid_transition' ) )
        );

        $result = $this->controller->update_notice(
            $this->make_request( array( 'id' => 1, 'status' => 'closed' ) )
        );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 409, $result->get_error_data()['status'] );
    }

    public function test_update_notice_passes_meta_fields_through_to_repository(): void {
        $captured = null;
        $this->repoMock->shouldReceive( 'update' )->andReturnUsing( function ( $id, $meta ) use ( &$captured ) {
            $captured = compact( 'id', 'meta' );
            return true;
        } );
        $this->repoMock->shouldReceive( 'get_by_id' )->andReturn( (object) array( 'id' => 7 ) );

        $this->controller->update_notice(
            $this->make_request(
                array(
                    'id'                    => 7,
                    'name'                  => 'Updated Name',
                    'public_columns_config' => 'cfg',
                    'ignored_field'         => 'nope',
                )
            )
        );

        $this->assertNotNull( $captured );
        $this->assertSame( 7, $captured['id'] );
        $this->assertSame( 'Updated Name', $captured['meta']['name'] );
        $this->assertSame( 'cfg', $captured['meta']['public_columns_config'] );
        $this->assertArrayNotHasKey( 'ignored_field', $captured['meta'] );
    }
}
