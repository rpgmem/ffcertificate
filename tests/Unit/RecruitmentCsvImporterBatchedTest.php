<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Recruitment\RecruitmentCsvImporter;

/**
 * Coverage for the staging-based batched import flow (V10) of
 * RecruitmentCsvImporter: ingest_job → validate_job → promote_batch →
 * commit_job, plus the import_definitive entry point.
 *
 * These exercise the SQL-driven state machine against a mocked `$wpdb`
 * and alias-stubbed recruitment repositories. Process-isolated so the
 * alias mocks for the static repositories / Encryption / PcdHasher don't
 * leak into sibling tests.
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentCsvImporter
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class RecruitmentCsvImporterBatchedTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface */
	private $wpdb;

	private const HEADER = "name,cpf,rf,email,adjutancy,rank,score,pcd\n";

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		global $wpdb;
		$wpdb            = Mockery::mock( 'wpdb' )->makePartial();
		$wpdb->prefix    = 'wp_';
		$wpdb->insert_id = 0;
		$this->wpdb      = $wpdb;

		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'current_time' )->justReturn( '2026-05-01 10:00:00' );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'wp_generate_uuid4' )->justReturn( 'job-uuid-1234' );
		Functions\when( 'sanitize_text_field' )->returnArg();

		$this->wpdb->shouldReceive( 'prepare' )
			->andReturnUsing( static fn ( $sql ) => $sql )
			->byDefault();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function notice_stub( string $status ): object {
		return (object) array(
			'id'     => '5',
			'code'   => 'EDITAL',
			'name'   => 'Edital',
			'status' => $status,
		);
	}

	/**
	 * Wire NoticeRepository / NoticeAdjutancyRepository / AdjutancyRepository
	 * for the ingest path so build_adjutancy_map() resolves `mat` → 2.
	 *
	 * @param object|null $notice Notice stub or null.
	 * @param array<int>  $ids    Adjutancy ids for the notice.
	 * @return void
	 */
	private function wire_repos( ?object $notice, array $ids = array( 2 ) ): void {
		$notice_repo = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentNoticeRepository' );
		$notice_repo->shouldReceive( 'get_by_id' )->andReturn( $notice );

		$junction = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentNoticeAdjutancyRepository' );
		$junction->shouldReceive( 'get_adjutancy_ids_for_notice' )->andReturn( $ids );

		$adj = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentAdjutancyRepository' );
		$adj->shouldReceive( 'get_by_id' )->andReturnUsing(
			static function ( $id ) {
				return (object) array(
					'id'   => (string) $id,
					'slug' => 'mat',
					'name' => 'Matemática',
				);
			}
		);
	}

	// ==================================================================
	// ingest_job()
	// ==================================================================

	public function test_ingest_job_rejects_unknown_notice(): void {
		$this->wire_repos( null );

		$out = RecruitmentCsvImporter::ingest_job( 999, self::HEADER . 'A,12345678901,,a@b.test,mat,1,90,Sim', 'preview' );

		$this->assertFalse( $out['ok'] );
		$this->assertContains( 'recruitment_notice_not_found', $out['errors'] );
	}

	public function test_ingest_job_rejects_preview_when_notice_not_eligible(): void {
		$this->wire_repos( $this->notice_stub( 'active' ) );

		$out = RecruitmentCsvImporter::ingest_job( 5, self::HEADER . 'A,12345678901,,a@b.test,mat,1,90,Sim', 'preview' );

		$this->assertFalse( $out['ok'] );
		$this->assertContains( 'recruitment_invalid_state_for_preview_import', $out['errors'] );
	}

	public function test_ingest_job_rejects_parse_errors(): void {
		$this->wire_repos( $this->notice_stub( 'draft' ) );

		$out = RecruitmentCsvImporter::ingest_job( 5, 'name,cpf', 'preview' );

		$this->assertFalse( $out['ok'] );
		$this->assertStringContainsString( 'recruitment_csv_missing_headers', $out['errors'][0] );
	}

	public function test_ingest_job_rejects_empty_rows(): void {
		$this->wire_repos( $this->notice_stub( 'draft' ) );

		// Header only — no data rows.
		$out = RecruitmentCsvImporter::ingest_job( 5, self::HEADER, 'preview' );

		$this->assertFalse( $out['ok'] );
		$this->assertContains( 'recruitment_csv_empty', $out['errors'] );
	}

	public function test_ingest_job_rejects_when_notice_has_no_adjutancies(): void {
		$this->wire_repos( $this->notice_stub( 'draft' ), array() );

		$out = RecruitmentCsvImporter::ingest_job( 5, self::HEADER . 'A,12345678901,,a@b.test,mat,1,90,Sim', 'preview' );

		$this->assertFalse( $out['ok'] );
		$this->assertContains( 'recruitment_notice_has_no_adjutancies', $out['errors'] );
	}

	public function test_ingest_job_inserts_job_and_staging_rows(): void {
		$this->wire_repos( $this->notice_stub( 'draft' ) );

		// cleanup_stale_staging_jobs: two DELETE queries (query()).
		// Then the job-row insert and the staging mass-insert query().
		$this->wpdb->shouldReceive( 'query' )->andReturn( 0 )->byDefault();
		$this->wpdb->shouldReceive( 'insert' )->once()->andReturn( 1 );

		$out = RecruitmentCsvImporter::ingest_job(
			5,
			self::HEADER . "Alice,12345678901,,a@b.test,mat,1,90,Sim\nBob,98765432100,,b@b.test,mat,2,80,Não",
			'preview'
		);

		$this->assertTrue( $out['ok'] );
		$this->assertSame( 'job-uuid-1234', $out['job_id'] );
		$this->assertSame( 2, $out['total'] );
	}

	public function test_ingest_job_returns_error_when_job_insert_fails(): void {
		$this->wire_repos( $this->notice_stub( 'draft' ) );

		$this->wpdb->shouldReceive( 'query' )->andReturn( 0 )->byDefault();
		$this->wpdb->shouldReceive( 'insert' )->once()->andReturn( false );

		$out = RecruitmentCsvImporter::ingest_job( 5, self::HEADER . 'A,12345678901,,a@b.test,mat,1,90,Sim', 'preview' );

		$this->assertFalse( $out['ok'] );
		$this->assertContains( 'recruitment_import_job_insert_failed', $out['errors'] );
	}

	public function test_ingest_job_rolls_back_when_staging_insert_fails(): void {
		$this->wire_repos( $this->notice_stub( 'draft' ) );

		$this->wpdb->shouldReceive( 'insert' )->once()->andReturn( 1 );
		// cleanup DELETEs (2 query()) succeed; the staging INSERT query() fails.
		$this->wpdb->shouldReceive( 'query' )->andReturn( 0, 0, false );
		$this->wpdb->shouldReceive( 'delete' )->twice()->andReturn( 1 );

		$out = RecruitmentCsvImporter::ingest_job( 5, self::HEADER . 'A,12345678901,,a@b.test,mat,1,90,Sim', 'preview' );

		$this->assertFalse( $out['ok'] );
		$this->assertContains( 'recruitment_import_staging_insert_failed', $out['errors'] );
	}

	// ==================================================================
	// validate_job()
	// ==================================================================

	public function test_validate_job_rejects_unknown_job(): void {
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( null );

		$out = RecruitmentCsvImporter::validate_job( 'missing' );

		$this->assertFalse( $out['ok'] );
		$this->assertContains( 'recruitment_import_job_not_found', $out['errors'] );
	}

	public function test_validate_job_rejects_invalid_state(): void {
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn(
			(object) array(
				'job_id' => 'j1',
				'status' => 'committed',
			)
		);

		$out = RecruitmentCsvImporter::validate_job( 'j1' );

		$this->assertFalse( $out['ok'] );
		$this->assertContains( 'recruitment_import_job_invalid_state_for_validate', $out['errors'] );
	}

	public function test_validate_job_marks_validated_when_no_errors(): void {
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn(
			(object) array(
				'job_id' => 'j1',
				'status' => 'ingested',
			)
		);
		// Rules 1 + 2 use get_col (empty), rules 3 / 4 / 5 use get_results (empty).
		$this->wpdb->shouldReceive( 'get_col' )->andReturn( array() );
		$this->wpdb->shouldReceive( 'get_results' )->andReturn( array() );

		$updated = null;
		$this->wpdb->shouldReceive( 'update' )->once()->andReturnUsing(
			function ( $table, $data ) use ( &$updated ) {
				$updated = $data['status'];
				return 1;
			}
		);

		$out = RecruitmentCsvImporter::validate_job( 'j1' );

		$this->assertTrue( $out['ok'] );
		$this->assertSame( array(), $out['errors'] );
		$this->assertSame( 'validated', $updated );
	}

	public function test_validate_job_collects_errors_and_marks_invalid(): void {
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn(
			(object) array(
				'job_id' => 'j1',
				'status' => 'ingested',
			)
		);

		// Rule 1 (missing cpf/rf) returns line 3; rule 2 (missing adjutancy) empty.
		$this->wpdb->shouldReceive( 'get_col' )->andReturn( array( 3 ), array() );
		// Rule 3 (adjutancy_not_in_notice), then three duplicate passes, then divergence.
		$this->wpdb->shouldReceive( 'get_results' )->andReturn(
			array( (object) array( 'line_no' => 4, 'adjutancy_slug' => 'bogus' ) ), // rule 3
			array( (object) array( 'csv_lines' => '5,6' ) ),                        // rule 4 cpf dup
			array(),                                                                // rule 4 rf
			array(),                                                                // rule 4 email
			array(                                                                  // rule 5 divergence
				(object) array(
					'csv_lines' => '7,8',
					'd_name'    => 2,
					'd_email'   => 1,
					'd_rf'      => 1,
					'd_phone'   => 1,
					'd_pcd'     => 1,
				),
			)
		);

		$updated = null;
		$this->wpdb->shouldReceive( 'update' )->once()->andReturnUsing(
			function ( $table, $data ) use ( &$updated ) {
				$updated = $data['status'];
				return 1;
			}
		);

		$out = RecruitmentCsvImporter::validate_job( 'j1' );

		$this->assertTrue( $out['ok'] );
		$this->assertSame( 'invalid', $updated );
		$joined = implode( ' | ', $out['errors'] );
		$this->assertStringContainsString( 'recruitment_csv_missing_cpf_or_rf', $joined );
		$this->assertStringContainsString( 'recruitment_csv_adjutancy_not_in_notice: bogus', $joined );
		$this->assertStringContainsString( 'duplicate_candidate_adjutancy: matches line 5', $joined );
		$this->assertStringContainsString( 'candidate_field_divergence: field=name, ref_line=7', $joined );
	}

	// ==================================================================
	// promote_batch()
	// ==================================================================

	public function test_promote_batch_rejects_unknown_job(): void {
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( null );

		$out = RecruitmentCsvImporter::promote_batch( 'missing', 50 );

		$this->assertFalse( $out['ok'] );
		$this->assertContains( 'recruitment_import_job_not_found', $out['errors'] );
	}

	public function test_promote_batch_rejects_invalid_state(): void {
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn(
			(object) array(
				'job_id'          => 'j1',
				'status'          => 'ingested',
				'processed_count' => '0',
				'total'           => '2',
			)
		);

		$out = RecruitmentCsvImporter::promote_batch( 'j1', 50 );

		$this->assertFalse( $out['ok'] );
		$this->assertContains( 'recruitment_import_job_invalid_state_for_promote', $out['errors'] );
	}

	public function test_promote_batch_returns_done_when_no_unprocessed_rows(): void {
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn(
			(object) array(
				'job_id'          => 'j1',
				'status'          => 'promoting',
				'processed_count' => '2',
				'total'           => '2',
			)
		);
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( array() );

		$out = RecruitmentCsvImporter::promote_batch( 'j1', 50 );

		$this->assertTrue( $out['ok'] );
		$this->assertTrue( $out['done'] );
		$this->assertSame( 2, $out['processed'] );
		$this->assertSame( 2, $out['total'] );
	}

	public function test_promote_batch_processes_rows_and_flips_state(): void {
		// upsert_candidate path → no existing candidate, then create.
		$cand_repo = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentCandidateRepository' );
		$cand_repo->shouldReceive( 'get_by_cpf_hash' )->andReturn( null );
		$cand_repo->shouldReceive( 'get_by_rf_hash' )->andReturn( null );
		$cand_repo->shouldReceive( 'create' )->andReturn( 100 );
		$cand_repo->shouldReceive( 'get_table_name' )->andReturn( 'wp_ffc_recruitment_candidate' );
		$cand_repo->shouldReceive( 'set_user_id' )->andReturn( true );

		$enc = Mockery::mock( 'alias:FreeFormCertificate\Core\Encryption' );
		$enc->shouldReceive( 'hash' )->andReturnUsing( static fn ( $v ) => 'h:' . $v );
		$enc->shouldReceive( 'encrypt' )->andReturnUsing( static fn ( $v ) => 'e:' . $v );

		$pcd = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentPcdHasher' );
		$pcd->shouldReceive( 'compute' )->andReturn( 'pcd-hash' );

		$logger = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentActivityLogger' );
		$logger->shouldReceive( 'candidate_promoted' )->andReturn( null );

		// UserCreator returns 0 (no promotion) so maybe_promote stays quiet.
		$user_creator = Mockery::mock( 'alias:FreeFormCertificate\UserDashboard\UserCreator' );
		$user_creator->shouldReceive( 'get_or_create_user_dual' )->andReturn( 0 );

		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn(
			(object) array(
				'job_id'          => 'j1',
				'status'          => 'validated',
				'processed_count' => '0',
				'total'           => '1',
			)
		);
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn(
			array(
				(object) array(
					'id'             => '11',
					'name'           => 'Alice',
					'cpf_normalized' => '12345678901',
					'rf_normalized'  => '',
					'email'          => 'a@b.test',
					'phone'          => '',
					'pcd'            => '0',
				),
			)
		);
		// update() called for: refresh_pcd_hash (candidate), staging row,
		// the 'validated'→'promoting' flip. query() bumps processed_count.
		$this->wpdb->shouldReceive( 'update' )->andReturn( 1 );
		$this->wpdb->shouldReceive( 'query' )->andReturn( 1 );

		$out = RecruitmentCsvImporter::promote_batch( 'j1', 50 );

		$this->assertTrue( $out['ok'] );
		$this->assertSame( 1, $out['processed'] );
		$this->assertSame( 1, $out['total'] );
		$this->assertTrue( $out['done'] );
	}

	// ==================================================================
	// commit_job()
	// ==================================================================

	public function test_commit_job_rejects_unknown_job(): void {
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( null );

		$out = RecruitmentCsvImporter::commit_job( 'missing' );

		$this->assertFalse( $out['ok'] );
		$this->assertContains( 'recruitment_import_job_not_found', $out['errors'] );
	}

	public function test_commit_job_rejects_invalid_state(): void {
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn(
			(object) array(
				'job_id'    => 'j1',
				'status'    => 'validated',
				'notice_id' => '5',
				'list_type' => 'preview',
			)
		);

		$out = RecruitmentCsvImporter::commit_job( 'j1' );

		$this->assertFalse( $out['ok'] );
		$this->assertContains( 'recruitment_import_job_invalid_state_for_commit', $out['errors'] );
	}

	public function test_commit_job_rejects_when_rows_unpromoted(): void {
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn(
			(object) array(
				'job_id'    => 'j1',
				'status'    => 'promoting',
				'notice_id' => '5',
				'list_type' => 'preview',
			)
		);
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( 3 );

		$out = RecruitmentCsvImporter::commit_job( 'j1' );

		$this->assertFalse( $out['ok'] );
		$this->assertContains( 'recruitment_import_job_not_finished', $out['errors'] );
	}

	public function test_commit_job_swaps_staging_into_classifications(): void {
		$cls_repo = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentClassificationRepository' );
		$cls_repo->shouldReceive( 'delete_all_for_notice_list' )->andReturn( 0 );

		$logger = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentActivityLogger' );
		$logger->shouldReceive( 'csv_imported' )->andReturn( null );

		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn(
			(object) array(
				'job_id'    => 'j1',
				'status'    => 'promoting',
				'notice_id' => '5',
				'list_type' => 'preview',
			)
		);
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( 0 );
		// START TRANSACTION, the INSERT..SELECT, COMMIT.
		$this->wpdb->shouldReceive( 'query' )->andReturn( 1, 4, 1 );
		$this->wpdb->shouldReceive( 'update' )->once()->andReturn( 1 );
		$this->wpdb->shouldReceive( 'delete' )->twice()->andReturn( 1 );

		$out = RecruitmentCsvImporter::commit_job( 'j1' );

		$this->assertTrue( $out['ok'] );
		$this->assertSame( 4, $out['inserted'] );
	}

	public function test_commit_job_rolls_back_on_swap_failure(): void {
		$cls_repo = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentClassificationRepository' );
		$cls_repo->shouldReceive( 'delete_all_for_notice_list' )->andReturn( 0 );

		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn(
			(object) array(
				'job_id'    => 'j1',
				'status'    => 'promoting',
				'notice_id' => '5',
				'list_type' => 'preview',
			)
		);
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( 0 );
		// START TRANSACTION (1), INSERT..SELECT fails (false), ROLLBACK (0).
		$this->wpdb->shouldReceive( 'query' )->andReturn( 1, false, 0 );

		$out = RecruitmentCsvImporter::commit_job( 'j1' );

		$this->assertFalse( $out['ok'] );
		$this->assertContains( 'recruitment_import_swap_failed', $out['errors'] );
	}

	// ==================================================================
	// import_definitive()
	// ==================================================================

	public function test_import_definitive_rejects_unknown_notice(): void {
		$this->wire_repos( null );

		$out = RecruitmentCsvImporter::import_definitive( 999, self::HEADER . 'A,12345678901,,a@b.test,mat,1,90,Sim' );

		$this->assertFalse( $out['success'] );
		$this->assertContains( 'recruitment_notice_not_found', $out['errors'] );
	}

	// ==================================================================
	// run() — single-request happy path + rollback (via import_definitive)
	// ==================================================================

	/**
	 * Wire the candidate / classification / encryption / pcd / logger /
	 * user-creator statics shared by the single-request run() tests.
	 *
	 * @param int|false $classification_id Return of ClassificationRepository::create.
	 * @return void
	 */
	private function wire_run_writers( $classification_id = 200 ): void {
		$cand_repo = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentCandidateRepository' );
		$cand_repo->shouldReceive( 'get_by_cpf_hash' )->andReturn( null );
		$cand_repo->shouldReceive( 'get_by_rf_hash' )->andReturn( null );
		$cand_repo->shouldReceive( 'create' )->andReturn( 100 );
		$cand_repo->shouldReceive( 'get_table_name' )->andReturn( 'wp_ffc_recruitment_candidate' );
		$cand_repo->shouldReceive( 'set_user_id' )->andReturn( true );

		$cls_repo = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentClassificationRepository' );
		$cls_repo->shouldReceive( 'delete_all_for_notice_list' )->andReturn( 0 );
		$cls_repo->shouldReceive( 'create' )->andReturn( $classification_id );

		$enc = Mockery::mock( 'alias:FreeFormCertificate\Core\Encryption' );
		$enc->shouldReceive( 'hash' )->andReturnUsing( static fn ( $v ) => 'h:' . $v );
		$enc->shouldReceive( 'encrypt' )->andReturnUsing( static fn ( $v ) => 'e:' . $v );

		$pcd = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentPcdHasher' );
		$pcd->shouldReceive( 'compute' )->andReturn( 'pcd-hash' );

		$logger = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentActivityLogger' );
		$logger->shouldReceive( 'csv_imported' )->andReturn( null );
		$logger->shouldReceive( 'candidate_promoted' )->andReturn( null );

		$user_creator = Mockery::mock( 'alias:FreeFormCertificate\UserDashboard\UserCreator' );
		$user_creator->shouldReceive( 'get_or_create_user_dual' )->andReturn( 0 );
	}

	public function test_import_definitive_commits_rows_on_happy_path(): void {
		$this->wire_repos( $this->notice_stub( 'definitive' ) );
		$this->wire_run_writers( 200 );

		// START TRANSACTION, COMMIT — both query(). refresh_pcd_hash uses update().
		$this->wpdb->shouldReceive( 'query' )->andReturn( 0 );
		$this->wpdb->shouldReceive( 'update' )->andReturn( 1 );

		$out = RecruitmentCsvImporter::import_definitive(
			5,
			self::HEADER . "Alice,12345678901,,a@b.test,mat,1,90,Sim\nBob,98765432100,,b@b.test,mat,2,80,Não"
		);

		$this->assertTrue( $out['success'] );
		$this->assertSame( 2, $out['inserted'] );
		$this->assertSame( array(), $out['errors'] );
	}

	public function test_import_definitive_rolls_back_when_classification_insert_fails(): void {
		$this->wire_repos( $this->notice_stub( 'definitive' ) );
		// ClassificationRepository::create returns false → rollback.
		$this->wire_run_writers( false );

		$this->wpdb->shouldReceive( 'query' )->andReturn( 0 );
		$this->wpdb->shouldReceive( 'update' )->andReturn( 1 );

		$out = RecruitmentCsvImporter::import_definitive(
			5,
			self::HEADER . 'Alice,12345678901,,a@b.test,mat,1,90,Sim'
		);

		$this->assertFalse( $out['success'] );
		$this->assertContains( 'recruitment_classification_insert_failed', $out['errors'] );
	}

	public function test_import_definitive_propagates_validation_errors(): void {
		$this->wire_repos( $this->notice_stub( 'definitive' ) );

		// Row references an adjutancy slug not attached to the notice.
		$out = RecruitmentCsvImporter::import_definitive(
			5,
			self::HEADER . 'Alice,12345678901,,a@b.test,nope,1,90,Sim'
		);

		$this->assertFalse( $out['success'] );
		$joined = implode( ' | ', $out['errors'] );
		$this->assertStringContainsString( 'recruitment_csv_adjutancy_not_in_notice: nope', $joined );
	}
}
