<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Frontend\Submission\SubmissionPersister;
use FreeFormCertificate\Frontend\Submission\SubmissionContext;
use FreeFormCertificate\Frontend\Submission\SubmissionRejected;

/**
 * Tests for SubmissionPersister — the submission persistence + reprint/quiz
 * resolution pipeline stage.
 *
 * The stage fans out to a wide set of static collaborators (ReprintDetector,
 * AccessRestrictionChecker, Encryption, ScheduleExceptionSession, ActivityLog,
 * RequestInput, SubmissionRepository, DataSanitizer) plus an injected
 * SubmissionHandler instance. Statics are replaced with Mockery `alias:` mocks
 * (requires process isolation so the aliases do not leak); the handler and its
 * repository are plain mocks injected/returned.
 *
 * Coverage:
 *   - apply() normal flow: fresh insert, ticket consumption, schedule
 *     exception persistence, reprint detection, and the DB-failure branch.
 *   - apply() quiz flow: passing insert, failing in-progress + failed feedback,
 *     attempts-exhausted rejection, update of an existing in-progress row,
 *     already-passed reprint, and the quiz insert DB-failure branch.
 *   - calculate_quiz_score(): scoring, max-score, percent, empty/non-scoring.
 *   - find_quiz_submission(): empty cpf, encrypted-lookup (cpf vs rf column),
 *     and the unconfigured-encryption null branch.
 *   - maybe_persist_schedule_exception(): claim won / claim lost.
 *   - persist_schedule_exception(): full override + audit pair, no-op update.
 *
 * @covers \FreeFormCertificate\Frontend\Submission\SubmissionPersister
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class SubmissionPersisterTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface */
	private $wpdb;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		class_exists( '\\FreeFormCertificate\\Frontend\\Submission\\SubmissionPersister' );

		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$this->wpdb   = $wpdb;

		Functions\when( '__' )->returnArg();
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
		Functions\when( 'current_time' )->justReturn( '2026-06-30 12:00:00' );
		Functions\when( 'wp_json_encode' )->alias( static fn( $v ) => json_encode( $v ) );
		Functions\when( 'get_option' )->justReturn( array() );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** Build a SubmissionContext with sensible defaults. */
	private function ctx( array $overrides = array() ): SubmissionContext {
		$ctx                     = new SubmissionContext();
		$ctx->form_id            = 10;
		$ctx->form_config        = array();
		$ctx->fields_config      = array();
		$ctx->submission_data    = array( 'email' => 'a@b.com', 'cpf_rf' => '12345678909' );
		$ctx->user_email         = 'a@b.com';
		$ctx->val_cpf            = '12345678909';
		$ctx->val_ticket         = '';
		$ctx->has_exception      = false;
		$ctx->restriction_result = array( 'is_ticket' => false );

		foreach ( $overrides as $k => $v ) {
			$ctx->$k = $v;
		}
		return $ctx;
	}

	/**
	 * A handler + repository pair as mocks (handler injected). The repo is a
	 * typed SubmissionRepository mock because the handler's get_repository()
	 * return type is enforced (the real handler class is loaded).
	 *
	 * Pass an existing repo via $repo to reuse one (e.g. a SubmissionRepository
	 * `alias:` mock) instead of minting a fresh double — minting a second double
	 * of the same FQCN triggers a "Cannot redeclare" fatal.
	 */
	private function handlerWithRepo( &$repo = null ) {
		if ( null === $repo ) {
			$repo = Mockery::mock( 'FreeFormCertificate\\Repositories\\SubmissionRepository' );
		}
		$handler = Mockery::mock( 'FreeFormCertificate\\Submissions\\SubmissionHandler' );
		$handler->shouldReceive( 'get_repository' )->andReturn( $repo )->byDefault();
		return $handler;
	}

	/** A handler with no repository mock (for tests that alias-mock the repo class). */
	private function bareHandler() {
		return Mockery::mock( 'FreeFormCertificate\\Submissions\\SubmissionHandler' );
	}

	/** Alias-mock ReprintDetector returning a (non-)reprint result. */
	private function stubReprintDetector( array $result ): void {
		$rd = Mockery::mock( 'alias:FreeFormCertificate\\Frontend\\ReprintDetector' );
		$rd->shouldReceive( 'detect' )->andReturn( $result )->byDefault();
	}

	private function noReprint(): array {
		return array( 'is_reprint' => false, 'id' => 0, 'date' => '', 'data' => array() );
	}

	// =====================================================================
	// Normal (non-quiz) flow
	// =====================================================================

	public function test_normal_flow_fresh_insert_populates_context(): void {
		$this->stubReprintDetector( $this->noReprint() );
		Functions\when( 'get_post' )->justReturn( (object) array( 'post_title' => 'My Form' ) );
		Functions\when( 'is_wp_error' )->justReturn( false );

		$handler = $this->handlerWithRepo();
		$handler->shouldReceive( 'process_submission' )->once()->andReturn( 555 );

		$persister = new SubmissionPersister( $handler );
		$ctx       = $this->ctx();
		$persister->apply( $ctx );

		$this->assertSame( 555, $ctx->submission_id );
		$this->assertFalse( $ctx->is_reprint );
		$this->assertFalse( $ctx->is_quiz );
		$this->assertSame( '2026-06-30 12:00:00', $ctx->real_submission_date );
		$this->assertNull( $ctx->quiz_score );
	}

	public function test_normal_flow_consumes_ticket(): void {
		$this->stubReprintDetector( $this->noReprint() );
		Functions\when( 'get_post' )->justReturn( null );
		Functions\when( 'is_wp_error' )->justReturn( false );

		$handler = $this->handlerWithRepo();
		$handler->shouldReceive( 'process_submission' )->andReturn( 7 );

		$arc = Mockery::mock( 'alias:FreeFormCertificate\\Frontend\\AccessRestrictionChecker' );
		$arc->shouldReceive( 'consume_ticket' )->once()->with( 10, 'TICKET1' );

		$persister = new SubmissionPersister( $handler );
		$ctx       = $this->ctx(
			array(
				'val_ticket'         => 'TICKET1',
				'restriction_result' => array( 'is_ticket' => true ),
			)
		);
		$persister->apply( $ctx );

		$this->assertSame( 7, $ctx->submission_id );
	}

	public function test_normal_flow_persists_schedule_exception(): void {
		$this->stubReprintDetector( $this->noReprint() );
		Functions\when( 'get_post' )->justReturn( null );
		Functions\when( 'is_wp_error' )->justReturn( false );

		$handler = $this->handlerWithRepo( $repo );
		$handler->shouldReceive( 'process_submission' )->andReturn( 88 );
		$repo->shouldReceive( 'update' )->once()->with(
			88,
			array(
				'schedule_start_override' => '08:00:00',
				'schedule_end_override'   => '12:00:00',
			)
		)->andReturn( true );

		$ses = Mockery::mock( 'alias:FreeFormCertificate\\Frontend\\ScheduleExceptionSession' );
		$ses->shouldReceive( 'try_consume_jti' )->once()->with( 'jti-1', 9999 )->andReturn( true );

		// ActivityLog is left unloaded -> class_exists() false -> log() skipped.
		$this->assertFalse( class_exists( '\FreeFormCertificate\Core\ActivityLog', false ) );

		$persister = new SubmissionPersister( $handler );
		$ctx       = $this->ctx(
			array(
				'has_exception'              => true,
				'schedule_exception_payload' => array(
					'jti'   => 'jti-1',
					'exp'   => 9999,
					'start' => '08:00:00',
					'end'   => '12:00:00',
				),
			)
		);
		$persister->apply( $ctx );

		$this->assertSame( 88, $ctx->submission_id );
	}

	public function test_normal_flow_reprint_surfaces_auth_code(): void {
		$this->stubReprintDetector(
			array(
				'is_reprint' => true,
				'id'         => '321',
				'date'       => '2026-01-01 00:00:00',
				'data'       => array( 'auth_code' => 'AUTH-XYZ' ),
			)
		);
		Functions\when( 'get_post' )->justReturn( null );

		$handler = $this->handlerWithRepo();
		// Reprint path must NOT insert.
		$handler->shouldReceive( 'process_submission' )->never();

		$persister = new SubmissionPersister( $handler );
		$ctx       = $this->ctx();
		$persister->apply( $ctx );

		$this->assertTrue( $ctx->is_reprint );
		$this->assertSame( 321, $ctx->submission_id );
		$this->assertSame( '2026-01-01 00:00:00', $ctx->real_submission_date );
		$this->assertSame( 'AUTH-XYZ', $ctx->submission_data['auth_code'] );
	}

	public function test_normal_flow_db_failure_throws_rejected(): void {
		$this->stubReprintDetector( $this->noReprint() );
		Functions\when( 'get_post' )->justReturn( null );
		Functions\when( 'is_wp_error' )->justReturn( true );

		$error = Mockery::mock();
		$error->shouldReceive( 'get_error_code' )->andReturn( 'db_error' );
		$error->shouldReceive( 'get_error_message' )->andReturn( 'Duplicate entry' );

		$handler = $this->handlerWithRepo();
		$handler->shouldReceive( 'process_submission' )->andReturn( $error );

		$persister = new SubmissionPersister( $handler );

		try {
			$persister->apply( $this->ctx() );
			$this->fail( 'Expected SubmissionRejected' );
		} catch ( SubmissionRejected $e ) {
			$payload = $e->get_payload();
			$this->assertSame( 'db_error', $payload['code'] );
			$this->assertSame( 'Duplicate entry', $payload['detail'] );
			$this->assertArrayHasKey( 'message', $payload );
		}
	}

	// =====================================================================
	// Quiz flow
	// =====================================================================

	private function quizFieldsConfig(): array {
		return array(
			array(
				'type'    => 'radio',
				'name'    => 'q1',
				'options' => 'A,B,C',
				'points'  => '10,0,0',
			),
		);
	}

	public function test_quiz_passing_inserts_and_publishes(): void {
		Functions\when( 'get_post' )->justReturn( (object) array( 'post_title' => 'Quiz' ) );
		Functions\when( 'is_wp_error' )->justReturn( false );

		$handler = $this->handlerWithRepo();
		$handler->shouldReceive( 'process_submission' )->once()->andReturn( 900 );
		// quiz_status === 'publish' -> no updateStatus call.

		$persister = new SubmissionPersister( $handler );
		// find_quiz_submission returns null because Encryption not configured.
		$enc = Mockery::mock( 'alias:FreeFormCertificate\\Core\\Encryption' );
		$enc->shouldReceive( 'is_configured' )->andReturn( false )->byDefault();

		$ctx = $this->ctx(
			array(
				'form_config'     => array(
					'quiz_enabled'        => '1',
					'quiz_passing_score'  => 70,
					'quiz_max_attempts'   => 0,
				),
				'fields_config'   => $this->quizFieldsConfig(),
				'submission_data' => array( 'q1' => 'A' ),
			)
		);

		$persister->apply( $ctx );

		$this->assertTrue( $ctx->is_quiz );
		$this->assertSame( 900, $ctx->submission_id );
		$this->assertSame( 100, $ctx->quiz_score['percent'] );
		$this->assertSame( '1', $ctx->submission_data['_quiz_passed'] );
	}

	public function test_quiz_failing_in_progress_throws_feedback(): void {
		Functions\when( 'get_post' )->justReturn( null );
		Functions\when( 'is_wp_error' )->justReturn( false );

		$handler = $this->handlerWithRepo( $repo );
		$handler->shouldReceive( 'process_submission' )->once()->andReturn( 901 );
		$repo->shouldReceive( 'updateStatus' )->once()->with( 901, 'quiz_in_progress' );

		$enc = Mockery::mock( 'alias:FreeFormCertificate\\Core\\Encryption' );
		$enc->shouldReceive( 'is_configured' )->andReturn( false )->byDefault();

		$persister = new SubmissionPersister( $handler );
		$ctx       = $this->ctx(
			array(
				'form_config'     => array(
					'quiz_enabled'       => '1',
					'quiz_passing_score' => 70,
					'quiz_max_attempts'  => 3,
					'quiz_show_score'    => '1',
				),
				'fields_config'   => $this->quizFieldsConfig(),
				'submission_data' => array( 'q1' => 'B' ),
			)
		);

		try {
			$persister->apply( $ctx );
			$this->fail( 'Expected SubmissionRejected' );
		} catch ( SubmissionRejected $e ) {
			$payload = $e->get_payload();
			$this->assertFalse( $payload['quiz']['passed'] );
			$this->assertSame( 'quiz_in_progress', $payload['quiz']['status'] );
			$this->assertSame( 1, $payload['quiz']['attempt'] );
			$this->assertSame( 2, $payload['quiz']['remaining'] );
		}
	}

	public function test_quiz_attempts_exhausted_throws_before_insert(): void {
		Functions\when( 'get_post' )->justReturn( null );

		// Existing submission has _quiz_attempt = 1; max_attempts = 1 -> next
		// attempt (2) > max -> exhausted rejection before any insert.
		$existing = (object) array(
			'id'              => '50',
			'status'          => 'quiz_in_progress',
			'data'            => json_encode( array( '_quiz_attempt' => 1 ) ),
			'submission_date' => '2026-01-01 00:00:00',
			'auth_code'       => '',
		);

		$enc = Mockery::mock( 'alias:FreeFormCertificate\\Core\\Encryption' );
		$enc->shouldReceive( 'is_configured' )->andReturn( true )->byDefault();
		$enc->shouldReceive( 'hash' )->andReturn( 'hh' )->byDefault();

		$srepo = Mockery::mock( 'alias:FreeFormCertificate\\Repositories\\SubmissionRepository' );
		$srepo->shouldReceive( 'get_submissions_table' )->andReturn( 'wp_ffc_submissions' );

		$ds = Mockery::mock( 'alias:FreeFormCertificate\\Core\\DataSanitizer' );
		$ds->shouldReceive( 'normalize_cpf_rf' )->andReturn( '12345678909' );

		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )->andReturn( $existing );

		$handler = $this->bareHandler();
		$handler->shouldReceive( 'process_submission' )->never();

		$persister = new SubmissionPersister( $handler );
		$ctx       = $this->ctx(
			array(
				'form_config'     => array(
					'quiz_enabled'       => '1',
					'quiz_passing_score' => 70,
					'quiz_max_attempts'  => 1,
				),
				'fields_config'   => $this->quizFieldsConfig(),
				'submission_data' => array( 'q1' => 'B' ),
			)
		);

		try {
			$persister->apply( $ctx );
			$this->fail( 'Expected SubmissionRejected' );
		} catch ( SubmissionRejected $e ) {
			$this->assertTrue( $e->get_payload()['quiz']['attempts_exhausted'] );
		}
	}

	public function test_quiz_update_existing_in_progress_failed_status(): void {
		Functions\when( 'get_post' )->justReturn( null );

		// Existing in-progress, attempt 1, max_attempts 2 -> attempt 2 == max,
		// not passed -> status quiz_failed -> UPDATE path -> failed feedback.
		$existing = (object) array(
			'id'              => '60',
			'status'          => 'quiz_in_progress',
			'data'            => json_encode( array( '_quiz_attempt' => 1 ) ),
			'submission_date' => '2026-01-01 00:00:00',
			'auth_code'       => '',
		);

		$enc = Mockery::mock( 'alias:FreeFormCertificate\\Core\\Encryption' );
		$enc->shouldReceive( 'is_configured' )->andReturn( true )->byDefault();
		$enc->shouldReceive( 'hash' )->andReturn( 'hh' )->byDefault();
		$enc->shouldReceive( 'encrypt' )->andReturnUsing( static fn( $v ) => 'enc:' . $v )->byDefault();

		$srepo = Mockery::mock( 'alias:FreeFormCertificate\\Repositories\\SubmissionRepository' );
		$srepo->shouldReceive( 'get_submissions_table' )->andReturn( 'wp_ffc_submissions' );

		$ds = Mockery::mock( 'alias:FreeFormCertificate\\Core\\DataSanitizer' );
		$ds->shouldReceive( 'normalize_cpf_rf' )->andReturn( '12345678909' );

		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )->andReturn( $existing );

		// The SubmissionRepository alias mock is itself an instance of the class,
		// so reuse it as the handler's repository (avoids a second double of the
		// same FQCN, which would fatal with "Cannot redeclare").
		$captured = null;
		$repo     = $srepo;
		$handler  = $this->handlerWithRepo( $repo );
		$repo->shouldReceive( 'update' )->once()->andReturnUsing(
			function ( $id, $fields ) use ( &$captured ) {
				$captured = array( $id, $fields );
				return true;
			}
		);

		$persister = new SubmissionPersister( $handler );
		$ctx       = $this->ctx(
			array(
				'form_config'     => array(
					'quiz_enabled'       => '1',
					'quiz_passing_score' => 70,
					'quiz_max_attempts'  => 2,
					'quiz_show_score'    => '1',
				),
				'fields_config'   => $this->quizFieldsConfig(),
				'submission_data' => array( 'q1' => 'B' ),
			)
		);

		try {
			$persister->apply( $ctx );
			$this->fail( 'Expected SubmissionRejected' );
		} catch ( SubmissionRejected $e ) {
			$this->assertSame( 'quiz_failed', $e->get_payload()['quiz']['status'] );
		}

		$this->assertSame( 60, $captured[0] );
		$this->assertSame( 'quiz_failed', $captured[1]['status'] );
		// Encryption configured -> data stored encrypted.
		$this->assertArrayHasKey( 'data_encrypted', $captured[1] );
	}

	public function test_quiz_already_passed_is_reprint(): void {
		Functions\when( 'get_post' )->justReturn( null );

		$existing = (object) array(
			'id'              => '70',
			'status'          => 'publish',
			'data'            => json_encode( array() ),
			'submission_date' => '2025-12-31 00:00:00',
			'auth_code'       => 'PREVAUTH',
		);

		$enc = Mockery::mock( 'alias:FreeFormCertificate\\Core\\Encryption' );
		$enc->shouldReceive( 'is_configured' )->andReturn( true )->byDefault();
		$enc->shouldReceive( 'hash' )->andReturn( 'hh' )->byDefault();

		$srepo = Mockery::mock( 'alias:FreeFormCertificate\\Repositories\\SubmissionRepository' );
		$srepo->shouldReceive( 'get_submissions_table' )->andReturn( 'wp_ffc_submissions' );

		$ds = Mockery::mock( 'alias:FreeFormCertificate\\Core\\DataSanitizer' );
		$ds->shouldReceive( 'normalize_cpf_rf' )->andReturn( '12345678909' );

		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )->andReturn( $existing );

		$handler = $this->bareHandler();
		$handler->shouldReceive( 'process_submission' )->never();

		$persister = new SubmissionPersister( $handler );
		$ctx       = $this->ctx(
			array(
				'form_config'     => array(
					'quiz_enabled'       => '1',
					'quiz_passing_score' => 70,
				),
				'fields_config'   => $this->quizFieldsConfig(),
				'submission_data' => array( 'q1' => 'A' ),
			)
		);

		$persister->apply( $ctx );

		$this->assertTrue( $ctx->is_reprint );
		$this->assertSame( 70, $ctx->submission_id );
		$this->assertSame( 'PREVAUTH', $ctx->submission_data['auth_code'] );
	}

	public function test_quiz_insert_db_failure_throws_rejected(): void {
		Functions\when( 'get_post' )->justReturn( null );
		Functions\when( 'is_wp_error' )->justReturn( true );

		$enc = Mockery::mock( 'alias:FreeFormCertificate\\Core\\Encryption' );
		$enc->shouldReceive( 'is_configured' )->andReturn( false )->byDefault();

		$error = Mockery::mock();
		$error->shouldReceive( 'get_error_code' )->andReturn( 'q_err' );
		$error->shouldReceive( 'get_error_message' )->andReturn( 'boom' );

		$handler = $this->handlerWithRepo();
		$handler->shouldReceive( 'process_submission' )->andReturn( $error );

		$persister = new SubmissionPersister( $handler );
		$ctx       = $this->ctx(
			array(
				'form_config'     => array(
					'quiz_enabled'       => '1',
					'quiz_passing_score' => 70,
				),
				'fields_config'   => $this->quizFieldsConfig(),
				'submission_data' => array( 'q1' => 'A' ),
			)
		);

		try {
			$persister->apply( $ctx );
			$this->fail( 'Expected SubmissionRejected' );
		} catch ( SubmissionRejected $e ) {
			$this->assertSame( 'q_err', $e->get_payload()['code'] );
			$this->assertSame( 'boom', $e->get_payload()['detail'] );
		}
	}

	// =====================================================================
	// calculate_quiz_score
	// =====================================================================

	public function test_calculate_quiz_score_scores_correct_answer(): void {
		$persister = new SubmissionPersister( $this->handlerWithRepo() );
		$result    = $persister->calculate_quiz_score(
			array(
				array( 'type' => 'radio', 'name' => 'q1', 'options' => 'A,B', 'points' => '5,0' ),
				array( 'type' => 'select', 'name' => 'q2', 'options' => 'X,Y', 'points' => '0,3' ),
				// Skipped: no points.
				array( 'type' => 'text', 'name' => 'q3', 'options' => '', 'points' => '' ),
			),
			array( 'q1' => 'A', 'q2' => 'Y' )
		);

		$this->assertSame( 8, $result['score'] );
		$this->assertSame( 8, $result['max_score'] );
		$this->assertSame( 100, $result['percent'] );
	}

	public function test_calculate_quiz_score_zero_when_no_scoring_fields(): void {
		$persister = new SubmissionPersister( $this->handlerWithRepo() );
		$result    = $persister->calculate_quiz_score(
			array( array( 'type' => 'text', 'name' => 'q', 'options' => '', 'points' => '' ) ),
			array()
		);

		$this->assertSame( 0, $result['score'] );
		$this->assertSame( 0, $result['max_score'] );
		$this->assertSame( 0, $result['percent'] );
	}

	// =====================================================================
	// find_quiz_submission
	// =====================================================================

	public function test_find_quiz_submission_returns_null_on_empty_cpf(): void {
		$persister = new SubmissionPersister( $this->handlerWithRepo() );
		$this->assertNull( $persister->find_quiz_submission( 5, '' ) );
	}

	public function test_find_quiz_submission_uses_rf_column_for_7_digits(): void {
		$enc = Mockery::mock( 'alias:FreeFormCertificate\\Core\\Encryption' );
		$enc->shouldReceive( 'is_configured' )->andReturn( true );
		$enc->shouldReceive( 'hash' )->with( '1234567' )->andReturn( 'rfhash' );

		$srepo = Mockery::mock( 'alias:FreeFormCertificate\\Repositories\\SubmissionRepository' );
		$srepo->shouldReceive( 'get_submissions_table' )->andReturn( 'wp_ffc_submissions' );

		$ds = Mockery::mock( 'alias:FreeFormCertificate\\Core\\DataSanitizer' );
		$ds->shouldReceive( 'normalize_cpf_rf' )->andReturn( '1234567' );

		$row = (object) array( 'id' => '11' );
		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturnUsing(
			function ( $sql ) {
				$this->assertStringContainsString( 'rf_hash', $sql );
				return 'SQL';
			}
		);
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $row );

		$persister = new SubmissionPersister( $this->bareHandler() );
		$this->assertSame( $row, $persister->find_quiz_submission( 5, '1234567' ) );
	}

	public function test_find_quiz_submission_null_when_encryption_unconfigured(): void {
		$enc = Mockery::mock( 'alias:FreeFormCertificate\\Core\\Encryption' );
		$enc->shouldReceive( 'is_configured' )->andReturn( false );

		$srepo = Mockery::mock( 'alias:FreeFormCertificate\\Repositories\\SubmissionRepository' );
		$srepo->shouldReceive( 'get_submissions_table' )->andReturn( 'wp_ffc_submissions' );

		$ds = Mockery::mock( 'alias:FreeFormCertificate\\Core\\DataSanitizer' );
		$ds->shouldReceive( 'normalize_cpf_rf' )->andReturn( '12345678909' );

		$persister = new SubmissionPersister( $this->bareHandler() );
		$this->assertNull( $persister->find_quiz_submission( 5, '12345678909' ) );
	}

	// =====================================================================
	// maybe_persist_schedule_exception
	// =====================================================================

	public function test_maybe_persist_returns_false_when_claim_lost(): void {
		$ses = Mockery::mock( 'alias:FreeFormCertificate\\Frontend\\ScheduleExceptionSession' );
		$ses->shouldReceive( 'try_consume_jti' )->once()->andReturn( false );

		$persister = new SubmissionPersister( $this->handlerWithRepo() );
		$result    = $persister->maybe_persist_schedule_exception(
			1,
			10,
			array( 'jti' => 'x', 'exp' => 1 ),
			'12345678909'
		);

		$this->assertFalse( $result );
	}

	public function test_maybe_persist_returns_true_when_claim_won(): void {
		$ses = Mockery::mock( 'alias:FreeFormCertificate\\Frontend\\ScheduleExceptionSession' );
		$ses->shouldReceive( 'try_consume_jti' )->once()->andReturn( true );

		// ActivityLog unloaded -> log() skipped after the repo update.
		$handler = $this->handlerWithRepo( $repo );
		$repo->shouldReceive( 'update' )->once()->andReturn( true );

		$persister = new SubmissionPersister( $handler );
		$result    = $persister->maybe_persist_schedule_exception(
			1,
			10,
			array( 'jti' => 'x', 'exp' => 1, 'start' => '08:00:00' ),
			'12345678909'
		);

		$this->assertTrue( $result );
	}

	// =====================================================================
	// persist_schedule_exception — audit pair
	// =====================================================================

	public function test_persist_schedule_exception_logs_audit_pair(): void {
		$handler = $this->handlerWithRepo( $repo );
		$repo->shouldReceive( 'update' )->once()->with(
			1,
			array( 'schedule_start_override' => '08:00:00' )
		)->andReturn( true );

		$log = Mockery::mock( 'alias:FreeFormCertificate\\Core\\ActivityLog' );
		$log->shouldReceive( 'log' )->twice();

		$ri = Mockery::mock( 'alias:FreeFormCertificate\\Core\\RequestInput' );
		$ri->shouldReceive( 'get_user_ip' )->andReturn( '203.0.113.5' );


		$persister = new SubmissionPersister( $handler );
		$persister->persist_schedule_exception(
			1,
			10,
			array(
				'jti'                 => 'x',
				'start'               => '08:00:00',
				'operator_cpf_hash'   => 'ohash',
				'operator_cpf_masked' => '***',
			),
			'12345678909'
		);

		$this->assertTrue( true );
	}

	public function test_persist_schedule_exception_skips_update_when_no_overrides(): void {
		$handler = $this->handlerWithRepo( $repo );
		// Empty overrides -> no repo update.
		$repo->shouldReceive( 'update' )->never();

		$log = Mockery::mock( 'alias:FreeFormCertificate\\Core\\ActivityLog' );
		$log->shouldReceive( 'log' )->twice();

		$ri = Mockery::mock( 'alias:FreeFormCertificate\\Core\\RequestInput' );
		$ri->shouldReceive( 'get_user_ip' )->andReturn( '' );


		$persister = new SubmissionPersister( $handler );
		// Empty participant cpf -> participant_cpf_hash = ''.
		$persister->persist_schedule_exception( 1, 10, array(), '' );

		$this->assertTrue( true );
	}
}
