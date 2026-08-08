<?php
/**
 * PublicCsvRowFormatter
 *
 * Column layout + row formatting for the public CSV export. Extracted from
 * {@see \FreeFormCertificate\Frontend\PublicCsvExporter} (#589 phase-2,
 * Sprint E3): owns `get_fixed_headers()`, `format_csv_row()` and the
 * dynamic-key scan so the exporter is left with request orchestration only.
 *
 * Historically the column layout mirrored `CsvExporter` byte-for-byte. Since
 * #836 the public export is deliberately minimized for data protection: the
 * `User IP`, `Token` (magic link), the consent trail (`Consent Given` /
 * `Consent Date` / `Consent IP` / `Consent Text`) and `Status` columns were
 * dropped here — they remain in the admin export. The public download now
 * exposes only ID / Form / User ID / Submission Date / E-mail / CPF / RF /
 * Auth Code, plus the dynamic form fields.
 *
 * @package FreeFormCertificate\Frontend\Csv
 * @since   6.12.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Frontend\Csv;

use FreeFormCertificate\Core\CsvExportTrait;
use FreeFormCertificate\Repositories\SubmissionRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the header row, formats data rows, and harvests dynamic JSON keys.
 *
 * @since 6.12.0
 */
class PublicCsvRowFormatter {

	use CsvExportTrait;

	/**
	 * Rows pulled per batch during dynamic-key discovery.
	 */
	const KEYS_BATCH_SIZE = 500;

	/**
	 * Cached form titles to avoid repeated get_the_title() lookups.
	 *
	 * @var array<int, string>
	 */
	private array $form_title_cache = array();

	/**
	 * Fixed CSV headers for the public export (minimized — see class docblock).
	 *
	 * Deliberately narrower than the admin `CsvExporter` layout (#836): the
	 * public download carries no IP, token, consent-trail or status columns.
	 *
	 * @param bool $include_edit_columns Whether to append edit-tracking columns.
	 * @return array<int, string>
	 */
	public function get_fixed_headers( bool $include_edit_columns = false ): array {
		$headers = array(
			__( 'ID', 'ffcertificate' ),
			__( 'Form', 'ffcertificate' ),
			__( 'User ID', 'ffcertificate' ),
			__( 'Submission Date', 'ffcertificate' ),
			__( 'E-mail', 'ffcertificate' ),
			__( 'CPF', 'ffcertificate' ),
			__( 'RF', 'ffcertificate' ),
			__( 'Auth Code', 'ffcertificate' ),
		);

		if ( $include_edit_columns ) {
			$headers[] = __( 'Was Edited', 'ffcertificate' );
			$headers[] = __( 'Edit Date', 'ffcertificate' );
			$headers[] = __( 'Edited By', 'ffcertificate' );
		}

		return $headers;
	}

	/**
	 * Format one submission row as a CSV line for the public export.
	 *
	 * Minimized layout (#836) — narrower than the admin `CsvExporter`.
	 *
	 * @param array<string, mixed> $row Row.
	 * @param array<int, string>   $dynamic_keys Dynamic keys.
	 * @param bool                 $include_edit_columns Include edit columns.
	 * @return array<int, mixed>
	 */
	public function format_csv_row( array $row, array $dynamic_keys, bool $include_edit_columns = false ): array {
		$form_display = $this->get_form_title_cached( (int) $row['form_id'] );

		$email   = \FreeFormCertificate\Core\Encryption::decrypt_field( $row, 'email' );
		$cpf_val = \FreeFormCertificate\Core\Encryption::decrypt_field( $row, 'cpf' );
		$rf_val  = \FreeFormCertificate\Core\Encryption::decrypt_field( $row, 'rf' );

		// `submission_date` is unix UTC int since 6.6.0 (#249 sub-escopo a).
		// Format for the public CSV — admin/operator reads this in a spreadsheet.
		// Minimized layout (#836): IP, token, the consent trail and status are
		// intentionally omitted from the public export.
		$line = array(
			$row['id'],
			$form_display,
			! empty( $row['user_id'] ) ? $row['user_id'] : '',
			\FreeFormCertificate\Core\DateFormatter::format_datetime( (int) ( $row['submission_date'] ?? 0 ) ),
			$email,
			$cpf_val,
			$rf_val,
			! empty( $row['auth_code'] ) ? $row['auth_code'] : '',
		);

		if ( $include_edit_columns ) {
			$was_edited = '';
			$edit_date  = '';
			$edited_by  = '';
			if ( ! empty( $row['edited_at'] ) ) {
				$was_edited = __( 'Yes', 'ffcertificate' );
				// `edited_at` is unix UTC int since 6.6.0 (#249 sub-escopo d).
				$edit_date = \FreeFormCertificate\Core\DateFormatter::format_datetime( (int) $row['edited_at'] );
				if ( ! empty( $row['edited_by'] ) ) {
					$user      = get_userdata( (int) $row['edited_by'] );
					$edited_by = $user ? $user->display_name : 'ID: ' . $row['edited_by'];
				}
			}
			$line[] = $was_edited;
			$line[] = $edit_date;
			$line[] = $edited_by;
		}

		$data = $this->decode_json_field( $row, 'data', 'data_encrypted' );
		foreach ( $dynamic_keys as $key ) {
			$value  = $data[ $key ] ?? '';
			$line[] = is_array( $value ) ? implode( ', ', $value ) : $value;
		}

		return $line;
	}

	/**
	 * Scan all matching records to discover dynamic JSON keys.
	 *
	 * @param SubmissionRepository $repository Repository used to page submissions.
	 * @param array<int, int>      $form_ids   Form IDs.
	 * @param string               $status     Status.
	 * @return array<int, string>
	 */
	public function scan_dynamic_keys( SubmissionRepository $repository, array $form_ids, string $status ): array {
		$all_keys = array();
		$cursor   = PHP_INT_MAX;

		while ( true ) {
			$batch = $repository->getExportKeysBatch( $form_ids, $status, $cursor, self::KEYS_BATCH_SIZE );
			if ( empty( $batch ) ) {
				break;
			}
			$all_keys = array_merge( $all_keys, $this->extract_dynamic_keys( $batch, 'data', 'data_encrypted' ) );
			$last_row = end( $batch );
			$cursor   = (int) $last_row['id'];
			unset( $batch );
		}

		return array_values( array_unique( $all_keys ) );
	}

	/**
	 * Get form title cached.
	 *
	 * @param int $form_id Form post ID.
	 * @return string Form title or "(Deleted)" placeholder.
	 */
	public function get_form_title_cached( int $form_id ): string {
		if ( ! isset( $this->form_title_cache[ $form_id ] ) ) {
			$title                              = get_the_title( $form_id );
			$this->form_title_cache[ $form_id ] = $title ? $title : __( '(Deleted)', 'ffcertificate' );
		}
		return $this->form_title_cache[ $form_id ];
	}
}
