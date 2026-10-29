<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\Encryption;
use FreeFormCertificate\Frontend\Submission\SubmissionPersister;
use FreeFormCertificate\Frontend\Submission\SubmissionContext;
use FreeFormCertificate\Frontend\Submission\SubmissionRejected;
use FreeFormCertificate\Tests\Support\ActivityLogBuffer;
use FreeFormCertificate\Tests\Support\Identifiers;

/**
 * Tests for SubmissionPersister — the submission persistence + reprint/quiz
 * resolution pipeline stage.
 *
 * The stage's static collaborators run for real — ReprintDetector,
 * AccessRestrictionChecker, Encryption (with the bootstrap's key constants),
 * SensitiveFieldRegistry, ScheduleExceptionSession, ActivityLog, RequestInput,
 * SubmissionRepository's table name and DataSanitizer — over a `$wpdb` double
 * that answers each query by its SQL text and records the reads and the
 * one-use claim, plus global WordPress stubs. The injected SubmissionHandler
 * and the repository it returns are plain instance mocks. Only the
 * unconfigured-encryption test replaces a class, so only it runs in its own
 * process.
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
 * @preserveGlobalState disabled
 */
class SubmissionPersisterTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface */
	private $wpdb;

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	/** @var array<string, mixed> The `$_POST` an earlier test left. */
	private array $post_backup = array();

	/** @var mixed The REMOTE_ADDR an earlier test left (null when unset). */
	private $remote_addr_backup;

	/** @var array<string, mixed> What `get_option( 'ffc_settings' )` answers. */
	private array $settings = array();

	/** @var object|null The row the reprint lookup by CPF/RF finds. */
	private $reprint_row = null;

	/** @var object|null The row the quiz lookup finds. */
	private $quiz_row = null;

	/** @var bool Whether the one-use schedule-exception claim wins. */
	private bool $claim_wins = true;

	/** @var list<array{column: string, args: array<int, mixed>}> Quiz lookups, as prepared. */
	private array $quiz_lookups = array();

	/** @var list<array<int, mixed>> Arguments of each one-use claim INSERT. */
	private array $claims = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->settings     = array();
		$this->reprint_row  = null;
		$this->quiz_row     = null;
		$this->claim_wins   = true;
		$this->quiz_lookups = array();
		$this->claims       = array();

		$this->post_backup        = $_POST ?? array();
		$this->remote_addr_backup = $_SERVER['REMOTE_ADDR'] ?? null;
		unset( $_SERVER['REMOTE_ADDR'] );

		$wpdb          = Mockery::mock( 'wpdb' );
		$wpdb->prefix  = 'wp_';
		$wpdb->options = 'wp_options';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( $sql, ...$args ) {
				$flat = 1 === count( $args ) && is_array( $args[0] ) ? $args[0] : $args;
				return array(
					'sql'  => $sql,
					'args' => $flat,
				);
			}
		);
		$wpdb->shouldReceive( 'esc_like' )->andReturnUsing( static fn( $v ) => addcslashes( (string) $v, '_%\\' ) );
		$wpdb->shouldReceive( 'get_row' )->andReturnUsing(
			function ( $q ) {
				if ( false !== strpos( $q['sql'], 'WHERE form_id = %d AND %i = %s ORDER BY id DESC LIMIT 1' ) ) {
					return $this->reprint_row;
				}
				if ( false !== strpos( $q['sql'], 'ticket_hash = %s' ) || false !== strpos( $q['sql'], 'data LIKE %s' ) ) {
					return null;
				}
				if ( preg_match( '/AND (cpf_hash|rf_hash) = %s ORDER BY id DESC LIMIT 1/', $q['sql'], $m ) ) {
					$this->quiz_lookups[] = array(
						'column' => $m[1],
						'args'   => $q['args'],
					);
					return $this->quiz_row;
				}
				$this->fail( 'Unexpected get_row(): ' . $q['sql'] );
			}
		);
		$wpdb->shouldReceive( 'query' )->andReturnUsing(
			function ( $q ) use ( $wpdb ) {
				if ( 0 === strpos( $q['sql'], 'INSERT IGNORE INTO wp_options (option_name, option_value, autoload)' ) ) {
					$this->claims[]      = $q['args'];
					$wpdb->rows_affected = $this->claim_wins ? 1 : 0;
					return $wpdb->rows_affected;
				}
				$this->fail( 'Unexpected query(): ' . $q['sql'] );
			}
		);
		$this->wpdb = $wpdb;

		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']   = $wpdb;

		ActivityLogBuffer::reset();

		Functions\when( '__' )->returnArg();
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
		Functions\when( 'current_time' )->justReturn( '2026-06-30 12:00:00' );
		Functions\when( 'wp_json_encode' )->alias( static fn( $v ) => json_encode( $v ) );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		// Explicit, so Debug::is_enabled() and the activity-log switch read
		// this test's settings rather than whatever an earlier test left.
		Functions\when( 'get_option' )->alias(
			fn( $key ) => 'ffc_settings' === $key ? $this->settings : array()
		);
	}

	protected function tearDown(): void {
		ActivityLogBuffer::reset();
		$_POST = $this->post_backup;
		if ( null === $this->remote_addr_backup ) {
			unset( $_SERVER['REMOTE_ADDR'] );
		} else {
			$_SERVER['REMOTE_ADDR'] = $this->remote_addr_backup;
		}
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/** Build a SubmissionContext with sensible defaults. */
	private function ctx( array $overrides = array() ): SubmissionContext {
		$ctx                     = new SubmissionContext();
		$ctx->form_id            = 10;
		$ctx->form_config        = array();
		$ctx->fields_config      = array();
		$ctx->submission_data    = array( 'email' => 'a@b.com', 'cpf_rf' => Identifiers::CPF_A );
		$ctx->user_email         = 'a@b.com';
		$ctx->val_cpf            = Identifiers::CPF_A;
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
	 */
	private function handlerWithRepo( &$repo = null ) {
		if ( null === $repo ) {
			$repo = Mockery::mock( 'FreeFormCertificate\\Repositories\\SubmissionRepository' );
		}
		$handler = Mockery::mock( 'FreeFormCertificate\\Submissions\\SubmissionHandler' );
		$handler->shouldReceive( 'get_repository' )->andReturn( $repo )->byDefault();
		return $handler;
	}

	/** A handler with no repository mock. */
	private function bareHandler() {
		return Mockery::mock( 'FreeFormCertificate\\Submissions\\SubmissionHandler' );
	}

	/** A stored submission row the lookups can find. */
	private function storedRow( array $fields ): object {
		return (object) array_merge(
			array(
				'id'              => '1',
				'status'          => 'publish',
				'data'            => json_encode( array() ),
				'submission_date' => '1767225600',
				'auth_code'       => '',
			),
			$fields
		);
	}

	// =====================================================================
	// Normal (non-quiz) flow
	// =====================================================================

	public function test_normal_flow_fresh_insert_populates_context(): void {
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

	public function test_an_invited_submission_names_its_booking_and_a_reprint_does_not_write(): void {
		Functions\when( 'get_post' )->justReturn( (object) array( 'post_title' => 'My Form' ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
		$invite = array(
			'booking_id' => 5,
			'user_id'    => 7,
			'name'       => 'Ana',
			'email'      => 'a@b.com',
			'event'      => 'Workshop',
		);

		$repo    = null;
		$handler = $this->handlerWithRepo( $repo );
		// The insert stores the row the reprint lookup finds the second time.
		$handler->shouldReceive( 'process_submission' )->once()->andReturnUsing(
			function () {
				$this->reprint_row = $this->storedRow( array( 'id' => '555' ) );
				return 555;
			}
		);
		$repo->shouldReceive( 'update' )->once()->with( 555, array( 'audience_booking_id' => 5 ) )->andReturn( 1 );

		$persister = new SubmissionPersister( $handler );
		$persister->apply( $this->ctx( array( 'invite' => $invite ) ) );

		$again = $this->ctx( array( 'invite' => $invite ) );
		$persister->apply( $again );
		$this->assertTrue( $again->is_reprint, 'Opening the link again after answering reprints the certificate.' );
	}

	public function test_normal_flow_consumes_ticket(): void {
		Functions\when( 'get_post' )->justReturn( null );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'get_post_meta' )->justReturn( array( 'generated_codes_list' => "TICKET1\nTICKET2" ) );
		$meta_writes = array();
		Functions\when( 'update_post_meta' )->alias(
			static function ( ...$args ) use ( &$meta_writes ) {
				$meta_writes[] = $args;
				return true;
			}
		);

		$handler = $this->handlerWithRepo();
		$handler->shouldReceive( 'process_submission' )->andReturn( 7 );

		$persister = new SubmissionPersister( $handler );
		$ctx       = $this->ctx(
			array(
				'val_ticket'         => 'TICKET1',
				'restriction_result' => array( 'is_ticket' => true ),
			)
		);
		$persister->apply( $ctx );

		$this->assertSame( 7, $ctx->submission_id );
		$this->assertSame(
			array( array( 10, '_ffc_form_config', array( 'generated_codes_list' => 'TICKET2' ) ) ),
			$meta_writes,
			'The used ticket leaves the form\'s code list, once.'
		);
	}

	public function test_normal_flow_persists_schedule_exception(): void {
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
		$this->assertSame( array( array( 'ffc_sched_exc_used_jti-1', '9999' ) ), $this->claims, 'The token is claimed once, by its jti and expiry.' );
		// The activity log is off in these settings, so nothing is buffered.
		$this->assertSame( array(), ActivityLogBuffer::entries() );
	}

	public function test_normal_flow_reprint_surfaces_auth_code(): void {
		$this->reprint_row = $this->storedRow(
			array(
				'id'   => '321',
				'data' => json_encode( array( 'auth_code' => 'AUTH-XYZ' ) ),
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
		$this->assertSame( '1767225600', $ctx->real_submission_date, 'The stored submission date (unix seconds) is surfaced.' );
		$this->assertSame( 'AUTH-XYZ', $ctx->submission_data['auth_code'] );
	}

	public function test_normal_flow_db_failure_throws_rejected(): void {
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
		// No earlier attempt is stored, so find_quiz_submission() returns null.

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

	/**
	 * #1731 — a reset quiz records how many times the window was left,
	 * capped; a quiz without the reset records nothing.
	 *
	 * @dataProvider provider_focus_losses
	 */
	public function test_quiz_records_the_window_leave_count_only_when_reset( string $reset, $posted, ?int $expected ): void {
		Functions\when( 'get_post' )->justReturn( (object) array( 'post_title' => 'Quiz' ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
		$_POST['ffc_quiz_focus_losses'] = $posted;

		$handler = $this->handlerWithRepo();
		$handler->shouldReceive( 'process_submission' )->once()->andReturn( 901 );

		$ctx = $this->ctx(
			array(
				'form_config'     => array(
					'quiz_enabled'        => '1',
					'quiz_passing_score'  => 70,
					'quiz_max_attempts'   => 0,
					'quiz_reset_on_leave' => $reset,
				),
				'fields_config'   => $this->quizFieldsConfig(),
				'submission_data' => array( 'q1' => 'A' ),
			)
		);

		( new SubmissionPersister( $handler ) )->apply( $ctx );

		if ( null === $expected ) {
			$this->assertArrayNotHasKey( '_quiz_focus_losses', $ctx->submission_data );
		} else {
			$this->assertSame( $expected, $ctx->submission_data['_quiz_focus_losses'] );
		}
	}

	/**
	 * @return array<string, array{string, mixed, ?int}>
	 */
	public static function provider_focus_losses(): array {
		return array(
			'reset on, two exits'      => array( '1', '2', 2 ),
			'reset on, capped'         => array( '1', '5000', SubmissionPersister::MAX_FOCUS_LOSSES ),
			'reset on, not a number'   => array( '1', array( 'x' ), 0 ),
			'reset off, nothing kept'  => array( '0', '3', null ),
			'strict, kept too (#1745)' => array( '2', '4', 4 ),
		);
	}

	public function test_quiz_failing_in_progress_throws_feedback(): void {
		Functions\when( 'get_post' )->justReturn( null );
		Functions\when( 'is_wp_error' )->justReturn( false );

		$handler = $this->handlerWithRepo( $repo );
		$handler->shouldReceive( 'process_submission' )->once()->andReturn( 901 );
		$repo->shouldReceive( 'updateStatus' )->once()->with( 901, 'quiz_in_progress' );

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

	/**
	 * #1732 — an open answer a person grades holds the result back: the
	 * attempt is stored awaiting grading, with no feedback and no certificate.
	 */
	public function test_quiz_with_an_open_answer_waits_for_grading(): void {
		Functions\when( 'get_post' )->justReturn( null );
		Functions\when( 'is_wp_error' )->justReturn( false );

		$handler = $this->handlerWithRepo( $repo );
		$handler->shouldReceive( 'process_submission' )->once()->andReturn( 902 );
		$repo->shouldReceive( 'updateStatus' )->once()->with( 902, 'quiz_pending' );

		$fields   = $this->quizFieldsConfig();
		$fields[] = array(
			'type'        => 'textarea',
			'name'        => 'essay',
			'quiz_scored' => '1',
			'quiz_text'   => 'manual',
			'quiz_points' => '10',
		);
		$ctx = $this->ctx(
			array(
				'form_config'     => array(
					'quiz_enabled'       => '1',
					'quiz_passing_score' => 70,
					'quiz_max_attempts'  => 3,
				),
				'fields_config'   => $fields,
				'submission_data' => array(
					'q1'    => 'A',
					'essay' => 'An answer.',
				),
			)
		);

		( new SubmissionPersister( $handler ) )->apply( $ctx );

		$this->assertTrue( $ctx->awaiting_grading );
		$this->assertSame( 902, $ctx->submission_id );
		$this->assertSame( array( 'essay' ), $ctx->submission_data['_quiz_pending'] );
		$this->assertSame( 20, $ctx->submission_data['_quiz_max_score'], 'The open answer counts in the maximum from the start.' );
		$this->assertSame( 10, $ctx->submission_data['_quiz_score'] );
	}

	public function test_quiz_refuses_a_new_attempt_while_the_last_awaits_grading(): void {
		Functions\when( 'get_post' )->justReturn( null );

		$this->quiz_row = $this->storedRow(
			array(
				'id'     => '80',
				'status' => 'quiz_pending',
				'data'   => json_encode( array( '_quiz_attempt' => 1 ) ),
			)
		);

		$handler = $this->bareHandler();
		$handler->shouldReceive( 'process_submission' )->never();

		$ctx = $this->ctx(
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
			( new SubmissionPersister( $handler ) )->apply( $ctx );
			$this->fail( 'Expected SubmissionRejected' );
		} catch ( SubmissionRejected $e ) {
			$this->assertTrue( $e->get_payload()['quiz']['awaiting_grading'] );
		}
	}

	public function test_quiz_attempts_exhausted_throws_before_insert(): void {
		Functions\when( 'get_post' )->justReturn( null );

		// Existing submission has _quiz_attempt = 1; max_attempts = 1 -> next
		// attempt (2) > max -> exhausted rejection before any insert.
		$this->quiz_row = $this->storedRow(
			array(
				'id'     => '50',
				'status' => 'quiz_in_progress',
				'data'   => json_encode( array( '_quiz_attempt' => 1 ) ),
			)
		);

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
		$this->quiz_row = $this->storedRow(
			array(
				'id'     => '60',
				'status' => 'quiz_in_progress',
				'data'   => json_encode( array( '_quiz_attempt' => 1 ) ),
			)
		);

		$captured = null;
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
		$this->assertArrayNotHasKey( 'data', $captured[1] );
		$stored = json_decode( (string) Encryption::decrypt( (string) $captured[1]['data_encrypted'] ), true );
		$this->assertSame( 2, $stored['_quiz_attempt'], 'The encrypted data carries the second attempt.' );
	}

	public function test_quiz_already_passed_is_reprint(): void {
		Functions\when( 'get_post' )->justReturn( null );

		$this->quiz_row = $this->storedRow(
			array(
				'id'              => '70',
				'status'          => 'publish',
				'submission_date' => '2025-12-31 00:00:00',
				'auth_code'       => 'PREVAUTH',
			)
		);

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

	/**
	 * #1730 — the per-question verdict leaves only when the form shows it,
	 * and carries the verdict, never the right answer.
	 */
	public function test_shown_results_follow_the_show_correct_setting(): void {
		$score = array(
			'correct_count'  => 1,
			'question_count' => 2,
			'results'        => array( 'q1' => true, 'q2' => false ),
		);

		$this->assertNull( SubmissionPersister::shown_results( array( 'quiz_show_correct' => '0' ), $score ) );
		$this->assertNull( SubmissionPersister::shown_results( array(), $score ), 'Off unless the form turns it on.' );
		$this->assertSame(
			array(
				'correct_count'  => 1,
				'question_count' => 2,
				'fields'         => array( 'q1' => true, 'q2' => false ),
			),
			SubmissionPersister::shown_results( array( 'quiz_show_correct' => '1' ), $score )
		);
	}

	public function test_calculate_quiz_score_returns_the_counts(): void {
		$persister = new SubmissionPersister( $this->handlerWithRepo() );
		$result    = $persister->calculate_quiz_score(
			array(
				array( 'type' => 'radio', 'name' => 'q1', 'options' => 'A,B', 'points' => '5,0' ),
				array( 'type' => 'radio', 'name' => 'q2', 'options' => 'A,B', 'points' => '5,0' ),
			),
			array( 'q1' => 'A', 'q2' => 'B' )
		);

		$this->assertSame( 1, $result['correct_count'] );
		$this->assertSame( 2, $result['question_count'] );
	}

	// =====================================================================
	// find_quiz_submission
	// =====================================================================

	public function test_find_quiz_submission_returns_null_on_empty_cpf(): void {
		$persister = new SubmissionPersister( $this->handlerWithRepo() );
		$this->assertNull( $persister->find_quiz_submission( 5, '' ) );
	}

	public function test_find_quiz_submission_uses_rf_column_for_7_digits(): void {
		$row            = (object) array( 'id' => '11' );
		$this->quiz_row = $row;

		$persister = new SubmissionPersister( $this->bareHandler() );
		$this->assertSame( $row, $persister->find_quiz_submission( 5, Identifiers::RF_A ) );

		$this->assertCount( 1, $this->quiz_lookups );
		$this->assertSame( 'rf_hash', $this->quiz_lookups[0]['column'] );
		$this->assertSame(
			array( 'wp_ffc_submissions', 5, Encryption::hash( Identifiers::RF_A ) ),
			$this->quiz_lookups[0]['args'],
			'The lookup runs on the submissions table by the RF\'s search hash.'
		);
	}

	/**
	 * Runs in its own process because it replaces Encryption: the bootstrap
	 * defines the key constants is_configured() reads, so no stored state
	 * can make the real class report itself unconfigured.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_find_quiz_submission_null_when_encryption_unconfigured(): void {
		$enc = Mockery::mock( 'alias:FreeFormCertificate\\Core\\Encryption' );
		$enc->shouldReceive( 'is_configured' )->andReturn( false );

		$persister = new SubmissionPersister( $this->bareHandler() );
		$this->assertNull( $persister->find_quiz_submission( 5, Identifiers::CPF_A ) );
		$this->assertSame( array(), $this->quiz_lookups, 'No lookup runs without encryption.' );
	}

	// =====================================================================
	// maybe_persist_schedule_exception
	// =====================================================================

	public function test_maybe_persist_returns_false_when_claim_lost(): void {
		$this->claim_wins = false;

		$handler = $this->handlerWithRepo( $repo );
		$repo->shouldNotReceive( 'update' );

		$persister = new SubmissionPersister( $handler );
		$result    = $persister->maybe_persist_schedule_exception(
			1,
			10,
			array( 'jti' => 'x', 'exp' => 1 ),
			Identifiers::CPF_A
		);

		$this->assertFalse( $result );
		$this->assertSame( array( array( 'ffc_sched_exc_used_x', '1' ) ), $this->claims );
	}

	public function test_maybe_persist_returns_true_when_claim_won(): void {
		// The activity log is off in these settings, so log() returns early.
		$handler = $this->handlerWithRepo( $repo );
		$repo->shouldReceive( 'update' )->once()->andReturn( true );

		$persister = new SubmissionPersister( $handler );
		$result    = $persister->maybe_persist_schedule_exception(
			1,
			10,
			array( 'jti' => 'x', 'exp' => 1, 'start' => '08:00:00' ),
			Identifiers::CPF_A
		);

		$this->assertTrue( $result );
		$this->assertCount( 1, $this->claims );
	}

	// =====================================================================
	// persist_schedule_exception — audit pair
	// =====================================================================

	public function test_persist_schedule_exception_logs_audit_pair(): void {
		ActivityLogBuffer::stub_functions();
		$this->settings         = ActivityLogBuffer::ENABLED_SETTINGS;
		$_SERVER['REMOTE_ADDR'] = '203.0.113.5';

		$handler = $this->handlerWithRepo( $repo );
		$repo->shouldReceive( 'update' )->once()->with(
			1,
			array( 'schedule_start_override' => '08:00:00' )
		)->andReturn( true );

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
			Identifiers::CPF_A
		);

		$logged = ActivityLogBuffer::contexts();
		$this->assertSame( array( 'schedule_override_created', 'operator_ip_bypass' ), array_column( $logged, 'action' ) );
		$this->assertSame( hash( 'sha256', Identifiers::CPF_A ), $logged[0]['context']['participant_cpf_hash'] );
		$this->assertSame( '08:00:00', $logged[0]['context']['schedule_start_after'] );
		$this->assertSame( '203.0.113.5', $logged[1]['context']['bypassed_ip'] );
		$this->assertSame( 'ohash', $logged[1]['context']['operator_cpf_hash'] );
	}

	public function test_persist_schedule_exception_skips_update_when_no_overrides(): void {
		ActivityLogBuffer::stub_functions();
		$this->settings = ActivityLogBuffer::ENABLED_SETTINGS;

		$handler = $this->handlerWithRepo( $repo );
		// Empty overrides -> no repo update.
		$repo->shouldReceive( 'update' )->never();

		$persister = new SubmissionPersister( $handler );
		// Empty participant cpf -> participant_cpf_hash = ''.
		$persister->persist_schedule_exception( 1, 10, array(), '' );

		$logged = ActivityLogBuffer::contexts();
		$this->assertSame( array( 'schedule_override_created', 'operator_ip_bypass' ), array_column( $logged, 'action' ) );
		$this->assertSame( '', $logged[0]['context']['participant_cpf_hash'] );
		$this->assertNull( $logged[0]['context']['schedule_start_after'] );
	}
}
