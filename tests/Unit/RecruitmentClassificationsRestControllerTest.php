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

    /** @var array<int, string> */
    private array $tmp_files = array();

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        class_exists( '\\FreeFormCertificate\\Recruitment\\RecruitmentClassificationsRestController' );

        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'current_user_can' )->justReturn( false );
        Functions\when( 'is_user_logged_in' )->justReturn( false );
        Functions\when( 'get_current_user_id' )->justReturn( 42 );
        Functions\when( 'sanitize_text_field' )->returnArg();

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
        foreach ( $this->tmp_files as $f ) {
            if ( is_file( $f ) ) {
                unlink( $f );
            }
        }
        $this->tmp_files = array();
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Build a $_FILES-style array pointing at a real temp file so the
     * controller's file_get_contents() succeeds.
     *
     * @param string $content CSV body.
     * @return array<string, array{tmp_name: string}>
     */
    private function csv_files( string $content = "rank,name\n1,Ana\n" ): array {
        $path = tempnam( sys_get_temp_dir(), 'ffc-csv-' );
        file_put_contents( $path, $content );
        $this->tmp_files[] = $path;
        return array( 'csv_file' => array( 'tmp_name' => $path ) );
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

        // 15 register_rest_route calls: list/item/import/promote/call/bulk-call/
        // status/preview-status/override-to-empty/cancel-call/adjutancy + 4
        // batched-import endpoints (import-job/start, import-job/validate,
        // import-job/batch, import-job/commit).
        $this->assertCount( 15, $this->registered_routes );
    }

    public function test_register_routes_includes_classifications_and_import_routes(): void {
        $this->controller->register_routes();

        $routes = array_column( $this->registered_routes, 'route' );
        $combined = implode( ' ', $routes );

        $this->assertStringContainsString( '/classifications', $combined );
        $this->assertStringContainsString( '/import', $combined );
        $this->assertStringContainsString( '/promote-preview', $combined );
        $this->assertStringContainsString( '/override-to-empty', $combined );
    }

    // ------------------------------------------------------------------
    // override_classification_to_empty() — #Item 8
    // ------------------------------------------------------------------

    public function test_override_to_empty_returns_409_when_reason_missing(): void {
        // Routed through the real state machine: empty reason is gated before
        // any DB read, so the handler surfaces a 409 WP_Error.
        $result = $this->controller->override_classification_to_empty(
            $this->make_request( array( 'id' => 10, 'reason' => '' ) )
        );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'recruitment_transition_reason_required', $result->get_error_code() );
        $this->assertSame( 409, $result->get_error_data()['status'] );
    }

    public function test_override_to_empty_returns_409_when_classification_missing(): void {
        // get_by_id default-mock returns null → state machine reports
        // not-found, handler maps it to a 409 WP_Error.
        $result = $this->controller->override_classification_to_empty(
            $this->make_request( array( 'id' => 999, 'reason' => 'Undo hire' ) )
        );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'recruitment_classification_not_found', $result->get_error_code() );
        $this->assertSame( 409, $result->get_error_data()['status'] );
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

    // ------------------------------------------------------------------
    // change_classification_adjutancy() — issue #331 "Edit estendido"
    // ------------------------------------------------------------------

    public function test_change_classification_adjutancy_returns_400_when_id_zero(): void {
        $result = $this->controller->change_classification_adjutancy(
            $this->make_request( array( 'id' => 7, 'adjutancy_id' => 0 ) )
        );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'ffc_invalid_adjutancy', $result->get_error_code() );
        $this->assertSame( 400, $result->get_error_data()['status'] );
    }

    public function test_change_classification_adjutancy_returns_404_when_row_missing(): void {
        $this->clsRepoMock->shouldReceive( 'get_by_id' )->with( 7 )->andReturn( null );

        $result = $this->controller->change_classification_adjutancy(
            $this->make_request( array( 'id' => 7, 'adjutancy_id' => 5 ) )
        );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'ffc_classification_not_found', $result->get_error_code() );
    }

    public function test_change_classification_adjutancy_returns_409_when_unchanged(): void {
        $this->clsRepoMock->shouldReceive( 'get_by_id' )->with( 7 )
            ->andReturn( (object) array( 'id' => '7', 'notice_id' => '1', 'adjutancy_id' => '5' ) );

        $result = $this->controller->change_classification_adjutancy(
            $this->make_request( array( 'id' => 7, 'adjutancy_id' => 5 ) )
        );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'ffc_classification_adjutancy_unchanged', $result->get_error_code() );
    }

    public function test_change_classification_adjutancy_rejects_when_not_attached_to_notice(): void {
        $this->clsRepoMock->shouldReceive( 'get_by_id' )->with( 7 )
            ->andReturn( (object) array( 'id' => '7', 'notice_id' => '1', 'adjutancy_id' => '3' ) );

        $naMock = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentNoticeAdjutancyRepository' );
        $naMock->shouldReceive( 'is_attached' )->with( 1, 9 )->andReturn( false );

        $result = $this->controller->change_classification_adjutancy(
            $this->make_request( array( 'id' => 7, 'adjutancy_id' => 9 ) )
        );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'ffc_classification_adjutancy_not_attached_to_notice', $result->get_error_code() );
        $this->assertSame( 409, $result->get_error_data()['status'] );
    }

    public function test_change_classification_adjutancy_success_path_returns_envelope(): void {
        $this->clsRepoMock->shouldReceive( 'get_by_id' )->with( 7 )
            ->andReturn( (object) array( 'id' => '7', 'notice_id' => '1', 'adjutancy_id' => '3' ) );
        $this->clsRepoMock->shouldReceive( 'set_adjutancy' )->with( 7, 9 )->andReturn( true );

        $naMock = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentNoticeAdjutancyRepository' );
        $naMock->shouldReceive( 'is_attached' )->with( 1, 9 )->andReturn( true );

        $loggerMock = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentActivityLogger' );
        $loggerMock->shouldReceive( 'classification_adjutancy_changed' )->once()->with( 7, 3, 9 );

        $response = $this->controller->change_classification_adjutancy(
            $this->make_request( array( 'id' => 7, 'adjutancy_id' => 9 ) )
        );

        $this->assertNotInstanceOf( \WP_Error::class, $response );
        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertTrue( $data['success'] );
        $this->assertSame( 7, $data['classification_id'] );
        $this->assertSame( 3, $data['from'] );
        $this->assertSame( 9, $data['to'] );
    }

    public function test_change_classification_adjutancy_returns_500_when_update_fails(): void {
        $this->clsRepoMock->shouldReceive( 'get_by_id' )->with( 7 )
            ->andReturn( (object) array( 'id' => '7', 'notice_id' => '1', 'adjutancy_id' => '3' ) );
        $this->clsRepoMock->shouldReceive( 'set_adjutancy' )->with( 7, 9 )->andReturn( false );

        $naMock = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentNoticeAdjutancyRepository' );
        $naMock->shouldReceive( 'is_attached' )->with( 1, 9 )->andReturn( true );

        $result = $this->controller->change_classification_adjutancy(
            $this->make_request( array( 'id' => 7, 'adjutancy_id' => 9 ) )
        );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'ffc_classification_adjutancy_update_failed', $result->get_error_code() );
        $this->assertSame( 500, $result->get_error_data()['status'] );
    }

    // ------------------------------------------------------------------
    // permission callbacks (trait)
    // ------------------------------------------------------------------

    public function test_check_admin_cap_allow_and_deny(): void {
        Functions\when( 'current_user_can' )->alias( fn( $c ) => 'ffc_manage_recruitment' === $c );
        $this->assertTrue( $this->controller->check_admin_cap() );

        Functions\when( 'current_user_can' )->justReturn( false );
        $this->assertFalse( $this->controller->check_admin_cap() );
    }

    public function test_check_can_import_csv_allow_and_deny(): void {
        Functions\when( 'current_user_can' )->alias( fn( $c ) => 'ffc_import_recruitment' === $c );
        $this->assertTrue( $this->controller->check_can_import_csv() );

        // Umbrella cap is NOT a fallback for import (GAP H).
        Functions\when( 'current_user_can' )->alias( fn( $c ) => 'ffc_manage_recruitment' === $c );
        $this->assertFalse( $this->controller->check_can_import_csv() );
    }

    public function test_check_can_call_candidates_allow_via_umbrella_and_deny(): void {
        Functions\when( 'current_user_can' )->alias( fn( $c ) => 'ffc_manage_recruitment' === $c );
        $this->assertTrue( $this->controller->check_can_call_candidates() );

        Functions\when( 'current_user_can' )->alias( fn( $c ) => 'ffc_call_recruitment' === $c );
        $this->assertTrue( $this->controller->check_can_call_candidates() );

        Functions\when( 'current_user_can' )->justReturn( false );
        $this->assertFalse( $this->controller->check_can_call_candidates() );
    }

    public function test_check_can_delete_recruitment_allow_and_deny(): void {
        Functions\when( 'current_user_can' )->alias( fn( $c ) => 'ffc_delete_recruitment' === $c );
        $this->assertTrue( $this->controller->check_can_delete_recruitment() );

        Functions\when( 'current_user_can' )->justReturn( false );
        $this->assertFalse( $this->controller->check_can_delete_recruitment() );
    }

    // ------------------------------------------------------------------
    // list_classifications() — adjutancy filter passthrough
    // ------------------------------------------------------------------

    public function test_list_classifications_passes_adjutancy_filter_through(): void {
        $captured = array();
        $this->clsRepoMock->shouldReceive( 'get_for_notice' )->andReturnUsing(
            function ( $id, $list_type, $adj_id ) use ( &$captured ) {
                $captured = compact( 'id', 'list_type', 'adj_id' );
                return array();
            }
        );

        $this->controller->list_classifications(
            $this->make_request( array( 'id' => 7, 'adjutancy_id' => 3 ) )
        );

        $this->assertNull( $captured['list_type'] );
        $this->assertSame( 3, $captured['adj_id'] );
    }

    // ------------------------------------------------------------------
    // import_csv() — success + service-failure paths
    // ------------------------------------------------------------------

    public function test_import_csv_success_returns_200_with_preview(): void {
        $importer = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentCsvImporter' );
        $importer->shouldReceive( 'import_preview' )->once()
            ->andReturn( array( 'success' => true, 'errors' => array(), 'rows' => 3 ) );

        $response = $this->controller->import_csv(
            $this->make_request( array( 'id' => 7 ), $this->csv_files() )
        );

        $this->assertNotInstanceOf( \WP_Error::class, $response );
        $this->assertSame( 200, $response->get_status() );
        $this->assertTrue( $response->get_data()['success'] );
    }

    public function test_import_csv_returns_400_when_importer_reports_errors(): void {
        $importer = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentCsvImporter' );
        $importer->shouldReceive( 'import_preview' )->once()
            ->andReturn( array( 'success' => false, 'errors' => array( 'recruitment_csv_bad_header' ) ) );

        $result = $this->controller->import_csv(
            $this->make_request( array( 'id' => 7 ), $this->csv_files() )
        );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'recruitment_csv_bad_header', $result->get_error_code() );
        $this->assertSame( 400, $result->get_error_data()['status'] );
    }

    // ------------------------------------------------------------------
    // import_job_start()
    // ------------------------------------------------------------------

    public function test_import_job_start_returns_400_when_file_missing(): void {
        $result = $this->controller->import_job_start( $this->make_request( array( 'id' => 7 ), array() ) );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'recruitment_csv_file_missing', $result->get_error_code() );
    }

    public function test_import_job_start_success_returns_job_envelope(): void {
        $importer = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentCsvImporter' );
        $importer->shouldReceive( 'ingest_job' )->once()
            ->andReturn( array( 'ok' => true, 'errors' => array(), 'job_id' => 'job-1', 'total' => 5 ) );

        $response = $this->controller->import_job_start(
            $this->make_request( array( 'id' => 7 ), $this->csv_files() )
        );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 'job-1', $response->get_data()['job_id'] );
    }

    public function test_import_job_start_returns_400_when_ingest_fails(): void {
        $importer = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentCsvImporter' );
        $importer->shouldReceive( 'ingest_job' )->once()
            ->andReturn( array( 'ok' => false, 'errors' => array( 'recruitment_csv_empty' ) ) );

        $result = $this->controller->import_job_start(
            $this->make_request( array( 'id' => 7 ), $this->csv_files() )
        );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'recruitment_csv_empty', $result->get_error_code() );
    }

    // ------------------------------------------------------------------
    // import_job_validate / batch / commit
    // ------------------------------------------------------------------

    public function test_import_job_validate_success(): void {
        $importer = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentCsvImporter' );
        $importer->shouldReceive( 'validate_job' )->once()->with( 'job-1' )
            ->andReturn( array( 'ok' => true, 'errors' => array() ) );

        $response = $this->controller->import_job_validate(
            $this->make_request( array( 'job_id' => 'job-1' ) )
        );

        $this->assertSame( 200, $response->get_status() );
    }

    public function test_import_job_validate_failure(): void {
        $importer = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentCsvImporter' );
        $importer->shouldReceive( 'validate_job' )->once()
            ->andReturn( array( 'ok' => false, 'errors' => array( 'recruitment_job_not_found' ) ) );

        $result = $this->controller->import_job_validate(
            $this->make_request( array( 'job_id' => 'bad' ) )
        );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'recruitment_job_not_found', $result->get_error_code() );
    }

    public function test_import_job_batch_success_with_explicit_size(): void {
        $importer = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentCsvImporter' );
        $importer->shouldReceive( 'promote_batch' )->once()->with( 'job-1', 25 )
            ->andReturn( array( 'ok' => true, 'errors' => array(), 'done' => false ) );

        $response = $this->controller->import_job_batch(
            $this->make_request( array( 'job_id' => 'job-1', 'size' => 25 ) )
        );

        $this->assertSame( 200, $response->get_status() );
        $this->assertFalse( $response->get_data()['done'] );
    }

    public function test_import_job_batch_failure(): void {
        $importer = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentCsvImporter' );
        $importer->shouldReceive( 'promote_batch' )->once()
            ->andReturn( array( 'ok' => false, 'errors' => array( 'recruitment_batch_failed' ) ) );

        $result = $this->controller->import_job_batch(
            $this->make_request( array( 'job_id' => 'job-1', 'size' => 10 ) )
        );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'recruitment_batch_failed', $result->get_error_code() );
    }

    public function test_import_job_commit_success(): void {
        $importer = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentCsvImporter' );
        $importer->shouldReceive( 'commit_job' )->once()->with( 'job-1' )
            ->andReturn( array( 'ok' => true, 'errors' => array(), 'committed' => 5 ) );

        $response = $this->controller->import_job_commit(
            $this->make_request( array( 'job_id' => 'job-1' ) )
        );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 5, $response->get_data()['committed'] );
    }

    public function test_import_job_commit_failure(): void {
        $importer = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentCsvImporter' );
        $importer->shouldReceive( 'commit_job' )->once()
            ->andReturn( array( 'ok' => false, 'errors' => array( 'recruitment_commit_conflict' ) ) );

        $result = $this->controller->import_job_commit(
            $this->make_request( array( 'job_id' => 'job-1' ) )
        );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'recruitment_commit_conflict', $result->get_error_code() );
    }

    // ------------------------------------------------------------------
    // promote_preview()
    // ------------------------------------------------------------------

    public function test_promote_preview_snapshot_success(): void {
        $svc = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentPromotionService' );
        $svc->shouldReceive( 'snapshot_to_definitive' )->once()->with( 7 )
            ->andReturn( array( 'success' => true, 'errors' => array() ) );

        $response = $this->controller->promote_preview(
            $this->make_request( array( 'id' => 7, 'mode' => 'snapshot' ) )
        );

        $this->assertSame( 200, $response->get_status() );
    }

    public function test_promote_preview_snapshot_failure_returns_409(): void {
        $svc = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentPromotionService' );
        $svc->shouldReceive( 'snapshot_to_definitive' )->once()
            ->andReturn( array( 'success' => false, 'errors' => array( 'recruitment_no_preview_rows' ) ) );

        $result = $this->controller->promote_preview(
            $this->make_request( array( 'id' => 7, 'mode' => 'snapshot' ) )
        );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'recruitment_no_preview_rows', $result->get_error_code() );
        $this->assertSame( 409, $result->get_error_data()['status'] );
    }

    public function test_promote_preview_definitive_import_missing_file_returns_400(): void {
        $result = $this->controller->promote_preview(
            $this->make_request( array( 'id' => 7, 'mode' => 'definitive_import' ), array() )
        );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'recruitment_csv_file_missing', $result->get_error_code() );
    }

    public function test_promote_preview_definitive_import_importer_failure(): void {
        $importer = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentCsvImporter' );
        $importer->shouldReceive( 'import_definitive' )->once()
            ->andReturn( array( 'success' => false, 'errors' => array( 'recruitment_def_bad' ) ) );

        $result = $this->controller->promote_preview(
            $this->make_request( array( 'id' => 7, 'mode' => 'definitive_import' ), $this->csv_files() )
        );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'recruitment_def_bad', $result->get_error_code() );
    }

    public function test_promote_preview_definitive_import_transition_failure_returns_409(): void {
        $importer = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentCsvImporter' );
        $importer->shouldReceive( 'import_definitive' )->once()
            ->andReturn( array( 'success' => true, 'errors' => array() ) );

        $sm = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentNoticeStateMachine' );
        $sm->shouldReceive( 'transition_to' )->once()->with( 7, 'definitive' )
            ->andReturn( array( 'success' => false, 'errors' => array( 'recruitment_bad_transition' ) ) );

        $result = $this->controller->promote_preview(
            $this->make_request( array( 'id' => 7, 'mode' => 'definitive_import' ), $this->csv_files() )
        );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'recruitment_bad_transition', $result->get_error_code() );
        $this->assertSame( 409, $result->get_error_data()['status'] );
    }

    public function test_promote_preview_definitive_import_full_success(): void {
        $importer = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentCsvImporter' );
        $importer->shouldReceive( 'import_definitive' )->once()
            ->andReturn( array( 'success' => true, 'errors' => array(), 'rows' => 4 ) );

        $sm = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentNoticeStateMachine' );
        $sm->shouldReceive( 'transition_to' )->once()->with( 7, 'definitive' )
            ->andReturn( array( 'success' => true, 'errors' => array() ) );

        $logger = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentActivityLogger' );
        $logger->shouldReceive( 'notice_promoted' )->once()->with( 7, 'definitive_import', 0 );

        $response = $this->controller->promote_preview(
            $this->make_request( array( 'id' => 7, 'mode' => 'definitive_import' ), $this->csv_files() )
        );

        $this->assertNotInstanceOf( \WP_Error::class, $response );
        $this->assertSame( 200, $response->get_status() );
    }

    // ------------------------------------------------------------------
    // call_classification()
    // ------------------------------------------------------------------

    public function test_call_classification_success_returns_201(): void {
        $svc = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentCallService' );
        $svc->shouldReceive( 'call_single' )->once()
            ->with( 5, '2026-05-20', '09:00', 42, null, null )
            ->andReturn( array( 'success' => true, 'errors' => array() ) );

        $response = $this->controller->call_classification(
            $this->make_request(
                array(
                    'id'             => 5,
                    'date_to_assume' => '2026-05-20',
                    'time_to_assume' => '09:00',
                )
            )
        );

        $this->assertSame( 201, $response->get_status() );
    }

    public function test_call_classification_passes_reason_and_notes(): void {
        $svc = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentCallService' );
        $svc->shouldReceive( 'call_single' )->once()
            ->with( 5, '2026-05-20', '09:00', 42, 'skip', 'note here' )
            ->andReturn( array( 'success' => true, 'errors' => array() ) );

        $response = $this->controller->call_classification(
            $this->make_request(
                array(
                    'id'                  => 5,
                    'date_to_assume'      => '2026-05-20',
                    'time_to_assume'      => '09:00',
                    'out_of_order_reason' => 'skip',
                    'notes'               => 'note here',
                )
            )
        );

        $this->assertSame( 201, $response->get_status() );
    }

    public function test_call_classification_failure_returns_409(): void {
        $svc = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentCallService' );
        $svc->shouldReceive( 'call_single' )->once()
            ->andReturn( array( 'success' => false, 'errors' => array( 'recruitment_already_called' ) ) );

        $result = $this->controller->call_classification(
            $this->make_request(
                array( 'id' => 5, 'date_to_assume' => '2026-05-20', 'time_to_assume' => '09:00' )
            )
        );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'recruitment_already_called', $result->get_error_code() );
        $this->assertSame( 409, $result->get_error_data()['status'] );
    }

    // ------------------------------------------------------------------
    // bulk_call_classifications()
    // ------------------------------------------------------------------

    public function test_bulk_call_returns_400_when_id_list_empty(): void {
        $result = $this->controller->bulk_call_classifications(
            $this->make_request( array( 'classification_ids' => array() ) )
        );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'recruitment_bulk_call_empty_id_list', $result->get_error_code() );
        $this->assertSame( 400, $result->get_error_data()['status'] );
    }

    public function test_bulk_call_returns_400_when_ids_not_array(): void {
        $result = $this->controller->bulk_call_classifications(
            $this->make_request( array( 'classification_ids' => 'nope' ) )
        );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'recruitment_bulk_call_empty_id_list', $result->get_error_code() );
    }

    public function test_bulk_call_success_returns_201_with_filtered_ids(): void {
        $captured = array();
        $svc = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentCallService' );
        $svc->shouldReceive( 'call_bulk' )->once()->andReturnUsing(
            function ( $ids, $date, $time, $user, $reasons, $notes ) use ( &$captured ) {
                $captured = compact( 'ids', 'date', 'time', 'user', 'reasons', 'notes' );
                return array( 'success' => true, 'errors' => array() );
            }
        );

        $response = $this->controller->bulk_call_classifications(
            $this->make_request(
                array(
                    'classification_ids'   => array( '3', '0', 'x', 5 ),
                    'date_to_assume'       => '2026-05-20',
                    'time_to_assume'       => '09:00',
                    'out_of_order_reasons' => array( 'a', 'b' ),
                    'notes'                => 'bulk note',
                )
            )
        );

        $this->assertSame( 201, $response->get_status() );
        $this->assertSame( array( 3, 5 ), $captured['ids'] );
        $this->assertSame( array( 'a', 'b' ), $captured['reasons'] );
        $this->assertSame( 'bulk note', $captured['notes'] );
        $this->assertSame( 42, $captured['user'] );
    }

    public function test_bulk_call_failure_returns_409(): void {
        $svc = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentCallService' );
        $svc->shouldReceive( 'call_bulk' )->once()
            ->andReturn( array( 'success' => false, 'errors' => array( 'recruitment_bulk_failed' ) ) );

        $result = $this->controller->bulk_call_classifications(
            $this->make_request(
                array(
                    'classification_ids' => array( 1, 2 ),
                    'date_to_assume'     => '2026-05-20',
                    'time_to_assume'     => '09:00',
                )
            )
        );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'recruitment_bulk_failed', $result->get_error_code() );
    }

    // ------------------------------------------------------------------
    // change_classification_status()
    // ------------------------------------------------------------------

    public function test_change_status_success(): void {
        $sm = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentClassificationStateMachine' );
        $sm->shouldReceive( 'transition_to' )->once()->with( 5, 'hired', 'good fit' )
            ->andReturn( array( 'success' => true, 'errors' => array() ) );

        $response = $this->controller->change_classification_status(
            $this->make_request( array( 'id' => 5, 'status' => 'hired', 'reason' => 'good fit' ) )
        );

        $this->assertSame( 200, $response->get_status() );
    }

    public function test_change_status_null_reason_when_absent(): void {
        $sm = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentClassificationStateMachine' );
        $sm->shouldReceive( 'transition_to' )->once()->with( 5, 'hired', null )
            ->andReturn( array( 'success' => true, 'errors' => array() ) );

        $response = $this->controller->change_classification_status(
            $this->make_request( array( 'id' => 5, 'status' => 'hired' ) )
        );

        $this->assertSame( 200, $response->get_status() );
    }

    public function test_change_status_failure_returns_409(): void {
        $sm = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentClassificationStateMachine' );
        $sm->shouldReceive( 'transition_to' )->once()
            ->andReturn( array( 'success' => false, 'errors' => array( 'recruitment_invalid_status' ) ) );

        $result = $this->controller->change_classification_status(
            $this->make_request( array( 'id' => 5, 'status' => 'bogus' ) )
        );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'recruitment_invalid_status', $result->get_error_code() );
        $this->assertSame( 409, $result->get_error_data()['status'] );
    }

    // ------------------------------------------------------------------
    // override_classification_to_empty() — success
    // ------------------------------------------------------------------

    public function test_override_to_empty_success(): void {
        $sm = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentClassificationStateMachine' );
        $sm->shouldReceive( 'admin_override_to_empty' )->once()->with( 5, 'undo' )
            ->andReturn( array( 'success' => true, 'errors' => array() ) );

        $response = $this->controller->override_classification_to_empty(
            $this->make_request( array( 'id' => 5, 'reason' => 'undo' ) )
        );

        $this->assertNotInstanceOf( \WP_Error::class, $response );
        $this->assertSame( 200, $response->get_status() );
    }

    public function test_override_to_empty_service_failure_returns_409(): void {
        $sm = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentClassificationStateMachine' );
        $sm->shouldReceive( 'admin_override_to_empty' )->once()
            ->andReturn( array( 'success' => false, 'errors' => array( 'recruitment_override_blocked' ) ) );

        $result = $this->controller->override_classification_to_empty(
            $this->make_request( array( 'id' => 5, 'reason' => 'undo' ) )
        );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'recruitment_override_blocked', $result->get_error_code() );
        $this->assertSame( 409, $result->get_error_data()['status'] );
    }

    // ------------------------------------------------------------------
    // change_classification_preview_status()
    // ------------------------------------------------------------------

    public function test_preview_status_404_when_classification_missing(): void {
        // default get_by_id mock returns null
        $result = $this->controller->change_classification_preview_status(
            $this->make_request( array( 'id' => 5, 'preview_status' => 'granted' ) )
        );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'recruitment_classification_not_found', $result->get_error_code() );
        $this->assertSame( 404, $result->get_error_data()['status'] );
    }

    public function test_preview_status_409_when_not_preview_list(): void {
        $this->clsRepoMock->shouldReceive( 'get_by_id' )->with( 5 )
            ->andReturn( (object) array( 'id' => 5, 'list_type' => 'definitive' ) );

        $result = $this->controller->change_classification_preview_status(
            $this->make_request( array( 'id' => 5, 'preview_status' => 'granted' ) )
        );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'recruitment_preview_status_only_on_preview_list', $result->get_error_code() );
        $this->assertSame( 409, $result->get_error_data()['status'] );
    }

    public function test_preview_status_400_when_status_invalid(): void {
        $this->clsRepoMock->shouldReceive( 'get_by_id' )->with( 5 )
            ->andReturn( (object) array( 'id' => 5, 'list_type' => 'preview' ) );

        $result = $this->controller->change_classification_preview_status(
            $this->make_request( array( 'id' => 5, 'preview_status' => 'bogus' ) )
        );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'recruitment_preview_status_invalid', $result->get_error_code() );
        $this->assertSame( 400, $result->get_error_data()['status'] );
    }

    public function test_preview_status_400_when_reason_required_but_missing(): void {
        $this->clsRepoMock->shouldReceive( 'get_by_id' )->with( 5 )
            ->andReturn( (object) array( 'id' => 5, 'list_type' => 'preview' ) );

        $settings = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentSettings' );
        $settings->shouldReceive( 'all' )->andReturn( array( 'preview_reason_required_granted' => 1 ) );

        $result = $this->controller->change_classification_preview_status(
            $this->make_request( array( 'id' => 5, 'preview_status' => 'granted' ) )
        );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'recruitment_preview_reason_required', $result->get_error_code() );
        $this->assertSame( 400, $result->get_error_data()['status'] );
    }

    public function test_preview_status_404_when_reason_not_found(): void {
        $this->clsRepoMock->shouldReceive( 'get_by_id' )->with( 5 )
            ->andReturn( (object) array( 'id' => 5, 'list_type' => 'preview' ) );

        $settings = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentSettings' );
        $settings->shouldReceive( 'all' )->andReturn( array() );

        $reasons = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentReasonReader' );
        $reasons->shouldReceive( 'get_by_id' )->with( 8 )->andReturn( null );

        $result = $this->controller->change_classification_preview_status(
            $this->make_request(
                array( 'id' => 5, 'preview_status' => 'granted', 'preview_reason_id' => 8 )
            )
        );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'recruitment_preview_reason_not_found', $result->get_error_code() );
        $this->assertSame( 404, $result->get_error_data()['status'] );
    }

    public function test_preview_status_400_when_reason_status_mismatch(): void {
        $this->clsRepoMock->shouldReceive( 'get_by_id' )->with( 5 )
            ->andReturn( (object) array( 'id' => 5, 'list_type' => 'preview' ) );

        $settings = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentSettings' );
        $settings->shouldReceive( 'all' )->andReturn( array() );

        $reasons = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentReasonReader' );
        $reasons->shouldReceive( 'get_by_id' )->with( 8 )
            ->andReturn( (object) array( 'id' => 8, 'applies_to' => 'denied' ) );
        $reasons->shouldReceive( 'decode_applies_to' )->with( 'denied' )->andReturn( array( 'denied' ) );

        $result = $this->controller->change_classification_preview_status(
            $this->make_request(
                array( 'id' => 5, 'preview_status' => 'granted', 'preview_reason_id' => 8 )
            )
        );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'recruitment_preview_reason_status_mismatch', $result->get_error_code() );
        $this->assertSame( 400, $result->get_error_data()['status'] );
    }

    public function test_preview_status_400_when_update_fails(): void {
        $this->clsRepoMock->shouldReceive( 'get_by_id' )->with( 5 )
            ->andReturn( (object) array( 'id' => 5, 'list_type' => 'preview' ) );
        $this->clsRepoMock->shouldReceive( 'set_preview_status' )->with( 5, 'granted', null )->andReturn( false );

        $settings = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentSettings' );
        $settings->shouldReceive( 'all' )->andReturn( array() );

        $result = $this->controller->change_classification_preview_status(
            $this->make_request( array( 'id' => 5, 'preview_status' => 'granted' ) )
        );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'recruitment_preview_status_update_failed', $result->get_error_code() );
        $this->assertSame( 400, $result->get_error_data()['status'] );
    }

    public function test_preview_status_success_with_valid_reason(): void {
        $updated = (object) array( 'id' => 5, 'list_type' => 'preview', 'preview_status' => 'granted' );
        $this->clsRepoMock->shouldReceive( 'get_by_id' )->with( 5 )
            ->andReturn( (object) array( 'id' => 5, 'list_type' => 'preview' ), $updated );
        $this->clsRepoMock->shouldReceive( 'set_preview_status' )->with( 5, 'granted', 8 )->andReturn( true );

        $settings = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentSettings' );
        $settings->shouldReceive( 'all' )->andReturn( array() );

        $reasons = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentReasonReader' );
        $reasons->shouldReceive( 'get_by_id' )->with( 8 )
            ->andReturn( (object) array( 'id' => 8, 'applies_to' => 'granted' ) );
        $reasons->shouldReceive( 'decode_applies_to' )->andReturn( array( 'granted' ) );

        $response = $this->controller->change_classification_preview_status(
            $this->make_request(
                array( 'id' => 5, 'preview_status' => 'granted', 'preview_reason_id' => 8 )
            )
        );

        $this->assertNotInstanceOf( \WP_Error::class, $response );
        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( $updated, $response->get_data() );
    }

    public function test_preview_status_empty_clears_reason_and_succeeds(): void {
        $updated = (object) array( 'id' => 5, 'list_type' => 'preview', 'preview_status' => 'empty' );
        $this->clsRepoMock->shouldReceive( 'get_by_id' )->with( 5 )
            ->andReturn( (object) array( 'id' => 5, 'list_type' => 'preview' ), $updated );
        // 'empty' forces reason_id to 0 → null passed to setter.
        $this->clsRepoMock->shouldReceive( 'set_preview_status' )->with( 5, 'empty', null )->andReturn( true );

        $settings = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentSettings' );
        $settings->shouldReceive( 'all' )->andReturn( array() );

        $response = $this->controller->change_classification_preview_status(
            $this->make_request(
                array( 'id' => 5, 'preview_status' => 'empty', 'preview_reason_id' => 8 )
            )
        );

        $this->assertSame( 200, $response->get_status() );
    }

    // ------------------------------------------------------------------
    // delete_classification()
    // ------------------------------------------------------------------

    public function test_delete_classification_success(): void {
        $svc = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentDeleteService' );
        $svc->shouldReceive( 'delete_classification' )->once()->with( 5 )
            ->andReturn( array( 'success' => true, 'errors' => array() ) );

        $response = $this->controller->delete_classification(
            $this->make_request( array( 'id' => 5 ) )
        );

        $this->assertSame( 200, $response->get_status() );
    }

    public function test_delete_classification_blocked_returns_409_with_blocked_by(): void {
        $svc = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentDeleteService' );
        $svc->shouldReceive( 'delete_classification' )->once()
            ->andReturn(
                array(
                    'success'    => false,
                    'errors'     => array( 'recruitment_classification_has_calls' ),
                    'blocked_by' => array( 'calls' => 2 ),
                )
            );

        $result = $this->controller->delete_classification(
            $this->make_request( array( 'id' => 5 ) )
        );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'recruitment_classification_has_calls', $result->get_error_code() );
        $this->assertSame( 409, $result->get_error_data()['status'] );
        $this->assertSame( array( 'calls' => 2 ), $result->get_error_data()['blocked_by'] );
    }

    // ------------------------------------------------------------------
    // cancel_call()
    // ------------------------------------------------------------------

    public function test_cancel_call_success(): void {
        $svc = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentCallService' );
        $svc->shouldReceive( 'cancel_call' )->once()->with( 99, 'mistake', 42 )
            ->andReturn( array( 'success' => true, 'errors' => array() ) );

        $response = $this->controller->cancel_call(
            $this->make_request( array( 'call_id' => 99, 'reason' => 'mistake' ) )
        );

        $this->assertSame( 200, $response->get_status() );
    }

    public function test_cancel_call_failure_returns_409(): void {
        $svc = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentCallService' );
        $svc->shouldReceive( 'cancel_call' )->once()
            ->andReturn( array( 'success' => false, 'errors' => array( 'recruitment_call_not_cancellable' ) ) );

        $result = $this->controller->cancel_call(
            $this->make_request( array( 'call_id' => 99, 'reason' => 'mistake' ) )
        );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'recruitment_call_not_cancellable', $result->get_error_code() );
        $this->assertSame( 409, $result->get_error_data()['status'] );
    }
}
