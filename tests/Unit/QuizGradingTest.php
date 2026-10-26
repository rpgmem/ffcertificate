<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Admin\QuizGrading;
use FreeFormCertificate\Core\SubmissionStatus;
use PHPUnit\Framework\TestCase;

/**
 * Applying a person's grades to a stored quiz attempt (#1732).
 *
 * @covers \FreeFormCertificate\Admin\QuizGrading
 */
class QuizGradingTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function essay( string $name = 'essay', int $points = 4 ): array {
		return array(
			'type'        => 'textarea',
			'name'        => $name,
			'label'       => 'Explain',
			'quiz_scored' => '1',
			'quiz_text'   => 'manual',
			'quiz_points' => (string) $points,
		);
	}

	/**
	 * An attempt whose automatic part earned 6 of 6, with a 4-point essay pending: max 10.
	 */
	private function attempt( array $extra = array() ): array {
		return $extra + array(
			'name'                 => 'Maria',
			'essay'                => "Line one\nLine two",
			'_quiz_score'          => 6,
			'_quiz_max_score'      => 10,
			'_quiz_percent'        => 60,
			'_quiz_passed'         => '0',
			'_quiz_correct_count'  => 2,
			'_quiz_question_count' => 2,
			'_quiz_attempt'        => 1,
			'_quiz_pending'        => array( 'essay' ),
		);
	}

	public function test_pending_questions_carry_label_worth_and_answer(): void {
		$pending = QuizGrading::pending_questions( $this->attempt(), array( $this->essay() ) );

		$this->assertSame(
			array(
				'essay' => array(
					'label'  => 'Explain',
					'worth'  => 4,
					'answer' => "Line one\nLine two",
					'added'  => false,
				),
			),
			$pending
		);
	}

	public function test_a_question_that_left_the_form_is_still_listed_worth_nothing(): void {
		$pending = QuizGrading::pending_questions( $this->attempt(), array() );

		$this->assertSame( 0, $pending['essay']['worth'] );
		$this->assertSame( 'essay', $pending['essay']['label'] );
	}

	public function test_a_manual_question_of_the_form_is_graded_even_without_a_pending_list(): void {
		$data = $this->attempt();
		unset( $data['_quiz_pending'] );

		$pending = QuizGrading::pending_questions( $data, array( $this->essay() ) );

		$this->assertSame( array( 'essay' ), array_keys( $pending ) );
		$this->assertSame( 4, $pending['essay']['worth'] );
	}

	public function test_a_manual_question_added_after_the_submission_is_graded_too(): void {
		$data = $this->attempt();

		$pending = QuizGrading::pending_questions( $data, array( $this->essay( 'essay' ), $this->essay( 'second', 2 ) ) );

		$this->assertSame( array( 'essay', 'second' ), array_keys( $pending ) );
		$this->assertSame( 2, $pending['second']['worth'] );
		$this->assertFalse( $pending['essay']['added'] );
		$this->assertTrue( $pending['second']['added'] );
	}

	public function test_a_question_added_after_the_attempt_adds_its_worth_to_the_maximum(): void {
		$data    = $this->attempt();
		$pending = QuizGrading::pending_questions( $data, array( $this->essay( 'essay' ), $this->essay( 'second', 10 ) ) );

		$result = QuizGrading::apply( $data, $pending, array( 'quiz_passing_score' => 70 ), array( 'essay' => 4, 'second' => 10 ) );

		// 6 automatic + 4 + 10 out of 10 + 10: the full mark, never 200%.
		$this->assertSame( 20, $result['data']['_quiz_score'] );
		$this->assertSame( 20, $result['data']['_quiz_max_score'] );
		$this->assertSame( 100, $result['percent'] );
	}

	public function test_an_answered_question_missing_from_the_snapshot_is_not_graded_twice(): void {
		// `auto` was answered and scored automatically; the form now marks it manual.
		$data    = $this->attempt( array( 'auto' => 'Paris' ) );
		$pending = QuizGrading::pending_questions( $data, array( $this->essay( 'essay' ), $this->essay( 'auto', 2 ) ) );

		$this->assertSame( array( 'essay' ), array_keys( $pending ) );
	}

	public function test_a_question_already_graded_is_not_listed_again(): void {
		$data    = $this->attempt( array( '_quiz_grades' => array( 'essay' => 3 ) ) );
		$pending = QuizGrading::pending_questions( $data, array( $this->essay( 'essay' ) ) );

		$this->assertSame( array(), $pending );
	}

	public function test_the_percentage_never_passes_one_hundred(): void {
		$data = $this->attempt( array( '_quiz_score' => 9 ) );
		unset( $data['_quiz_pending'] );
		$pending = QuizGrading::pending_questions( $data, array( $this->essay( 'essay', 4 ) ) );

		$result = QuizGrading::apply( $data, $pending, array(), array( 'essay' => 4 ) );

		$this->assertSame( 100, $result['percent'] );
	}

	public function test_an_automatic_question_is_never_listed_for_grading(): void {
		$auto = array(
			'type'        => 'text',
			'name'        => 'auto',
			'label'       => 'Auto',
			'quiz_scored' => '1',
			'quiz_text'   => 'answered',
			'quiz_points' => '2',
		);

		$this->assertSame( array(), QuizGrading::pending_questions( array( '_quiz_pending' => array() ), array( $auto ) ) );
	}

	public function test_a_full_grade_passes_and_publishes(): void {
		$pending = QuizGrading::pending_questions( $this->attempt(), array( $this->essay() ) );

		$result = QuizGrading::apply( $this->attempt(), $pending, array( 'quiz_passing_score' => '70' ), array( 'essay' => 4 ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['passed'] );
		$this->assertSame( SubmissionStatus::PUBLISH, $result['status'] );
		$this->assertSame( 100, $result['percent'] );
		$this->assertSame( 10, $result['data']['_quiz_score'] );
		$this->assertSame( '1', $result['data']['_quiz_passed'] );
		$this->assertSame( 3, $result['data']['_quiz_correct_count'] );
		$this->assertSame( 3, $result['data']['_quiz_question_count'] );
		$this->assertSame( array( 'essay' => 4 ), $result['data']['_quiz_grades'] );
		$this->assertArrayNotHasKey( '_quiz_pending', $result['data'] );
	}

	public function test_a_partial_grade_counts_its_points_but_not_a_correct_answer(): void {
		$pending = QuizGrading::pending_questions( $this->attempt(), array( $this->essay() ) );

		$result = QuizGrading::apply( $this->attempt(), $pending, array( 'quiz_passing_score' => '70' ), array( 'essay' => 1 ) );

		$this->assertSame( 70, $result['percent'], '6 + 1 of 10.' );
		$this->assertTrue( $result['passed'], 'The passing score is inclusive.' );
		$this->assertSame( 2, $result['data']['_quiz_correct_count'] );
	}

	public function test_a_grade_is_clamped_to_the_question_worth(): void {
		$pending = QuizGrading::pending_questions( $this->attempt(), array( $this->essay() ) );

		$high = QuizGrading::apply( $this->attempt(), $pending, array(), array( 'essay' => 99 ) );
		$low  = QuizGrading::apply( $this->attempt(), $pending, array(), array( 'essay' => -5 ) );

		$this->assertSame( 10, $high['data']['_quiz_score'] );
		$this->assertSame( 6, $low['data']['_quiz_score'] );
	}

	public function test_below_the_passing_score_with_attempts_left_stays_in_progress(): void {
		$pending = QuizGrading::pending_questions( $this->attempt(), array( $this->essay() ) );

		$result = QuizGrading::apply( $this->attempt(), $pending, array( 'quiz_passing_score' => '90', 'quiz_max_attempts' => '3' ), array( 'essay' => 0 ) );

		$this->assertFalse( $result['passed'] );
		$this->assertSame( SubmissionStatus::QUIZ_IN_PROGRESS, $result['status'] );
		$this->assertSame( 2, $result['remaining'] );
		$this->assertSame( '0', $result['data']['_quiz_passed'] );
	}

	public function test_below_the_passing_score_on_the_last_attempt_fails(): void {
		$data    = $this->attempt( array( '_quiz_attempt' => 3 ) );
		$pending = QuizGrading::pending_questions( $data, array( $this->essay() ) );

		$result = QuizGrading::apply( $data, $pending, array( 'quiz_passing_score' => '90', 'quiz_max_attempts' => '3' ), array( 'essay' => 0 ) );

		$this->assertSame( SubmissionStatus::QUIZ_FAILED, $result['status'] );
		$this->assertSame( 0, $result['remaining'] );
	}

	public function test_unlimited_attempts_report_minus_one_remaining(): void {
		$pending = QuizGrading::pending_questions( $this->attempt(), array( $this->essay() ) );

		$result = QuizGrading::apply( $this->attempt(), $pending, array( 'quiz_passing_score' => '90' ), array( 'essay' => 0 ) );

		$this->assertSame( -1, $result['remaining'] );
		$this->assertSame( SubmissionStatus::QUIZ_IN_PROGRESS, $result['status'] );
	}

	public function test_a_missing_grade_is_an_error_and_decides_nothing(): void {
		$pending = QuizGrading::pending_questions( $this->attempt(), array( $this->essay() ) );

		$result = QuizGrading::apply( $this->attempt(), $pending, array(), array() );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_earlier_grades_are_kept_beside_the_new_ones(): void {
		$data    = $this->attempt( array( '_quiz_grades' => array( 'old' => 2 ) ) );
		$pending = QuizGrading::pending_questions( $data, array( $this->essay() ) );

		$result = QuizGrading::apply( $data, $pending, array(), array( 'essay' => 3 ) );

		$this->assertSame( array( 'old' => 2, 'essay' => 3 ), $result['data']['_quiz_grades'] );
	}

	public function test_a_grade_for_a_question_not_pending_is_ignored(): void {
		$pending = QuizGrading::pending_questions( $this->attempt(), array( $this->essay() ) );

		$result = QuizGrading::apply( $this->attempt(), $pending, array(), array( 'essay' => 4, 'other' => 99 ) );

		$this->assertSame( 10, $result['data']['_quiz_score'] );
		$this->assertSame( array( 'essay' => 4 ), $result['data']['_quiz_grades'] );
	}
}
