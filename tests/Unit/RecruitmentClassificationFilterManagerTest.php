<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Recruitment\RecruitmentClassificationFilterManager;

/**
 * Tests for the recruitment classification filter manager — read_filters
 * (GET-param normalization + CPF/RF candidate-id resolution) and
 * apply_filters (AND-composed row filtering).
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentClassificationFilterManager
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class RecruitmentClassificationFilterManagerTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var \Mockery\MockInterface */
    private $candRepoMock;

    /** @var \Mockery\MockInterface */
    private $sanitizerMock;

    /** @var \Mockery\MockInterface */
    private $encryptionMock;

    /** @var \Mockery\MockInterface */
    private $pcdHasherMock;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'absint' )->alias( fn( $v ) => abs( (int) $v ) );
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'sanitize_key' )->returnArg();
        Functions\when( 'wp_unslash' )->returnArg();

        $this->candRepoMock = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentCandidateReader' );
        $this->candRepoMock->shouldReceive( 'get_by_cpf_hash' )->andReturn( null )->byDefault();
        $this->candRepoMock->shouldReceive( 'get_by_rf_hash' )->andReturn( null )->byDefault();
        $this->candRepoMock->shouldReceive( 'get_by_id' )->andReturn( null )->byDefault();

        $this->sanitizerMock = Mockery::mock( 'alias:FreeFormCertificate\Core\DataSanitizer' );
        $this->sanitizerMock->shouldReceive( 'normalize_cpf_rf' )->andReturnUsing(
            fn( $v ) => preg_replace( '/\D/', '', (string) $v )
        );

        $this->encryptionMock = Mockery::mock( 'alias:FreeFormCertificate\Core\Encryption' );
        $this->encryptionMock->shouldReceive( 'hash' )->andReturnUsing( fn( $v ) => 'hash:' . $v );

        $this->pcdHasherMock = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentPcdHasher' );
        $this->pcdHasherMock->shouldReceive( 'verify' )->andReturn( false )->byDefault();
    }

    protected function tearDown(): void {
        unset( $_GET['ffc_cls_adj'], $_GET['ffc_cls_q'], $_GET['ffc_cls_cpf'], $_GET['ffc_cls_rf'], $_GET['ffc_cls_sub'] );
        Monkey\tearDown();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // read_filters() — defaults
    // ------------------------------------------------------------------

    public function test_read_filters_returns_zeros_when_no_get_params(): void {
        $filters = RecruitmentClassificationFilterManager::read_filters( 42 );

        $this->assertSame( 42, $filters['notice_id'] );
        $this->assertSame( 0, $filters['adjutancy_id'] );
        $this->assertSame( '', $filters['query'] );
        $this->assertSame( 0, $filters['cpf_candidate_id'] );
        $this->assertSame( 0, $filters['rf_candidate_id'] );
        $this->assertSame( '', $filters['subscription'] );
    }

    // ------------------------------------------------------------------
    // read_filters() — CPF resolution
    // ------------------------------------------------------------------

    public function test_read_filters_resolves_cpf_to_candidate_id_when_match_found(): void {
        $_GET['ffc_cls_cpf'] = '123.456.789-00';
        $this->candRepoMock->shouldReceive( 'get_by_cpf_hash' )
            ->with( 'hash:12345678900' )
            ->andReturn( (object) array( 'id' => 7 ) );

        $filters = RecruitmentClassificationFilterManager::read_filters( 1 );

        $this->assertSame( 7, $filters['cpf_candidate_id'] );
    }

    public function test_read_filters_marks_cpf_candidate_minus_one_when_no_match(): void {
        $_GET['ffc_cls_cpf'] = '12345678900';
        $this->candRepoMock->shouldReceive( 'get_by_cpf_hash' )->andReturn( null );

        $filters = RecruitmentClassificationFilterManager::read_filters( 1 );

        $this->assertSame( -1, $filters['cpf_candidate_id'] );
    }

    public function test_read_filters_normalizes_subscription_to_allowed_set(): void {
        $_GET['ffc_cls_sub'] = 'bogus';

        $filters = RecruitmentClassificationFilterManager::read_filters( 1 );

        $this->assertSame( '', $filters['subscription'] );
    }

    public function test_read_filters_keeps_pcd_subscription(): void {
        $_GET['ffc_cls_sub'] = 'pcd';

        $filters = RecruitmentClassificationFilterManager::read_filters( 1 );

        $this->assertSame( 'pcd', $filters['subscription'] );
    }

    // ------------------------------------------------------------------
    // apply_filters() — early return on unresolved CPF/RF
    // ------------------------------------------------------------------

    public function test_apply_filters_returns_empty_when_cpf_unresolved(): void {
        $rows = array(
            (object) array( 'adjutancy_id' => 1, 'candidate_id' => 1 ),
            (object) array( 'adjutancy_id' => 1, 'candidate_id' => 2 ),
        );
        $filters = array( 'cpf_candidate_id' => -1 );

        $out = RecruitmentClassificationFilterManager::apply_filters( $rows, $filters );

        $this->assertSame( array(), $out );
    }

    public function test_apply_filters_returns_empty_when_rf_unresolved(): void {
        $rows    = array( (object) array( 'adjutancy_id' => 1, 'candidate_id' => 1 ) );
        $filters = array( 'rf_candidate_id' => -1 );

        $out = RecruitmentClassificationFilterManager::apply_filters( $rows, $filters );

        $this->assertSame( array(), $out );
    }

    // ------------------------------------------------------------------
    // apply_filters() — adjutancy filter
    // ------------------------------------------------------------------

    public function test_apply_filters_keeps_only_rows_with_matching_adjutancy_id(): void {
        $rows = array(
            (object) array( 'adjutancy_id' => 1, 'candidate_id' => 10 ),
            (object) array( 'adjutancy_id' => 2, 'candidate_id' => 20 ),
            (object) array( 'adjutancy_id' => 1, 'candidate_id' => 30 ),
        );
        $filters = array( 'adjutancy_id' => 1 );

        $out = RecruitmentClassificationFilterManager::apply_filters( $rows, $filters );

        $this->assertCount( 2, $out );
        $this->assertSame( 10, $out[0]->candidate_id );
        $this->assertSame( 30, $out[1]->candidate_id );
    }

    // ------------------------------------------------------------------
    // apply_filters() — CPF candidate match
    // ------------------------------------------------------------------

    public function test_apply_filters_keeps_only_rows_for_resolved_cpf_candidate(): void {
        $rows = array(
            (object) array( 'adjutancy_id' => 1, 'candidate_id' => 7 ),
            (object) array( 'adjutancy_id' => 1, 'candidate_id' => 99 ),
        );
        $filters = array( 'cpf_candidate_id' => 7 );

        $out = RecruitmentClassificationFilterManager::apply_filters( $rows, $filters );

        $this->assertCount( 1, $out );
        $this->assertSame( 7, $out[0]->candidate_id );
    }

    // ------------------------------------------------------------------
    // apply_filters() — name substring search
    // ------------------------------------------------------------------

    public function test_apply_filters_name_query_is_case_insensitive_substring(): void {
        $rows = array(
            (object) array( 'adjutancy_id' => 0, 'candidate_id' => 1 ),
            (object) array( 'adjutancy_id' => 0, 'candidate_id' => 2 ),
        );
        $this->candRepoMock->shouldReceive( 'get_by_id' )->with( 1 )->andReturn( (object) array( 'name' => 'John Doe' ) );
        $this->candRepoMock->shouldReceive( 'get_by_id' )->with( 2 )->andReturn( (object) array( 'name' => 'Jane Smith' ) );

        $filters = array( 'query' => 'doe' );

        $out = RecruitmentClassificationFilterManager::apply_filters( $rows, $filters );

        $this->assertCount( 1, $out );
        $this->assertSame( 1, $out[0]->candidate_id );
    }

    public function test_apply_filters_drops_rows_when_candidate_lookup_fails_during_name_search(): void {
        $rows = array( (object) array( 'adjutancy_id' => 0, 'candidate_id' => 99 ) );
        $this->candRepoMock->shouldReceive( 'get_by_id' )->with( 99 )->andReturn( null );

        $out = RecruitmentClassificationFilterManager::apply_filters( $rows, array( 'query' => 'anything' ) );

        $this->assertSame( array(), $out );
    }

    // ------------------------------------------------------------------
    // apply_filters() — subscription filter
    // ------------------------------------------------------------------

    public function test_apply_filters_subscription_pcd_keeps_only_pcd_candidates(): void {
        $rows = array(
            (object) array( 'adjutancy_id' => 0, 'candidate_id' => 1 ),
            (object) array( 'adjutancy_id' => 0, 'candidate_id' => 2 ),
        );
        $this->candRepoMock->shouldReceive( 'get_by_id' )->with( 1 )->andReturn( (object) array( 'name' => 'A', 'pcd_hash' => 'pcd-1' ) );
        $this->candRepoMock->shouldReceive( 'get_by_id' )->with( 2 )->andReturn( (object) array( 'name' => 'B', 'pcd_hash' => 'no-pcd-2' ) );
        $this->pcdHasherMock->shouldReceive( 'verify' )->with( 'pcd-1', 1 )->andReturn( true );
        $this->pcdHasherMock->shouldReceive( 'verify' )->with( 'no-pcd-2', 2 )->andReturn( false );

        $filters = array( 'subscription' => 'pcd' );

        $out = RecruitmentClassificationFilterManager::apply_filters( $rows, $filters );

        $this->assertCount( 1, $out );
        $this->assertSame( 1, $out[0]->candidate_id );
    }

    public function test_apply_filters_passes_through_when_no_filters_active(): void {
        $rows = array(
            (object) array( 'adjutancy_id' => 1, 'candidate_id' => 1 ),
            (object) array( 'adjutancy_id' => 2, 'candidate_id' => 2 ),
        );

        $out = RecruitmentClassificationFilterManager::apply_filters( $rows, array() );

        $this->assertSame( $rows, $out );
    }
}
