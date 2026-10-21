<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Core\Encryption;
use FreeFormCertificate\Integrations\EmailHandler;
use FreeFormCertificate\Repositories\SubmissionRepository;
use FreeFormCertificate\Submissions\BulkIssueActivator;
use FreeFormCertificate\Submissions\BulkIssueRepository;
use FreeFormCertificate\Submissions\BulkIssueRunner;
use FreeFormCertificate\Submissions\SubmissionHandler;
use FreeFormCertificate\Tests\Support\Identifiers;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Tests for BulkIssueRunner: staging a bulk-issue job and promoting its lines
 * in batches (#1945).
 *
 * @covers \FreeFormCertificate\Submissions\BulkIssueRunner
 */
class BulkIssueRunnerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const FORM_ID = 7;
	private const JOB_ID  = 3;

	/** @var Mockery\MockInterface&SubmissionHandler */
	private $handler;

	/** @var Mockery\MockInterface&SubmissionRepository */
	private $submissions;

	/** @var EmailHandler&\Mockery\MockInterface Sends the link e-mails of lines marked to get one. */
	private $email;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->email = Mockery::mock( EmailHandler::class );

		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' )->byDefault();
		$wpdb->shouldReceive( 'query' )->andReturn( 1 )->byDefault();
		$wpdb->shouldReceive( 'update' )->andReturn( 1 )->byDefault();
		$wpdb->shouldReceive( 'delete' )->andReturn( 1 )->byDefault();
		$wpdb->shouldReceive( 'insert' )->andReturn( 1 )->byDefault();
		$wpdb->shouldReceive( 'get_var' )->andReturn( '0' )->byDefault();
		$wpdb->shouldReceive( 'get_row' )->andReturn( $this->job( BulkIssueRepository::STATUS_STAGING ) )->byDefault();
		$wpdb->insert_id = self::JOB_ID;

		Functions\when( 'get_the_title' )->justReturn( 'Participants 2026' );
		Functions\when( 'get_post_meta' )->alias(
			static function ( int $post_id, string $key ) {
				return '_ffc_form_fields' === $key
					? array(
						array(
							'name'     => 'name',
							'required' => true,
						),
						array(
							'name'     => 'unit',
							'required' => true,
						),
					)
					: array();
			}
		);
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$this->handler     = Mockery::mock( SubmissionHandler::class );
		$this->submissions = Mockery::mock( SubmissionRepository::class );
		$this->submissions->shouldReceive( 'countByFormAndCpfHash' )->andReturn( 0 )->byDefault();
		$this->submissions->shouldReceive( 'countByFormAndRfHash' )->andReturn( 0 )->byDefault();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_start_stages_planned_lines_as_pending_and_rejected_lines_as_skipped(): void {
		global $wpdb;
		$wpdb->shouldReceive( 'insert' )
			->with( BulkIssueActivator::jobs_table(), Mockery::type( 'array' ), Mockery::type( 'array' ) )
			->once()
			->andReturn( 1 );
		$wpdb->shouldReceive( 'insert' )
			->with( BulkIssueActivator::rows_table(), Mockery::on( static fn( array $row ): bool => 'pending' === $row['status'] ), Mockery::type( 'array' ) )
			->once()
			->andReturn( 1 );
		$wpdb->shouldReceive( 'insert' )
			->with( BulkIssueActivator::rows_table(), Mockery::on( static fn( array $row ): bool => 'skipped' === $row['status'] && 'invalid_email' === $row['skip_reason'] ), Mockery::type( 'array' ) )
			->once()
			->andReturn( 1 );

		$job_id = BulkIssueRunner::start(
			self::FORM_ID,
			5,
			array(
				'issue'   => array(
					array(
						'line'   => 2,
						'name'   => 'Ana',
						'cpf_rf' => Identifiers::CPF_A,
						'email'  => 'ana@example.org',
						'fields' => array( 'unit' => 'North' ),
						'issued_at' => null,
						'send_link' => false,
					),
				),
				'skipped' => array(
					array(
						'line'   => 3,
						'reason' => 'invalid_email',
					),
				),
			)
		);

		$this->assertSame( self::JOB_ID, $job_id );
	}

	public function test_issues_a_pending_line_without_sending_its_email_and_finishes_the_job(): void {
		$this->seed_pending_rows( array( $this->row( 11, 'Ana' ) ) );
		$this->handler->shouldReceive( 'process_submission' )
			->once()
			->with(
				self::FORM_ID,
				'Participants 2026',
				Mockery::on( static fn( array $data ): bool => 'Ana' === $data['name'] && 'North' === $data['unit'] ),
				'ana@example.org',
				Mockery::type( 'array' ),
				Mockery::type( 'array' ),
				Mockery::on(
					static fn( array $options ): bool => false === $options['send_email'] && is_int( $options['submission_date'] )
				)
			)
			->andReturn( 91 );

		$this->expect_row_update( 11, BulkIssueRepository::ROW_ISSUED );
		$this->expect_job_finished();

		$this->assertSame( 1, ( $this->runner() )->process_batch( self::JOB_ID ) );
	}

	public function test_a_line_dated_by_the_import_keeps_that_date(): void {
		$this->seed_pending_rows( array( $this->row( 11, 'Ana', 'cpf', 'North', 1700000000 ) ) );
		$this->handler->shouldReceive( 'process_submission' )
			->once()
			->with(
				self::FORM_ID,
				'Participants 2026',
				Mockery::type( 'array' ),
				'ana@example.org',
				Mockery::type( 'array' ),
				Mockery::type( 'array' ),
				Mockery::on( static fn( array $options ): bool => 1700000000 === $options['submission_date'] )
			)
			->andReturn( 91 );

		$this->expect_row_update( 11, BulkIssueRepository::ROW_ISSUED );
		$this->expect_job_finished();

		$this->assertSame( 1, ( $this->runner() )->process_batch( self::JOB_ID ) );
	}

	public function test_a_line_marked_to_get_its_link_gets_it_once_it_is_issued(): void {
		$this->seed_pending_rows( array( $this->row( 11, 'Ana', 'cpf', 'North', null, true ) ) );
		$this->handler->shouldReceive( 'process_submission' )->once()->andReturn( 91 );
		$this->email->shouldReceive( 'resend_link' )->once()->with( 91 )->andReturn( true );

		$this->expect_row_update( 11, BulkIssueRepository::ROW_ISSUED );
		$this->expect_job_finished();

		$this->assertSame( 1, ( $this->runner() )->process_batch( self::JOB_ID ) );
	}

	public function test_a_line_not_marked_to_get_its_link_is_issued_without_one(): void {
		$this->seed_pending_rows( array( $this->row( 11, 'Ana' ) ) );
		$this->handler->shouldReceive( 'process_submission' )->once()->andReturn( 91 );
		$this->email->shouldNotReceive( 'resend_link' );

		$this->expect_row_update( 11, BulkIssueRepository::ROW_ISSUED );
		$this->expect_job_finished();

		$this->assertSame( 1, ( $this->runner() )->process_batch( self::JOB_ID ) );
	}

	public function test_skips_a_line_whose_cpf_the_form_already_issued(): void {
		$this->seed_pending_rows( array( $this->row( 12, 'Bia' ) ) );
		$this->submissions->shouldReceive( 'countByFormAndCpfHash' )->once()->andReturn( 1 );
		$this->handler->shouldNotReceive( 'process_submission' );

		$this->expect_row_update( 12, BulkIssueRepository::ROW_SKIPPED, BulkIssueRunner::SKIP_ALREADY_ISSUED );
		$this->expect_job_finished();

		$this->assertSame( 1, ( $this->runner() )->process_batch( self::JOB_ID ) );
	}

	public function test_skips_a_line_whose_rf_the_form_already_issued(): void {
		$this->seed_pending_rows( array( $this->row( 13, 'Caio', 'rf' ) ) );
		$this->submissions->shouldReceive( 'countByFormAndRfHash' )->once()->andReturn( 2 );
		$this->handler->shouldNotReceive( 'process_submission' );

		$this->expect_row_update( 13, BulkIssueRepository::ROW_SKIPPED, BulkIssueRunner::SKIP_ALREADY_ISSUED );
		$this->expect_job_finished();

		$this->assertSame( 1, ( $this->runner() )->process_batch( self::JOB_ID ) );
	}

	public function test_skips_a_line_missing_a_field_the_form_requires(): void {
		$this->seed_pending_rows( array( $this->row( 14, 'Dani', 'cpf', '' ) ) );
		$this->handler->shouldNotReceive( 'process_submission' );

		$this->expect_row_update( 14, BulkIssueRepository::ROW_SKIPPED, BulkIssueRunner::SKIP_MISSING_REQUIRED );
		$this->expect_job_finished();

		$this->assertSame( 1, ( $this->runner() )->process_batch( self::JOB_ID ) );
	}

	public function test_a_refused_certificate_is_skipped_and_counted_without_stopping_the_batch(): void {
		$this->seed_pending_rows( array( $this->row( 15, 'Eva' ), $this->row( 16, 'Fabio' ) ) );
		$this->handler->shouldReceive( 'process_submission' )
			->twice()
			->andReturn( new \WP_Error( 'db_error', 'refused' ), 92 );

		$this->expect_row_update( 15, BulkIssueRepository::ROW_SKIPPED, BulkIssueRunner::SKIP_ISSUE_FAILED );
		$this->expect_row_update( 16, BulkIssueRepository::ROW_ISSUED );
		$this->expect_job_finished();

		$this->assertSame( 2, ( $this->runner() )->process_batch( self::JOB_ID ) );
	}

	public function test_a_line_whose_payload_cannot_be_decrypted_is_skipped(): void {
		$this->seed_pending_rows( array( array( 'id' => 17, 'cpf_hash' => null, 'rf_hash' => null, 'payload_encrypted' => 'not-a-ciphertext' ) ) );
		$this->handler->shouldNotReceive( 'process_submission' );

		$this->expect_row_update( 17, BulkIssueRepository::ROW_SKIPPED, BulkIssueRunner::SKIP_UNREADABLE );
		$this->expect_job_finished();

		$this->assertSame( 1, ( $this->runner() )->process_batch( self::JOB_ID ) );
	}

	public function test_a_job_already_finished_or_missing_changes_nothing(): void {
		global $wpdb;
		$wpdb->shouldReceive( 'get_row' )->andReturn( $this->job( BulkIssueRepository::STATUS_DONE ) );
		$this->handler->shouldNotReceive( 'process_submission' );

		$this->assertSame( 0, ( $this->runner() )->process_batch( self::JOB_ID ) );
	}

	public function test_a_missing_job_is_reported_as_nothing_to_do(): void {
		global $wpdb;
		$wpdb->shouldReceive( 'get_row' )->andReturn( null );

		$this->assertSame( 0, ( $this->runner() )->process_batch( self::JOB_ID ) );
	}

	public function test_purge_expires_only_the_jobs_older_than_the_stale_window(): void {
		global $wpdb;
		$now = 1800000000;
		$wpdb->shouldReceive( 'prepare' )
			->once()
			->with( Mockery::type( 'string' ), Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any() )
			->andReturn( 'SQL' );
		$wpdb->shouldReceive( 'get_col' )->once()->andReturn( array( '4' ) );
		$wpdb->shouldReceive( 'delete' )->with( 'wp_ffc_bulk_issue_rows', array( 'job_id' => 4 ), array( '%d' ) )->once()->andReturn( 0 );
		$wpdb->shouldReceive( 'update' )->once()->andReturn( 1 );

		$this->assertSame( 1, BulkIssueRunner::purge_expired( $now ) );
	}

	/**
	 * @return BulkIssueRunner
	 */
	private function runner(): BulkIssueRunner {
		return new BulkIssueRunner( $this->handler, $this->submissions, $this->email );
	}

	/**
	 * @param string $status Job state.
	 * @return array<string, mixed>
	 */
	private function job( string $status ): array {
		return array(
			'id'       => self::JOB_ID,
			'form_id'  => self::FORM_ID,
			'user_id'  => 5,
			'status'   => $status,
			'total'    => 1,
			'issued'   => 0,
			'skipped'  => 0,
		);
	}

	/**
	 * A staged line as `pending_rows()` reads it from the table.
	 *
	 * @param int         $id      Row ID.
	 * @param string      $name    Participant name.
	 * @param string      $kind    'cpf' or 'rf': which hash the line carries.
	 * @param string|null $unit    Value of the required field `unit`; null leaves it out.
	 * @return array<string, mixed>
	 */
	private function row( int $id, string $name, string $kind = 'cpf', ?string $unit = 'North', ?int $issued_at = null, bool $send_link = false ): array {
		$payload = array(
			'name'      => $name,
			'cpf_rf'    => 'cpf' === $kind ? Identifiers::CPF_A : Identifiers::RF_A,
			'email'     => strtolower( $name ) . '@example.org',
			'fields'    => null === $unit ? array() : array( 'unit' => $unit ),
			'issued_at' => $issued_at,
			'send_link' => $send_link,
		);

		return array(
			'id'               => $id,
			'cpf_hash'         => 'cpf' === $kind ? 'cpfhash' : null,
			'rf_hash'          => 'rf' === $kind ? 'rfhash' : null,
			'payload_encrypted' => Encryption::encrypt( (string) json_encode( $payload ) ),
		);
	}

	/**
	 * Makes the pending-lines read answer with these rows (already encrypted).
	 *
	 * @param array<int, array<string, mixed>> $rows Rows from row().
	 * @return void
	 */
	private function seed_pending_rows( array $rows ): void {
		global $wpdb;
		$wpdb->shouldReceive( 'get_results' )->andReturn( $rows );
	}

	/**
	 * Expects one line to be recorded with its outcome.
	 *
	 * @param int         $row_id Row ID.
	 * @param string      $status ROW_ISSUED or ROW_SKIPPED.
	 * @param string|null $reason Skip reason, when skipped.
	 * @return void
	 */
	private function expect_row_update( int $row_id, string $status, ?string $reason = null ): void {
		global $wpdb;
		$wpdb->shouldReceive( 'update' )
			->with(
				BulkIssueActivator::rows_table(),
				Mockery::on(
					static fn( array $data ): bool => $status === $data['status'] && ( null === $reason ? null === $data['skip_reason'] : $reason === $data['skip_reason'] )
				),
				array( 'id' => $row_id ),
				Mockery::type( 'array' ),
				array( '%d' )
			)
			->once()
			->andReturn( 1 );
	}

	/**
	 * Expects the job to close: state written, personal rows deleted.
	 *
	 * @return void
	 */
	private function expect_job_finished(): void {
		global $wpdb;
		$wpdb->shouldReceive( 'get_var' )->andReturn( '0' );
		$wpdb->shouldReceive( 'update' )
			->with(
				BulkIssueActivator::jobs_table(),
				Mockery::on( static fn( array $data ): bool => BulkIssueRepository::STATUS_DONE === $data['status'] && isset( $data['finished_at'] ) ),
				array( 'id' => self::JOB_ID ),
				Mockery::type( 'array' ),
				array( '%d' )
			)
			->once()
			->andReturn( 1 );
		$wpdb->shouldReceive( 'delete' )
			->with( BulkIssueActivator::rows_table(), array( 'job_id' => self::JOB_ID ), array( '%d' ) )
			->once()
			->andReturn( 1 );
	}
}
