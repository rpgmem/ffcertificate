<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Recruitment\CsvParser;
use FreeFormCertificate\Recruitment\CsvStagingService;
use FreeFormCertificate\Tests\Support\ActivityLogBuffer;
use FreeFormCertificate\Tests\Support\Identifiers;

/**
 * Tests for CsvStagingService — the four-phase batched CSV import
 * (ingest → validate → promote → commit) extracted from RecruitmentCsvImporter
 * (#563). Covers each phase's happy path plus its guard/error branches.
 *
 * Runs the real collaborators — RecruitmentNoticeReader, CsvParser (over real
 * CSV text), CandidatePersister with the adjutancy readers, the candidate
 * Reader/Writer, Encryption (with the bootstrap's key constants),
 * RecruitmentPcdHasher and UserCreator, CsvValidator,
 * RecruitmentClassificationRepository and RecruitmentActivityLogger down to
 * the real ActivityLog write buffer — over a `$wpdb` double that answers each
 * statement by its SQL text and bound values from the state a test sets,
 * records every write, and fails the test on a statement it was not taught.
 *
 * @covers \FreeFormCertificate\Recruitment\CsvStagingService
 */
class CsvStagingServiceTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const JOBS = 'wp_ffc_recruitment_import_jobs';

	private const STAGING = 'wp_ffc_recruitment_import_staging';

	private const CANDIDATES = 'wp_ffc_recruitment_candidate';

	private const CLASSIFICATION = 'wp_ffc_recruitment_classification';

	private const JOB_ID = 'job-uuid-1';

	/** @var Mockery\MockInterface */
	private $wpdb;

	/** @var mixed The `$wpdb` global before the test, restored in tearDown(). */
	private $previous_wpdb;

	/** Whether `$wpdb` was set before the test. */
	private bool $had_wpdb = false;

	/** @var list<array{0: string, 1: array<int, mixed>}> Every prepared statement: [ sql, args ]. */
	private array $prepared = array();

	/** @var array<int, object> Stored notices by id. */
	private array $notices = array();

	/** @var array<int, list<int>> Adjutancy ids attached to each notice. */
	private array $notice_adjutancies = array();

	/** @var array<int, object> Stored adjutancies by id. */
	private array $adjutancies = array();

	/** @var array<string, object> Stored import jobs by job_id. */
	private array $jobs = array();

	/** @var array<string, list<mixed>> What each validation rule's query answers, by rule. */
	private array $rule_answers = array();

	/** @var list<object> The unprocessed staging rows a promote batch reads. */
	private array $batch = array();

	/** Unprocessed staging rows the commit guard counts. */
	private int $unpromoted = 0;

	/** @var array<string, int|false> What `insert()` answers, by table (default 1). */
	private array $insert_results = array();

	/** @var int|false What the staging multi-VALUES INSERT answers. */
	private $staging_insert_result = 1;

	/** @var int|false What the classification INSERT … SELECT answers. */
	private $swap_result = 2;

	/** Next id `insert()` publishes as `insert_id`. */
	private int $next_insert_id = 100;

	/** @var list<array{table: string, data: array<string, mixed>}> Every insert. */
	private array $inserts = array();

	/** @var list<array{table: string, data: array<string, mixed>, where: array<string, mixed>}> Every update. */
	private array $updates = array();

	/** @var list<array{table: string, where: array<string, mixed>}> Every delete. */
	private array $deletes = array();

	/** @var list<array{0: string, 1: array<int, mixed>}> Every query() statement: [ sql, args ]. */
	private array $queries = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->had_wpdb      = array_key_exists( 'wpdb', $GLOBALS );
		$this->previous_wpdb = $GLOBALS['wpdb'] ?? null;

		ActivityLogBuffer::reset();
		ActivityLogBuffer::stub_functions();
		Functions\when( 'get_option' )->alias(
			static fn( $key, $fallback = false ) => 'ffc_settings' === $key ? ActivityLogBuffer::ENABLED_SETTINGS : $fallback
		);
		Functions\when( 'current_time' )->justReturn( '2026-06-28 12:00:00' );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'wp_generate_uuid4' )->justReturn( self::JOB_ID );
		Functions\when( '__' )->returnArg();
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'wp_salt' )->justReturn( 'test-salt' );

		// Account creation fails, which UserCreator reports as a WP_Error and
		// the persister swallows: the candidate stays unpromoted.
		Functions\when( 'get_user_by' )->justReturn( false );
		Functions\when( 'username_exists' )->justReturn( false );
		Functions\when( 'remove_accents' )->returnArg();
		Functions\when( 'sanitize_user' )->returnArg();
		Functions\when( 'wp_generate_password' )->justReturn( 'generated-password' );
		Functions\when( 'wp_create_user' )->justReturn( new \WP_Error( 'existing_user_login', 'refused' ) );

		$this->wpdb         = Mockery::mock( 'wpdb' );
		$this->wpdb->prefix = 'wp_';
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( $sql, ...$args ) {
				if ( 1 === count( $args ) && is_array( $args[0] ) ) {
					$args = $args[0];
				}
				$this->prepared[] = array( (string) $sql, array_values( $args ) );
				return 'Q' . ( count( $this->prepared ) - 1 );
			}
		);
		$this->wpdb->shouldReceive( 'get_row' )->andReturnUsing( fn( $query ) => $this->answerRow( $query ) );
		$this->wpdb->shouldReceive( 'get_col' )->andReturnUsing( fn( $query ) => $this->answerCol( $query ) );
		$this->wpdb->shouldReceive( 'get_results' )->andReturnUsing( fn( $query ) => $this->answerResults( $query ) );
		$this->wpdb->shouldReceive( 'get_var' )->andReturnUsing( fn( $query ) => $this->answerVar( $query ) );
		$this->wpdb->shouldReceive( 'query' )->andReturnUsing( fn( $query ) => $this->answerQuery( $query ) );
		$this->wpdb->shouldReceive( 'insert' )->andReturnUsing(
			function ( $table, $data ) {
				$this->inserts[] = array(
					'table' => $table,
					'data'  => $data,
				);
				$result = $this->insert_results[ $table ] ?? 1;
				if ( false !== $result ) {
					$this->wpdb->insert_id = $this->next_insert_id++;
				}
				return $result;
			}
		);
		$this->wpdb->shouldReceive( 'update' )->andReturnUsing(
			function ( $table, $data, $where ) {
				$this->updates[] = array(
					'table' => $table,
					'data'  => $data,
					'where' => $where,
				);
				return 1;
			}
		);
		$this->wpdb->shouldReceive( 'delete' )->andReturnUsing(
			function ( $table, $where ) {
				$this->deletes[] = array(
					'table' => $table,
					'where' => $where,
				);
				return 1;
			}
		);

		$GLOBALS['wpdb'] = $this->wpdb;
	}

	protected function tearDown(): void {
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->previous_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		ActivityLogBuffer::reset();
		Monkey\tearDown();
		parent::tearDown();
	}

	// ───────────────────────── the $wpdb double ─────────────────────────

	/**
	 * The SQL and bound values a statement stands for: a prepared token, or a
	 * raw statement such as START TRANSACTION.
	 *
	 * @param mixed $query Token returned by prepare(), or raw SQL.
	 * @return array{0: string, 1: array<int, mixed>}
	 */
	private function statement( $query ): array {
		$query = (string) $query;
		if ( 1 === preg_match( '/^Q(\d+)$/', $query, $m ) ) {
			$this->assertArrayHasKey( (int) $m[1], $this->prepared );
			return $this->prepared[ (int) $m[1] ];
		}
		return array( $query, array() );
	}

	/** @param mixed $query Statement. */
	private function answerRow( $query ) {
		[ $sql, $args ] = $this->statement( $query );
		if ( 'SELECT * FROM %i WHERE id = %d' === $sql && 'wp_ffc_recruitment_notice' === $args[0] ) {
			return $this->notices[ $args[1] ] ?? null;
		}
		if ( 'wp_ffc_recruitment_adjutancy' === ( $args[0] ?? null ) && false !== strpos( $sql, 'WHERE id = %d' ) ) {
			return $this->adjutancies[ $args[1] ] ?? null;
		}
		if ( false !== strpos( $sql, 'FROM ' . self::JOBS . ' WHERE job_id = %s' ) ) {
			return $this->jobs[ $args[0] ] ?? null;
		}
		if ( self::CANDIDATES === ( $args[0] ?? null ) && false !== strpos( $sql, '_hash = %s LIMIT 1' ) ) {
			return null;
		}
		if ( 'wp_ffc_user_profiles' === ( $args[0] ?? null ) ) {
			return null;
		}
		$this->fail( 'Unexpected get_row: ' . $sql );
	}

	/** @param mixed $query Statement. */
	private function answerCol( $query ) {
		[ $sql, $args ] = $this->statement( $query );
		if ( 'wp_ffc_recruitment_notice_adjutancy' === ( $args[0] ?? null ) && false !== strpos( $sql, 'WHERE notice_id = %d' ) ) {
			return array_map( 'strval', $this->notice_adjutancies[ $args[1] ] ?? array() );
		}
		if ( false !== strpos( $sql, "cpf_normalized = '' AND rf_normalized = ''" ) ) {
			return $this->rule_answers['missing_id'] ?? array();
		}
		if ( false !== strpos( $sql, "adjutancy_slug = ''" ) ) {
			return $this->rule_answers['missing_adjutancy'] ?? array();
		}
		$this->fail( 'Unexpected get_col: ' . $sql );
	}

	/** @param mixed $query Statement. */
	private function answerResults( $query ) {
		[ $sql, $args ] = $this->statement( $query );
		if ( false !== strpos( $sql, 'AND adjutancy_id = 0' ) ) {
			return $this->rule_answers['not_in_notice'] ?? array();
		}
		if ( 1 === preg_match( '/GROUP BY (\w+), adjutancy_id HAVING COUNT\(\*\) > 1/', $sql, $m ) ) {
			return $this->rule_answers[ 'duplicate_' . $m[1] ] ?? array();
		}
		if ( false !== strpos( $sql, 'COUNT(DISTINCT name)' ) ) {
			return $this->rule_answers['divergence'] ?? array();
		}
		if ( 'SELECT line_no, cpf_normalized, rf_normalized, email, name FROM %i WHERE job_id = %s ORDER BY row_no' === $sql ) {
			return $this->rule_answers['staged'] ?? array();
		}
		if ( false !== strpos( $sql, 'SELECT id, cpf_hash, rf_hash, email_hash, name FROM %i WHERE %i IN' ) ) {
			return $this->rule_answers[ 'stored_' . $args[1] ] ?? array();
		}
		if ( false !== strpos( $sql, 'FROM ' . self::STAGING . ' WHERE job_id = %s AND processed = 0 ORDER BY row_no LIMIT %d' ) ) {
			return $this->batch;
		}
		if ( 'SELECT * FROM %i WHERE notice_id = %d AND list_type = %s ORDER BY `rank` ASC, candidate_id ASC' === $sql ) {
			// The list a commit replaces (#2245): empty, so no comparison waits.
			return $this->rule_answers['stored_list'] ?? array();
		}
		$this->fail( 'Unexpected get_results: ' . $sql );
	}

	/** @param mixed $query Statement. */
	private function answerVar( $query ) {
		[ $sql ] = $this->statement( $query );
		if ( false !== strpos( $sql, 'SELECT COUNT(*) FROM ' . self::STAGING . ' WHERE job_id = %s AND processed = 0' ) ) {
			return (string) $this->unpromoted;
		}
		if ( false !== strpos( $sql, 'SELECT user_id FROM %i WHERE (' ) || false !== strpos( $sql, 'SHOW TABLES LIKE' ) ) {
			return null;
		}
		$this->fail( 'Unexpected get_var: ' . $sql );
	}

	/** @param mixed $query Statement. */
	private function answerQuery( $query ) {
		$statement       = $this->statement( $query );
		$this->queries[] = $statement;
		[ $sql, $args ]  = $statement;
		if ( in_array( $sql, array( 'START TRANSACTION', 'COMMIT', 'ROLLBACK' ), true ) ) {
			return 0;
		}
		if ( 0 === strpos( $sql, 'DELETE s FROM ' . self::STAGING ) || 0 === strpos( $sql, 'DELETE FROM ' . self::JOBS . ' WHERE created_at' ) ) {
			return 0;
		}
		if ( 0 === strpos( $sql, 'INSERT INTO ' . self::STAGING ) ) {
			return $this->staging_insert_result;
		}
		if ( 0 === strpos( $sql, 'UPDATE ' . self::JOBS . ' SET processed_count = processed_count + %d' ) ) {
			return 1;
		}
		if ( 0 === strpos( $sql, 'INSERT INTO %i' ) && self::CLASSIFICATION === ( $args[0] ?? null ) ) {
			return $this->swap_result;
		}
		$this->fail( 'Unexpected query: ' . $sql );
	}

	// ───────────────────────── fixtures and readers ─────────────────────────

	/** Store notice 5 with a status. */
	private function storeNotice( string $status = 'draft' ): void {
		$this->notices[5] = (object) array(
			'id'     => 5,
			'status' => $status,
		);
	}

	/** Attach the `math` adjutancy (id 10) to notice 5. */
	private function attachMathAdjutancy(): void {
		$this->notice_adjutancies[5] = array( 10 );
		$this->adjutancies[10]       = (object) array(
			'id'   => 10,
			'slug' => 'math',
		);
	}

	/** Store the job under test with a status and optional extra columns. */
	private function storeJob( string $status, array $extra = array() ): void {
		$this->jobs[ self::JOB_ID ] = (object) array_merge(
			array(
				'job_id' => self::JOB_ID,
				'status' => $status,
			),
			$extra
		);
	}

	/** CPF_A written the way a spreadsheet exports it, with dots and a dash. */
	private function formattedCpf(): string {
		$cpf = Identifiers::CPF_A;
		return substr( $cpf, 0, 3 ) . '.' . substr( $cpf, 3, 3 ) . '.' . substr( $cpf, 6, 3 ) . '-' . substr( $cpf, 9 );
	}

	/** The CSV header line the parser requires (plus the optional columns). */
	private function header(): string {
		return "name,cpf,rf,email,phone,adjutancy,rank,score,time_points,hab_emebs,pcd\n";
	}

	/** A CSV with `$count` copies of one valid row. */
	private function csv( int $count ): string {
		return $this->header() . str_repeat( 'Alice,' . $this->formattedCpf() . ",,ALICE@test.com,11999,math,1,9.5,3,0,0\n", $count );
	}

	/** One staging row a promote batch reads. */
	private function stagedRow( int $id, string $cpf, int $pcd ): object {
		return (object) array(
			'id'             => $id,
			'name'           => 'Person ' . $id,
			'cpf_normalized' => $cpf,
			'rf_normalized'  => '',
			'email'          => 'person' . $id . '@example.com',
			'phone'          => (string) $id,
			'pcd'            => $pcd,
		);
	}

	/**
	 * Writes sent to one table.
	 *
	 * @param list<array<string, mixed>> $writes Recorded writes.
	 * @param string                     $table  Table.
	 * @return list<array<string, mixed>>
	 */
	private function to( array $writes, string $table ): array {
		return array_values( array_filter( $writes, static fn( array $w ): bool => $table === $w['table'] ) );
	}

	/**
	 * The raw query() statements, SQL only, in order.
	 *
	 * @return list<string>
	 */
	private function querySql(): array {
		return array_map( static fn( array $q ): string => $q[0], $this->queries );
	}

	// ───────────────────────── ingest_job ─────────────────────────

	public function test_ingest_returns_error_when_notice_missing(): void {
		$r = CsvStagingService::ingest_job( 5, $this->csv( 1 ), 'preview' );
		$this->assertFalse( $r['ok'] );
		$this->assertContains( 'recruitment_notice_not_found', $r['errors'] );
		$this->assertSame( array(), $this->inserts );
	}

	public function test_ingest_rejects_preview_in_wrong_state(): void {
		$this->storeNotice( 'definitive' );
		$r = CsvStagingService::ingest_job( 5, $this->csv( 1 ), 'preview' );
		$this->assertFalse( $r['ok'] );
		$this->assertContains( 'recruitment_invalid_state_for_preview_import', $r['errors'] );
		$this->assertSame( array(), $this->inserts );
	}

	public function test_ingest_propagates_parse_errors(): void {
		$this->storeNotice();
		// A header without the required columns: the parser refuses it.
		$csv      = "foo,bar\n1,2\n";
		$expected = CsvParser::parse( $csv )['errors'];
		$this->assertNotEmpty( $expected );
		$this->assertStringStartsWith( 'recruitment_csv_missing_headers', $expected[0] );

		$r = CsvStagingService::ingest_job( 5, $csv, 'preview' );
		$this->assertFalse( $r['ok'] );
		$this->assertSame( $expected, $r['errors'], 'The parser errors pass through unchanged.' );
	}

	public function test_ingest_rejects_empty_rows(): void {
		$this->storeNotice();
		// The header alone parses cleanly into zero rows.
		$r = CsvStagingService::ingest_job( 5, $this->header(), 'preview' );
		$this->assertFalse( $r['ok'] );
		$this->assertContains( 'recruitment_csv_empty', $r['errors'] );
	}

	public function test_ingest_rejects_notice_without_adjutancies(): void {
		$this->storeNotice();
		$r = CsvStagingService::ingest_job( 5, $this->csv( 1 ), 'preview' );
		$this->assertFalse( $r['ok'] );
		$this->assertContains( 'recruitment_notice_has_no_adjutancies', $r['errors'] );
		$this->assertSame( array(), $this->inserts );
	}

	public function test_ingest_returns_error_when_job_insert_fails(): void {
		$this->storeNotice();
		$this->attachMathAdjutancy();
		$this->insert_results[ self::JOBS ] = false;
		$r                                  = CsvStagingService::ingest_job( 5, $this->csv( 1 ), 'preview' );
		$this->assertFalse( $r['ok'] );
		$this->assertContains( 'recruitment_import_job_insert_failed', $r['errors'] );
		$this->assertCount( 1, $this->to( $this->inserts, self::JOBS ) );
		$this->assertSame( array(), preg_grep( '/^INSERT INTO ' . self::STAGING . '/', $this->querySql() ), 'Nothing is staged without a job.' );
	}

	public function test_ingest_rolls_back_when_staging_insert_fails(): void {
		$this->storeNotice();
		$this->attachMathAdjutancy();
		$this->staging_insert_result = false;
		$r                           = CsvStagingService::ingest_job( 5, $this->csv( 1 ), 'preview' );
		$this->assertFalse( $r['ok'] );
		$this->assertContains( 'recruitment_import_staging_insert_failed', $r['errors'] );
		// The job row and any staged rows are removed again.
		$this->assertSame(
			array(
				array(
					'table' => self::JOBS,
					'where' => array( 'job_id' => self::JOB_ID ),
				),
				array(
					'table' => self::STAGING,
					'where' => array( 'job_id' => self::JOB_ID ),
				),
			),
			$this->deletes
		);
	}

	public function test_ingest_refuses_an_invalid_row_before_staging_anything(): void {
		$this->storeNotice( 'preliminary' );
		$this->attachMathAdjutancy();
		$csv = $this->header() . 'Alice,' . $this->formattedCpf() . ",,ALICE@test.com,11999,math,first,nine,3,0,0\n";

		$r = CsvStagingService::ingest_job( 5, $csv, 'preview' );

		$this->assertFalse( $r['ok'] );
		$this->assertSame(
			array(
				'line=2: recruitment_csv_score_invalid_format: "nine"',
				'line=2: recruitment_csv_rank_invalid: "first"',
			),
			array_slice( $r['errors'], 0, 2 ),
			'Every problem of the line is named, with its value, and nothing is written.'
		);
		$this->assertSame( array(), $this->to( $this->inserts, self::JOBS ), 'No job, no staging.' );
	}

	public function test_ingest_happy_path_returns_job_id_and_total(): void {
		$this->storeNotice( 'preliminary' );
		$this->attachMathAdjutancy();
		// Two different people: the same row twice is a duplicate the
		// validator now refuses before staging (#2245).
		$csv = $this->header()
			. 'Alice,' . $this->formattedCpf() . ",,ALICE@test.com,11999,math,1,9.5,3,0,0\n"
			. 'Bia,' . Identifiers::CPF_B . ",,bia@test.com,11998,math,2,9.0,3,0,0\n";
		$r   = CsvStagingService::ingest_job( 5, $csv, 'preview' );
		$this->assertTrue( $r['ok'] );
		$this->assertSame( self::JOB_ID, $r['job_id'] );
		$this->assertSame( 2, $r['total'] );

		$jobs = $this->to( $this->inserts, self::JOBS );
		$this->assertCount( 1, $jobs );
		$this->assertSame( 'ingested', $jobs[0]['data']['status'] );
		$this->assertSame( 2, $jobs[0]['data']['total'] );
		$this->assertSame( 7, $jobs[0]['data']['user_id'] );

		// One multi-VALUES statement for both rows, after the stale-job sweep.
		$staged = array_values(
			array_filter(
				$this->queries,
				static fn( array $q ): bool => 0 === strpos( $q[0], 'INSERT INTO ' . self::STAGING )
			)
		);
		$this->assertCount( 1, $staged );
		$values = $staged[0][1];
		$this->assertCount( 32, $values, 'Sixteen columns per row, two rows.' );
		// First row: job, row_no, line, notice, name, cpf, rf, email, phone, slug, adjutancy_id.
		$this->assertSame(
			array( self::JOB_ID, 1, 2, 5, 'Alice', Identifiers::CPF_A, '', 'alice@test.com', '11999', 'math', 10 ),
			array_slice( $values, 0, 11 )
		);
		$this->assertSame( 2, $values[17], 'The second row is numbered 2.' );
	}

	// ───────────────────────── validate_job ─────────────────────────

	public function test_validate_returns_error_when_job_missing(): void {
		$r = CsvStagingService::validate_job( self::JOB_ID );
		$this->assertFalse( $r['ok'] );
		$this->assertContains( 'recruitment_import_job_not_found', $r['errors'] );
	}

	public function test_validate_rejects_invalid_state(): void {
		$this->storeJob( 'committed' );
		$r = CsvStagingService::validate_job( self::JOB_ID );
		$this->assertFalse( $r['ok'] );
		$this->assertContains( 'recruitment_import_job_invalid_state_for_validate', $r['errors'] );
		$this->assertSame( array(), $this->updates );
	}

	public function test_validate_marks_validated_when_no_errors(): void {
		$this->storeJob( 'ingested' );
		// All rule queries return empty → no errors.
		$r = CsvStagingService::validate_job( self::JOB_ID );
		$this->assertTrue( $r['ok'] );
		$this->assertSame( array(), $r['errors'] );

		$updates = $this->to( $this->updates, self::JOBS );
		$this->assertCount( 1, $updates );
		$this->assertSame( 'validated', $updates[0]['data']['status'] );
		$this->assertSame( array( 'job_id' => self::JOB_ID ), $updates[0]['where'] );
	}

	public function test_validate_collects_errors_and_marks_invalid(): void {
		$this->storeJob( 'ingested' );
		// Rule 1 reports an offending line; rule 5 a divergence group on name.
		$this->rule_answers['missing_id'] = array( 4 );
		$this->rule_answers['divergence'] = array(
			(object) array(
				'csv_lines' => '2,3',
				'd_name'    => 2,
				'd_email'   => 1,
				'd_rf'      => 1,
				'd_phone'   => 1,
				'd_pcd'     => 1,
			),
		);
		$r = CsvStagingService::validate_job( self::JOB_ID );
		$this->assertTrue( $r['ok'] );
		$this->assertSame(
			array(
				'line=4: recruitment_csv_missing_cpf_or_rf',
				'line=3: recruitment_csv_candidate_field_divergence: field=name, ref_line=2',
			),
			$r['errors']
		);

		$updates = $this->to( $this->updates, self::JOBS );
		$this->assertCount( 1, $updates );
		$this->assertSame( 'invalid', $updates[0]['data']['status'] );
	}

	// ───────────────────────── promote_batch ─────────────────────────

	/**
	 * A staged row as the identity check reads it.
	 *
	 * @param int    $line Line number.
	 * @param string $cpf  CPF.
	 * @param string $rf   RF.
	 * @param string $name Name.
	 */
	private function stagedIdentity( int $line, string $cpf, string $rf, string $name ): object {
		return (object) array(
			'line_no'        => (string) $line,
			'cpf_normalized' => $cpf,
			'rf_normalized'  => $rf,
			'email'          => '',
			'name'           => $name,
		);
	}

	public function test_validate_parks_the_job_when_a_row_differs_from_a_stored_candidate(): void {
		$this->storeJob( 'ingested' );
		$cpf_hash                              = (string) \FreeFormCertificate\Core\SensitiveFieldRegistry::hash_identifier( 'cpf', Identifiers::CPF_A );
		$this->rule_answers['staged']          = array( $this->stagedIdentity( 2, Identifiers::CPF_A, '', 'Somebody Else' ) );
		$this->rule_answers['stored_cpf_hash'] = array(
			(object) array(
				'id'         => '9',
				'cpf_hash'   => $cpf_hash,
				'rf_hash'    => null,
				'email_hash' => null,
				'name'       => 'Ana Souza',
			),
		);

		$r = CsvStagingService::validate_job( self::JOB_ID );

		$this->assertTrue( $r['ok'] );
		$this->assertSame( array(), $r['errors'] );
		$this->assertSame(
			array(
				array(
					'line'         => 2,
					'candidate_id' => 9,
					'fields'       => array( 'name' ),
				),
			),
			$r['mismatches']
		);
		$this->assertSame( 'needs_ack', $this->to( $this->updates, self::JOBS )[0]['data']['status'] );
	}

	public function test_validate_refuses_a_cpf_and_an_rf_of_two_candidates(): void {
		$this->storeJob( 'ingested' );
		$cpf_hash                              = (string) \FreeFormCertificate\Core\SensitiveFieldRegistry::hash_identifier( 'cpf', Identifiers::CPF_A );
		$rf_hash                               = (string) \FreeFormCertificate\Core\SensitiveFieldRegistry::hash_identifier( 'rf', Identifiers::RF_A );
		$this->rule_answers['staged']          = array( $this->stagedIdentity( 3, Identifiers::CPF_A, Identifiers::RF_A, 'Ana' ) );
		$this->rule_answers['stored_cpf_hash'] = array(
			(object) array(
				'id'         => '9',
				'cpf_hash'   => $cpf_hash,
				'rf_hash'    => null,
				'email_hash' => null,
				'name'       => 'Ana',
			),
		);
		$this->rule_answers['stored_rf_hash']  = array(
			(object) array(
				'id'         => '10',
				'cpf_hash'   => null,
				'rf_hash'    => $rf_hash,
				'email_hash' => null,
				'name'       => 'Bia',
			),
		);

		$r = CsvStagingService::validate_job( self::JOB_ID );

		$this->assertSame( array( 'line=3: recruitment_csv_identity_conflict' ), $r['errors'] );
		$this->assertSame( 'invalid', $this->to( $this->updates, self::JOBS )[0]['data']['status'] );
	}

	public function test_promote_refuses_a_parked_job_until_the_operator_acknowledges(): void {
		$this->storeJob(
			'needs_ack',
			array(
				'processed_count' => 0,
				'total'           => 1,
			)
		);
		$this->batch = array( $this->stagedRow( 1, Identifiers::CPF_A, 0 ) );

		$refused = CsvStagingService::promote_batch( self::JOB_ID, 50 );
		$this->assertFalse( $refused['ok'] );
		$this->assertSame( array( 'recruitment_import_identity_mismatch' ), $refused['errors'] );
		$this->assertSame( array(), $this->inserts, 'Nothing is written before the acknowledgement.' );

		$accepted = CsvStagingService::promote_batch( self::JOB_ID, 50, true );
		$this->assertTrue( $accepted['ok'] );
		$this->assertSame( 'promoting', $this->to( $this->updates, self::JOBS )[0]['data']['status'] );
	}

	public function test_promote_returns_error_when_job_missing(): void {
		$r = CsvStagingService::promote_batch( self::JOB_ID, 50 );
		$this->assertFalse( $r['ok'] );
		$this->assertContains( 'recruitment_import_job_not_found', $r['errors'] );
	}

	public function test_promote_rejects_invalid_state(): void {
		$this->storeJob( 'ingested' );
		$r = CsvStagingService::promote_batch( self::JOB_ID, 50 );
		$this->assertFalse( $r['ok'] );
		$this->assertContains( 'recruitment_import_job_invalid_state_for_promote', $r['errors'] );
	}

	public function test_promote_empty_batch_reports_done(): void {
		$this->storeJob(
			'validated',
			array(
				'processed_count' => 3,
				'total'           => 3,
			)
		);
		$r = CsvStagingService::promote_batch( self::JOB_ID, 50 );
		$this->assertTrue( $r['ok'] );
		$this->assertTrue( $r['done'] );
		$this->assertSame( 3, $r['processed'] );
		$this->assertSame( array(), $this->updates, 'An empty batch changes nothing.' );
	}

	public function test_promote_happy_path_processes_rows(): void {
		$this->storeJob(
			'validated',
			array(
				'processed_count' => 0,
				'total'           => 2,
			)
		);
		$this->batch = array(
			$this->stagedRow( 1, Identifiers::CPF_A, 0 ),
			$this->stagedRow( 2, Identifiers::CPF_B, 1 ),
		);
		$r           = CsvStagingService::promote_batch( self::JOB_ID, 50 );
		$this->assertTrue( $r['ok'] );
		$this->assertSame( 2, $r['processed'] );
		$this->assertTrue( $r['done'] );

		// The first non-empty batch moves the job to `promoting`.
		$job_updates = $this->to( $this->updates, self::JOBS );
		$this->assertCount( 1, $job_updates );
		$this->assertSame( 'promoting', $job_updates[0]['data']['status'] );

		// Each row became a candidate, and the staging row records which.
		$this->assertCount( 2, $this->to( $this->inserts, self::CANDIDATES ) );
		$staging = $this->to( $this->updates, self::STAGING );
		$this->assertSame(
			array(
				array(
					'data'  => array(
						'candidate_id' => 100,
						'processed'    => 1,
					),
					'where' => array( 'id' => 1 ),
				),
				array(
					'data'  => array(
						'candidate_id' => 101,
						'processed'    => 1,
					),
					'where' => array( 'id' => 2 ),
				),
			),
			array_map(
				static fn( array $u ): array => array(
					'data'  => $u['data'],
					'where' => $u['where'],
				),
				$staging
			)
		);

		// The job counter moves by the batch size in one statement.
		$bumps = array_values(
			array_filter(
				$this->queries,
				static fn( array $q ): bool => 0 === strpos( $q[0], 'UPDATE ' . self::JOBS . ' SET processed_count' )
			)
		);
		$this->assertCount( 1, $bumps );
		$this->assertSame( 2, $bumps[0][1][0] );
	}

	public function test_promote_returns_error_when_upsert_fails(): void {
		$this->storeJob(
			'promoting',
			array(
				'processed_count' => 0,
				'total'           => 1,
			)
		);
		$this->batch = array( $this->stagedRow( 1, Identifiers::CPF_A, 0 ) );
		// The candidate INSERT fails, which upsert_candidate() reports as false.
		$this->insert_results[ self::CANDIDATES ] = false;
		$r                                        = CsvStagingService::promote_batch( self::JOB_ID, 50 );
		$this->assertFalse( $r['ok'] );
		$this->assertContains( 'recruitment_candidate_upsert_failed', $r['errors'] );
		$this->assertSame( array(), $this->to( $this->updates, self::STAGING ), 'No staging row is marked processed.' );
	}

	// ───────────────────────── commit_job ─────────────────────────

	public function test_commit_returns_error_when_job_missing(): void {
		$r = CsvStagingService::commit_job( self::JOB_ID );
		$this->assertFalse( $r['ok'] );
		$this->assertContains( 'recruitment_import_job_not_found', $r['errors'] );
	}

	public function test_commit_rejects_invalid_state(): void {
		$this->storeJob( 'validated' );
		$r = CsvStagingService::commit_job( self::JOB_ID );
		$this->assertFalse( $r['ok'] );
		$this->assertContains( 'recruitment_import_job_invalid_state_for_commit', $r['errors'] );
	}

	public function test_commit_rejects_unfinished_promotion(): void {
		$this->storeJob(
			'promoting',
			array(
				'notice_id' => 5,
				'list_type' => 'preview',
			)
		);
		$this->unpromoted = 2;
		$r                = CsvStagingService::commit_job( self::JOB_ID );
		$this->assertFalse( $r['ok'] );
		$this->assertContains( 'recruitment_import_job_not_finished', $r['errors'] );
		$this->assertSame( array(), $this->queries, 'No transaction is opened.' );
	}

	public function test_commit_happy_path_swaps_and_cleans_up(): void {
		$this->storeJob(
			'promoting',
			array(
				'notice_id' => 5,
				'list_type' => 'preview',
			)
		);
		$this->swap_result = 3;
		$r                 = CsvStagingService::commit_job( self::JOB_ID );
		$this->assertTrue( $r['ok'] );
		$this->assertSame( 3, $r['inserted'] );

		// START TRANSACTION, the INSERT…SELECT, COMMIT.
		$sql = $this->querySql();
		$this->assertCount( 3, $sql );
		$this->assertSame( 'START TRANSACTION', $sql[0] );
		$this->assertStringStartsWith( 'INSERT INTO %i', $sql[1] );
		$this->assertSame( 'COMMIT', $sql[2] );

		// The live list is wiped, the job committed, then staging and job reaped.
		$this->assertSame(
			array(
				array(
					'table' => self::CLASSIFICATION,
					'where' => array(
						'notice_id' => 5,
						'list_type' => 'preview',
					),
				),
				array(
					'table' => self::STAGING,
					'where' => array( 'job_id' => self::JOB_ID ),
				),
				array(
					'table' => self::JOBS,
					'where' => array( 'job_id' => self::JOB_ID ),
				),
			),
			$this->deletes
		);
		$job_updates = $this->to( $this->updates, self::JOBS );
		$this->assertCount( 1, $job_updates );
		$this->assertSame( 'committed', $job_updates[0]['data']['status'] );

		$logged = array_values(
			array_filter(
				ActivityLogBuffer::contexts(),
				static fn( array $e ): bool => 'recruitment_csv_imported' === $e['action']
			)
		);
		$this->assertCount( 1, $logged );
		$this->assertSame(
			array(
				'notice_id'      => 5,
				'list_type'      => 'preview',
				'inserted_count' => 3,
			),
			$logged[0]['context']
		);
	}

	public function test_commit_rolls_back_when_swap_fails(): void {
		$this->storeJob(
			'promoting',
			array(
				'notice_id' => 5,
				'list_type' => 'preview',
			)
		);
		// START TRANSACTION → 0, INSERT…SELECT → false (fail) → ROLLBACK.
		$this->swap_result = false;
		$r                 = CsvStagingService::commit_job( self::JOB_ID );
		$this->assertFalse( $r['ok'] );
		$this->assertContains( 'recruitment_import_swap_failed', $r['errors'] );

		$sql = $this->querySql();
		$this->assertSame( 'ROLLBACK', end( $sql ) );
		$this->assertNotContains( 'COMMIT', $sql );
		$this->assertSame( array(), $this->to( $this->updates, self::JOBS ), 'The job is not marked committed.' );
		$this->assertSame( array(), $this->to( $this->deletes, self::STAGING ), 'Staging is kept for a retry.' );
		$this->assertSame( array(), ActivityLogBuffer::contexts(), 'No import is logged.' );
	}
}
