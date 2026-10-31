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
	 * A manual question the form gained after the attempt is listed too, and
	 * marked `added`: its points were never in the attempt's maximum, so
	 * {@see apply()} adds them there. A question the participant answered that
	 * was not in the snapshot existed at submission as an automatic question,
	 * so its points are already in the score and it is not listed again.
	 *
	 * @param array<string, mixed>             $data   Stored submission data.
	 * @param array<int, array<string, mixed>> $fields Form fields configuration.
	 * @return array<string, array{label: string, worth: int, answer: string, added: bool}> Keyed by question name.
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
		$has_snapshot = isset( $data['_quiz_pending'] ) && is_array( $data['_quiz_pending'] );
		$snapshot     = $has_snapshot ? $data['_quiz_pending'] : array();
		$graded       = isset( $data['_quiz_grades'] ) && is_array( $data['_quiz_grades'] ) ? $data['_quiz_grades'] : array();
		$names        = array_values( array_unique( array_merge( $snapshot, array_keys( $by_name ) ) ) );

		$out = array();
		foreach ( $names as $name ) {
			if ( ! is_string( $name ) || '' === $name || array_key_exists( $name, $graded ) ) {
				continue;
			}

			// An attempt stored before the snapshot existed has no list to compare with,
			// so every manual question of the form is taken as part of its maximum.
			$added = $has_snapshot && ! in_array( $name, $snapshot, true );
			if ( $added && array_key_exists( $name, $data ) ) {
				continue;
			}

			$field        = $by_name[ $name ] ?? null;
			$answer       = $data[ $name ] ?? '';
			$out[ $name ] = array(
				'label'  => null !== $field ? ArrayValue::string( $field, 'label', $name ) : $name,
				'worth'  => null !== $field ? QuizQuestion::points( $field ) : 0,
				'answer' => is_array( $answer ) ? implode( ', ', array_map( 'strval', $answer ) ) : ( is_scalar( $answer ) ? (string) $answer : '' ),
				'added'  => $added,
			);
		}
		return $out;
	}

	/**
	 * Apply the grades and compute the attempt's result.
	 *
	 * @param array<string, mixed>                                                          $data        Stored submission data.
	 * @param array<string, array{label: string, worth: int, answer: string, added?: bool}> $pending     {@see pending_questions()}.
	 * @param array<string, mixed>                                                          $form_config Form configuration.
	 * @param array<string, int>                                                            $grades      Points given, keyed by question name.
	 * @return GradingResult|\WP_Error An error when a pending question has no grade.
	 */
	public static function apply( array $data, array $pending, array $form_config, array $grades ) {
		$given   = array();
		$score   = ArrayValue::int( $data, '_quiz_score', 0 );
		$max     = ArrayValue::int( $data, '_quiz_max_score', 0 );
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
			// A question the form gained after the attempt was never in its maximum.
			if ( ! empty( $question['added'] ) ) {
				$max += $question['worth'];
			}
		}

		$previous = isset( $data['_quiz_grades'] ) && is_array( $data['_quiz_grades'] ) ? $data['_quiz_grades'] : array();

		return self::result( $data, $form_config, $score, $max, $correct, $counted, array_merge( $previous, $given ) );
	}

	/**
	 * The questions of an attempt already graded, with their worth (#2241).
	 *
	 * The worth is read from the form; a question that has since left it is
	 * worth what it was given, so a regrade can lower it but not raise it.
	 *
	 * @param array<string, mixed>             $data   Stored submission data.
	 * @param array<int, array<string, mixed>> $fields Form fields configuration.
	 * @return array<string, array{label: string, worth: int, answer: string, given: int}> Keyed by question name.
	 */
	public static function graded_questions( array $data, array $fields ): array {
		$graded = isset( $data['_quiz_grades'] ) && is_array( $data['_quiz_grades'] ) ? $data['_quiz_grades'] : array();
		$out    = array();
		foreach ( $graded as $name => $given ) {
			$name  = (string) $name;
			$given = is_numeric( $given ) ? (int) $given : 0;
			$field = null;
			foreach ( $fields as $candidate ) {
				if ( ArrayValue::string( $candidate, 'name' ) === $name ) {
					$field = $candidate;
					break;
				}
			}
			$answer       = $data[ $name ] ?? '';
			$out[ $name ] = array(
				'label'  => null !== $field ? ArrayValue::string( $field, 'label', $name ) : $name,
				'worth'  => null !== $field ? max( $given, QuizQuestion::points( $field ) ) : $given,
				'answer' => is_array( $answer ) ? implode( ', ', array_map( 'strval', $answer ) ) : ( is_scalar( $answer ) ? (string) $answer : '' ),
				'given'  => $given,
			);
		}
		return $out;
	}

	/**
	 * Replace the grades an attempt was given and recompute its result (#2241).
	 *
	 * The automatic part is untouched: the difference between the new and
	 * the old grade is all that moves the score, and the maximum stays where
	 * grading left it.
	 *
	 * @param array<string, mixed>                                                        $data        Stored submission data.
	 * @param array<string, array{label: string, worth: int, answer: string, given: int}> $graded      {@see graded_questions()}.
	 * @param array<string, mixed>                                                        $form_config Form configuration.
	 * @param array<string, int>                                                          $grades      New points, keyed by question name.
	 * @return GradingResult|\WP_Error An error when a graded question has no new grade.
	 */
	public static function regrade( array $data, array $graded, array $form_config, array $grades ) {
		$score   = ArrayValue::int( $data, '_quiz_score', 0 );
		$max     = ArrayValue::int( $data, '_quiz_max_score', 0 );
		$correct = ArrayValue::int( $data, '_quiz_correct_count', 0 );
		$counted = ArrayValue::int( $data, '_quiz_question_count', 0 );
		$given   = array();

		foreach ( $graded as $name => $question ) {
			if ( ! array_key_exists( $name, $grades ) ) {
				return new \WP_Error( 'ffc_grade_missing', __( 'Give every open answer a grade.', 'ffcertificate' ) );
			}
			$new            = min( $question['worth'], max( 0, $grades[ $name ] ) );
			$given[ $name ] = $new;
			$score         += $new - $question['given'];
			$was_right      = $question['worth'] > 0 && $question['given'] >= $question['worth'];
			$is_right       = $question['worth'] > 0 && $new >= $question['worth'];
			$correct       += (int) $is_right - (int) $was_right;
		}

		$previous = isset( $data['_quiz_grades'] ) && is_array( $data['_quiz_grades'] ) ? $data['_quiz_grades'] : array();
		return self::result( $data, $form_config, max( 0, $score ), $max, max( 0, $correct ), $counted, array_merge( $previous, $given ) );
	}

	/**
	 * The attempt's data and status for a score.
	 *
	 * @param array<string, mixed> $data        Stored submission data.
	 * @param array<string, mixed> $form_config Form configuration.
	 * @param int                  $score       Points earned.
	 * @param int                  $max         Maximum points.
	 * @param int                  $correct     Questions answered right.
	 * @param int                  $counted     Questions scored.
	 * @param array<string, mixed> $grades      Grades given, keyed by question name.
	 * @return GradingResult
	 */
	private static function result( array $data, array $form_config, int $score, int $max, int $correct, int $counted, array $grades ): array {
		// Never above 100%: an attempt stored without a snapshot cannot tell which
		// of the form's manual questions its maximum already holds.
		$percent = $max > 0 ? min( 100, (int) round( ( $score / $max ) * 100 ) ) : 0;
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

		unset( $data['_quiz_pending'] );
		$data['_quiz_score']          = $score;
		$data['_quiz_max_score']      = $max;
		$data['_quiz_percent']        = $percent;
		$data['_quiz_passed']         = $passed ? '1' : '0';
		$data['_quiz_correct_count']  = $correct;
		$data['_quiz_question_count'] = $counted;
		$data['_quiz_grades']         = $grades;

		return array(
			'data'      => $data,
			'status'    => $status,
			'passed'    => $passed,
			'percent'   => $percent,
			'remaining' => $max_attempts > 0 ? max( 0, $max_attempts - $attempt ) : -1,
		);
	}
}
