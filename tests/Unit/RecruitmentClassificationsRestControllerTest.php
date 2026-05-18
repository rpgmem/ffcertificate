<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Recruitment\RecruitmentClassificationsRestController;

/**
 * Tests for the recruitment classifications REST controller — route
 * registration + read endpoints + the simpler failure paths (missing CSV
 * file, missing classification). The full import/promote/call pipeline
 * cascades into CsvImporter + ClassificationService + CallService which
 * are out of scope for this smoke tier.
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentClassificationsRestController
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class RecruitmentClassificationsRestControllerTest extends TestCase {

    use MockeryPHPUnitIntegration;

    private RecruitmentClassificationsRestController $controller;

    /** @var \Mockery\MockInterface */
    private $clsRepoMock;

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

        $this->clsRepoMock = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentClassificationRepository' );
        $this->clsRepoMock->shouldReceive( 'get_for_notice' )->andReturn( array() )->byDefault();
        $this->clsRepoMock->shouldReceive( 'get_by_id' )->andReturn( null )->byDefault();

        $errMsgMock = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentErrorMessages' );
        $errMsgMock->shouldReceive( 'translate' )->andReturnUsing( fn( $c ) => 'msg:' . $c );
        $errMsgMock->shouldReceive( 'translate_all' )->andReturnUsing( fn( $codes ) => array_map( fn( $c ) => 'msg:' . $c, $codes ) );

        $this->controller = new RecruitmentClassificationsRestController();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function make_request( array $params, array $files = array() ): \WP_REST_Request {
        $req = Mockery::mock( 'WP_REST_Request' );
        $req->shouldReceive( 'get_param' )->andReturnUsing( fn( $k ) => $params[ $k ] ?? null );
        $req->shouldReceive( 'get_params' )->andReturn( $params );
        $req->shouldReceive( 'get_file_params' )->andReturn( $files );
        return $req;
    }

    // ------------------------------------------------------------------
    // register_routes()
    // ------------------------------------------------------------------

    public function test_register_routes_registers_classification_route_groups(): void {
        $this->controller->register_routes();

        // 9 register_rest_route calls: list/item/import/promote/call/bulk-call/
        // status/preview-status/cancel-call.
        $this->assertCount( 9, $this->registered_routes );
    }

    public function test_register_routes_includes_classifications_and_import_routes(): void {
        $this->controller->register_routes();

        $routes = array_column( $this->registered_routes, 'route' );
        $combined = implode( ' ', $routes );

        $this->assertStringContainsString( '/classifications', $combined );
        $this->assertStringContainsString( '/import', $combined );
        $this->assertStringContainsString( '/promote-preview', $combined );
    }

    // ------------------------------------------------------------------
    // list_classifications()
    // ------------------------------------------------------------------

    public function test_list_classifications_returns_200_with_repository_payload(): void {
        $rows = array(
            (object) array( 'id' => 1, 'rank' => 1 ),
            (object) array( 'id' => 2, 'rank' => 2 ),
        );
        $this->clsRepoMock->shouldReceive( 'get_for_notice' )->andReturn( $rows );

        $response = $this->controller->list_classifications( $this->make_request( array( 'id' => 7 ) ) );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( $rows, $response->get_data() );
    }

    public function test_list_classifications_passes_list_type_filter_through(): void {
        $captured = array();
        $this->clsRepoMock->shouldReceive( 'get_for_notice' )->andReturnUsing(
            function ( $id, $list_type, $adj_id ) use ( &$captured ) {
                $captured = compact( 'id', 'list_type', 'adj_id' );
                return array();
            }
        );

        $this->controller->list_classifications(
            $this->make_request( array( 'id' => 7, 'list_type' => 'preview' ) )
        );

        $this->assertSame( 7, $captured['id'] );
        $this->assertSame( 'preview', $captured['list_type'] );
        $this->assertNull( $captured['adj_id'] );
    }

    // ------------------------------------------------------------------
    // import_csv() — failure path
    // ------------------------------------------------------------------

    public function test_import_csv_returns_400_when_file_missing(): void {
        $result = $this->controller->import_csv( $this->make_request( array( 'id' => 7 ), array() ) );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'recruitment_csv_file_missing', $result->get_error_code() );
        $this->assertSame( 400, $result->get_error_data()['status'] );
    }
}
