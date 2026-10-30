<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Admin\QuizGradingHandler;
use FreeFormCertificate\Core\SubmissionStatus;
use FreeFormCertificate\Tests\Support\ActivityLogBuffer;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Captures what the handler would send instead of sending it.
 */
class QuizGradingHandlerSpy extends QuizGradingHandler {

	/**
	 * Messages handed to the pipeline.
	 *
	 * @var array<int, array{to: string, subject: string, body: string}>
	 */
	public array $sent = array();

	protected function deliver( string $to, string $subject, string $body ): void {
		$this->sent[] = compact( 'to', 'subject', 'body' );
	}
}

/**
 * Saving a person's grades for an attempt awaiting grading (#1732), and
 * changing them once the result was sent (#2241).
 *
 * @covers \FreeFormCertificate\Admin\QuizGradingHandler
 */
class QuizGradingHandlerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const TOKEN = '0123456789abcdef0123456789abcdef';

	/** @var \Mockery\MockInterface */
	private $handler;

	/** @var \Mockery\MockInterface */
	private $repo;

	/** @var array<int, array<string, mixed>> */
	private array $updates = array();

	/** @var array<int, string> */
	private array $cache_deleted = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( '_n' )->alias( static fn( $single, $plural, $n ) => 1 === $n ? $single : $plural );
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'wpautop' )->returnArg();
		Functions\when( 'home_url' )->alias( static fn( $p = '' ) => 'https://example.test' . $p );
		Functions\when( 'site_url' )->alias( static fn( $p = '' ) => 'https://example.test/' . ltrim( $p, '/' ) );
		Functions\when( 'untrailingslashit' )->alias( static fn( $s ) => rtrim( $s, '/' ) );
		Functions\when( 'get_option' )->alias( static fn( $k, $d = false ) => 'ffc_settings' === $k || 'ffc_email_bodies' === $k ? array() : $d );
		Functions\when( 'wp_json_encode' )->alias( static fn( $v, $f = 0 ) => json_encode( $v, $f ) );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'get_the_title' )->justReturn( 'Safety course' );
		Functions\when( 'is_email' )->alias( static fn( $e ) => filter_var( $e, FILTER_VALIDATE_EMAIL ) ? $e : false );
		Functions\when( 'wp_cache_delete' )->alias(
			function ( $key ) {
				$this->cache_deleted[] = $key;
				return true;
			}
		);
		Functions\when( 'get_post_meta' )->alias(
			static function ( $id, $key ) {
				if ( '_ffc_form_fields' === $key ) {
					return array(
						array(
							'type'        => 'textarea',
							'name'        => 'essay',
							'label'       => 'Explain',
							'quiz_scored' => '1',
							'quiz_text'   => 'manual',
							'quiz_points' => '4',
						),
					);
				}
				return array(
					'quiz_passing_score' => '70',
					'quiz_max_attempts'  => '2',
					'quiz_show_score'    => '1',
				);
			}
		);

		$this->repo = Mockery::mock( 'FreeFormCertificate\Repositories\SubmissionRepository' );
		$this->repo->shouldReceive( 'update' )->andReturnUsing(
			function ( $id, $fields ) {
				$this->updates[ $id ] = $fields;
				return 1;
			}
		);
		$this->handler = Mockery::mock( 'FreeFormCertificate\Submissions\SubmissionHandler' );
		$this->handler->shouldReceive( 'get_repository' )->andReturn( $this->repo );
	}

	protected function tearDown(): void {
		ActivityLogBuffer::reset();
		Monkey\tearDown();
		parent::tearDown();
	}

	private function row( string $status = SubmissionStatus::QUIZ_PENDING, array $data = array() ): array {
		return array(
			'id'          => 5,
			'form_id'     => 3,
			'status'      => $status,
			'email'       => 'maria@example.test',
			'auth_code'   => 'ABCD1234EFGH',
			'magic_token' => self::TOKEN,
			'data'        => json_encode(
				$data + array(
					'name'            => 'Maria',
					'essay'           => 'Because.',
					'_quiz_score'     => 6,
					'_quiz_max_score' => 10,
					'_quiz_attempt'   => 1,
					'_quiz_pending'   => array( 'essay' ),
				)
			),
		);
	}

	private function grader( array $row ): QuizGradingHandlerSpy {
		$this->handler->shouldReceive( 'get_submission' )->with( 5 )->andReturn( $row );
		return new QuizGradingHandlerSpy( $this->handler );
	}

	public function test_init_registers_the_admin_post_action(): void {
		Functions\expect( 'add_action' )->once()->with( 'admin_post_ffc_grade_submission', Mockery::type( 'array' ) );

		( new QuizGradingHandler( $this->handler ) )->init();
	}

	public function test_the_nonce_is_bound_to_the_submission(): void {
		$this->assertSame( 'ffc_grade_submission_5', QuizGradingHandler::nonce_action( 5 ) );
		$this->assertNotSame( QuizGradingHandler::nonce_action( 5 ), QuizGradingHandler::nonce_action( 6 ) );
	}

	public function test_a_pass_publishes_clears_the_lookups_and_sends_the_document_link(): void {
		$spy = $this->grader( $this->row() );

		$this->assertSame( 'quiz_graded_passed', $spy->grade( 5, array( 'essay' => 4 ) ) );

		$this->assertSame( SubmissionStatus::PUBLISH, $this->updates[5]['status'] );
		$this->assertArrayHasKey( 'data_encrypted', $this->updates[5], 'Encryption is configured under test: the answers are written encrypted, as the persister writes them.' );
		$this->assertSame( array( 'auth_ABCD1234EFGH', 'token_' . self::TOKEN ), $this->cache_deleted );

		$this->assertCount( 1, $spy->sent );
		$this->assertSame( 'maria@example.test', $spy->sent[0]['to'] );
		$this->assertSame( 'Your answers were graded: Safety course', $spy->sent[0]['subject'] );
		$this->assertStringContainsString( 'Hello Maria,', $spy->sent[0]['body'] );
		$this->assertStringContainsString( '#token=' . self::TOKEN, $spy->sent[0]['body'], 'The download link is the attempt\'s own magic link.' );
		$this->assertStringContainsString( 'C-ABCD-1234-EFGH', $spy->sent[0]['body'] );
	}

	public function test_a_result_below_the_passing_score_says_so_and_counts_the_attempts_left(): void {
		$spy = $this->grader( $this->row() );

		$this->assertSame( 'quiz_graded_not_passed', $spy->grade( 5, array( 'essay' => 0 ) ) );

		$this->assertSame( SubmissionStatus::QUIZ_IN_PROGRESS, $this->updates[5]['status'] );
		$this->assertStringContainsString( 'Your score was 60%, below the passing score.', $spy->sent[0]['body'] );
		$this->assertStringContainsString( 'You have 1 attempt left', $spy->sent[0]['body'] );
		$this->assertStringNotContainsString( '#token=', $spy->sent[0]['body'], 'No document link for an attempt that did not pass.' );
	}

	public function test_an_attempt_not_awaiting_grading_is_left_alone(): void {
		$spy = $this->grader( $this->row( SubmissionStatus::PUBLISH ) );

		$this->assertSame( 'quiz_grade_stale', $spy->grade( 5, array( 'essay' => 4 ) ) );
		$this->assertSame( array(), $this->updates );
		$this->assertSame( array(), $spy->sent );
	}

	public function test_a_missing_submission_is_stale(): void {
		$this->handler->shouldReceive( 'get_submission' )->with( 9 )->andReturn( null );

		$this->assertSame( 'quiz_grade_stale', ( new QuizGradingHandlerSpy( $this->handler ) )->grade( 9, array() ) );
	}

	public function test_a_missing_grade_writes_and_sends_nothing(): void {
		$spy = $this->grader( $this->row() );

		$this->assertSame( 'quiz_grade_incomplete', $spy->grade( 5, array() ) );
		$this->assertSame( array(), $this->updates );
		$this->assertSame( array(), $spy->sent );
	}

	public function test_no_address_means_no_email_but_the_grade_still_stands(): void {
		$row          = $this->row();
		$row['email'] = '';
		$spy          = $this->grader( $row );

		$this->assertSame( 'quiz_graded_passed', $spy->grade( 5, array( 'essay' => 4 ) ) );
		$this->assertSame( SubmissionStatus::PUBLISH, $this->updates[5]['status'] );
		$this->assertSame( array(), $spy->sent );
	}

	public function test_the_score_stays_out_of_the_email_when_the_form_hides_it(): void {
		$result = array(
			'data'      => array(),
			'status'    => SubmissionStatus::QUIZ_FAILED,
			'passed'    => false,
			'percent'   => 40,
			'remaining' => 0,
		);

		$this->assertSame( 'Your score did not reach the passing score.', QuizGradingHandler::result_line( $result, array( 'quiz_show_score' => '0' ) ) );
		$this->assertSame( 'No attempts are left.', QuizGradingHandler::attempts_line( $result ) );
	}

	public function test_unlimited_attempts_invite_another_try(): void {
		$result = array(
			'data'      => array(),
			'status'    => SubmissionStatus::QUIZ_IN_PROGRESS,
			'passed'    => false,
			'percent'   => 40,
			'remaining' => -1,
		);

		$this->assertSame( 'You can submit the form again to try once more.', QuizGradingHandler::attempts_line( $result ) );
	}

	public function test_handle_refuses_a_user_without_the_capability(): void {
		$_POST = array( 'submission_id' => '5' );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'check_admin_referer' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'esc_html__' )->returnArg();
		Functions\expect( 'wp_die' )->once()->andThrow( new \RuntimeException( 'die' ) );

		try {
			( new QuizGradingHandlerSpy( $this->handler ) )->handle();
			$this->fail( 'A user without the capability must be stopped.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'die', $e->getMessage() );
		} finally {
			$_POST = array();
		}
		$this->assertSame( array(), $this->updates );
	}

	public function test_handle_checks_the_nonce_of_that_submission_first(): void {
		$_POST = array( 'submission_id' => '5' );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\expect( 'check_admin_referer' )->once()->with( 'ffc_grade_submission_5' )->andThrow( new \RuntimeException( 'bad nonce' ) );

		try {
			( new QuizGradingHandlerSpy( $this->handler ) )->handle();
			$this->fail( 'A bad nonce must stop the request.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'bad nonce', $e->getMessage() );
		} finally {
			$_POST = array();
		}
		$this->assertSame( array(), $this->updates );
	}
	// =====================================================================
	// Regrade (#2241)
	// =====================================================================

	/**
	 * An attempt a person already graded.
	 *
	 * @param string $status Stored status.
	 * @param int    $given  Points the essay was given.
	 */
	private function graded_row( string $status, int $given ): array {
		$score = 6 + $given;
		$row   = $this->row(
			$status,
			array(
				'_quiz_score'          => $score,
				'_quiz_percent'        => $score * 10,
				'_quiz_passed'         => $score >= 7 ? '1' : '0',
				'_quiz_grades'         => array( 'essay' => $given ),
				'_quiz_correct_count'  => 4 === $given ? 1 : 0,
				'_quiz_question_count' => 1,
			)
		);
		$data = json_decode( $row['data'], true );
		unset( $data['_quiz_pending'] );
		$row['data'] = json_encode( $data );
		return $row;
	}

	/** Turn the activity log on, so what a regrade records can be read. */
	private function log_on(): void {
		ActivityLogBuffer::reset();
		ActivityLogBuffer::stub_functions();
		Functions\when( 'get_option' )->alias( static fn( $k, $d = false ) => 'ffc_settings' === $k ? ActivityLogBuffer::ENABLED_SETTINGS : ( 'ffc_email_bodies' === $k ? array() : $d ) );
		Functions\when( 'sanitize_textarea_field' )->alias( static fn( $v ) => (string) $v );
	}

	public function test_a_regrade_without_a_reason_writes_and_sends_nothing(): void {
		$this->log_on();
		$spy = $this->grader( $this->graded_row( SubmissionStatus::PUBLISH, 4 ) );

		$this->assertSame( 'quiz_regrade_no_reason', $spy->regrade( 5, array( 'essay' => 1 ), '  typo  ' ) );
		$this->assertSame( array(), $this->updates );
		$this->assertSame( array(), $spy->sent );
		$this->assertSame( array(), ActivityLogBuffer::entries() );
	}

	public function test_a_regrade_on_the_same_outcome_is_logged_and_not_emailed(): void {
		$this->log_on();
		$spy = $this->grader( $this->graded_row( SubmissionStatus::PUBLISH, 4 ) );

		$this->assertSame( 'quiz_regraded', $spy->regrade( 5, array( 'essay' => 1 ), 'Wrong rubric applied.' ) );

		$this->assertSame( SubmissionStatus::PUBLISH, $this->updates[5]['status'], '7 of 10 still passes at 70%.' );
		$this->assertSame( array(), $spy->sent, 'Same outcome: the participant is not e-mailed.' );

		$logged = ActivityLogBuffer::contexts();
		$this->assertCount( 1, $logged );
		$this->assertSame( 'quiz_regraded', $logged[0]['action'] );
		$this->assertSame( 'Wrong rubric applied.', $logged[0]['context']['reason'] );
		$this->assertSame( array( 'essay' => 4 ), $logged[0]['context']['old_grades'] );
		$this->assertSame( array( 'essay' => 1 ), $logged[0]['context']['new_grades'] );
		$this->assertSame( 100, $logged[0]['context']['old_percent'] );
		$this->assertSame( 70, $logged[0]['context']['new_percent'] );
		$this->assertFalse( $logged[0]['context']['outcome_change'] );
	}

	public function test_a_regrade_that_fails_a_pass_emails_the_result(): void {
		$this->log_on();
		$spy = $this->grader( $this->graded_row( SubmissionStatus::PUBLISH, 4 ) );

		$this->assertSame( 'quiz_regraded', $spy->regrade( 5, array( 'essay' => 0 ), 'Answer copied from a classmate.' ) );

		$this->assertSame( SubmissionStatus::QUIZ_IN_PROGRESS, $this->updates[5]['status'], 'Attempt 1 of 2: another try is open.' );
		$this->assertCount( 1, $spy->sent );
		$this->assertStringContainsString( 'Your score was 60%, below the passing score.', $spy->sent[0]['body'] );
		$this->assertTrue( ActivityLogBuffer::contexts()[0]['context']['outcome_change'] );
	}

	public function test_a_regrade_that_passes_a_failed_attempt_sends_the_document_link(): void {
		$this->log_on();
		$spy = $this->grader( $this->graded_row( SubmissionStatus::QUIZ_IN_PROGRESS, 0 ) );

		$this->assertSame( 'quiz_regraded', $spy->regrade( 5, array( 'essay' => 4 ), 'Grade typed in the wrong row.' ) );

		$this->assertSame( SubmissionStatus::PUBLISH, $this->updates[5]['status'] );
		$this->assertCount( 1, $spy->sent );
		$this->assertStringContainsString( '#token=' . self::TOKEN, $spy->sent[0]['body'] );
		$this->assertSame( array( 'auth_ABCD1234EFGH', 'token_' . self::TOKEN ), $this->cache_deleted );
	}

	public function test_a_regrade_needs_an_attempt_a_person_graded(): void {
		$this->log_on();

		$pending = $this->grader( $this->row() );
		$this->assertSame( 'quiz_grade_stale', $pending->regrade( 5, array( 'essay' => 1 ), 'Still waiting for a grade.' ), 'An attempt awaiting grading is graded, not regraded.' );

		$this->assertSame( array(), $this->updates );
	}

	public function test_an_automatically_scored_attempt_cannot_be_regraded(): void {
		$this->log_on();
		$spy = $this->grader( $this->row( SubmissionStatus::PUBLISH, array( '_quiz_grades' => array() ) ) );

		$this->assertSame( 'quiz_grade_stale', $spy->regrade( 5, array(), 'Nothing a person graded.' ) );
		$this->assertSame( array(), $this->updates );
	}

	public function test_a_regrade_missing_a_grade_writes_nothing(): void {
		$this->log_on();
		$spy = $this->grader( $this->graded_row( SubmissionStatus::PUBLISH, 4 ) );

		$this->assertSame( 'quiz_grade_incomplete', $spy->regrade( 5, array(), 'Clearing the grade.' ) );
		$this->assertSame( array(), $this->updates );
	}
}
