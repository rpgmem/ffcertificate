<?php
/**
 * Custom Field Validator
 *
 * Validates field values against their definitions — type-specific rules,
 * format validation (CPF, email, phone, regex), and complex types
 * (working_hours, dependent_select).
 *
 * Extracted from CustomFieldRepository to separate validation concerns
 * from data persistence.
 *
 * @since   5.2.0
 * @package FreeFormCertificate\Reregistration
 */

declare(strict_types=1);

namespace FreeFormCertificate\Reregistration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates custom field values against their definitions.
 *
 * @since 5.2.0
 *
 * @phpstan-import-type CustomFieldRow from CustomFieldRepository
 */
class CustomFieldValidator {

	/**
	 * Validate a field value against its definition.
	 *
	 * @param object $field Field definition object.
	 * @phpstan-param CustomFieldRow $field
	 * @param mixed  $value Value to validate.
	 * @return true|\WP_Error True if valid, WP_Error with message if invalid.
	 */
	public static function validate( object $field, $value ) {
		// Required check.
		if ( ! empty( $field->is_required ) && self::is_empty_value( $value ) ) {
			return new \WP_Error(
				'field_required',
				/* translators: %s: field label */
				sprintf( __( '%s is required.', 'ffcertificate' ), $field->field_label )
			);
		}

		// Skip further validation if empty and not required.
		if ( self::is_empty_value( $value ) ) {
			return true;
		}

		// Type-specific validation.
		switch ( $field->field_type ) {
			case 'number':
				if ( ! is_numeric( $value ) ) {
					return new \WP_Error(
						'field_invalid_number',
						/* translators: %s: field label */
						sprintf( __( '%s must be a number.', 'ffcertificate' ), $field->field_label )
					);
				}
				break;

			case 'date':
				if ( ! self::is_valid_date( $value ) ) {
					return new \WP_Error(
						'field_invalid_date',
						/* translators: %s: field label */
						sprintf( __( '%s must be a valid date (YYYY-MM-DD).', 'ffcertificate' ), $field->field_label )
					);
				}
				break;

			case 'select':
				$options = CustomFieldRepository::get_field_choices( $field );
				if ( ! empty( $options ) && ! in_array( $value, $options, true ) ) {
					return new \WP_Error(
						'field_invalid_option',
						/* translators: %s: field label */
						sprintf( __( '%s has an invalid selection.', 'ffcertificate' ), $field->field_label )
					);
				}
				break;

			case 'dependent_select':
				$dep_result = self::validate_dependent_select( $field, $value );
				if ( is_wp_error( $dep_result ) ) {
					return $dep_result;
				}
				break;

			case 'working_hours':
				$wh_result = self::validate_working_hours( $field, $value );
				if ( is_wp_error( $wh_result ) ) {
					return $wh_result;
				}
				break;
		}

		// Format validation from validation_rules.
		$rules = CustomFieldRepository::get_validation_rules( $field );
		if ( ! empty( $rules ) ) {
			$format_result = self::validate_format( $field, $value, $rules );
			if ( is_wp_error( $format_result ) ) {
				return $format_result;
			}
		}

		return true;
	}

	/**
	 * Validate value format against validation rules.
	 *
	 * @param object               $field Field definition.
	 * @phpstan-param CustomFieldRow $field
	 * @param mixed                $value Value to validate.
	 * @param array<string, mixed> $rules Validation rules.
	 * @return true|\WP_Error
	 */
	private static function validate_format( object $field, $value, array $rules ) {
		$str_value = (string) $value;

		// Min/max length.
		if ( isset( $rules['min_length'] ) && mb_strlen( $str_value ) < (int) $rules['min_length'] ) {
			return new \WP_Error(
				'field_too_short',
				/* translators: 1: field label, 2: minimum length */
				sprintf( __( '%1$s must be at least %2$d characters.', 'ffcertificate' ), $field->field_label, (int) $rules['min_length'] )
			);
		}

		if ( isset( $rules['max_length'] ) && mb_strlen( $str_value ) > (int) $rules['max_length'] ) {
			return new \WP_Error(
				'field_too_long',
				/* translators: 1: field label, 2: maximum length */
				sprintf( __( '%1$s must be at most %2$d characters.', 'ffcertificate' ), $field->field_label, (int) $rules['max_length'] )
			);
		}

		// Built-in format validation.
		if ( ! empty( $rules['format'] ) ) {
			switch ( $rules['format'] ) {
				case 'cpf':
					if ( ! \FreeFormCertificate\Core\DocumentFormatter::validate_cpf( $str_value ) ) {
						return new \WP_Error(
							'field_invalid_cpf',
							/* translators: %s: field label */
							sprintf( __( '%s must be a valid CPF.', 'ffcertificate' ), $field->field_label )
						);
					}
					break;

				case 'email':
					if ( ! is_email( $str_value ) ) {
						return new \WP_Error(
							'field_invalid_email',
							/* translators: %s: field label */
							sprintf( __( '%s must be a valid email address.', 'ffcertificate' ), $field->field_label )
						);
					}
					break;

				case 'phone':
					if ( ! \FreeFormCertificate\Core\DocumentFormatter::validate_phone( $str_value ) ) {
						return new \WP_Error(
							'field_invalid_phone',
							/* translators: %s: field label */
							sprintf( __( '%s must be a valid phone number.', 'ffcertificate' ), $field->field_label )
						);
					}
					break;

				case 'custom_regex':
					if ( ! empty( $rules['custom_regex'] ) ) {
						$regex = (string) $rules['custom_regex'];
						if ( '/' !== $regex[0] && '~' !== $regex[0] && '#' !== $regex[0] ) {
							$regex = '~' . $regex . '~';
						}
						if ( @preg_match( $regex, '' ) === false ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid patterns treated as "no rule".
							break;
						}
						if ( ! preg_match( $regex, $str_value ) ) {
							$message = ! empty( $rules['custom_regex_message'] )
								? $rules['custom_regex_message']
								/* translators: %s: field label */
								: sprintf( __( '%s has an invalid format.', 'ffcertificate' ), $field->field_label );
							return new \WP_Error( 'field_invalid_format', $message );
						}
					}
					break;
			}
		}

		return true;
	}

	/**
	 * Validate working_hours JSON value.
	 *
	 * @param object $field Field definition.
	 * @phpstan-param CustomFieldRow $field
	 * @param mixed  $value Raw value (JSON string or array).
	 * @return true|\WP_Error
	 */
	private static function validate_working_hours( object $field, $value ) {
		$time_re = '/^\d{2}:\d{2}$/';
		$entries = is_string( $value ) ? json_decode( $value, true ) : $value;

		if ( ! is_array( $entries ) ) {
			return new \WP_Error(
				'field_invalid_working_hours',
				/* translators: %s: field label */
				sprintf( __( '%s must be a valid working hours schedule.', 'ffcertificate' ), $field->field_label )
			);
		}

		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				/* translators: %s: field label */
				return new \WP_Error( 'field_invalid_working_hours', sprintf( __( '%s contains invalid entries.', 'ffcertificate' ), $field->field_label ) );
			}

			$day = $entry['day'] ?? null;
			if ( null === $day || ! is_numeric( $day ) || (int) $day < 0 || (int) $day > 6 ) {
				/* translators: %s: field label */
				return new \WP_Error( 'field_invalid_working_hours', sprintf( __( '%s contains an invalid day.', 'ffcertificate' ), $field->field_label ) );
			}

			$entry1 = $entry['entry1'] ?? null;
			if ( ! $entry1 || ! preg_match( $time_re, $entry1 ) ) {
				/* translators: %s: field label */
				return new \WP_Error( 'field_invalid_working_hours', sprintf( __( '%s: Entry 1 is required for each day.', 'ffcertificate' ), $field->field_label ) );
			}

			$exit2 = $entry['exit2'] ?? null;
			if ( ! $exit2 || ! preg_match( $time_re, $exit2 ) ) {
				/* translators: %s: field label */
				return new \WP_Error( 'field_invalid_working_hours', sprintf( __( '%s: Exit 2 is required for each day.', 'ffcertificate' ), $field->field_label ) );
			}

			$exit1 = $entry['exit1'] ?? '';
			if ( '' !== $exit1 && ! preg_match( $time_re, $exit1 ) ) {
				/* translators: %s: field label */
				return new \WP_Error( 'field_invalid_working_hours', sprintf( __( '%s contains an invalid time.', 'ffcertificate' ), $field->field_label ) );
			}
			$entry2 = $entry['entry2'] ?? '';
			if ( '' !== $entry2 && ! preg_match( $time_re, $entry2 ) ) {
				/* translators: %s: field label */
				return new \WP_Error( 'field_invalid_working_hours', sprintf( __( '%s contains an invalid time.', 'ffcertificate' ), $field->field_label ) );
			}
		}

		return true;
	}

	/**
	 * Validate a dependent_select field value.
	 *
	 * @param object $field Field definition.
	 * @phpstan-param CustomFieldRow $field
	 * @param mixed  $value Raw value (JSON string or array).
	 * @return true|\WP_Error
	 */
	private static function validate_dependent_select( object $field, $value ) {
		$parsed = is_string( $value ) ? json_decode( $value, true ) : $value;

		if ( ! is_array( $parsed ) || ! isset( $parsed['parent'], $parsed['child'] ) ) {
			return new \WP_Error(
				'field_invalid_dependent_select',
				/* translators: %s: field label */
				sprintf( __( '%s requires both selections.', 'ffcertificate' ), $field->field_label )
			);
		}

		$groups = CustomFieldRepository::get_dependent_choices( $field );
		if ( empty( $groups ) ) {
			return true;
		}

		$parent_val = $parsed['parent'];
		$child_val  = $parsed['child'];

		if ( ! isset( $groups[ $parent_val ] ) ) {
			return new \WP_Error(
				'field_invalid_dependent_select',
				/* translators: %s: field label */
				sprintf( __( '%s has an invalid primary selection.', 'ffcertificate' ), $field->field_label )
			);
		}

		if ( ! in_array( $child_val, $groups[ $parent_val ], true ) ) {
			return new \WP_Error(
				'field_invalid_dependent_select',
				/* translators: %s: field label */
				sprintf( __( '%s has an invalid secondary selection.', 'ffcertificate' ), $field->field_label )
			);
		}

		return true;
	}

	/**
	 * Check if a value is empty (considering various types).
	 *
	 * @param mixed $value Value to check.
	 * @return bool
	 */
	public static function is_empty_value( $value ): bool {
		if ( null === $value || '' === $value || array() === $value ) {
			return true;
		}
		if ( is_string( $value ) && ( '' === trim( $value ) || '[]' === $value ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Validate a date string (YYYY-MM-DD format).
	 *
	 * @param string $date Date string.
	 * @return bool
	 */
	public static function is_valid_date( string $date ): bool {
		$d = \DateTime::createFromFormat( 'Y-m-d', $date );
		return $d && $d->format( 'Y-m-d' ) === $date;
	}
}
