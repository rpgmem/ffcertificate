<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\Encryption;
use FreeFormCertificate\Recruitment\RecruitmentCsvImporter;
use FreeFormCertificate\Recruitment\RecruitmentPcdHasher;
use FreeFormCertificate\Tests\Support\Identifiers;

/**
 * Coverage for the staging-based batched import flow (V10) of
 * RecruitmentCsvImporter: ingest_job → validate_job → promote_batch →
 * commit_job, plus the import_definitive entry point.
 *
 * Every collaborator is the real class -- the notice, adjutancy, candidate and
 * classification repositories, CandidatePersister, CsvValidator, Encryption
 * (with the bootstrap's key constants), RecruitmentPcdHasher, the activity
 * logger (switched off through its own setting) and UserCreator down to a
 * stubbed `wp_create_user()`. They run against a `$wpdb` double that answers
 * each statement by its SQL text and table, records every write, and reports
 * any statement no test arranged.
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentCsvImporter
 */
class RecruitmentCsvImporterBatchedTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface */
	private $wpdb;

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	/**
	 * Answers the double gives, in registration order: method, pattern, answer
	 * (a value or a callable taking the SQL / table and the call arguments) and
	 * how many calls it may still answer (null for any number).
	 *
	 * @var list<array{0: string, 1: string, 2: mixed, 3: int|null}>
	 */
	private array $rules = array();

	/** @var int Rows of the notice's preliminary list (#2245). */
	private int $preview_rows = 1;

	/** @var list<array{0: string, 1: string, 2: array<int, mixed>}> Every call the double answered: method, SQL or table, arguments. */
	private array $calls = array();

	/** @var list<string> Calls no rule answered. */
	private array $unexpected = array();

	/** @var array<string, int> Next `insert_id` per table, for the inserts the double accepts. */
	private array $next_insert_id = array();

	/** @var list<string> The usernames `wp_create_user()` was asked to create. */
	private array $created_users = array();

	private const HEADER = "name,cpf,rf,email,adjutancy,rank,score,pcd\n";

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $this->had_wpdb ? $GLOBALS['wpdb'] : null;

		$this->rules          = array();
		$this->calls          = array();
		$this->unexpected     = array();
		$this->next_insert_id = array();
		$this->created_users  = array();

		$wpdb            = Mockery::mock( 'wpdb' );
		$wpdb->prefix    = 'wp_';
		$wpdb->insert_id = 0;
		$this->wpdb      = $wpdb;
		$GLOBALS['wpdb'] = $wpdb;

		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'current_time' )->justReturn( '2026-05-01 10:00:00' );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		// `ffc_settings` is empty: the activity log is off and every Debug
		// area is off, whatever an earlier test left behind.
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'wp_generate_uuid4' )->justReturn( 'job-uuid-1234' );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_salt' )->justReturn( 'test-salt' );

		// UserCreator's account path, down to the core call that fails: the
		// candidate is persisted and no account is linked to it.
		Functions\when( 'get_user_by' )->justReturn( false );
		Functions\when( 'username_exists' )->justReturn( false );
		Functions\when( 'remove_accents' )->returnArg();
		Functions\when( 'sanitize_user' )->returnArg();
		Functions\when( 'wp_generate_password' )->justReturn( 'generated-password' );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_single_event' )->justReturn( true );
		$created = &$this->created_users;
		Functions\when( 'wp_create_user' )->alias(
			static function ( $username ) use ( &$created ) {
				$created[] = (string) $username;
				return new \WP_Error( 'existing_user_login', 'refused' );
			}
		);

		$this->wpdb->shouldReceive( 'prepare' )
			->andReturnUsing( array( $this, 'prepare' ) )
			->byDefault();
		foreach ( array( 'get_row', 'get_results', 'get_var', 'get_col', 'query' ) as $method ) {
			$this->wpdb->shouldReceive( $method )
				->andReturnUsing(
					function ( ...$args ) use ( $method ) {
						return $this->answer( $method, (string) $args[0], $args );
					}
				)
				->byDefault();
		}
		// The notice has a preliminary list unless a test says otherwise (#2245).
		$this->on( 'get_var', "/list_type = 'preview'$/", fn() => (string) $this->preview_rows );
		foreach ( array( 'insert', 'update', 'delete' ) as $method ) {
			$this->wpdb->shouldReceive( $method )
				->andReturnUsing(
					function ( ...$args ) use ( $method ) {
						return $this->answer( $method, (string) $args[0], $args );
					}
				)
				->byDefault();
		}
	}

	protected function tearDown(): void {
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A statement no test arranged fails the test.
	 *
	 * It is checked here rather than thrown from the double, because the code
	 * under test wraps its transaction in `catch ( \Throwable )` and would turn
	 * the failure into an ordinary error result.
	 */
	protected function assertPostConditions(): void {
		$this->assertSame( array(), $this->unexpected, 'The $wpdb double received statements no test arranged.' );
	}

	/**
	 * `$wpdb->prepare()` with the placeholders bound, so a rule can match the
	 * values a statement carries.
	 *
	 * @param string $query SQL with placeholders.
	 * @param mixed  ...$args Values, variadic or as one array.
	 * @return string
	 */
	public function prepare( $query, ...$args ): string {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$i = 0;
		return (string) preg_replace_callback(
			'/%[ids]/',
			static function ( array $m ) use ( &$i, $args ): string {
				$value = $args[ $i++ ] ?? '';
				if ( '%i' === $m[0] ) {
					return '`' . $value . '`';
				}
				if ( '%d' === $m[0] ) {
					return (string) (int) $value;
				}
				return "'" . addslashes( (string) $value ) . "'";
			},
			(string) $query
		);
	}

	/**
	 * Answer a call from the first rule that matches it.
	 *
	 * @param string            $method `$wpdb` method.
	 * @param string            $target SQL, or the table for insert / update / delete.
	 * @param array<int, mixed> $args   The call's arguments.
	 * @return mixed
	 */
	private function answer( string $method, string $target, array $args ) {
		$this->calls[] = array( $method, $target, $args );
		foreach ( $this->rules as $i => $rule ) {
			if ( $rule[0] !== $method || 0 === $rule[3] || ! preg_match( $rule[1], $target ) ) {
				continue;
			}
			if ( null !== $rule[3] ) {
				--$this->rules[ $i ][3];
			}
			return is_callable( $rule[2] ) ? ( $rule[2] )( $target, $args ) : $rule[2];
		}
		$this->unexpected[] = $method . ': ' . $target;
		return in_array( $method, array( 'get_results', 'get_col' ), true ) ? array() : null;
	}

	/**
	 * Arrange an answer.
	 *
	 * @param string   $method  `$wpdb` method.
	 * @param string   $pattern Regex over the SQL, or over the table name.
	 * @param mixed    $answer  Value, or callable( $target, $args ).
	 * @param int|null $times   How many calls it answers; null for any number.
	 */
	private function on( string $method, string $pattern, $answer, ?int $times = null ): void {
		$this->rules[] = array( $method, $pattern, $answer, $times );
	}

	/**
	 * Calls the double answered, filtered by method and target.
	 *
	 * @return list<array{0: string, 1: string, 2: array<int, mixed>}>
	 */
	private function calls_to( string $method, string $pattern = '/./' ): array {
		return array_values(
			array_filter(
				$this->calls,
				static fn( array $c ): bool => $c[0] === $method && 1 === preg_match( $pattern, $c[1] )
			)
		);
	}

	/**
	 * Accept inserts into a table, handing out ids from `$first_id` on; a
	 * `false` id makes every insert into that table fail.
	 *
	 * @param string    $table    Table without prefix.
	 * @param int|false $first_id First id.
	 */
	private function accept_inserts( string $table, $first_id ): void {
		$this->next_insert_id[ $table ] = false === $first_id ? 0 : $first_id;
		$wpdb                           = $this->wpdb;
		$this->on(
			'insert',
			'/^wp_' . $table . '$/',
			function () use ( $table, $first_id, $wpdb ) {
				if ( false === $first_id ) {
					return false;
				}
				$wpdb->insert_id = $this->next_insert_id[ $table ]++;
				return 1;
			}
		);
	}

	private function notice_stub( string $status ): object {
		return (object) array(
			'id'        => '5',
			'code'      => 'EDITAL',
			'name'      => 'Edital',
			'status'    => $status,
			'opened_at' => null,
			'closed_at' => null,
		);
	}

	/**
	 * Store notice 5 and the adjutancies attached to it, so the real readers
	 * resolve `mat` → 2 through build_adjutancy_map(). Any other notice id is
	 * absent from the store.
	 *
	 * @param object|null $notice Notice row stored under id 5, or null for none.
	 * @param array<int>  $ids    Adjutancy ids attached to notice 5.
	 * @return void
	 */
	private function store_notice( ?object $notice, array $ids = array( 2 ) ): void {
		$this->on(
			'get_row',
			'/FROM `wp_ffc_recruitment_notice` WHERE id = (\d+)/',
			static function ( string $sql ) use ( $notice ) {
				return ( null !== $notice && 1 === preg_match( '/WHERE id = 5$/', $sql ) ) ? $notice : null;
			}
		);
		$this->on(
			'get_col',
			'/FROM `wp_ffc_recruitment_notice_adjutancy` WHERE notice_id = \d+/',
			static function ( string $sql ) use ( $ids ) {
				return 1 === preg_match( '/notice_id = 5$/', $sql ) ? array_map( 'strval', $ids ) : array();
			}
		);
		$this->on(
			'get_row',
			'/FROM `wp_ffc_recruitment_adjutancy` WHERE id = \d+/',
			static function ( string $sql ) {
				preg_match( '/id = (\d+)$/', $sql, $m );
				return (object) array(
					'id'   => $m[1],
					'slug' => 'mat',
					'name' => 'Mathematics',
				);
			}
		);
	}

	/**
	 * Arrange the candidate writes of CandidatePersister for rows with no
	 * existing candidate: the lookups by hash find nothing, the insert hands
	 * out ids from 100, the pcd-hash refresh succeeds, and UserCreator finds no
	 * account by hash before its creation fails.
	 */
	private function arrange_new_candidates(): void {
		$this->on( 'get_row', '/FROM `wp_ffc_recruitment_candidate` WHERE (cpf|rf)_hash = /', null );
		// The pre-import identity check finds no stored candidate either.
		$this->on( 'get_results', '/^SELECT id, cpf_hash, rf_hash, email_hash, name FROM `wp_ffc_recruitment_candidate` WHERE/', array() );
		$this->accept_inserts( 'ffc_recruitment_candidate', 100 );
		$this->on( 'update', '/^wp_ffc_recruitment_candidate$/', 1 );
		$this->on( 'get_var', '/^SELECT user_id FROM `wp_ffc_(user_profiles|submissions)` WHERE/', null );
	}

	/**
	 * The candidate inserts the double received, as their data arrays.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function candidate_inserts(): array {
		return array_map(
			static fn( array $c ): array => $c[2][1],
			$this->calls_to( 'insert', '/^wp_ffc_recruitment_candidate$/' )
		);
	}

	// ==================================================================
	// ingest_job()
	// ==================================================================

	public function test_ingest_job_rejects_unknown_notice(): void {
		$this->store_notice( $this->notice_stub( 'draft' ) );

		$out = RecruitmentCsvImporter::ingest_job( 999, self::HEADER . 'A,' . Identifiers::CPF_A . ',,a@b.test,mat,1,90,Sim', 'preview' );

		$this->assertFalse( $out['ok'] );
		$this->assertContains( 'recruitment_notice_not_found', $out['errors'] );
		$this->assertSame( array(), $this->calls_to( 'insert' ) );
	}

	public function test_ingest_job_rejects_preview_when_notice_not_eligible(): void {
		$this->store_notice( $this->notice_stub( 'active' ) );

		$out = RecruitmentCsvImporter::ingest_job( 5, self::HEADER . 'A,' . Identifiers::CPF_A . ',,a@b.test,mat,1,90,Sim', 'preview' );

		$this->assertFalse( $out['ok'] );
		$this->assertContains( 'recruitment_invalid_state_for_preview_import', $out['errors'] );
		$this->assertSame( array(), $this->calls_to( 'insert' ) );
	}

	public function test_ingest_job_rejects_parse_errors(): void {
		$this->store_notice( $this->notice_stub( 'draft' ) );

		$out = RecruitmentCsvImporter::ingest_job( 5, 'name,cpf', 'preview' );

		$this->assertFalse( $out['ok'] );
		$this->assertStringContainsString( 'recruitment_csv_missing_headers', $out['errors'][0] );
	}

	public function test_ingest_job_rejects_empty_rows(): void {
		$this->store_notice( $this->notice_stub( 'draft' ) );

		// Header only — no data rows.
		$out = RecruitmentCsvImporter::ingest_job( 5, self::HEADER, 'preview' );

		$this->assertFalse( $out['ok'] );
		$this->assertContains( 'recruitment_csv_empty', $out['errors'] );
	}

	public function test_ingest_job_rejects_when_notice_has_no_adjutancies(): void {
		$this->store_notice( $this->notice_stub( 'draft' ), array() );

		$out = RecruitmentCsvImporter::ingest_job( 5, self::HEADER . 'A,' . Identifiers::CPF_A . ',,a@b.test,mat,1,90,Sim', 'preview' );

		$this->assertFalse( $out['ok'] );
		$this->assertContains( 'recruitment_notice_has_no_adjutancies', $out['errors'] );
		$this->assertSame( array(), $this->calls_to( 'insert' ) );
	}

	public function test_ingest_job_inserts_job_and_staging_rows(): void {
		$this->store_notice( $this->notice_stub( 'draft' ) );

		// cleanup_stale_staging_jobs: two DELETE queries.
		// Then the job-row insert and the staging mass-insert query().
		$this->on( 'query', '/^DELETE /', 0, 2 );
		$this->on( 'insert', '/^wp_ffc_recruitment_import_jobs$/', 1, 1 );
		$this->on( 'query', '/^INSERT INTO wp_ffc_recruitment_import_staging /', 2, 1 );

		$out = RecruitmentCsvImporter::ingest_job(
			5,
			self::HEADER . 'Alice,' . Identifiers::CPF_A . ",,a@b.test,mat,1,90,Sim\nBob," . Identifiers::CPF_B . ',,b@b.test,mat,2,80,Não',
			'preview'
		);

		$this->assertTrue( $out['ok'] );
		$this->assertSame( 'job-uuid-1234', $out['job_id'] );
		$this->assertSame( 2, $out['total'] );

		$job = $this->calls_to( 'insert' )[0][2][1];
		$this->assertSame( 'job-uuid-1234', $job['job_id'] );
		$this->assertSame( 'ingested', $job['status'] );
		$this->assertSame( 2, $job['total'] );

		$staging = $this->calls_to( 'query', '/^INSERT INTO wp_ffc_recruitment_import_staging /' )[0][1];
		$this->assertStringContainsString( "'" . Identifiers::CPF_A . "'", $staging );
		$this->assertStringContainsString( "'" . Identifiers::CPF_B . "'", $staging );
	}

	public function test_ingest_job_returns_error_when_job_insert_fails(): void {
		$this->store_notice( $this->notice_stub( 'draft' ) );

		$this->on( 'query', '/^DELETE /', 0, 2 );
		$this->on( 'insert', '/^wp_ffc_recruitment_import_jobs$/', false, 1 );

		$out = RecruitmentCsvImporter::ingest_job( 5, self::HEADER . 'A,' . Identifiers::CPF_A . ',,a@b.test,mat,1,90,Sim', 'preview' );

		$this->assertFalse( $out['ok'] );
		$this->assertContains( 'recruitment_import_job_insert_failed', $out['errors'] );
		$this->assertSame( array(), $this->calls_to( 'query', '/^INSERT INTO/' ), 'No staging row is written without its job.' );
	}

	public function test_ingest_job_rolls_back_when_staging_insert_fails(): void {
		$this->store_notice( $this->notice_stub( 'draft' ) );

		$this->on( 'insert', '/^wp_ffc_recruitment_import_jobs$/', 1, 1 );
		// cleanup DELETEs (2 query()) succeed; the staging INSERT query() fails.
		$this->on( 'query', '/^DELETE /', 0, 2 );
		$this->on( 'query', '/^INSERT INTO wp_ffc_recruitment_import_staging /', false, 1 );
		$this->on( 'delete', '/^wp_ffc_recruitment_import_(jobs|staging)$/', 1, 2 );

		$out = RecruitmentCsvImporter::ingest_job( 5, self::HEADER . 'A,' . Identifiers::CPF_A . ',,a@b.test,mat,1,90,Sim', 'preview' );

		$this->assertFalse( $out['ok'] );
		$this->assertContains( 'recruitment_import_staging_insert_failed', $out['errors'] );
		$deleted = array_map( static fn( array $c ): array => array( $c[1], $c[2][1] ), $this->calls_to( 'delete' ) );
		$this->assertSame(
			array(
				array( 'wp_ffc_recruitment_import_jobs', array( 'job_id' => 'job-uuid-1234' ) ),
				array( 'wp_ffc_recruitment_import_staging', array( 'job_id' => 'job-uuid-1234' ) ),
			),
			$deleted
		);
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
		// upsert_candidate path → no existing candidate, then create. No
		// account is resolved here any more (#2245): that is the account
		// batch's job, once the notice leaves draft.
		$this->arrange_new_candidates();

		$this->on(
			'get_row',
			"/^SELECT \\* FROM wp_ffc_recruitment_import_jobs WHERE job_id = 'j1'$/",
			(object) array(
				'job_id'          => 'j1',
				'status'          => 'validated',
				'processed_count' => '0',
				'total'           => '1',
			),
			1
		);
		$this->on(
			'get_results',
			"/FROM wp_ffc_recruitment_import_staging WHERE job_id = 'j1' AND processed = 0 ORDER BY row_no LIMIT 50$/",
			array(
				(object) array(
					'id'             => '11',
					'name'           => 'Alice',
					'cpf_normalized' => Identifiers::CPF_A,
					'rf_normalized'  => '',
					'email'          => 'a@b.test',
					'phone'          => '',
					'pcd'            => '0',
				),
			),
			1
		);
		// update() for the 'validated'→'promoting' flip and the staging row;
		// query() bumps processed_count.
		$this->on( 'update', '/^wp_ffc_recruitment_import_(jobs|staging)$/', 1, 2 );
		$this->on( 'query', "/^UPDATE wp_ffc_recruitment_import_jobs SET processed_count = processed_count \\+ 1, .* WHERE job_id = 'j1'$/", 1, 1 );

		$out = RecruitmentCsvImporter::promote_batch( 'j1', 50 );

		$this->assertTrue( $out['ok'] );
		$this->assertSame( 1, $out['processed'] );
		$this->assertSame( 1, $out['total'] );
		$this->assertTrue( $out['done'] );

		$inserts = $this->candidate_inserts();
		$this->assertCount( 1, $inserts );
		$this->assertSame( Encryption::hash( Identifiers::CPF_A ), $inserts[0]['cpf_hash'] );
		$this->assertSame( Identifiers::CPF_A, Encryption::decrypt( (string) $inserts[0]['cpf_encrypted'] ) );

		$jobs = $this->calls_to( 'update', '/^wp_ffc_recruitment_import_jobs$/' );
		$this->assertSame( 'promoting', $jobs[0][2][1]['status'] );

		$staging = $this->calls_to( 'update', '/^wp_ffc_recruitment_import_staging$/' );
		$this->assertSame( array( 'candidate_id' => 100, 'processed' => 1 ), $staging[0][2][1] );
		$this->assertSame( array( 'id' => 11 ), $staging[0][2][2] );

		$candidate_updates = array_map( static fn( array $c ): array => $c[2][1], $this->calls_to( 'update', '/^wp_ffc_recruitment_candidate$/' ) );
		$this->assertCount( 1, $candidate_updates, 'Only the pcd-hash refresh touches the candidate: no user is linked.' );
		$this->assertSame( RecruitmentPcdHasher::compute( 100, false ), $candidate_updates[0]['pcd_hash'] );
		$this->assertSame( array(), $this->created_users, 'The import creates no account (#2245).' );
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

	/**
	 * The job row commit_job() reads, in the 'promoting' state.
	 */
	private function arrange_promoting_job(): void {
		$this->on(
			'get_row',
			"/^SELECT \\* FROM wp_ffc_recruitment_import_jobs WHERE job_id = 'j1'$/",
			(object) array(
				'job_id'    => 'j1',
				'status'    => 'promoting',
				'notice_id' => '5',
				'list_type' => 'preview',
			),
			1
		);
		$this->on( 'get_var', "/^SELECT COUNT\\(\\*\\) FROM wp_ffc_recruitment_import_staging WHERE job_id = 'j1' AND processed = 0$/", 0, 1 );
		// The real ClassificationRepository clears the notice's preview list.
		$this->on( 'delete', '/^wp_ffc_recruitment_classification$/', 0, 1 );
	}

	/**
	 * The plain statements (transaction control) the double received, in order.
	 *
	 * @return list<string>
	 */
	private function transaction_statements(): array {
		return array_map(
			static fn( array $c ): string => $c[1],
			$this->calls_to( 'query', '/^(START TRANSACTION|COMMIT|ROLLBACK)$/' )
		);
	}

	public function test_commit_job_swaps_staging_into_classifications(): void {
		$this->arrange_promoting_job();
		$this->store_notice( $this->notice_stub( 'preliminary' ) );
		$this->on( 'query', '/^(START TRANSACTION|COMMIT)$/', 1 );
		$this->on( 'query', '/^INSERT INTO `wp_ffc_recruitment_classification`/', 4, 1 );
		$this->on( 'update', '/^wp_ffc_recruitment_import_jobs$/', 1, 1 );
		$this->on( 'delete', '/^wp_ffc_recruitment_import_(staging|jobs)$/', 1, 2 );

		$out = RecruitmentCsvImporter::commit_job( 'j1' );

		$this->assertTrue( $out['ok'] );
		$this->assertSame( 4, $out['inserted'] );
		$this->assertSame( array( 'START TRANSACTION', 'COMMIT' ), $this->transaction_statements() );

		$cleared = $this->calls_to( 'delete', '/^wp_ffc_recruitment_classification$/' );
		$this->assertSame( array( 'notice_id' => 5, 'list_type' => 'preview' ), $cleared[0][2][1] );
		$this->assertSame( 'committed', $this->calls_to( 'update' )[0][2][1]['status'] );
		$this->assertSame(
			array( 'wp_ffc_recruitment_import_staging', 'wp_ffc_recruitment_import_jobs' ),
			array_map( static fn( array $c ): string => $c[1], $this->calls_to( 'delete', '/^wp_ffc_recruitment_import_/' ) )
		);
	}

	public function test_commit_job_rolls_back_on_swap_failure(): void {
		$this->arrange_promoting_job();
		// START TRANSACTION (1), INSERT..SELECT fails (false), ROLLBACK (0).
		$this->on( 'query', '/^START TRANSACTION$/', 1, 1 );
		$this->on( 'query', '/^INSERT INTO `wp_ffc_recruitment_classification`/', false, 1 );
		$this->on( 'query', '/^ROLLBACK$/', 0, 1 );

		$out = RecruitmentCsvImporter::commit_job( 'j1' );

		$this->assertFalse( $out['ok'] );
		$this->assertContains( 'recruitment_import_swap_failed', $out['errors'] );
		$this->assertSame( array( 'START TRANSACTION', 'ROLLBACK' ), $this->transaction_statements() );
		$this->assertSame( array(), $this->calls_to( 'update' ), 'A failed swap leaves the job state alone.' );
	}

	// ==================================================================
	// import_definitive()
	// ==================================================================

	public function test_import_definitive_rejects_unknown_notice(): void {
		$this->store_notice( $this->notice_stub( 'definitive' ) );

		$out = RecruitmentCsvImporter::import_definitive( 999, self::HEADER . 'A,' . Identifiers::CPF_A . ',,a@b.test,mat,1,90,Sim' );

		$this->assertFalse( $out['success'] );
		$this->assertContains( 'recruitment_notice_not_found', $out['errors'] );
	}

	// ==================================================================
	// run() — single-request happy path + rollback (via import_definitive)
	// ==================================================================

	/**
	 * Arrange the writes shared by the single-request run() tests: the
	 * transaction statements, the definitive list cleared, the candidates
	 * created, and the classification inserts.
	 *
	 * @param int|false $classification_id First classification id, or false for a failing insert.
	 * @return void
	 */
	private function arrange_run_writes( $classification_id = 200 ): void {
		$this->on( 'query', '/^(START TRANSACTION|COMMIT|ROLLBACK)$/', 0 );
		$this->on( 'delete', '/^wp_ffc_recruitment_classification$/', 0, 1 );
		$this->arrange_new_candidates();
		$this->accept_inserts( 'ffc_recruitment_classification', $classification_id );
	}

	public function test_import_definitive_commits_rows_on_happy_path(): void {
		$this->store_notice( $this->notice_stub( 'preliminary' ) );
		$this->arrange_run_writes( 200 );
		$this->arrange_promotion();

		$out = RecruitmentCsvImporter::import_definitive(
			5,
			self::HEADER . 'Alice,' . Identifiers::CPF_A . ",,a@b.test,mat,1,90,Sim\nBob," . Identifiers::CPF_B . ',,b@b.test,mat,2,80,Não'
		);

		$this->assertTrue( $out['success'], implode( ' | ', $out['errors'] ) );
		$this->assertSame( 2, $out['inserted'] );
		$this->assertSame( array(), $out['errors'] );
		$this->assertSame( array( 'START TRANSACTION', 'COMMIT' ), $this->transaction_statements() );

		$classifications = array_map(
			static fn( array $c ): array => array( $c[2][1]['candidate_id'], $c[2][1]['adjutancy_id'], $c[2][1]['notice_id'], $c[2][1]['list_type'], $c[2][1]['rank'] ),
			$this->calls_to( 'insert', '/^wp_ffc_recruitment_classification$/' )
		);
		$this->assertSame(
			array(
				array( 100, 2, 5, 'definitive', '1' ),
				array( 101, 2, 5, 'definitive', '2' ),
			),
			$classifications
		);
		$this->assertSame(
			array( Encryption::hash( Identifiers::CPF_A ), Encryption::hash( Identifiers::CPF_B ) ),
			array_column( $this->candidate_inserts(), 'cpf_hash' )
		);
		$this->assertCount( 1, $this->calls_to( 'query', "/SET status = 'definitive'/" ), 'The promotion ran inside the transaction (#2245).' );
		$this->assertSame( array(), $this->created_users, 'Accounts come from the batch, not the import.' );
	}

	public function test_import_definitive_rolls_back_the_rows_when_the_promotion_loses(): void {
		$this->store_notice( $this->notice_stub( 'preliminary' ) );
		$this->arrange_run_writes( 200 );
		$this->on( 'get_var', '/FROM `?wp_ffc_recruitment_call`? c/', '0' );
		// Another request promoted the notice first: the conditional UPDATE matches nothing.
		$this->on( 'query', "/SET status = 'definitive'/", 0, 1 );

		$out = RecruitmentCsvImporter::import_definitive(
			5,
			self::HEADER . 'Alice,' . Identifiers::CPF_A . ',,a@b.test,mat,1,90,Sim'
		);

		$this->assertFalse( $out['success'] );
		$this->assertSame( array( 'recruitment_transition_race_lost' ), $out['errors'] );
		$this->assertSame( array( 'START TRANSACTION', 'ROLLBACK' ), $this->transaction_statements() );
	}

	/**
	 * @dataProvider provider_refused_statuses
	 */
	public function test_import_definitive_refuses_before_any_write( string $status, int $calls, string $error ): void {
		$this->store_notice( $this->notice_stub( $status ) );
		$this->preview_rows = 'recruitment_definitive_import_requires_preliminary_list' === $error ? 0 : 1;
		$this->on( 'get_var', '/FROM `?wp_ffc_recruitment_call`? c/', (string) $calls );

		$out = RecruitmentCsvImporter::import_definitive(
			5,
			self::HEADER . 'Alice,' . Identifiers::CPF_A . ',,a@b.test,mat,1,90,Sim'
		);

		$this->assertFalse( $out['success'] );
		$this->assertSame( array( $error ), $out['errors'] );
		$this->assertSame( array(), $this->calls_to( 'query' ), 'Refused before the transaction opens (#2245).' );
		$this->assertSame( array(), $this->calls_to( 'delete' ) );
	}

	/**
	 * @return array<string, array{0: string, 1: int, 2: string}>
	 */
	public function provider_refused_statuses(): array {
		return array(
			'draft'                   => array( 'draft', 0, 'recruitment_definitive_import_requires_preliminary' ),
			'definitive'              => array( 'definitive', 0, 'recruitment_definitive_import_requires_preliminary' ),
			'closed'                  => array( 'closed', 0, 'recruitment_definitive_import_requires_preliminary' ),
			'preliminary with calls'  => array( 'preliminary', 3, 'recruitment_definitive_import_blocked_by_calls' ),
			'no preliminary list'     => array( 'preliminary', 0, 'recruitment_definitive_import_requires_preliminary_list' ),
		);
	}

	/**
	 * Arrange the promotion the definitive run commits with its rows: no
	 * calls on the notice, the conditional status UPDATE and the stamps.
	 *
	 * @return void
	 */
	private function arrange_promotion(): void {
		$this->on( 'get_var', '/FROM `?wp_ffc_recruitment_call`? c/', '0' );
		$this->on( 'query', "/SET status = 'definitive'/", 1, 1 );
		$this->on( 'query', '/^UPDATE `?wp_ffc_recruitment_notice`? SET (opened_at|was_reopened)/', 1 );
	}

	public function test_import_definitive_rolls_back_when_classification_insert_fails(): void {
		$this->store_notice( $this->notice_stub( 'preliminary' ) );
		$this->on( 'get_var', '/FROM `?wp_ffc_recruitment_call`? c/', '0' );
		// The classification insert fails → rollback.
		$this->arrange_run_writes( false );

		$out = RecruitmentCsvImporter::import_definitive(
			5,
			self::HEADER . 'Alice,' . Identifiers::CPF_A . ',,a@b.test,mat,1,90,Sim'
		);

		$this->assertFalse( $out['success'] );
		$this->assertContains( 'recruitment_classification_insert_failed', $out['errors'] );
		$this->assertSame( array( 'START TRANSACTION', 'ROLLBACK' ), $this->transaction_statements() );
	}

	public function test_import_definitive_propagates_validation_errors(): void {
		$this->store_notice( $this->notice_stub( 'preliminary' ) );
		$this->on( 'get_var', '/FROM `?wp_ffc_recruitment_call`? c/', '0' );

		// Row references an adjutancy slug not attached to the notice.
		$out = RecruitmentCsvImporter::import_definitive(
			5,
			self::HEADER . 'Alice,' . Identifiers::CPF_A . ',,a@b.test,nope,1,90,Sim'
		);

		$this->assertFalse( $out['success'] );
		$joined = implode( ' | ', $out['errors'] );
		$this->assertStringContainsString( 'recruitment_csv_adjutancy_not_in_notice: nope', $joined );
		$this->assertSame( array(), $this->calls_to( 'query' ), 'Validation fails before the transaction opens.' );
	}
}
