<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Core\QuizQuestion;
use PHPUnit\Framework\TestCase;

/**
 * Reading and saving a field's quiz configuration (#1730).
 *
 * @covers \FreeFormCertificate\Core\QuizQuestion
 */
class QuizQuestionTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'sanitize_text_field' )->alias( static fn( $v ) => trim( (string) $v ) );
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_option_rows_rebuild_the_points_and_the_correct_set_in_their_order(): void {
		$row = array(
			'choices'        => array( 'B', '', 'A', 'C' ),
			'choice_points'  => array( '5', '9', '', 'x' ),
			'choice_correct' => array( '1', '1', '0', '1' ),
			'quiz_block'     => '1',
			'quiz_scored'    => '1',
			'quiz_mode'      => 'correct',
			'quiz_points'    => '2',
		);

		$clean = QuizQuestion::sanitize( $row, 'checkbox', array( 'B', 'A', 'C' ) );

		$this->assertSame( '5,0,0', $clean['points'], 'An empty option row is dropped with its values; an unreadable number is 0.' );
		$this->assertSame( array( 'B', 'C' ), $clean['correct'] );
		$this->assertSame( '1', $clean['quiz_scored'] );
		$this->assertSame( 'correct', $clean['quiz_mode'] );
		$this->assertSame( '2', $clean['quiz_points'] );
	}

	public function test_option_rows_without_any_points_store_no_points(): void {
		$clean = QuizQuestion::sanitize(
			array(
				'choices'        => array( 'A', 'B' ),
				'choice_points'  => array( '', '' ),
				'choice_correct' => array( '0', '0' ),
			),
			'radio',
			array( 'A', 'B' )
		);

		$this->assertSame( '', $clean['points'], 'Otherwise a field nobody gave points to would read as scored by the legacy rule.' );
	}

	public function test_a_row_without_the_quiz_block_keeps_the_legacy_shape(): void {
		$clean = QuizQuestion::sanitize( array( 'points' => '10,0' ), 'radio', array( 'A', 'B' ) );

		$this->assertSame( array( 'points' => '10,0' ), $clean, 'No quiz_scored key: the pre-#1730 rule keeps applying.' );
	}

	public function test_an_unticked_toggle_is_stored_as_off(): void {
		$clean = QuizQuestion::sanitize( array( 'quiz_block' => '1', 'points' => '' ), 'radio', array() );

		$this->assertSame( '0', $clean['quiz_scored'] );
		$this->assertFalse( QuizQuestion::is_scored( array( 'type' => 'radio', 'points' => '10,0' ) + $clean ) );
	}

	public function test_an_unknown_mode_falls_back_to_points(): void {
		$clean = QuizQuestion::sanitize( array( 'quiz_block' => '1', 'quiz_mode' => 'magic' ), 'select', array() );

		$this->assertSame( 'points', $clean['quiz_mode'] );
	}

	public function test_a_date_answer_range_reads_its_own_keys_and_is_swapped_when_reversed(): void {
		$clean = QuizQuestion::sanitize(
			array(
				'quiz_block'           => '1',
				'quiz_scored'          => '1',
				'quiz_answer_min'      => '5',
				'quiz_answer_min_date' => '2026-12-31',
				'quiz_answer_max_date' => '2026-01-01',
			),
			'date',
			array()
		);

		$this->assertSame( '2026-01-01', $clean['quiz_answer_min'] );
		$this->assertSame( '2026-12-31', $clean['quiz_answer_max'] );
	}

	public function test_an_invalid_bound_is_dropped(): void {
		$clean = QuizQuestion::sanitize(
			array(
				'quiz_block'      => '1',
				'quiz_answer_min' => 'ten',
				'quiz_answer_max' => '12',
			),
			'number',
			array()
		);

		$this->assertSame( '', $clean['quiz_answer_min'] );
		$this->assertSame( '12', $clean['quiz_answer_max'] );
	}

	public function test_a_text_answer_mode_is_bounded(): void {
		$this->assertSame( 'answered', QuizQuestion::sanitize( array( 'quiz_block' => '1', 'quiz_text' => 'answered' ), 'text', array() )['quiz_text'] );
		$this->assertSame( 'none', QuizQuestion::sanitize( array( 'quiz_block' => '1', 'quiz_text' => 'manual' ), 'textarea', array() )['quiz_text'], 'Manual grading is not delivered yet.' );
	}

	public function test_a_display_field_gains_no_quiz_keys(): void {
		$this->assertSame( array(), QuizQuestion::sanitize( array( 'quiz_block' => '1', 'quiz_scored' => '1' ), 'info', array() ) );
	}

	public function test_points_default_to_one(): void {
		$this->assertSame( 1, QuizQuestion::points( array() ) );
		$this->assertSame( 0, QuizQuestion::points( array( 'quiz_points' => '0' ) ) );
	}

	public function test_option_points_pad_to_the_options(): void {
		$this->assertSame( array( 3, 0, 0 ), QuizQuestion::option_points( array( 'choices' => array( 'A', 'B', 'C' ), 'points' => '3' ) ) );
		$this->assertSame( array(), QuizQuestion::option_points( array( 'choices' => array( 'A' ) ) ) );
	}
}
