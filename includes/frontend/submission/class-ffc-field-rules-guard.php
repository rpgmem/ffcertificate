<?php
/**
 * FieldRulesGuard — enforces each field's own rules on the server (#1729).
 *
 * Until this stage the server checked one field, the email: "required", a
 * text's length, a number's range and whether a choice was one of the
 * options were all the browser's word, so an edited page could post any of
 * them. This stage reads the sanitized answers and refuses the submission
 * with every broken rule at once, in the shape RequiredPresenceGuard already uses.
 *
 * Runs after FieldSanitizer, so it sees the values that will be stored.
 *
 * @package FreeFormCertificate\Frontend\Submission
 * @since 6.39.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Frontend\Submission;

use FreeFormCertificate\Core\FormField;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per-field rules gate: required, options, length, range, a full name.
 */
class FieldRulesGuard {

	/**
	 * Types that collect nothing from the participant.
	 *
	 * @var array<int, string>
	 */
	private const NO_INPUT = array( 'info', 'embed', 'hidden' );

	/**
	 * Reject the request when any field breaks its rules.
	 *
	 * @param SubmissionContext $ctx Submission context.
	 * @throws SubmissionRejected With every broken rule at once.
	 */
	public function apply( SubmissionContext $ctx ): void {
		$errors = array();
		foreach ( $ctx->fields_config as $field ) {
			$error = $this->check( $field, $ctx->submission_data );
			if ( null !== $error ) {
				$errors[] = $error;
			}
		}

		if ( array() !== $errors ) {
			throw new SubmissionRejected(
				array(
					'message' => $errors[0],
					'errors'  => $errors,
				)
			);
		}
	}

	/**
	 * The rule one field's answer breaks, or null.
	 *
	 * @param array<array-key, mixed> $field Stored field.
	 * @param array<string, mixed>    $data  Sanitized answers.
	 */
	public function check( array $field, array $data ): ?string {
		$type = FormField::type( $field );
		$name = is_scalar( $field['name'] ?? null ) ? (string) $field['name'] : '';
		if ( '' === $name || in_array( $type, self::NO_INPUT, true ) ) {
			return null;
		}
		$label = is_scalar( $field['label'] ?? null ) && '' !== (string) $field['label'] ? (string) $field['label'] : $name;

		$values = $this->values( $data[ $name ] ?? null );
		if ( array() === $values ) {
			// The email's presence is RequiredPresenceGuard's, which reports it
			// together with the consent.
			if ( ! empty( $field['required'] ) && 'email' !== $type ) {
				return 'checkbox' === $type
					/* translators: %s: field label. */
					? sprintf( __( '%s: choose at least one option.', 'ffcertificate' ), $label )
					/* translators: %s: field label. */
					: sprintf( __( '%s is required.', 'ffcertificate' ), $label );
			}
			return null;
		}

		if ( in_array( $type, FormField::CHOICE_TYPES, true ) ) {
			return $this->check_choices( $field, $type, $label, $data[ $name ], $values );
		}

		// Only a checkbox answers with a list.
		if ( is_array( $data[ $name ] ) ) {
			/* translators: %s: field label. */
			return sprintf( __( '%s has an invalid answer.', 'ffcertificate' ), $label );
		}
		$value = $values[0];

		if ( in_array( $type, FormField::LENGTH_TYPES, true ) ) {
			if ( FormField::is_full_name( $field ) && ! FormField::looks_like_full_name( $value ) ) {
				return sprintf(
					/* translators: 1: field label, 2: fewest characters. */
					__( '%1$s: enter the full name — at least two words and %2$s characters.', 'ffcertificate' ),
					$label,
					number_format_i18n( FormField::FULL_NAME_MIN_LENGTH )
				);
			}
			return $this->check_length( $field, $label, $value );
		}
		if ( 'number' === $type ) {
			return $this->check_number( $field, $label, $value );
		}
		if ( 'date' === $type ) {
			return $this->check_date( $field, $label, $value );
		}
		return null;
	}

	/**
	 * Options rules: each answer is an option, a single-choice field has one
	 * answer, and a checkbox respects its maximum.
	 *
	 * @param array<array-key, mixed> $field  Stored field.
	 * @param string                  $type   Field type.
	 * @param string                  $label  Field label.
	 * @param mixed                   $raw    Posted answer.
	 * @param array<int, string>      $values Non-empty answers, decoded.
	 */
	private function check_choices( array $field, string $type, string $label, $raw, array $values ): ?string {
		if ( 'checkbox' !== $type && ( is_array( $raw ) || count( $values ) > 1 ) ) {
			/* translators: %s: field label. */
			return sprintf( __( '%s has an invalid answer.', 'ffcertificate' ), $label );
		}

		$options = array_map( array( $this, 'plain' ), FormField::choices( $field ) );
		foreach ( $values as $value ) {
			if ( ! in_array( $value, $options, true ) ) {
				/* translators: %s: field label. */
				return sprintf( __( '%s has an answer that is not one of its options.', 'ffcertificate' ), $label );
			}
		}

		$max = FormField::max_select( $field );
		if ( $max > 0 && count( $values ) > $max ) {
			return sprintf(
				/* translators: 1: field label, 2: most options the participant may tick. */
				_n( '%1$s: choose up to %2$s option.', '%1$s: choose up to %2$s options.', $max, 'ffcertificate' ),
				$label,
				number_format_i18n( $max )
			);
		}
		return null;
	}

	/**
	 * Length rules of a text or textarea answer, counted in characters.
	 *
	 * @param array<array-key, mixed> $field Stored field.
	 * @param string                  $label Field label.
	 * @param string                  $value Answer, decoded.
	 */
	private function check_length( array $field, string $label, string $value ): ?string {
		$length = mb_strlen( $value );
		$min    = FormField::min_length( $field );
		$max    = FormField::max_length( $field );
		if ( $min > 0 && $length < $min ) {
			/* translators: 1: field label, 2: fewest characters. */
			return sprintf( __( '%1$s needs at least %2$s characters.', 'ffcertificate' ), $label, number_format_i18n( $min ) );
		}
		if ( $max > 0 && $length > $max ) {
			/* translators: 1: field label, 2: most characters. */
			return sprintf( __( '%1$s accepts up to %2$s characters.', 'ffcertificate' ), $label, number_format_i18n( $max ) );
		}
		return null;
	}

	/**
	 * Number rules: numeric, within range, no more decimals than allowed.
	 *
	 * @param array<array-key, mixed> $field Stored field.
	 * @param string                  $label Field label.
	 * @param string                  $value Answer.
	 */
	private function check_number( array $field, string $label, string $value ): ?string {
		if ( ! is_numeric( $value ) ) {
			/* translators: %s: field label. */
			return sprintf( __( '%s must be a number.', 'ffcertificate' ), $label );
		}
		if ( FormField::decimals_in( $value ) > FormField::decimals( $field ) ) {
			return 0 === FormField::decimals( $field )
				/* translators: %s: field label. */
				? sprintf( __( '%s must be a whole number.', 'ffcertificate' ), $label )
				/* translators: 1: field label, 2: decimal places allowed. */
				: sprintf( __( '%1$s accepts up to %2$s decimal places.', 'ffcertificate' ), $label, number_format_i18n( FormField::decimals( $field ) ) );
		}
		return $this->check_range( $field, 'number', $label, $value );
	}

	/**
	 * Date rules: a real `Y-m-d` date within range.
	 *
	 * @param array<array-key, mixed> $field Stored field.
	 * @param string                  $label Field label.
	 * @param string                  $value Answer.
	 */
	private function check_date( array $field, string $label, string $value ): ?string {
		if ( ! FormField::is_date( $value ) ) {
			/* translators: %s: field label. */
			return sprintf( __( '%s must be a valid date.', 'ffcertificate' ), $label );
		}
		return $this->check_range( $field, 'date', $label, $value );
	}

	/**
	 * Whether a number or date lies within the field's bounds.
	 *
	 * @param array<array-key, mixed> $field Stored field.
	 * @param string                  $type  `number` or `date`.
	 * @param string                  $label Field label.
	 * @param string                  $value Answer.
	 */
	private function check_range( array $field, string $type, string $label, string $value ): ?string {
		$min = FormField::min( $field );
		$max = FormField::max( $field );
		if ( ( '' !== $min && FormField::compare( $type, $value, $min ) < 0 ) || ( '' !== $max && FormField::compare( $type, $value, $max ) > 0 ) ) {
			$range = ( new \FreeFormCertificate\Frontend\FormFieldRenderer() )->hint( $field );
			/* translators: 1: field label, 2: the accepted range, as a sentence. */
			return sprintf( __( '%1$s is out of range. %2$s', 'ffcertificate' ), $label, $range );
		}
		return null;
	}

	/**
	 * The non-empty answers of one field, as plain text.
	 *
	 * @param mixed $raw Posted answer.
	 * @return list<string>
	 */
	private function values( $raw ): array {
		$list   = is_array( $raw ) ? $raw : array( $raw );
		$values = array();
		foreach ( $list as $item ) {
			if ( ! is_scalar( $item ) ) {
				continue;
			}
			$item = trim( $this->plain( (string) $item ) );
			if ( '' !== $item ) {
				$values[] = $item;
			}
		}
		return $values;
	}

	/**
	 * Text as the participant typed it.
	 *
	 * The sanitizer runs answers through `wp_kses`, which writes a bare `&`
	 * as `&amp;`; counting or comparing that would make "A & B" four
	 * characters longer and never equal to its own option.
	 *
	 * @param string $text Sanitized text.
	 */
	private function plain( string $text ): string {
		return html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}
}
