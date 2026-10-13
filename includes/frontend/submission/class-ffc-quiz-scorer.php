<?php
/**
 * QuizScorer — scores a quiz submission on the server (#1730).
 *
 * Every question decides two things: how many points it earned out of how
 * many, and whether it was answered right as a whole. The first makes the
 * percentage; the second makes `{{correct_count}}` and the per-question
 * feedback. A question {@see QuizQuestion::is_scored()} leaves out counts in
 * neither.
 *
 * A question a person grades (#1732) is worth its points like any other, but
 * earns nothing until its grade is supplied: until then it is listed under
 * `pending` and counts in neither the results nor the correct answers.
 *
 * @package FreeFormCertificate\Frontend\Submission
 * @since 6.39.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Frontend\Submission;

use FreeFormCertificate\Core\FormField;
use FreeFormCertificate\Core\QuizQuestion;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Computes score, maximum, percentage and per-question results.
 *
 * @phpstan-type QuizResult array{score: int, max_score: int, percent: int, correct_count: int, question_count: int, results: array<string, bool>, pending: list<string>}
 */
final class QuizScorer {

	/**
	 * Score one submission.
	 *
	 * @param array<int, array<string, mixed>> $fields_config   Form fields configuration.
	 * @param array<string, mixed>             $submission_data Sanitized answers.
	 * @param array<string, int>               $grades          Points a person gave each manually graded question.
	 * @return QuizResult
	 */
	public function score( array $fields_config, array $submission_data, array $grades = array() ): array {
		$score   = 0;
		$max     = 0;
		$results = array();
		$pending = array();

		foreach ( $fields_config as $field ) {
			if ( ! QuizQuestion::is_scored( $field ) ) {
				continue;
			}
			$name = is_scalar( $field['name'] ?? null ) ? (string) $field['name'] : '';
			if ( '' === $name ) {
				continue;
			}

			if ( QuizQuestion::is_manual( $field ) ) {
				$worth = QuizQuestion::points( $field );
				$max  += $worth;
				if ( ! array_key_exists( $name, $grades ) ) {
					$pending[] = $name;
					continue;
				}
				$earned           = min( $worth, max( 0, $grades[ $name ] ) );
				$score           += $earned;
				$results[ $name ] = $worth > 0 && $earned >= $worth;
				continue;
			}

			list( $earned, $worth, $right ) = $this->question( $field, $this->answers( $submission_data[ $name ] ?? null ) );

			$score           += $earned;
			$max             += $worth;
			$results[ $name ] = $right;
		}

		return array(
			'score'          => $score,
			'max_score'      => $max,
			'percent'        => $max > 0 ? (int) round( ( $score / $max ) * 100 ) : 0,
			'correct_count'  => count( array_filter( $results ) ),
			'question_count' => count( $results ),
			'results'        => $results,
			'pending'        => $pending,
		);
	}

	/**
	 * Points earned, points available and whether the answer is right.
	 *
	 * @param array<string, mixed> $field   Stored field.
	 * @param array<int, string>   $answers Non-empty answers, decoded.
	 * @return array{0: int, 1: int, 2: bool}
	 */
	private function question( array $field, array $answers ): array {
		$type = FormField::type( $field );

		if ( in_array( $type, FormField::CHOICE_TYPES, true ) ) {
			return QuizQuestion::MODE_CORRECT === QuizQuestion::mode( $field )
				? $this->by_correct( $field, $type, $answers )
				: $this->by_points( $field, $type, $answers );
		}

		$worth = QuizQuestion::points( $field );
		$value = $answers[0] ?? '';

		if ( in_array( $type, FormField::LENGTH_TYPES, true ) ) {
			$right = '' !== $value;
			return array( $right ? $worth : 0, $worth, $right );
		}

		$right = '' !== $value && $this->in_range( $type, $value, QuizQuestion::answer_min( $field ), QuizQuestion::answer_max( $field ) );
		return array( $right ? $worth : 0, $worth, $right );
	}

	/**
	 * A choice question right only on the correct option — on a checkbox,
	 * on exactly the correct set: every correct option and no other.
	 *
	 * @param array<string, mixed> $field   Stored field.
	 * @param string               $type    Field type.
	 * @param array<int, string>   $answers Answers.
	 * @return array{0: int, 1: int, 2: bool}
	 */
	private function by_correct( array $field, string $type, array $answers ): array {
		$worth   = QuizQuestion::points( $field );
		$correct = array_map( array( $this, 'plain' ), QuizQuestion::correct( $field ) );

		if ( 'checkbox' === $type ) {
			$chosen = array_values( array_unique( $answers ) );
			sort( $chosen );
			sort( $correct );
			$right = $chosen === $correct;
		} else {
			$right = 1 === count( $answers ) && in_array( $answers[0], $correct, true );
		}
		return array( $right ? $worth : 0, $worth, $right );
	}

	/**
	 * A choice question scored by the points of what was chosen.
	 *
	 * A radio or select earns its option's points, out of the best option.
	 * A checkbox sums the boxes ticked, never below zero, out of every
	 * positive option together — so a wrong box costs what it is worth and
	 * ticking everything is not a strategy.
	 *
	 * @param array<string, mixed> $field   Stored field.
	 * @param string               $type    Field type.
	 * @param array<int, string>   $answers Answers.
	 * @return array{0: int, 1: int, 2: bool}
	 */
	private function by_points( array $field, string $type, array $answers ): array {
		$options = array_map( array( $this, 'plain' ), FormField::choices( $field ) );
		$points  = QuizQuestion::option_points( $field );

		$chosen = array();
		foreach ( $options as $i => $option ) {
			if ( in_array( $option, $answers, true ) ) {
				$chosen[] = $points[ $i ] ?? 0;
			}
		}

		if ( 'checkbox' === $type ) {
			$worth  = array_sum( array_filter( $points, static fn( int $p ): bool => $p > 0 ) );
			$earned = max( 0, array_sum( $chosen ) );
		} else {
			$worth  = array() === $points ? 0 : max( 0, max( $points ) );
			$earned = $chosen[0] ?? 0;
		}

		return array( $earned, $worth, $worth > 0 && $earned >= $worth );
	}

	/**
	 * Whether a value falls in an inclusive range; an empty bound is open.
	 *
	 * @param string $type  Field type.
	 * @param string $value Answer.
	 * @param string $min   Lowest correct value, or ''.
	 * @param string $max   Highest correct value, or ''.
	 */
	private function in_range( string $type, string $value, string $min, string $max ): bool {
		if ( '' === FormField::bound( $type, $value ) ) {
			return false;
		}
		if ( '' !== $min && FormField::compare( $type, $value, $min ) < 0 ) {
			return false;
		}
		return '' === $max || FormField::compare( $type, $value, $max ) <= 0;
	}

	/**
	 * The non-empty answers of one field, as plain text.
	 *
	 * @param mixed $raw Stored answer.
	 * @return list<string>
	 */
	private function answers( $raw ): array {
		$list    = is_array( $raw ) ? $raw : array( $raw );
		$answers = array();
		foreach ( $list as $item ) {
			if ( ! is_scalar( $item ) ) {
				continue;
			}
			$item = trim( $this->plain( (string) $item ) );
			if ( '' !== $item ) {
				$answers[] = $item;
			}
		}
		return $answers;
	}

	/**
	 * Text as the participant typed it: the sanitizer stores `&` as `&amp;`.
	 *
	 * @param string $value Stored text.
	 */
	private function plain( string $value ): string {
		return html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}
}
