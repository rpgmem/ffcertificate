<?php
/**
 * Reregistration CSV Exporter
 *
 * Handles CSV export of reregistration submissions.
 *
 * @package FreeFormCertificate\Reregistration
 * @since 4.12.13  Extracted from ReregistrationAdmin
 */

declare(strict_types=1);

namespace FreeFormCertificate\Reregistration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exporter for reregistration csv data.
 *
 * @phpstan-import-type CustomFieldRow from CustomFieldReader
 * @phpstan-import-type ReregistrationSubmissionRow from ReregistrationSubmissionReader
 * @phpstan-import-type ReregistrationRow from ReregistrationRepository
 */
class ReregistrationCsvExporter {

	/**
	 * Handle CSV export action.
	 *
	 * Verifies nonce, fetches submission data, and streams a CSV file.
	 *
	 * @return void
	 */
	public static function handle_export(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['action'] ) || 'export_csv' !== $_GET['action'] || ! isset( $_GET['id'] ) ) {
			return;
		}

		$id = absint( $_GET['id'] );
		if ( ! wp_verify_nonce( \FreeFormCertificate\Core\RequestInput::get_get_string( '_wpnonce' ), 'export_reregistration_' . $id ) ) {
			return;
		}

		// Bulk export is its own capability tier (GAP G), split out of
		// `ffc_manage_reregistration`. The caller's page-level gate already
		// requires `manage`; this additional check lets a manager be denied the
		// dataset extraction without losing campaign management. Mirrors how the
		// delete tier (GAP E) re-checks `ffc_delete_reregistration`.
		if ( ! \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_export_reregistration' ) ) {
			return;
		}

		$rereg = ReregistrationRepository::get_by_id( $id );
		if ( ! $rereg ) {
			return;
		}

		// Stream submissions in chunks of 500 so a 50k-row reregistration
		// stays memory-bounded. The generator pipes straight into the
		// CSV writer below without materialising the full result set.
		$submissions = ReregistrationSubmissionReader::stream_for_export( $id );
		$fields      = self::get_custom_fields_for_reregistration( $rereg );

		// Build CSV.
		$filename = \FreeFormCertificate\Core\FilenameHelper::get_export_filename( 'reregistration', (string) $rereg->title );

		// Headers.
		$safe_filename = str_replace( array( "\r", "\n", '"' ), '', $filename );
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $safe_filename . '"' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming CSV download to php://output.
		$output = fopen( 'php://output', 'w' );
		if ( false === $output ) {
			exit;
		}

		// Header row — fixed metadata + all dynamic fields in repository order.
		$headers = array(
			__( 'User ID', 'ffcertificate' ),
			__( 'Name', 'ffcertificate' ),
			__( 'Email', 'ffcertificate' ),
			__( 'Status', 'ffcertificate' ),
			__( 'Submitted At', 'ffcertificate' ),
			__( 'Reviewed At', 'ffcertificate' ),
		);

		foreach ( $fields as $f ) {
			$headers[] = $f->field_label;
		}

		$writer = \FreeFormCertificate\Core\Csv::writer( $output );
		$writer->row( $headers );

		// Data rows.
		foreach ( $submissions as $sub ) {
			$sub_data = $sub->data ? json_decode( $sub->data, true ) : array();
			$values   = is_array( $sub_data['fields'] ?? null ) ? $sub_data['fields'] : array();

			// Decrypt sensitive fields in-place.
			$values = self::decrypt_sensitive( $fields, $values );

			// `submitted_at`/`reviewed_at` are unix UTC int since 6.6.0
			// (#249 sub-escopos b/d); format for human eyes in the CSV.
			$submitted_at_display = ! empty( $sub->submitted_at )
				? \FreeFormCertificate\Core\DateFormatter::format_datetime( (int) $sub->submitted_at )
				: '';
			$reviewed_at_display  = ! empty( $sub->reviewed_at )
				? \FreeFormCertificate\Core\DateFormatter::format_datetime( (int) $sub->reviewed_at )
				: '';
			$row                  = array(
				$sub->user_id,
				$sub->user_name ?? '',
				$sub->user_email ?? '',
				$sub->status,
				$submitted_at_display,
				$reviewed_at_display,
			);

			foreach ( $fields as $f ) {
				$row[] = self::stringify_value( $f, $values[ (string) $f->field_key ] ?? '' );
			}

			$writer->row( $row );
		}

		$writer->close();
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the php://output handle this method opened.
		fclose( $output );
		exit;
	}

	/**
	 * Get custom fields for all audiences linked to a reregistration.
	 *
	 * @param object $rereg Reregistration object.
	 * @phpstan-param ReregistrationRow $rereg
	 * @return list<CustomFieldRow>
	 */
	private static function get_custom_fields_for_reregistration( object $rereg ): array {
		$audience_ids = ReregistrationRepository::get_audience_ids( (int) $rereg->id );
		$all_fields   = array();
		$seen         = array();

		foreach ( $audience_ids as $aud_id ) {
			$fields = CustomFieldReader::get_by_audience_with_parents( (int) $aud_id, true );
			foreach ( $fields as $field ) {
				if ( ! isset( $seen[ (int) $field->id ] ) ) {
					$seen[ (int) $field->id ] = true;
					$all_fields[]             = $field;
				}
			}
		}

		return $all_fields;
	}

	/**
	 * Decrypt sensitive values in place.
	 *
	 * @param array<int, object>   $fields Field definitions.
	 * @param array<string, mixed> $values field_key => value map.
	 * @phpstan-param list<CustomFieldRow> $fields
	 * @return array<string, mixed> Decrypted map.
	 */
	private static function decrypt_sensitive( array $fields, array $values ): array {
		if ( ! class_exists( '\FreeFormCertificate\Core\Encryption' ) ) {
			return $values;
		}

		foreach ( $fields as $field ) {
			if ( empty( $field->is_sensitive ) ) {
				continue;
			}
			$key = (string) $field->field_key;
			if ( ! isset( $values[ $key ] ) || '' === $values[ $key ] || ! is_string( $values[ $key ] ) ) {
				continue;
			}
			$plain = \FreeFormCertificate\Core\Encryption::decrypt( $values[ $key ] );
			if ( null !== $plain ) {
				$values[ $key ] = $plain;
			}
		}

		return $values;
	}

	/**
	 * Convert a stored field value into a CSV-friendly string.
	 *
	 * @param object $field Field definition.
	 * @param mixed  $value Plain value (may already be decrypted).
	 * @phpstan-param CustomFieldRow $field
	 * @return string
	 */
	private static function stringify_value( object $field, $value ): string {
		switch ( (string) $field->field_type ) {
			case 'checkbox':
				return ( '1' === $value || 1 === $value || true === $value )
					? __( 'Yes', 'ffcertificate' )
					: __( 'No', 'ffcertificate' );

			case 'dependent_select':
				$dep = is_string( $value ) ? json_decode( $value, true ) : $value;
				if ( is_array( $dep ) ) {
					$parent = (string) ( $dep['parent'] ?? '' );
					$child  = (string) ( $dep['child'] ?? '' );
					return trim( $parent . ' / ' . $child, ' /' );
				}
				return '';

			case 'working_hours':
				// Keep raw JSON — users can post-process in Excel if needed.
				if ( is_string( $value ) ) {
					return '[]' === $value ? '' : $value;
				}
				return is_array( $value ) ? (string) wp_json_encode( $value ) : '';

			default:
				if ( is_array( $value ) ) {
					return implode( ', ', array_map( 'strval', $value ) );
				}
				return is_scalar( $value ) ? (string) $value : '';
		}
	}
}
