<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Frontend\Submission\QuizScorer;
use PHPUnit\Framework\TestCase;

/**
 * Server-side quiz scoring per question (#1730).
 *
 * @covers \FreeFormCertificate\Frontend\Submission\QuizScorer
 * @covers \FreeFormCertificate\Core\QuizQuestion
 */
class QuizScorerTest extends TestCase {

	private QuizScorer $scorer;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
		$this->scorer = new QuizScorer();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function choice( string $type, array $extra = array() ): array {
		return $extra + array(
			'type'    => $type,
			'name'    => 'q',
			'choices' => array( 'A', 'B', 'C' ),
		);
	}

	public function test_a_legacy_radio_scores_exactly_as_before(): void {
		$field = array( 'type' => 'radio', 'name' => 'q', 'options' => 'A,B,C', 'points' => '10,5,0' );

		$result = $this->scorer->score( array( $field ), array( 'q' => 'B' ) );

		$this->assertSame( 5, $result['score'] );
		$this->assertSame( 10, $result['max_score'] );
		$this->assertSame( 50, $result['percent'] );
		$this->assertSame( array( 'q' => false ), $result['results'], 'Below the best option is not right as a whole.' );
	}

	public function test_a_legacy_checkbox_with_points_is_still_not_scored(): void {
		$field = $this->choice( 'checkbox', array( 'points' => '5,5,0' ) );

		$result = $this->scorer->score( array( $field ), array( 'q' => array( 'A', 'B' ) ) );

		$this->assertSame( 0, $result['question_count'], 'The scorer ignored checkbox points before #1730; an untouched form must not change.' );
	}

	public function test_an_unticked_score_toggle_leaves_the_question_out(): void {
		$field = $this->choice( 'radio', array( 'points' => '10,0,0', 'quiz_scored' => '0' ) );

		$result = $this->scorer->score( array( $field ), array( 'q' => 'A' ) );

		$this->assertSame( 0, $result['max_score'] );
		$this->assertSame( 0, $result['question_count'] );
	}

	public function test_radio_in_correct_mode_scores_any_correct_option(): void {
		$field = $this->choice( 'radio', array( 'quiz_scored' => '1', 'quiz_mode' => 'correct', 'correct' => array( 'B', 'C' ), 'quiz_points' => '3' ) );

		$this->assertSame( 3, $this->scorer->score( array( $field ), array( 'q' => 'C' ) )['score'] );
		$this->assertSame( 0, $this->scorer->score( array( $field ), array( 'q' => 'A' ) )['score'] );
		$this->assertSame( 3, $this->scorer->score( array( $field ), array( 'q' => 'A' ) )['max_score'] );
	}

	public function test_checkbox_in_correct_mode_needs_the_exact_set(): void {
		$field = $this->choice( 'checkbox', array( 'quiz_scored' => '1', 'quiz_mode' => 'correct', 'correct' => array( 'A', 'C' ) ) );

		$exact  = $this->scorer->score( array( $field ), array( 'q' => array( 'C', 'A' ) ) );
		$extra  = $this->scorer->score( array( $field ), array( 'q' => array( 'A', 'B', 'C' ) ) );
		$missed = $this->scorer->score( array( $field ), array( 'q' => array( 'A' ) ) );

		$this->assertSame( 1, $exact['score'], 'Order does not matter.' );
		$this->assertSame( array( 'q' => true ), $exact['results'] );
		$this->assertSame( 0, $extra['score'], 'A wrong box spoils the answer.' );
		$this->assertSame( 0, $missed['score'], 'A missed box spoils the answer.' );
	}

	public function test_checkbox_in_points_mode_sums_and_never_goes_below_zero(): void {
		$field = $this->choice( 'checkbox', array( 'quiz_scored' => '1', 'quiz_mode' => 'points', 'points' => '4,-6,2' ) );

		$right = $this->scorer->score( array( $field ), array( 'q' => array( 'A', 'C' ) ) );
		$wrong = $this->scorer->score( array( $field ), array( 'q' => array( 'B' ) ) );
		$mixed = $this->scorer->score( array( $field ), array( 'q' => array( 'A', 'B', 'C' ) ) );

		$this->assertSame( 6, $right['score'] );
		$this->assertSame( 6, $right['max_score'], 'The maximum is every positive option together.' );
		$this->assertTrue( $right['results']['q'] );
		$this->assertSame( 0, $wrong['score'], 'Floored at zero.' );
		$this->assertSame( 0, $mixed['score'], 'Ticking everything is not a strategy.' );
	}

	public function test_a_correct_mode_question_without_a_correct_option_is_left_out(): void {
		$field = $this->choice( 'radio', array( 'quiz_scored' => '1', 'quiz_mode' => 'correct', 'correct' => array( 'Z' ) ) );

		$this->assertSame( 0, $this->scorer->score( array( $field ), array( 'q' => 'A' ) )['question_count'], 'An option that is not one of the choices does not count as correct.' );
	}

	public function test_an_ampersand_in_an_option_still_matches(): void {
		$field = array(
			'type'        => 'radio',
			'name'        => 'q',
			'choices'     => array( 'Rock & roll', 'Jazz' ),
			'quiz_scored' => '1',
			'quiz_mode'   => 'correct',
			'correct'     => array( 'Rock & roll' ),
		);

		// The sanitizer stores the answer with the ampersand encoded.
		$this->assertSame( 1, $this->scorer->score( array( $field ), array( 'q' => 'Rock &amp; roll' ) )['score'] );
	}

	public function test_text_scores_when_answered(): void {
		$field = array( 'type' => 'textarea', 'name' => 't', 'quiz_scored' => '1', 'quiz_text' => 'answered', 'quiz_points' => '2' );

		$this->assertSame( 2, $this->scorer->score( array( $field ), array( 't' => 'An answer' ) )['score'] );
		$this->assertSame( 0, $this->scorer->score( array( $field ), array( 't' => '   ' ) )['score'] );
	}

	public function test_text_with_no_points_is_left_out(): void {
		$field = array( 'type' => 'text', 'name' => 't', 'quiz_scored' => '1', 'quiz_text' => 'none' );

		$this->assertSame( 0, $this->scorer->score( array( $field ), array( 't' => 'x' ) )['question_count'] );
	}

	public function test_number_scores_within_the_range_bounds_included(): void {
		$field = array( 'type' => 'number', 'name' => 'n', 'quiz_scored' => '1', 'quiz_answer_min' => '10', 'quiz_answer_max' => '12' );

		$this->assertSame( 1, $this->scorer->score( array( $field ), array( 'n' => '10' ) )['score'] );
		$this->assertSame( 1, $this->scorer->score( array( $field ), array( 'n' => '12' ) )['score'] );
		$this->assertSame( 0, $this->scorer->score( array( $field ), array( 'n' => '12.5' ) )['score'] );
		$this->assertSame( 0, $this->scorer->score( array( $field ), array( 'n' => 'abc' ) )['score'] );
	}

	public function test_date_with_one_exact_answer(): void {
		$field = array( 'type' => 'date', 'name' => 'd', 'quiz_scored' => '1', 'quiz_answer_min' => '1988-10-05', 'quiz_answer_max' => '1988-10-05' );

		$this->assertSame( 1, $this->scorer->score( array( $field ), array( 'd' => '1988-10-05' ) )['score'] );
		$this->assertSame( 0, $this->scorer->score( array( $field ), array( 'd' => '1988-10-06' ) )['score'] );
	}

	public function test_an_open_ended_range_has_one_bound(): void {
		$field = array( 'type' => 'number', 'name' => 'n', 'quiz_scored' => '1', 'quiz_answer_min' => '18' );

		$this->assertSame( 1, $this->scorer->score( array( $field ), array( 'n' => '99' ) )['score'] );
		$this->assertSame( 0, $this->scorer->score( array( $field ), array( 'n' => '17' ) )['score'] );
	}

	public function test_a_range_question_without_a_bound_is_left_out(): void {
		$field = array( 'type' => 'date', 'name' => 'd', 'quiz_scored' => '1' );

		$this->assertSame( 0, $this->scorer->score( array( $field ), array( 'd' => '2026-01-01' ) )['question_count'] );
	}

	public function test_counts_and_percent_across_questions(): void {
		$fields = array(
			$this->choice( 'radio', array( 'quiz_scored' => '1', 'quiz_mode' => 'correct', 'correct' => array( 'A' ) ) ),
			array( 'type' => 'number', 'name' => 'n', 'quiz_scored' => '1', 'quiz_answer_min' => '2', 'quiz_answer_max' => '2' ),
			array( 'type' => 'text', 'name' => 'free', 'label' => 'Not scored' ),
		);

		$result = $this->scorer->score( $fields, array( 'q' => 'A', 'n' => '3', 'free' => 'x' ) );

		$this->assertSame( 1, $result['correct_count'] );
		$this->assertSame( 2, $result['question_count'] );
		$this->assertSame( 50, $result['percent'] );
		$this->assertSame( array( 'q' => true, 'n' => false ), $result['results'] );
	}

	public function test_no_scored_question_gives_zero_without_dividing(): void {
		$result = $this->scorer->score( array( array( 'type' => 'text', 'name' => 't' ) ), array() );

		$this->assertSame( 0, $result['percent'] );
		$this->assertSame( 0, $result['question_count'] );
	}

	/**
	 * #1732 — a manually graded question is worth its points, earns none
	 * until graded, and is reported as pending meanwhile.
	 */
	public function test_a_manual_question_is_pending_until_graded(): void {
		$fields = array(
			$this->choice( 'radio', array( 'quiz_scored' => '1', 'quiz_mode' => 'correct', 'correct' => array( 'A' ) ) ),
			array( 'type' => 'textarea', 'name' => 'essay', 'quiz_scored' => '1', 'quiz_text' => 'manual', 'quiz_points' => '4' ),
		);

		$pending = $this->scorer->score( $fields, array( 'q' => 'A', 'essay' => 'My answer' ) );
		$this->assertSame( array( 'essay' ), $pending['pending'] );
		$this->assertSame( 1, $pending['score'] );
		$this->assertSame( 5, $pending['max_score'], 'The ungraded question still counts toward the maximum.' );
		$this->assertSame( array( 'q' => true ), $pending['results'], 'A pending question is neither right nor wrong yet.' );

		$graded = $this->scorer->score( $fields, array( 'q' => 'A', 'essay' => 'My answer' ), array( 'essay' => 3 ) );
		$this->assertSame( array(), $graded['pending'] );
		$this->assertSame( 4, $graded['score'] );
		$this->assertSame( 80, $graded['percent'] );
		$this->assertFalse( $graded['results']['essay'], 'Below full marks is not right as a whole.' );

		$full = $this->scorer->score( $fields, array( 'q' => 'A' ), array( 'essay' => 99 ) );
		$this->assertSame( 5, $full['score'], 'A grade above the points is capped.' );
		$this->assertTrue( $full['results']['essay'] );

		$this->assertSame( 1, $this->scorer->score( $fields, array( 'q' => 'A' ), array( 'essay' => -3 ) )['score'], 'A negative grade is zero.' );
	}

	public function test_a_quiz_without_manual_questions_has_nothing_pending(): void {
		$this->assertSame( array(), $this->scorer->score( array( array( 'type' => 'text', 'name' => 't' ) ), array() )['pending'] );
	}
}
