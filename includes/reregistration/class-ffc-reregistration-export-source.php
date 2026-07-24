<?php
/**
 * ReregistrationExportSource
 *
 * Batched {@see \FreeFormCertificate\Core\BatchedExportSourceInterface} for the
 * reregistration-submissions export. Migrated from the former synchronous export
 * (`ReregistrationCsvExporter::handle_export`, a page-action handler that streamed
 * straight to `php://output` via `CsvStreamer`) onto the shared timeout-safe
 * engine (issue #772): the export now runs as an AJAX start → batch → download
 * job via the unified dispatcher, keyset-paged by `id`.
 *
 * The export is per-campaign (one reregistration `id`). Its "dynamic" columns are
 * that campaign's custom fields — deterministic from the linked audiences, not
 * scanned from row JSON — so they are resolved once in `build_context()` and
 * reused for the header and every row. Sensitive field values are decrypted per
 * row in memory. Export order is `id`-DESC (a stable keyset).
 *
 * Gated by the dedicated `ffc_export_reregistration` capability + a job nonce.
 *
 * @package FreeFormCertificate\Reregistration
 * @since   6.17.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Reregistration;

use FreeFormCertificate\Core\BatchedExportSourceInterface;
use FreeFormCertificate\Core\Capabilities;
use FreeFormCertificate\Core\RequestInput;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reregistration-submissions export as a batched source.
 *
 * @phpstan-import-type CustomFieldRow from CustomFieldReader
 * @phpstan-import-type ReregistrationRow from ReregistrationRepository
 */
class ReregistrationExportSource implements BatchedExportSourceInterface {

	/**
	 * Stable source type routed by the dispatcher / registry.
	 */
	public const TYPE = 'reregistration';

	/**
	 * Capability gating every phase.
	 */
	private const CAP = 'ffc_export_reregistration';

	/**
	 * Nonce action shared by start / batch / download.
	 */
	private const NONCE = 'ffc_reregistration_export';

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function type(): string {
		return self::TYPE;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return void
	 */
	public function authorize_start(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! Capabilities::current_user_can_admin_or( self::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to export reregistration submissions.', 'ffcertificate' ) ), 403 );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $job Job state.
	 * @return void
	 */
	public function authorize_batch( array $job ): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! Capabilities::current_user_can_admin_or( self::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to export reregistration submissions.', 'ffcertificate' ) ), 403 );
		}
		if ( (int) get_current_user_id() !== (int) ( $job['user_id'] ?? -1 ) ) {
			wp_send_json_error( array( 'message' => __( 'Session mismatch.', 'ffcertificate' ) ), 403 );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $job Job state.
	 * @return void
	 */
	public function authorize_download( array $job ): void {
		if ( ! wp_verify_nonce( RequestInput::get_get_string( 'nonce' ), self::NONCE ) ) {
			wp_die( esc_html__( 'Security check failed.', 'ffcertificate' ) );
		}
		if ( ! Capabilities::current_user_can_admin_or( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to export reregistration submissions.', 'ffcertificate' ) );
		}
		if ( (int) get_current_user_id() !== (int) ( $job['user_id'] ?? -1 ) ) {
			wp_die( esc_html__( 'Session mismatch.', 'ffcertificate' ) );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, mixed>
	 */
	public function job_owner_fields(): array {
		return array( 'user_id' => get_current_user_id() );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, mixed>
	 */
	public function sanitize_filters(): array {
		// The nonce is verified in authorize_start(); RequestInput wraps + sanitizes.
		return array(
			'reregistration_id' => RequestInput::get_post_int( 'id' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @return int
	 */
	public function count( array $filters ): int {
		return ReregistrationSubmissionReader::count_by_reregistration( (int) $filters['reregistration_id'] );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Resolve the campaign's custom-field set + title once. Stored in the frozen
	 * job context and reused by header()/format_row()/filename().
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @return array<string, mixed>
	 */
	public function build_context( array $filters ): array {
		$rereg = ReregistrationRepository::get_by_id( (int) $filters['reregistration_id'] );
		if ( ! $rereg ) {
			return array(
				'fields' => array(),
				'title'  => '',
			);
		}
		return array(
			'fields' => $this->get_custom_fields_for_reregistration( $rereg ),
			'title'  => (string) $rereg->title,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @param array<string, mixed> $context Frozen context.
	 * @return array<int, string>
	 */
	public function header( array $filters, array $context ): array {
		unset( $filters );
		$headers = array(
			__( 'User ID', 'ffcertificate' ),
			__( 'Name', 'ffcertificate' ),
			__( 'Email', 'ffcertificate' ),
			__( 'Status', 'ffcertificate' ),
			__( 'Submitted At', 'ffcertificate' ),
			__( 'Reviewed At', 'ffcertificate' ),
		);
		foreach ( $this->context_fields( $context ) as $field ) {
			$headers[] = (string) $field->field_label;
		}
		return $headers;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @param array<string, mixed> $context Frozen context.
	 * @return string
	 */
	public function filename( array $filters, array $context ): string {
		unset( $filters );
		return \FreeFormCertificate\Core\FilenameHelper::get_export_filename( 'reregistration', (string) ( $context['title'] ?? '' ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @param array<string, mixed> $context Frozen context.
	 * @param int                  $cursor  Exclusive upper-bound id.
	 * @param int                  $size    Page size.
	 * @return array<int, array<string, mixed>>
	 */
	public function fetch_page( array $filters, array $context, int $cursor, int $size ): array {
		unset( $context );
		$rows = ReregistrationSubmissionReader::find_by_cursor_for_export( (int) $filters['reregistration_id'], $cursor, $size );
		return array_map( static fn( $row ): array => (array) $row, $rows );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $row Row.
	 * @return int
	 */
	public function cursor_of( array $row ): int {
		return (int) ( $row['id'] ?? 0 );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $row     Raw submission row (cast from stdClass).
	 * @param array<string, mixed> $context Frozen context.
	 * @return array<int, mixed>
	 */
	public function format_row( array $row, array $context ): array {
		$fields = $this->context_fields( $context );

		$raw_data = isset( $row['data'] ) && is_string( $row['data'] ) ? json_decode( $row['data'], true ) : array();
		$values   = ( is_array( $raw_data ) && is_array( $raw_data['fields'] ?? null ) ) ? $raw_data['fields'] : array();
		$values   = $this->decrypt_sensitive( $fields, $values );

		// `submitted_at`/`reviewed_at` are unix UTC int since 6.6.0 (#249
		// sub-escopos b/d); format for human eyes in the CSV.
		$submitted_at_display = ! empty( $row['submitted_at'] )
			? \FreeFormCertificate\Core\DateFormatter::format_datetime( (int) $row['submitted_at'] )
			: '';
		$reviewed_at_display  = ! empty( $row['reviewed_at'] )
			? \FreeFormCertificate\Core\DateFormatter::format_datetime( (int) $row['reviewed_at'] )
			: '';

		$line = array(
			$row['user_id'] ?? '',
			$row['user_name'] ?? '',
			$row['user_email'] ?? '',
			$row['status'] ?? '',
			$submitted_at_display,
			$reviewed_at_display,
		);

		foreach ( $fields as $field ) {
			$line[] = $this->stringify_value( $field, $values[ (string) $field->field_key ] ?? '' );
		}

		return $line;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $job_id Job id.
	 * @param array<string, mixed> $job    Job state.
	 * @return array<string, mixed>
	 */
	public function extra_start_response( string $job_id, array $job ): array {
		unset( $job_id, $job );
		return array();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $job_id Job id.
	 * @param array<string, mixed> $job    Final job state.
	 * @return void
	 */
	public function on_complete( string $job_id, array $job ): void {
		unset( $job_id, $job );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $job Job state.
	 * @return void
	 */
	public function on_before_download( array $job ): void {
		unset( $job );
	}

	// ──────────────────────────────────────────────────────────────.
	// Domain helpers (moved verbatim from the former ReregistrationCsvExporter).
	// ──────────────────────────────────────────────────────────────.

	/**
	 * Normalise the frozen context's `fields` entry to a list of field objects.
	 * The context round-trips through the job transient, so re-assert the shape
	 * for the type checker.
	 *
	 * @param array<string, mixed> $context Frozen context.
	 * @return list<CustomFieldRow>
	 */
	private function context_fields( array $context ): array {
		$fields = $context['fields'] ?? array();
		if ( ! is_array( $fields ) ) {
			return array();
		}
		/**
		 * The context's `fields` entry is the field set resolved in build_context().
		 *
		 * @var list<CustomFieldRow> $list
		 */
		$list = array_values( array_filter( $fields, 'is_object' ) );
		return $list;
	}

	/**
	 * Get custom fields for all audiences linked to a reregistration.
	 *
	 * @param object $rereg Reregistration object.
	 * @phpstan-param ReregistrationRow $rereg
	 * @return list<CustomFieldRow>
	 */
	private function get_custom_fields_for_reregistration( object $rereg ): array {
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
	private function decrypt_sensitive( array $fields, array $values ): array {
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
	private function stringify_value( object $field, $value ): string {
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
