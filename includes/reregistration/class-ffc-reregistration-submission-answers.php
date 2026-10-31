<?php
/**
 * Reregistration Submission Answers
 *
 * The participant's own answers to a reregistration, as label/value rows for
 * the user dashboard, so a submission that can no longer be edited can still
 * be read.
 *
 * @package FreeFormCertificate\Reregistration
 * @since 6.43.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Reregistration;

use FreeFormCertificate\Core\DateFormatter;
use FreeFormCertificate\Core\DocumentFormatter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the read-only answer list of one submission.
 *
 * @phpstan-import-type CustomFieldRow from CustomFieldReader
 */
class ReregistrationSubmissionAnswers {

	/**
	 * The answer rows of a submission, in the form's order.
	 *
	 * Personal data -- a key or mask {@see DocumentFormatter::kind_for_key()}
	 * knows, or a field marked sensitive -- is masked unless `$reveal` is
	 * true; the caller decides who may reveal.
	 *
	 * @param object $submission Submission row.
	 * @param bool   $reveal     Draw personal data in clear.
	 * @return array{rows: list<array{key: string, label: string, value: string}>, has_masked: bool}|null Null when the campaign is gone.
	 */
	public static function build( object $submission, bool $reveal ): ?array {
		$rereg = ReregistrationRepository::get_by_id( (int) ( $submission->reregistration_id ?? 0 ) );
		if ( ! $rereg ) {
			return null;
		}

		$fields = RecordGenerator::get_custom_fields_for_reregistration( $rereg, true );
		$data   = ! empty( $submission->data ) ? json_decode( (string) $submission->data, true ) : array();
		$raw    = is_array( $data ) && is_array( $data['fields'] ?? null ) ? $data['fields'] : array();
		$values = RecordGenerator::decrypt_field_values( $fields, $raw );

		$holds_second_post = ReregistrationDataProcessor::holds_second_post( $values );

		$rows       = array();
		$has_masked = false;
		foreach ( $fields as $field ) {
			$type = (string) $field->field_type;
			$key  = (string) $field->field_key;
			if ( in_array( $type, CustomFieldReader::DISPLAY_ONLY_TYPES, true ) ) {
				continue;
			}
			if ( ! $holds_second_post && in_array( $key, ReregistrationDataProcessor::SECOND_POST_KEYS, true ) ) {
				continue;
			}

			$value = $values[ $key ] ?? '';
			$mask  = (string) ( $field->field_mask ?? '' );
			$text  = self::display( $field, $value );

			$personal = null !== DocumentFormatter::kind_for_key( $key, $mask ) || ! empty( $field->is_sensitive );
			if ( $personal && ! $reveal && '' !== $text && is_scalar( $value ) ) {
				$text       = DocumentFormatter::mask_any( $key, (string) $value, $mask );
				$has_masked = true;
			} elseif ( '' !== $text ) {
				$text = RecordGenerator::apply_display_mask( $mask, $text );
			}

			$rows[] = array(
				'key'   => $key,
				'label' => (string) $field->field_label,
				'value' => $text,
			);
		}

		return array(
			'rows'       => $rows,
			'has_masked' => $has_masked,
		);
	}

	/**
	 * One value as plain text.
	 *
	 * @param object $field Field definition.
	 * @param mixed  $value Plain value.
	 * @phpstan-param CustomFieldRow $field
	 * @return string
	 */
	private static function display( object $field, $value ): string {
		$type = (string) $field->field_type;

		if ( 'working_hours' === $type ) {
			return self::working_hours( is_string( $value ) ? $value : (string) wp_json_encode( $value ) );
		}

		if ( 'date' === $type ) {
			$date = is_scalar( $value ) ? (string) $value : '';
			return DateFormatter::is_valid_date( $date ) ? DateFormatter::format_wallclock_date( $date ) : $date;
		}

		return RecordGenerator::format_field_value( $field, $value );
	}

	/**
	 * Working hours as one line per day: "Mon 08:00–12:00, 13:00–17:00".
	 *
	 * @param string $json Stored working-hours JSON.
	 * @return string
	 */
	private static function working_hours( string $json ): string {
		$entries = json_decode( $json, true );
		if ( ! is_array( $entries ) ) {
			return '';
		}

		$days  = array(
			0 => __( 'Sun', 'ffcertificate' ),
			1 => __( 'Mon', 'ffcertificate' ),
			2 => __( 'Tue', 'ffcertificate' ),
			3 => __( 'Wed', 'ffcertificate' ),
			4 => __( 'Thu', 'ffcertificate' ),
			5 => __( 'Fri', 'ffcertificate' ),
			6 => __( 'Sat', 'ffcertificate' ),
		);
		$lines = array();
		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$spans = array();
			foreach ( array( array( 'entry1', 'exit1' ), array( 'entry2', 'exit2' ) ) as $pair ) {
				$from = (string) ( $entry[ $pair[0] ] ?? '' );
				$to   = (string) ( $entry[ $pair[1] ] ?? '' );
				if ( '' !== $from || '' !== $to ) {
					$spans[] = $from . '–' . $to;
				}
			}
			if ( empty( $spans ) ) {
				continue;
			}
			$lines[] = ( $days[ (int) ( $entry['day'] ?? 0 ) ] ?? '' ) . ' ' . implode( ', ', $spans );
		}

		return implode( "\n", $lines );
	}
}
