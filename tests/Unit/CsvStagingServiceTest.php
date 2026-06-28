<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Recruitment\CsvStagingService;

/**
 * Tests for CsvStagingService — the four-phase batched CSV import
 * (ingest → validate → promote → commit) extracted from RecruitmentCsvImporter
 * (#563). Collaborators (notice reader, CSV parser, candidate persister,
 * validator, classification repo, activity logger) are alias-mocked; $wpdb is a
 * partial mock. Covers each phase's happy path plus its guard/error branches.
 *
 * @covers \FreeFormCertificate\Recruitment\CsvStagingService
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class CsvStagingServiceTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var \Mockery\MockInterface */
	private $wpdb;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		class_exists( '\\FreeFormCertificate\\Recruitment\\CsvStagingService' );

		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' )->makePartial();
		$wpdb->prefix = 'wp_';
		// prepare() is a passthrough — return the SQL untouched.
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function () {
				return (string) ( func_get_args()[0] ?? '' );
			}
		)->byDefault();
		// Loose defaults; individual tests override what they assert on.
		$wpdb->shouldReceive( 'query' )->andReturn( 0 )->byDefault();
		$wpdb->shouldReceive( 'insert' )->andReturn( 1 )->byDefault();
		$wpdb->shouldReceive( 'update' )->andReturn( 1 )->byDefault();
		$wpdb->shouldReceive( 'delete' )->andReturn( 1 )->byDefault();
		$wpdb->shouldReceive( 'get_row' )->andReturn( null )->byDefault();
		$wpdb->shouldReceive( 'get_col' )->andReturn( array() )->byDefault();
		$wpdb->shouldReceive( 'get_results' )->andReturn( array() )->byDefault();
		$wpdb->shouldReceive( 'get_var' )->andReturn( 0 )->byDefault();
		$this->wpdb = $wpdb;

		Functions\when( 'current_time' )->justReturn( '2026-06-28 12:00:00' );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'wp_generate_uuid4' )->justReturn( 'job-uuid-1' );
		Functions\when( '__' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** Build a notice row. */
	private function notice( string $status = 'draft' ): object {
		return (object) array(
			'id'     => 5,
			'status' => $status,
		);
	}

	/** Alias-mock the notice reader to return $notice for get_by_id. */
	private function mock_notice_reader( ?object $notice ): void {
		Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentNoticeReader' )
			->shouldReceive( 'get_by_id' )->andReturn( $notice );
	}

	/** Alias-mock CsvParser for the ingest path. */
	private function mock_parser( array $parse_return ): void {
		$m = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\CsvParser' );
		$m->shouldReceive( 'parse' )->andReturn( $parse_return );
		$m->shouldReceive( 'normalise_id' )->andReturnUsing(
			static function ( $v ) {
				return array( 'value' => preg_replace( '/\D/', '', (string) $v ) );
			}
		);
		$m->shouldReceive( 'parse_pcd_flag' )->andReturnUsing(
			static function ( $v ) {
				return '1' === (string) $v || 'sim' === strtolower( (string) $v );
			}
		);
	}

	/** Alias-mock CandidatePersister. */
	private function mock_persister( array $adjutancy_map, $upsert = 100 ): void {
		$m = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\CandidatePersister' );
		$m->shouldReceive( 'build_adjutancy_map' )->andReturn( $adjutancy_map );
		$m->shouldReceive( 'upsert_candidate' )->andReturn( $upsert );
	}

	private function mock_validator(): void {
		Mockery::mock( 'alias:FreeFormCertificate\Recruitment\CsvValidator' )
			->shouldReceive( 'line_error' )->andReturnUsing(
				static function ( $line, $code ) {
					return "line {$line}: {$code}";
				}
			);
	}

	private function valid_row(): array {
		return array(
			'_line'       => 2,
			'name'        => 'Alice',
			'cpf'         => '123.456.789-01',
			'rf'          => '',
			'email'       => 'ALICE@test.com',
			'phone'       => '11999',
			'adjutancy'   => 'math',
			'rank'        => '1',
			'score'       => '9.5',
			'time_points' => '3',
			'hab_emebs'   => '0',
			'pcd'         => '0',
		);
	}

	// ───────────────────────── ingest_job ─────────────────────────

	public function test_ingest_returns_error_when_notice_missing(): void {
		$this->mock_notice_reader( null );
		$r = CsvStagingService::ingest_job( 5, 'csv', 'preview' );
		$this->assertFalse( $r['ok'] );
		$this->assertContains( 'recruitment_notice_not_found', $r['errors'] );
	}

	public function test_ingest_rejects_preview_in_wrong_state(): void {
		$this->mock_notice_reader( $this->notice( 'definitive' ) );
		$r = CsvStagingService::ingest_job( 5, 'csv', 'preview' );
		$this->assertFalse( $r['ok'] );
		$this->assertContains( 'recruitment_invalid_state_for_preview_import', $r['errors'] );
	}

	public function test_ingest_propagates_parse_errors(): void {
		$this->mock_notice_reader( $this->notice() );
		$this->mock_parser( array( 'ok' => false, 'errors' => array( 'bad_header' ) ) );
		$r = CsvStagingService::ingest_job( 5, 'csv', 'preview' );
		$this->assertFalse( $r['ok'] );
		$this->assertSame( array( 'bad_header' ), $r['errors'] );
	}

	public function test_ingest_rejects_empty_rows(): void {
		$this->mock_notice_reader( $this->notice() );
		$this->mock_parser( array( 'ok' => true, 'rows' => array() ) );
		$r = CsvStagingService::ingest_job( 5, 'csv', 'preview' );
		$this->assertFalse( $r['ok'] );
		$this->assertContains( 'recruitment_csv_empty', $r['errors'] );
	}

	public function test_ingest_rejects_notice_without_adjutancies(): void {
		$this->mock_notice_reader( $this->notice() );
		$this->mock_parser( array( 'ok' => true, 'rows' => array( $this->valid_row() ) ) );
		$this->mock_persister( array() );
		$r = CsvStagingService::ingest_job( 5, 'csv', 'preview' );
		$this->assertFalse( $r['ok'] );
		$this->assertContains( 'recruitment_notice_has_no_adjutancies', $r['errors'] );
	}

	public function test_ingest_returns_error_when_job_insert_fails(): void {
		$this->mock_notice_reader( $this->notice() );
		$this->mock_parser( array( 'ok' => true, 'rows' => array( $this->valid_row() ) ) );
		$this->mock_persister( array( 'math' => 10 ) );
		$this->wpdb->shouldReceive( 'insert' )->andReturn( false );
		$r = CsvStagingService::ingest_job( 5, 'csv', 'preview' );
		$this->assertFalse( $r['ok'] );
		$this->assertContains( 'recruitment_import_job_insert_failed', $r['errors'] );
	}

	public function test_ingest_rolls_back_when_staging_insert_fails(): void {
		$this->mock_notice_reader( $this->notice() );
		$this->mock_parser( array( 'ok' => true, 'rows' => array( $this->valid_row() ) ) );
		$this->mock_persister( array( 'math' => 10 ) );
		$this->wpdb->shouldReceive( 'insert' )->andReturn( 1 );
		// Staging INSERT goes through query(); make it fail.
		$this->wpdb->shouldReceive( 'query' )->andReturn( false );
		$this->wpdb->shouldReceive( 'delete' )->andReturn( 1 );
		$r = CsvStagingService::ingest_job( 5, 'csv', 'preview' );
		$this->assertFalse( $r['ok'] );
		$this->assertContains( 'recruitment_import_staging_insert_failed', $r['errors'] );
	}

	public function test_ingest_happy_path_returns_job_id_and_total(): void {
		$this->mock_notice_reader( $this->notice( 'preliminary' ) );
		$this->mock_parser(
			array(
				'ok'   => true,
				'rows' => array( $this->valid_row(), $this->valid_row() ),
			)
		);
		$this->mock_persister( array( 'math' => 10 ) );
		$this->wpdb->shouldReceive( 'insert' )->andReturn( 1 );
		$this->wpdb->shouldReceive( 'query' )->andReturn( 1 );
		$r = CsvStagingService::ingest_job( 5, 'csv', 'preview' );
		$this->assertTrue( $r['ok'] );
		$this->assertSame( 'job-uuid-1', $r['job_id'] );
		$this->assertSame( 2, $r['total'] );
	}

	// ───────────────────────── validate_job ─────────────────────────

	public function test_validate_returns_error_when_job_missing(): void {
		$this->wpdb->shouldReceive( 'get_row' )->andReturn( null );
		$r = CsvStagingService::validate_job( 'job-uuid-1' );
		$this->assertFalse( $r['ok'] );
		$this->assertContains( 'recruitment_import_job_not_found', $r['errors'] );
	}

	public function test_validate_rejects_invalid_state(): void {
		$this->wpdb->shouldReceive( 'get_row' )->andReturn( (object) array( 'status' => 'committed' ) );
		$r = CsvStagingService::validate_job( 'job-uuid-1' );
		$this->assertFalse( $r['ok'] );
		$this->assertContains( 'recruitment_import_job_invalid_state_for_validate', $r['errors'] );
	}

	public function test_validate_marks_validated_when_no_errors(): void {
		$this->wpdb->shouldReceive( 'get_row' )->andReturn( (object) array( 'status' => 'ingested' ) );
		// All rule queries return empty → no errors.
		$this->mock_validator();
		$this->wpdb->shouldReceive( 'update' )->andReturn( 1 );
		$r = CsvStagingService::validate_job( 'job-uuid-1' );
		$this->assertTrue( $r['ok'] );
		$this->assertSame( array(), $r['errors'] );
	}

	public function test_validate_collects_errors_and_marks_invalid(): void {
		$this->wpdb->shouldReceive( 'get_row' )->andReturn( (object) array( 'status' => 'ingested' ) );
		$this->mock_validator();
		// Rule 1 (get_col) returns an offending line; rule 2 empty.
		$this->wpdb->shouldReceive( 'get_col' )->andReturn( array( 4 ), array() );
		// Rule 3/4/5 (get_results) — provide a divergence group for rule 5.
		$this->wpdb->shouldReceive( 'get_results' )->andReturn(
			array(),                                          // rule 3
			array(),                                          // rule 4 cpf
			array(),                                          // rule 4 rf
			array(),                                          // rule 4 email
			array(                                            // rule 5 divergence
				(object) array(
					'csv_lines' => '2,3',
					'd_name'    => 2,
					'd_email'   => 1,
					'd_rf'      => 1,
					'd_phone'   => 1,
					'd_pcd'     => 1,
				),
			)
		);
		$this->wpdb->shouldReceive( 'update' )->andReturn( 1 );
		$r = CsvStagingService::validate_job( 'job-uuid-1' );
		$this->assertTrue( $r['ok'] );
		$this->assertNotEmpty( $r['errors'] );
	}

	// ───────────────────────── promote_batch ─────────────────────────

	public function test_promote_returns_error_when_job_missing(): void {
		$this->wpdb->shouldReceive( 'get_row' )->andReturn( null );
		$r = CsvStagingService::promote_batch( 'job-uuid-1', 50 );
		$this->assertFalse( $r['ok'] );
		$this->assertContains( 'recruitment_import_job_not_found', $r['errors'] );
	}

	public function test_promote_rejects_invalid_state(): void {
		$this->wpdb->shouldReceive( 'get_row' )->andReturn( (object) array( 'status' => 'ingested' ) );
		$r = CsvStagingService::promote_batch( 'job-uuid-1', 50 );
		$this->assertFalse( $r['ok'] );
		$this->assertContains( 'recruitment_import_job_invalid_state_for_promote', $r['errors'] );
	}

	public function test_promote_empty_batch_reports_done(): void {
		$this->wpdb->shouldReceive( 'get_row' )->andReturn(
			(object) array(
				'status'          => 'validated',
				'processed_count' => 3,
				'total'           => 3,
			)
		);
		$this->wpdb->shouldReceive( 'get_results' )->andReturn( array() );
		$r = CsvStagingService::promote_batch( 'job-uuid-1', 50 );
		$this->assertTrue( $r['ok'] );
		$this->assertTrue( $r['done'] );
		$this->assertSame( 3, $r['processed'] );
	}

	public function test_promote_happy_path_processes_rows(): void {
		$this->wpdb->shouldReceive( 'get_row' )->andReturn(
			(object) array(
				'status'          => 'validated',
				'processed_count' => 0,
				'total'           => 2,
			)
		);
		$this->wpdb->shouldReceive( 'get_results' )->andReturn(
			array(
				(object) array(
					'id'             => 1,
					'name'           => 'A',
					'cpf_normalized' => '111',
					'rf_normalized'  => '',
					'email'          => 'a@x.com',
					'phone'          => '1',
					'pcd'            => 0,
				),
				(object) array(
					'id'             => 2,
					'name'           => 'B',
					'cpf_normalized' => '222',
					'rf_normalized'  => '',
					'email'          => 'b@x.com',
					'phone'          => '2',
					'pcd'            => 1,
				),
			)
		);
		$this->mock_persister( array(), 100 );
		$this->wpdb->shouldReceive( 'update' )->andReturn( 1 );
		$this->wpdb->shouldReceive( 'query' )->andReturn( 1 );
		$r = CsvStagingService::promote_batch( 'job-uuid-1', 50 );
		$this->assertTrue( $r['ok'] );
		$this->assertSame( 2, $r['processed'] );
		$this->assertTrue( $r['done'] );
	}

	public function test_promote_returns_error_when_upsert_fails(): void {
		$this->wpdb->shouldReceive( 'get_row' )->andReturn(
			(object) array(
				'status'          => 'promoting',
				'processed_count' => 0,
				'total'           => 1,
			)
		);
		$this->wpdb->shouldReceive( 'get_results' )->andReturn(
			array(
				(object) array(
					'id'             => 1,
					'name'           => 'A',
					'cpf_normalized' => '111',
					'rf_normalized'  => '',
					'email'          => 'a@x.com',
					'phone'          => '1',
					'pcd'            => 0,
				),
			)
		);
		$this->mock_persister( array(), false );
		$r = CsvStagingService::promote_batch( 'job-uuid-1', 50 );
		$this->assertFalse( $r['ok'] );
		$this->assertContains( 'recruitment_candidate_upsert_failed', $r['errors'] );
	}

	// ───────────────────────── commit_job ─────────────────────────

	public function test_commit_returns_error_when_job_missing(): void {
		$this->wpdb->shouldReceive( 'get_row' )->andReturn( null );
		$r = CsvStagingService::commit_job( 'job-uuid-1' );
		$this->assertFalse( $r['ok'] );
		$this->assertContains( 'recruitment_import_job_not_found', $r['errors'] );
	}

	public function test_commit_rejects_invalid_state(): void {
		$this->wpdb->shouldReceive( 'get_row' )->andReturn( (object) array( 'status' => 'validated' ) );
		$r = CsvStagingService::commit_job( 'job-uuid-1' );
		$this->assertFalse( $r['ok'] );
		$this->assertContains( 'recruitment_import_job_invalid_state_for_commit', $r['errors'] );
	}

	public function test_commit_rejects_unfinished_promotion(): void {
		$this->wpdb->shouldReceive( 'get_row' )->andReturn(
			(object) array(
				'status'    => 'promoting',
				'notice_id' => 5,
				'list_type' => 'preview',
			)
		);
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( 2 ); // 2 unpromoted rows
		$r = CsvStagingService::commit_job( 'job-uuid-1' );
		$this->assertFalse( $r['ok'] );
		$this->assertContains( 'recruitment_import_job_not_finished', $r['errors'] );
	}

	public function test_commit_happy_path_swaps_and_cleans_up(): void {
		$this->wpdb->shouldReceive( 'get_row' )->andReturn(
			(object) array(
				'status'    => 'promoting',
				'notice_id' => 5,
				'list_type' => 'preview',
			)
		);
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( 0 );
		Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentClassificationRepository' )
			->shouldReceive( 'delete_all_for_notice_list' )->andReturn( 0 );
		Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentActivityLogger' )
			->shouldReceive( 'csv_imported' )->andReturnNull();
		// START TRANSACTION, the INSERT…SELECT (returns rows), COMMIT.
		$this->wpdb->shouldReceive( 'query' )->andReturn( 1 );
		$this->wpdb->shouldReceive( 'update' )->andReturn( 1 );
		$this->wpdb->shouldReceive( 'delete' )->andReturn( 1 );
		$r = CsvStagingService::commit_job( 'job-uuid-1' );
		$this->assertTrue( $r['ok'] );
		$this->assertArrayHasKey( 'inserted', $r );
	}

	public function test_commit_rolls_back_when_swap_fails(): void {
		$this->wpdb->shouldReceive( 'get_row' )->andReturn(
			(object) array(
				'status'    => 'promoting',
				'notice_id' => 5,
				'list_type' => 'preview',
			)
		);
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( 0 );
		Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentClassificationRepository' )
			->shouldReceive( 'delete_all_for_notice_list' )->andReturn( 0 );
		// START TRANSACTION → 0, INSERT…SELECT → false (fail) → ROLLBACK.
		$this->wpdb->shouldReceive( 'query' )->andReturn( 0, false, 0 );
		$r = CsvStagingService::commit_job( 'job-uuid-1' );
		$this->assertFalse( $r['ok'] );
		$this->assertContains( 'recruitment_import_swap_failed', $r['errors'] );
	}
}
