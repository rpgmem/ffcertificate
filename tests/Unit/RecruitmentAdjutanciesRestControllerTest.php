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

    /** @var \Mockery\MockInterface */
    private $adjWriterMock;

    /** @var \Mockery\MockInterface */
    private $reasonWriterMock;

    /** @var \Mockery\MockInterface */
    private $noticeReaderMock;

    /** @var \Mockery\MockInterface */
    private $noticeAdjRepoMock;

    /** @var \Mockery\MockInterface */
    private $deleteServiceMock;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        class_exists( '\\FreeFormCertificate\\Recruitment\\RecruitmentAdjutanciesRestController' );

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

        $this->adjWriterMock = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentAdjutancyWriter' );
        $this->adjWriterMock->shouldReceive( 'create' )->andReturn( false )->byDefault();
        $this->adjWriterMock->shouldReceive( 'update' )->andReturn( false )->byDefault();

        $this->reasonRepoMock = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentReasonReader' );
        $this->reasonRepoMock->shouldReceive( 'get_all' )->andReturn( array() )->byDefault();
        $this->reasonRepoMock->shouldReceive( 'get_by_id' )->andReturn( null )->byDefault();
        $this->reasonRepoMock->shouldReceive( 'count_references' )->andReturn( 0 )->byDefault();

        $this->reasonWriterMock = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentReasonWriter' );
        $this->reasonWriterMock->shouldReceive( 'create' )->andReturn( false )->byDefault();
        $this->reasonWriterMock->shouldReceive( 'update' )->andReturn( false )->byDefault();
        $this->reasonWriterMock->shouldReceive( 'delete' )->andReturn( false )->byDefault();

        $this->noticeReaderMock = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentNoticeReader' );
        $this->noticeReaderMock->shouldReceive( 'get_by_id' )->andReturn( null )->byDefault();

        $this->noticeAdjRepoMock = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentNoticeAdjutancyRepository' );
        $this->noticeAdjRepoMock->shouldReceive( 'is_attached' )->andReturn( false )->byDefault();
        $this->noticeAdjRepoMock->shouldReceive( 'attach' )->andReturn( true )->byDefault();
        $this->noticeAdjRepoMock->shouldReceive( 'detach' )->andReturn( true )->byDefault();

        $this->deleteServiceMock = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentDeleteService' );
        $this->deleteServiceMock->shouldReceive( 'delete_adjutancy' )
            ->andReturn( array( 'success' => false, 'errors' => array( 'recruitment_error' ) ) )->byDefault();

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

    // ------------------------------------------------------------------
    // permission_callback gates (allow + deny)
    // ------------------------------------------------------------------

    public function test_check_admin_cap_denies_without_cap(): void {
        Functions\when( 'current_user_can' )->justReturn( false );
        $this->assertFalse( $this->controller->check_admin_cap() );
    }

    public function test_check_admin_cap_allows_with_manage_cap(): void {
        Functions\when( 'current_user_can' )->alias(
            fn( $cap ) => 'ffc_manage_recruitment' === $cap
        );
        $this->assertTrue( $this->controller->check_admin_cap() );
    }

    public function test_check_can_delete_recruitment_allow_and_deny(): void {
        Functions\when( 'current_user_can' )->justReturn( false );
        $this->assertFalse( $this->controller->check_can_delete_recruitment() );

        Functions\when( 'current_user_can' )->alias(
            fn( $cap ) => 'ffc_delete_recruitment' === $cap
        );
        $this->assertTrue( $this->controller->check_can_delete_recruitment() );
    }

    public function test_check_can_manage_reasons_allow_and_deny(): void {
        Functions\when( 'current_user_can' )->justReturn( false );
        $this->assertFalse( $this->controller->check_can_manage_reasons() );

        Functions\when( 'current_user_can' )->alias(
            fn( $cap ) => 'ffc_manage_recruitment_reasons' === $cap
        );
        $this->assertTrue( $this->controller->check_can_manage_reasons() );
    }

    // ------------------------------------------------------------------
    // create_adjutancy()
    // ------------------------------------------------------------------

    public function test_create_adjutancy_returns_409_on_duplicate_slug(): void {
        $this->adjWriterMock->shouldReceive( 'create' )->andReturn( false );

        $result = $this->controller->create_adjutancy(
            $this->make_request( array( 'slug' => 'dup', 'name' => 'Dup', 'color' => '' ) )
        );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'recruitment_adjutancy_create_failed', $result->get_error_code() );
        $this->assertSame( 409, $result->get_error_data()['status'] );
    }

    public function test_create_adjutancy_returns_201_on_success(): void {
        $row = (object) array( 'id' => 5, 'slug' => 'new', 'name' => 'New' );
        $this->adjWriterMock->shouldReceive( 'create' )->with( 'new', 'New', '#fff' )->andReturn( 5 );
        $this->adjRepoMock->shouldReceive( 'get_by_id' )->with( 5 )->andReturn( $row );

        $response = $this->controller->create_adjutancy(
            $this->make_request( array( 'slug' => 'new', 'name' => 'New', 'color' => '#fff' ) )
        );

        $this->assertNotInstanceOf( \WP_Error::class, $response );
        $this->assertSame( 201, $response->get_status() );
        $this->assertSame( $row, $response->get_data() );
    }

    // ------------------------------------------------------------------
    // update_adjutancy()
    // ------------------------------------------------------------------

    public function test_update_adjutancy_returns_400_on_failure(): void {
        $this->adjWriterMock->shouldReceive( 'update' )->andReturn( false );

        $result = $this->controller->update_adjutancy(
            $this->make_request( array( 'id' => 3, 'name' => 'X' ) )
        );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'recruitment_adjutancy_update_failed', $result->get_error_code() );
        $this->assertSame( 400, $result->get_error_data()['status'] );
    }

    public function test_update_adjutancy_returns_200_on_success(): void {
        $row = (object) array( 'id' => 3, 'name' => 'Renamed' );
        $this->adjWriterMock->shouldReceive( 'update' )->with( 3, Mockery::on( fn( $d ) => 'Renamed' === ( $d['name'] ?? null ) ) )->andReturn( true );
        $this->adjRepoMock->shouldReceive( 'get_by_id' )->with( 3 )->andReturn( $row );

        $response = $this->controller->update_adjutancy(
            $this->make_request( array( 'id' => 3, 'name' => 'Renamed', 'ignored' => 'x' ) )
        );

        $this->assertNotInstanceOf( \WP_Error::class, $response );
        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( $row, $response->get_data() );
    }

    // ------------------------------------------------------------------
    // delete_adjutancy()
    // ------------------------------------------------------------------

    public function test_delete_adjutancy_returns_409_envelope_when_blocked(): void {
        $this->deleteServiceMock->shouldReceive( 'delete_adjutancy' )->with( 8 )->andReturn(
            array(
                'success'    => false,
                'errors'     => array( 'recruitment_adjutancy_in_use' ),
                'blocked_by' => array( 'classifications' => 4 ),
            )
        );

        $result = $this->controller->delete_adjutancy( $this->make_request( array( 'id' => 8 ) ) );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'recruitment_adjutancy_in_use', $result->get_error_code() );
        $data = $result->get_error_data();
        $this->assertSame( 409, $data['status'] );
        $this->assertSame( array( 'classifications' => 4 ), $data['blocked_by'] );
    }

    public function test_delete_adjutancy_returns_200_on_success(): void {
        $envelope = array( 'success' => true, 'errors' => array() );
        $this->deleteServiceMock->shouldReceive( 'delete_adjutancy' )->with( 8 )->andReturn( $envelope );

        $response = $this->controller->delete_adjutancy( $this->make_request( array( 'id' => 8 ) ) );

        $this->assertNotInstanceOf( \WP_Error::class, $response );
        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( $envelope, $response->get_data() );
    }

    // ------------------------------------------------------------------
    // create_reason()
    // ------------------------------------------------------------------

    public function test_create_reason_returns_409_on_duplicate_slug(): void {
        $this->reasonWriterMock->shouldReceive( 'create' )->andReturn( false );

        $result = $this->controller->create_reason(
            $this->make_request( array( 'slug' => 'dup', 'label' => 'Dup' ) )
        );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'recruitment_reason_create_failed', $result->get_error_code() );
        $this->assertSame( 409, $result->get_error_data()['status'] );
    }

    public function test_create_reason_returns_201_and_filters_applies_to(): void {
        $row      = (object) array( 'id' => 9, 'slug' => 'denied' );
        $captured = array();
        $this->reasonWriterMock->shouldReceive( 'create' )->andReturnUsing(
            function ( $slug, $label, $color, $applies ) use ( &$captured ) {
                $captured = compact( 'slug', 'label', 'color', 'applies' );
                return 9;
            }
        );
        $this->reasonRepoMock->shouldReceive( 'get_by_id' )->with( 9 )->andReturn( $row );

        $response = $this->controller->create_reason(
            $this->make_request(
                array(
                    'slug'       => 'denied',
                    'label'      => 'Denied',
                    'color'      => '#000',
                    // Mixed array: non-string entries must be filtered out.
                    'applies_to' => array( 'preview', 123, 'definitive' ),
                )
            )
        );

        $this->assertNotInstanceOf( \WP_Error::class, $response );
        $this->assertSame( 201, $response->get_status() );
        $this->assertSame( $row, $response->get_data() );
        $this->assertSame( array( 'preview', 'definitive' ), $captured['applies'] );
    }

    public function test_create_reason_handles_non_array_applies_to(): void {
        $captured = array();
        $this->reasonWriterMock->shouldReceive( 'create' )->andReturnUsing(
            function ( $slug, $label, $color, $applies ) use ( &$captured ) {
                $captured = $applies;
                return false;
            }
        );

        $this->controller->create_reason(
            $this->make_request( array( 'slug' => 's', 'label' => 'L', 'applies_to' => 'not-array' ) )
        );

        $this->assertSame( array(), $captured );
    }

    // ------------------------------------------------------------------
    // update_reason()
    // ------------------------------------------------------------------

    public function test_update_reason_returns_400_on_failure(): void {
        $this->reasonWriterMock->shouldReceive( 'update' )->andReturn( false );

        $result = $this->controller->update_reason(
            $this->make_request( array( 'id' => 4, 'label' => 'X' ) )
        );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'recruitment_reason_update_failed', $result->get_error_code() );
        $this->assertSame( 400, $result->get_error_data()['status'] );
    }

    public function test_update_reason_returns_200_on_success(): void {
        $row = (object) array( 'id' => 4, 'label' => 'Renamed' );
        $this->reasonWriterMock->shouldReceive( 'update' )->with( 4, Mockery::type( 'array' ) )->andReturn( true );
        $this->reasonRepoMock->shouldReceive( 'get_by_id' )->with( 4 )->andReturn( $row );

        $response = $this->controller->update_reason(
            $this->make_request( array( 'id' => 4, 'label' => 'Renamed' ) )
        );

        $this->assertNotInstanceOf( \WP_Error::class, $response );
        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( $row, $response->get_data() );
    }

    // ------------------------------------------------------------------
    // delete_reason()
    // ------------------------------------------------------------------

    public function test_delete_reason_returns_409_when_in_use(): void {
        $this->reasonRepoMock->shouldReceive( 'count_references' )->with( 6 )->andReturn( 3 );

        $result = $this->controller->delete_reason( $this->make_request( array( 'id' => 6 ) ) );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'recruitment_reason_in_use', $result->get_error_code() );
        $data = $result->get_error_data();
        $this->assertSame( 409, $data['status'] );
        $this->assertSame( 3, $data['reference_count'] );
    }

    public function test_delete_reason_returns_400_when_writer_fails(): void {
        $this->reasonRepoMock->shouldReceive( 'count_references' )->with( 6 )->andReturn( 0 );
        $this->reasonWriterMock->shouldReceive( 'delete' )->with( 6 )->andReturn( false );

        $result = $this->controller->delete_reason( $this->make_request( array( 'id' => 6 ) ) );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'recruitment_reason_delete_failed', $result->get_error_code() );
        $this->assertSame( 400, $result->get_error_data()['status'] );
    }

    public function test_delete_reason_returns_200_on_success(): void {
        $this->reasonRepoMock->shouldReceive( 'count_references' )->with( 6 )->andReturn( 0 );
        $this->reasonWriterMock->shouldReceive( 'delete' )->with( 6 )->andReturn( true );

        $response = $this->controller->delete_reason( $this->make_request( array( 'id' => 6 ) ) );

        $this->assertNotInstanceOf( \WP_Error::class, $response );
        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( array( 'success' => true ), $response->get_data() );
    }

    // ------------------------------------------------------------------
    // attach_notice_adjutancy()
    // ------------------------------------------------------------------

    public function test_attach_returns_404_when_notice_missing(): void {
        $this->noticeReaderMock->shouldReceive( 'get_by_id' )->with( 1 )->andReturn( null );

        $result = $this->controller->attach_notice_adjutancy(
            $this->make_request( array( 'id' => 1, 'adjutancy_id' => 2 ) )
        );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'recruitment_notice_not_found', $result->get_error_code() );
        $this->assertSame( 404, $result->get_error_data()['status'] );
    }

    public function test_attach_returns_404_when_adjutancy_missing(): void {
        $this->noticeReaderMock->shouldReceive( 'get_by_id' )->with( 1 )->andReturn( (object) array( 'id' => 1 ) );
        $this->adjRepoMock->shouldReceive( 'get_by_id' )->with( 2 )->andReturn( null );

        $result = $this->controller->attach_notice_adjutancy(
            $this->make_request( array( 'id' => 1, 'adjutancy_id' => 2 ) )
        );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'recruitment_adjutancy_not_found', $result->get_error_code() );
        $this->assertSame( 404, $result->get_error_data()['status'] );
    }

    public function test_attach_returns_200_created_false_when_already_attached(): void {
        $this->noticeReaderMock->shouldReceive( 'get_by_id' )->with( 1 )->andReturn( (object) array( 'id' => 1 ) );
        $this->adjRepoMock->shouldReceive( 'get_by_id' )->with( 2 )->andReturn( (object) array( 'id' => 2 ) );
        $this->noticeAdjRepoMock->shouldReceive( 'is_attached' )->with( 1, 2 )->andReturn( true );

        $response = $this->controller->attach_notice_adjutancy(
            $this->make_request( array( 'id' => 1, 'adjutancy_id' => 2 ) )
        );

        $this->assertNotInstanceOf( \WP_Error::class, $response );
        $this->assertSame( 200, $response->get_status() );
        $this->assertFalse( $response->get_data()['created'] );
    }

    public function test_attach_returns_500_when_attach_fails(): void {
        $this->noticeReaderMock->shouldReceive( 'get_by_id' )->with( 1 )->andReturn( (object) array( 'id' => 1 ) );
        $this->adjRepoMock->shouldReceive( 'get_by_id' )->with( 2 )->andReturn( (object) array( 'id' => 2 ) );
        $this->noticeAdjRepoMock->shouldReceive( 'is_attached' )->with( 1, 2 )->andReturn( false );
        $this->noticeAdjRepoMock->shouldReceive( 'attach' )->with( 1, 2 )->andReturn( false );

        $result = $this->controller->attach_notice_adjutancy(
            $this->make_request( array( 'id' => 1, 'adjutancy_id' => 2 ) )
        );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'recruitment_notice_adjutancy_attach_failed', $result->get_error_code() );
        $this->assertSame( 500, $result->get_error_data()['status'] );
    }

    public function test_attach_returns_201_created_true_on_success(): void {
        $this->noticeReaderMock->shouldReceive( 'get_by_id' )->with( 1 )->andReturn( (object) array( 'id' => 1 ) );
        $this->adjRepoMock->shouldReceive( 'get_by_id' )->with( 2 )->andReturn( (object) array( 'id' => 2 ) );
        $this->noticeAdjRepoMock->shouldReceive( 'is_attached' )->with( 1, 2 )->andReturn( false );
        $this->noticeAdjRepoMock->shouldReceive( 'attach' )->with( 1, 2 )->andReturn( true );

        $response = $this->controller->attach_notice_adjutancy(
            $this->make_request( array( 'id' => 1, 'adjutancy_id' => 2 ) )
        );

        $this->assertNotInstanceOf( \WP_Error::class, $response );
        $this->assertSame( 201, $response->get_status() );
        $data = $response->get_data();
        $this->assertTrue( $data['created'] );
        $this->assertSame( 1, $data['notice_id'] );
        $this->assertSame( 2, $data['adjutancy_id'] );
    }

    // ------------------------------------------------------------------
    // detach_notice_adjutancy()
    // ------------------------------------------------------------------

    public function test_detach_returns_200_with_attached_false(): void {
        $this->noticeAdjRepoMock->shouldReceive( 'detach' )->with( 1, 2 )->andReturn( true );

        $response = $this->controller->detach_notice_adjutancy(
            $this->make_request( array( 'id' => 1, 'adjutancy_id' => 2 ) )
        );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertFalse( $data['attached'] );
        $this->assertSame( 1, $data['notice_id'] );
        $this->assertSame( 2, $data['adjutancy_id'] );
    }
}
