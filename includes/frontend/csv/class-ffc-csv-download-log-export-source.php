<?php
/**
 * CsvDownloadLogExportSource
 *
 * Synchronous {@see \FreeFormCertificate\Core\SyncSourceInterface} for the
 * per-form public-download audit-log export (Settings → the form's download-log
 * "Export" link). Streams the `_ffc_csv_public_download_log` ring buffer — a
 * bounded (≤ {@see PublicCsvDownload::DOWNLOAD_LOG_MAX}) list of delivery rows —
 * with each entry's CPF decrypted on the fly and its UTC timestamp rendered in
 * the site timezone. The bounded size is exactly why this is a synchronous
 * (single-request) export rather than a batched one; PII touches memory but
 * never disk. Streamed via {@see \FreeFormCertificate\Core\SyncCsvExport}. (#772.)
 *
 * Gating: dedicated `admin_post` endpoint, so `authorize()` runs the full
 * nonce + form + capability gate and `wp_die()`s on denial.
 *
 * Note (#772): when encryption is not configured, the advisory note that used
 * to precede the header now streams as the first data row (CsvStreamer always
 * writes the header first); it keeps its `#` comment prefix.
 *
 * @package FreeFormCertificate\Frontend\Csv
 * @since   6.17.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Frontend\Csv;

use FreeFormCertificate\Core\Capabilities;
use FreeFormCertificate\Core\RequestInput;
use FreeFormCertificate\Core\SyncSourceInterface;
use FreeFormCertificate\Frontend\PublicCsvDownload;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The public-download audit log as a synchronous export source.
 */
class CsvDownloadLogExportSource implements SyncSourceInterface {

	/**
	 * Target form id.
	 *
	 * @var int
	 */
	private int $form_id;

	/**
	 * Constructor.
	 *
	 * @param int $form_id Target form id (from the request).
	 */
	public function __construct( int $form_id ) {
		$this->form_id = $form_id;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return void
	 */
	public function authorize(): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$nonce = RequestInput::get_get_string( '_wpnonce' );

		if ( ! wp_verify_nonce( $nonce, PublicCsvDownload::EXPORT_LOG_NONCE . '_' . $this->form_id ) ) {
			wp_die( esc_html__( 'Security check failed.', 'ffcertificate' ), 403 );
		}
		if ( $this->form_id <= 0 || get_post_type( $this->form_id ) !== 'ffc_form' ) {
			wp_die( esc_html__( 'Form not found.', 'ffcertificate' ), 404 );
		}
		if ( ! current_user_can( 'edit_post', $this->form_id ) ) {
			wp_die( esc_html__( 'You do not have permission to export this log.', 'ffcertificate' ), 403 );
		}
		$can_audit = class_exists( '\FreeFormCertificate\Core\Utils' )
			? Capabilities::current_user_can_admin_or( 'ffc_manage_settings' )
			: current_user_can( 'manage_options' );
		if ( ! $can_audit ) {
			wp_die( esc_html__( 'You do not have permission to export this log.', 'ffcertificate' ), 403 );
		}

		nocache_headers();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function filename(): string {
		return 'ffc-csv-download-log-' . $this->form_id . '-' . gmdate( 'Y-m-d-His' ) . '.csv';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<int, string>
	 */
	public function header(): array {
		return array( 'timestamp', 'ip', 'mode', 'cpf', 'result' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * One row per audit entry, CPF decrypted on the fly and the UTC `ts`
	 * rendered in the site timezone. Non-array entries are skipped.
	 *
	 * @return iterable
	 * @phpstan-return iterable<array<int, string>>
	 */
	public function rows(): iterable {
		$log = get_post_meta( $this->form_id, PublicCsvDownload::META_DOWNLOAD_LOG, true );
		$log = is_array( $log ) ? $log : array();

		$encryption_ok = class_exists( '\FreeFormCertificate\Core\Encryption' )
			&& \FreeFormCertificate\Core\Encryption::is_configured();

		if ( ! $encryption_ok ) {
			// Advisory so the admin knows why CPFs come out empty.
			yield array( '# Encryption is not configured on this site; CPF column will be empty for new entries. See plugin docs.' );
		}

		foreach ( $log as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			// Render in the site timezone: `ts` is a UTC unix timestamp;
			// `wp_date()` with no timezone arg uses `wp_timezone()`.
			$ts = '';
			if ( isset( $entry['ts'] ) ) {
				$formatted = wp_date( 'Y-m-d H:i:s', (int) $entry['ts'] );
				$ts        = false === $formatted ? '' : $formatted;
			}
			$ip  = isset( $entry['ip'] ) ? (string) $entry['ip'] : '';
			$mod = isset( $entry['mode'] ) ? (string) $entry['mode'] : '';
			$res = isset( $entry['result'] ) ? (string) $entry['result'] : '';
			$cpf = CsvDownloadAuditLog::decrypt_log_entry_cpf( $entry );

			yield array( $ts, $ip, $mod, $cpf, $res );
		}
	}
}
