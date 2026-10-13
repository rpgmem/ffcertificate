<?php
/**
 * FormField
 *
 * The one reader of a certificate form field's stored configuration — the
 * rows of `_ffc_form_fields` — for everything that is not its label, name or
 * type: the options of a choice field and the limits of an input.
 *
 * Options were stored as one comma-separated string, so an option could not
 * contain a comma, and four places split it themselves (#1729). They are now
 * stored as a list under `choices`; a field saved before keeps its string,
 * which {@see self::choices()} still reads, and becomes a list on its next
 * save. Every consumer reads through here so the two shapes never diverge.
 *
 * The limits are bounded twice: by what the administrator configured, and by
 * the server's own ceiling for the type, which holds whatever is stored.
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
 * Stored-configuration reads and save-time sanitising for a form field.
 */
final class FormField {

	/**
	 * Longest answer a single-line text field accepts.
	 */
	public const TEXT_MAX_LENGTH = 255;

	/**
	 * Longest answer a textarea accepts.
	 */
	public const TEXTAREA_MAX_LENGTH = 2000;

	/**
	 * Most decimal places a number field may be configured to accept.
	 */
	public const MAX_DECIMALS = 4;

	/**
	 * Types whose answer is one or more of the field's options.
	 *
	 * @var array<int, string>
	 */
	public const CHOICE_TYPES = array( 'select', 'radio', 'checkbox' );

	/**
	 * Types whose answer has a length limit.
	 *
	 * @var array<int, string>
	 */
	public const LENGTH_TYPES = array( 'text', 'textarea' );

	/**
	 * Types whose answer has a range.
	 *
	 * @var array<int, string>
	 */
	public const RANGE_TYPES = array( 'number', 'date' );

	/**
	 * Shortest full name accepted on a name field (#1734).
	 */
	public const FULL_NAME_MIN_LENGTH = 4;

	/**
	 * Whether the field asks for the participant's full name: a text field
	 * under one of the keys the plugin reads the submitter's name from.
	 *
	 * @param array<array-key, mixed> $field Stored field.
	 */
	public static function is_full_name( array $field ): bool {
		return 'text' === self::type( $field )
			&& in_array( ArrayValue::string( $field, 'name' ), SubmitterName::CANDIDATE_KEYS, true );
	}

	/**
	 * Whether a name has at least two words, each with a letter, and the
	 * minimum length — "A" or "12345" never names anybody (#1734).
	 *
	 * @param string $value Answer, decoded.
	 */
	public static function looks_like_full_name( string $value ): bool {
		$value = trim( $value );
		if ( mb_strlen( $value ) < self::FULL_NAME_MIN_LENGTH ) {
			return false;
		}
		$words = preg_split( '/\s+/u', $value );
		$named = 0;
		foreach ( false === $words ? array() : $words as $word ) {
			if ( 1 === preg_match( '/\p{L}/u', $word ) ) {
				++$named;
			}
		}
		return $named >= 2;
	}

	/**
	 * Shortest answer a field accepts, the full-name floor included.
	 *
	 * @param array<array-key, mixed> $field Stored field.
	 */
	public static function effective_min_length( array $field ): int {
		$min = self::min_length( $field );
		return self::is_full_name( $field ) ? max( $min, self::FULL_NAME_MIN_LENGTH ) : $min;
	}

	/**
	 * The field's type.
	 *
	 * @param array<array-key, mixed> $field Stored field.
	 */
	public static function type( array $field ): string {
		$type = ArrayValue::string( $field, 'type', 'text' );
		return '' === $type ? 'text' : $type;
	}

	/**
	 * The options of a choice field, in the administrator's order.
	 *
	 * Reads the `choices` list, or the legacy `options` — a comma-separated
	 * string, or a list where a field was written by code rather than by the
	 * editor — when the field predates `choices`. Empty entries are dropped.
	 *
	 * @param array<array-key, mixed> $field Stored field.
	 * @return list<string>
	 */
	public static function choices( array $field ): array {
		$raw = $field['choices'] ?? null;
		if ( ! is_array( $raw ) && is_array( $field['options'] ?? null ) ) {
			$raw = $field['options'];
		}
		if ( ! is_array( $raw ) ) {
			$legacy = ArrayValue::string( $field, 'options' );
			$raw    = '' === $legacy ? array() : explode( ',', $legacy );
		}

		$choices = array();
		foreach ( $raw as $choice ) {
			if ( ! is_scalar( $choice ) ) {
				continue;
			}
			$choice = trim( (string) $choice );
			if ( '' !== $choice ) {
				$choices[] = $choice;
			}
		}
		return $choices;
	}

	/**
	 * Whether the participant sees the options in a random order.
	 *
	 * @param array<array-key, mixed> $field Stored field.
	 */
	public static function shuffles( array $field ): bool {
		return in_array( self::type( $field ), self::CHOICE_TYPES, true ) && '1' === ArrayValue::string( $field, 'shuffle' );
	}

	/**
	 * Most options a checkbox field accepts; 0 when unlimited.
	 *
	 * @param array<array-key, mixed> $field Stored field.
	 */
	public static function max_select( array $field ): int {
		if ( 'checkbox' !== self::type( $field ) ) {
			return 0;
		}
		return max( 0, ArrayValue::int( $field, 'max_select', 0 ) );
	}

	/**
	 * The server's ceiling for a length-limited type; 0 for any other.
	 *
	 * @param string $type Field type.
	 */
	public static function length_ceiling( string $type ): int {
		if ( 'textarea' === $type ) {
			return self::TEXTAREA_MAX_LENGTH;
		}
		return 'text' === $type ? self::TEXT_MAX_LENGTH : 0;
	}

	/**
	 * Longest answer the field accepts, never above the type's ceiling.
	 *
	 * @param array<array-key, mixed> $field Stored field.
	 */
	public static function max_length( array $field ): int {
		$ceiling = self::length_ceiling( self::type( $field ) );
		if ( 0 === $ceiling ) {
			return 0;
		}
		$configured = ArrayValue::int( $field, 'max_length', 0 );
		return ( $configured > 0 && $configured < $ceiling ) ? $configured : $ceiling;
	}

	/**
	 * Shortest answer the field accepts; 0 when there is no minimum.
	 *
	 * @param array<array-key, mixed> $field Stored field.
	 */
	public static function min_length( array $field ): int {
		$max = self::max_length( $field );
		if ( 0 === $max ) {
			return 0;
		}
		return min( max( 0, ArrayValue::int( $field, 'min_length', 0 ) ), $max );
	}

	/**
	 * Whether the administrator set a length limit of their own.
	 *
	 * Only a configured limit is announced above the field; the ceiling is
	 * shown by the counter alone.
	 *
	 * @param array<array-key, mixed> $field Stored field.
	 */
	public static function has_configured_length( array $field ): bool {
		return self::min_length( $field ) > 0 || self::max_length( $field ) < self::length_ceiling( self::type( $field ) );
	}

	/**
	 * Lower bound of a number or date field; '' when unbounded.
	 *
	 * @param array<array-key, mixed> $field Stored field.
	 */
	public static function min( array $field ): string {
		return self::bound( self::type( $field ), ArrayValue::string( $field, 'min' ) );
	}

	/**
	 * Upper bound of a number or date field; '' when unbounded.
	 *
	 * @param array<array-key, mixed> $field Stored field.
	 */
	public static function max( array $field ): string {
		return self::bound( self::type( $field ), ArrayValue::string( $field, 'max' ) );
	}

	/**
	 * Decimal places a number field accepts (0 = whole numbers).
	 *
	 * @param array<array-key, mixed> $field Stored field.
	 */
	public static function decimals( array $field ): int {
		if ( 'number' !== self::type( $field ) ) {
			return 0;
		}
		return min( self::MAX_DECIMALS, max( 0, ArrayValue::int( $field, 'decimals', 0 ) ) );
	}

	/**
	 * The `step` attribute a number field renders with.
	 *
	 * @param array<array-key, mixed> $field Stored field.
	 */
	public static function step( array $field ): string {
		$decimals = self::decimals( $field );
		return 0 === $decimals ? '1' : '0.' . str_repeat( '0', $decimals - 1 ) . '1';
	}

	/**
	 * A bound valid for its type, or ''.
	 *
	 * @param string $type  Field type.
	 * @param string $value Raw bound.
	 */
	public static function bound( string $type, string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		if ( 'number' === $type ) {
			return is_numeric( $value ) ? $value : '';
		}
		if ( 'date' === $type ) {
			return self::is_date( $value ) ? $value : '';
		}
		return '';
	}

	/**
	 * Whether a string is a real calendar date in `Y-m-d`.
	 *
	 * @param string $value Candidate.
	 */
	public static function is_date( string $value ): bool {
		if ( 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) ) {
			return false;
		}
		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	/**
	 * The stored shape of one editor row: options as a list, every limit
	 * bounded, and only the keys the row's type uses.
	 *
	 * The legacy `options` string is accepted when no `choices` list was
	 * posted, so a row saved by an older editor is converted rather than
	 * lost. Bounds that contradict each other are swapped rather than
	 * refused: the editor has no error channel, and a swapped range is what
	 * the administrator meant.
	 *
	 * @param array<array-key, mixed> $row  One `ffc_fields[]` row from the request.
	 * @param string                  $type The row's sanitized type.
	 * @return array<string, mixed>
	 */
	public static function sanitize_limits( array $row, string $type ): array {
		$clean = array();

		if ( in_array( $type, self::CHOICE_TYPES, true ) ) {
			$posted = $row['choices'] ?? null;
			$source = is_array( $posted ) ? array( 'choices' => $posted ) : array( 'options' => ArrayValue::string( $row, 'options' ) );

			$choices = array();
			foreach ( self::choices( $source ) as $choice ) {
				$choice = sanitize_text_field( $choice );
				if ( '' !== $choice ) {
					$choices[] = $choice;
				}
			}
			$clean['choices'] = $choices;
			$clean['shuffle'] = '1' === ArrayValue::string( $row, 'shuffle' ) ? '1' : '';
			if ( 'checkbox' === $type ) {
				$max_select          = absint( ArrayValue::string( $row, 'max_select' ) );
				$clean['max_select'] = $max_select > 0 ? (string) min( $max_select, max( 1, count( $choices ) ) ) : '';
			}
		}

		if ( in_array( $type, self::LENGTH_TYPES, true ) ) {
			$ceiling = self::length_ceiling( $type );
			$min     = absint( ArrayValue::string( $row, 'min_length' ) );
			$max     = absint( ArrayValue::string( $row, 'max_length' ) );
			$max     = ( $max > 0 && $max < $ceiling ) ? $max : 0;
			$min     = min( $min, 0 === $max ? $ceiling : $max );

			$clean['min_length'] = $min > 0 ? (string) $min : '';
			$clean['max_length'] = $max > 0 ? (string) $max : '';
		}

		if ( in_array( $type, self::RANGE_TYPES, true ) ) {
			// A row carries both a number and a date range — only one block is
			// visible — so the date inputs post under their own keys.
			$suffix = 'date' === $type ? '_date' : '';
			$min    = self::bound( $type, sanitize_text_field( ArrayValue::string( $row, 'min' . $suffix ) ) );
			$max    = self::bound( $type, sanitize_text_field( ArrayValue::string( $row, 'max' . $suffix ) ) );
			if ( '' !== $min && '' !== $max && self::compare( $type, $min, $max ) > 0 ) {
				list( $min, $max ) = array( $max, $min );
			}
			$clean['min'] = $min;
			$clean['max'] = $max;
			if ( 'number' === $type ) {
				$clean['decimals'] = (string) min( self::MAX_DECIMALS, absint( ArrayValue::string( $row, 'decimals' ) ) );
			}
		}

		return $clean;
	}

	/**
	 * Compare two values of a range type: negative, zero or positive.
	 *
	 * @param string $type Field type (`number` or `date`).
	 * @param string $a    First value.
	 * @param string $b    Second value.
	 */
	public static function compare( string $type, string $a, string $b ): int {
		if ( 'number' === $type ) {
			return (float) $a <=> (float) $b;
		}
		return strcmp( $a, $b );
	}

	/**
	 * Number of decimal places written in a numeric string.
	 *
	 * @param string $value Numeric string.
	 */
	public static function decimals_in( string $value ): int {
		$dot = strpos( $value, '.' );
		if ( false === $dot ) {
			return 0;
		}
		return strlen( rtrim( substr( $value, $dot + 1 ), '0' ) );
	}
}
