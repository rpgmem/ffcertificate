<?php
/**
 * QuizQuestion
 *
 * The one reader of a form field's quiz configuration (#1730): whether the
 * field is scored, how, and what counts as right. FormField reads what the
 * field asks of the participant; this reads what the quiz makes of it.
 *
 * A field saved before #1730 has none of these keys. It is scored exactly as
 * it was — a radio or select with a points string, per option — so opening
 * an older form changes no score until the administrator changes the field.
 *
 * @package FreeFormCertificate\Core
 * @since 6.39.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stored quiz-configuration reads and save-time sanitising for a form field.
 */
final class QuizQuestion {

	/**
	 * Choice field scored when a correct option is chosen.
	 */
	public const MODE_CORRECT = 'correct';

	/**
	 * Choice field scored by the points of the options chosen.
	 */
	public const MODE_POINTS = 'points';

	/**
	 * Text answer earns nothing.
	 */
	public const TEXT_NONE = 'none';

	/**
	 * Text answer earns the question's points when it is answered.
	 */
	public const TEXT_ANSWERED = 'answered';

	/**
	 * Points a question is worth when nothing else was configured.
	 */
	public const DEFAULT_POINTS = 1;

	/**
	 * Types the participant answers, so a quiz can score them.
	 *
	 * @var array<int, string>
	 */
	public const SCORABLE_TYPES = array( 'select', 'radio', 'checkbox', 'text', 'textarea', 'number', 'date' );

	/**
	 * Types a pre-#1730 form scored, and only with a points string.
	 *
	 * @var array<int, string>
	 */
	private const LEGACY_TYPES = array( 'radio', 'select' );

	/**
	 * Whether the field takes part in the score.
	 *
	 * An explicit choice wins. Without one the pre-#1730 rule applies, and a
	 * question that cannot be marked (no correct option, no answer range, a
	 * text that earns nothing) never counts, whatever the toggle says, so it
	 * cannot lower everybody's percentage.
	 *
	 * @param array<array-key, mixed> $field Stored field.
	 */
	public static function is_scored( array $field ): bool {
		$type = FormField::type( $field );
		if ( ! in_array( $type, self::SCORABLE_TYPES, true ) ) {
			return false;
		}

		$flag = $field['quiz_scored'] ?? null;
		if ( null === $flag ) {
			return in_array( $type, self::LEGACY_TYPES, true ) && '' !== trim( ArrayValue::string( $field, 'points' ) );
		}
		if ( '1' !== (string) $flag ) {
			return false;
		}

		if ( in_array( $type, FormField::CHOICE_TYPES, true ) ) {
			return self::MODE_CORRECT === self::mode( $field )
				? array() !== self::correct( $field )
				: array() !== self::option_points( $field );
		}
		if ( in_array( $type, FormField::LENGTH_TYPES, true ) ) {
			return self::TEXT_ANSWERED === self::text_mode( $field );
		}
		return '' !== self::answer_min( $field ) || '' !== self::answer_max( $field );
	}

	/**
	 * How a choice field is scored. A field without the key keeps the
	 * points model it was saved with.
	 *
	 * @param array<array-key, mixed> $field Stored field.
	 */
	public static function mode( array $field ): string {
		return self::MODE_CORRECT === ArrayValue::string( $field, 'quiz_mode' ) ? self::MODE_CORRECT : self::MODE_POINTS;
	}

	/**
	 * The options marked correct, as they are stored in `choices`.
	 *
	 * @param array<array-key, mixed> $field Stored field.
	 * @return list<string>
	 */
	public static function correct( array $field ): array {
		$raw = $field['correct'] ?? array();
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$choices = FormField::choices( $field );
		$correct = array();
		foreach ( $raw as $item ) {
			if ( is_scalar( $item ) && in_array( (string) $item, $choices, true ) && ! in_array( (string) $item, $correct, true ) ) {
				$correct[] = (string) $item;
			}
		}
		return $correct;
	}

	/**
	 * Points of each option, in option order. An option with no number is
	 * worth 0; a field with no number at all has no points.
	 *
	 * @param array<array-key, mixed> $field Stored field.
	 * @return list<int>
	 */
	public static function option_points( array $field ): array {
		$raw = trim( ArrayValue::string( $field, 'points' ) );
		if ( '' === $raw ) {
			return array();
		}
		$count  = count( FormField::choices( $field ) );
		$points = $count > 0 ? array_fill( 0, $count, 0 ) : array();
		foreach ( explode( ',', $raw ) as $i => $value ) {
			$value = trim( $value );
			if ( '' !== $value && is_numeric( $value ) ) {
				$points[ $i ] = (int) $value;
			} elseif ( ! isset( $points[ $i ] ) ) {
				$points[ $i ] = 0;
			}
		}
		return array_values( $points );
	}

	/**
	 * Points the question is worth when it is right as a whole.
	 *
	 * @param array<array-key, mixed> $field Stored field.
	 */
	public static function points( array $field ): int {
		$raw = ArrayValue::string( $field, 'quiz_points' );
		return '' === $raw ? self::DEFAULT_POINTS : absint( $raw );
	}

	/**
	 * How a text or textarea answer is scored.
	 *
	 * @param array<array-key, mixed> $field Stored field.
	 */
	public static function text_mode( array $field ): string {
		return self::TEXT_ANSWERED === ArrayValue::string( $field, 'quiz_text' ) ? self::TEXT_ANSWERED : self::TEXT_NONE;
	}

	/**
	 * Lowest correct answer of a number or date field, or ''.
	 *
	 * @param array<array-key, mixed> $field Stored field.
	 */
	public static function answer_min( array $field ): string {
		return FormField::bound( FormField::type( $field ), ArrayValue::string( $field, 'quiz_answer_min' ) );
	}

	/**
	 * Highest correct answer of a number or date field, or ''.
	 *
	 * @param array<array-key, mixed> $field Stored field.
	 */
	public static function answer_max( array $field ): string {
		return FormField::bound( FormField::type( $field ), ArrayValue::string( $field, 'quiz_answer_max' ) );
	}

	/**
	 * The stored quiz shape of one editor row.
	 *
	 * The option rows post their points and their "correct" mark as arrays
	 * aligned with the options, so the order the administrator dragged them
	 * into is the order the values are read in. A row posted without the
	 * quiz block (an older editor) keeps whatever points string it sent and
	 * gains no `quiz_scored`, so the pre-#1730 rule keeps applying to it.
	 *
	 * @param array<array-key, mixed> $row     One `ffc_fields[]` row from the request.
	 * @param string                  $type    The row's sanitized type.
	 * @param array<int, string>      $choices The row's sanitized options.
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $row, string $type, array $choices ): array {
		$clean = array();

		if ( in_array( $type, FormField::CHOICE_TYPES, true ) ) {
			$clean['points'] = sanitize_text_field( ArrayValue::string( $row, 'points' ) );
			$posted_points   = $row['choice_points'] ?? null;
			$posted_correct  = $row['choice_correct'] ?? null;
			$raw_choices     = is_array( $row['choices'] ?? null ) ? array_values( $row['choices'] ) : array();

			if ( is_array( $posted_points ) || is_array( $posted_correct ) ) {
				$points  = is_array( $posted_points ) ? array_values( $posted_points ) : array();
				$marks   = is_array( $posted_correct ) ? array_values( $posted_correct ) : array();
				$values  = array();
				$correct = array();
				$any     = false;
				foreach ( $raw_choices as $i => $choice ) {
					$choice = is_scalar( $choice ) ? sanitize_text_field( (string) $choice ) : '';
					if ( '' === $choice ) {
						continue;
					}
					$point = is_scalar( $points[ $i ] ?? null ) ? trim( (string) $points[ $i ] ) : '';
					$any   = $any || '' !== $point;
					// A number the field cannot read is worth nothing.
					$values[] = is_numeric( $point ) ? (string) (int) $point : '0';
					if ( '1' === ( is_scalar( $marks[ $i ] ?? null ) ? (string) $marks[ $i ] : '' ) ) {
						$correct[] = $choice;
					}
				}
				$clean['points']  = $any ? implode( ',', $values ) : '';
				$clean['correct'] = $correct;
			}
		}

		if ( ! isset( $row['quiz_block'] ) || ! in_array( $type, self::SCORABLE_TYPES, true ) ) {
			return $clean;
		}

		$clean['quiz_scored'] = '1' === ArrayValue::string( $row, 'quiz_scored' ) ? '1' : '0';
		$clean['quiz_points'] = (string) absint( ArrayValue::string( $row, 'quiz_points', (string) self::DEFAULT_POINTS ) );

		if ( in_array( $type, FormField::CHOICE_TYPES, true ) ) {
			$clean['quiz_mode'] = self::MODE_CORRECT === ArrayValue::string( $row, 'quiz_mode' ) ? self::MODE_CORRECT : self::MODE_POINTS;
		} elseif ( in_array( $type, FormField::LENGTH_TYPES, true ) ) {
			$clean['quiz_text'] = self::TEXT_ANSWERED === ArrayValue::string( $row, 'quiz_text' ) ? self::TEXT_ANSWERED : self::TEXT_NONE;
		} else {
			// Like the field's own range, a row carries a number and a date
			// answer block, so the date inputs post under their own keys.
			$suffix = 'date' === $type ? '_date' : '';
			$min    = FormField::bound( $type, sanitize_text_field( ArrayValue::string( $row, 'quiz_answer_min' . $suffix ) ) );
			$max    = FormField::bound( $type, sanitize_text_field( ArrayValue::string( $row, 'quiz_answer_max' . $suffix ) ) );
			if ( '' !== $min && '' !== $max && FormField::compare( $type, $min, $max ) > 0 ) {
				list( $min, $max ) = array( $max, $min );
			}
			$clean['quiz_answer_min'] = $min;
			$clean['quiz_answer_max'] = $max;
		}

		return $clean;
	}
}
