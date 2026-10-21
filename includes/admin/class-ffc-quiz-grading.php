<?php
/**
 * QuizGrading
 *
 * The result of an attempt once a person has graded its open answers (#1732).
 * The attempt was stored with the automatic part already scored and the open
 * answers' worth already in the maximum, so grading only adds the points given:
 * nothing is re-scored from the answers, and an edit to the form after the
 * attempt cannot move what the automatic part earned.
 *
 * Pure: it reads the stored data and the form, and returns the new data and
 * status. Persisting it is {@see QuizGradingHandler}'s job.
 *
 * @package FreeFormCertificate\Admin
 * @since 6.39.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

use FreeFormCertificate\Core\ArrayValue;
use FreeFormCertificate\Core\QuizQuestion;
use FreeFormCertificate\Core\SubmissionStatus;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Applies grades to a stored quiz attempt.
 *
 * @phpstan-type GradingResult array{data: array<string, mixed>, status: string, passed: bool, percent: int, remaining: int}
 */
final class QuizGrading {

	/**
	 * The questions of an attempt still waiting for a grade, with their worth.
	 *
	 * A question that left the form since the attempt is still listed, worth
	 * nothing: its points are already in the stored maximum and cannot be
	 * earned any more.
	 *
	 * @param array<string, mixed>             $data   Stored submission data.
	 * @param array<int, array<string, mixed>> $fields Form fields configuration.
	 * @return array<string, array{label: string, worth: int, answer: string}> Keyed by question name.
	 */
	public static function pending_questions( array $data, array $fields ): array {
		$by_name = array();
		foreach ( $fields as $field ) {
			$name = ArrayValue::string( $field, 'name' );
			if ( '' !== $name && QuizQuestion::is_manual( $field ) ) {
				$by_name[ $name ] = $field;
			}
		}

		// The snapshot taken at submission keeps a question that has since left the form:
		// its points are still in the attempt's maximum, so it must still be graded.
		$snapshot = isset( $data['_quiz_pending'] ) && is_array( $data['_quiz_pending'] ) ? $data['_quiz_pending'] : array();
		$names    = array_values( array_unique( array_merge( $snapshot, array_keys( $by_name ) ) ) );

		$out = array();
		foreach ( $names as $name ) {
			if ( ! is_string( $name ) || '' === $name ) {
				continue;
			}
			$field        = $by_name[ $name ] ?? null;
			$answer       = $data[ $name ] ?? '';
			$out[ $name ] = array(
				'label'  => null !== $field ? ArrayValue::string( $field, 'label', $name ) : $name,
				'worth'  => null !== $field ? QuizQuestion::points( $field ) : 0,
				'answer' => is_array( $answer ) ? implode( ', ', array_map( 'strval', $answer ) ) : ( is_scalar( $answer ) ? (string) $answer : '' ),
			);
		}
		return $out;
	}

	/**
	 * Apply the grades and compute the attempt's result.
	 *
	 * @param array<string, mixed>                                            $data        Stored submission data.
	 * @param array<string, array{label: string, worth: int, answer: string}> $pending     {@see pending_questions()}.
	 * @param array<string, mixed>                                            $form_config Form configuration.
	 * @param array<string, int>                                              $grades      Points given, keyed by question name.
	 * @return GradingResult|\WP_Error An error when a pending question has no grade.
	 */
	public static function apply( array $data, array $pending, array $form_config, array $grades ) {
		$given   = array();
		$score   = ArrayValue::int( $data, '_quiz_score', 0 );
		$correct = ArrayValue::int( $data, '_quiz_correct_count', 0 );
		$counted = ArrayValue::int( $data, '_quiz_question_count', 0 );

		foreach ( $pending as $name => $question ) {
			if ( ! array_key_exists( $name, $grades ) ) {
				return new \WP_Error( 'ffc_grade_missing', __( 'Give every open answer a grade.', 'ffcertificate' ) );
			}
			$earned         = min( $question['worth'], max( 0, $grades[ $name ] ) );
			$given[ $name ] = $earned;
			$score         += $earned;
			++$counted;
			if ( $question['worth'] > 0 && $earned >= $question['worth'] ) {
				++$correct;
			}
		}

		$max     = ArrayValue::int( $data, '_quiz_max_score', 0 );
		$percent = $max > 0 ? (int) round( ( $score / $max ) * 100 ) : 0;
		$passed  = $percent >= ArrayValue::int( $form_config, 'quiz_passing_score', 70 );

		$max_attempts = ArrayValue::int( $form_config, 'quiz_max_attempts', 0 );
		$attempt      = ArrayValue::int( $data, '_quiz_attempt', 1 );
		if ( $passed ) {
			$status = SubmissionStatus::PUBLISH;
		} elseif ( $max_attempts > 0 && $attempt >= $max_attempts ) {
			$status = SubmissionStatus::QUIZ_FAILED;
		} else {
			$status = SubmissionStatus::QUIZ_IN_PROGRESS;
		}

		$previous = isset( $data['_quiz_grades'] ) && is_array( $data['_quiz_grades'] ) ? $data['_quiz_grades'] : array();
		unset( $data['_quiz_pending'] );
		$data['_quiz_score']          = $score;
		$data['_quiz_percent']        = $percent;
		$data['_quiz_passed']         = $passed ? '1' : '0';
		$data['_quiz_correct_count']  = $correct;
		$data['_quiz_question_count'] = $counted;
		$data['_quiz_grades']         = array_merge( $previous, $given );

		return array(
			'data'      => $data,
			'status'    => $status,
			'passed'    => $passed,
			'percent'   => $percent,
			'remaining' => $max_attempts > 0 ? max( 0, $max_attempts - $attempt ) : -1,
		);
	}
}
