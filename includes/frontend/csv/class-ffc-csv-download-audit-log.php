<?php
/**
 * CsvDownloadAuditLog
 *
 * Audit-log read helper for the public CSV download feature. Extracted from
 * {@see \FreeFormCertificate\Frontend\PublicCsvDownload} (#589 phase-2,
 * Sprint E3): holds the implementation of the metabox summary
 * (`get_summary()`) plus the per-entry CPF decryptor.
 *
 * `PublicCsvDownload::get_audit_log_summary()` stays in place as a thin
 * public delegator to {@see self::get_summary()} because it is a public API
 * contract (consumed by the form-editor metabox and pinned by
 * PublicCsvDownloadTest; the count/success/fail/url return keys are an
 * external contract). The return array shape is byte-identical to the
 * pre-extraction implementation.
 *
 * @package FreeFormCertificate\Frontend\Csv
 * @since   6.7.x
 */

declare(strict_types=1);

namespace FreeFormCertificate\Frontend\Csv;

use FreeFormCertificate\Frontend\PublicCsvDownload;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only audit-log summary + CPF decryption for the public CSV feature.
 *
 * @since 6.7.x
 */
class CsvDownloadAuditLog {

	/**
	 * Build the read-only count + URL summary for the metabox button.
	 *
	 * Returns three operator-facing buckets (6.5.13):
	 *
	 *   - `access_success` — captcha + CPF gate both passed (i.e. the
	 *     visitor reached the info / preview screen successfully).
	 *     Computed from the `success` / `audit_pass` / `voluntary` rows
	 *     emitted by `CsvDownloadValidator::validate_cpf_requirement()`;
	 *     by construction those rows imply captcha already passed,
	 *     since captcha runs as an earlier gate.
	 *   - `download_success` — count of CSV files actually delivered
	 *     by `handle_request()`. Sourced from the long-lived
	 *     `_ffc_csv_public_count` counter rather than from the audit
	 *     ring buffer (which only keeps the most recent
	 *     DOWNLOAD_LOG_MAX rows). A single counter survives log
	 *     rotation and never under-counts after the buffer fills.
	 *   - `failed_access` — every `fail_*` row in the ring buffer
	 *     (`fail_missing`, `fail_format`, `fail_match`,
	 *     `fail_unknown_mode`, `fail_captcha`, `fail_other`). Unknown
	 *     future tags fall through to this bucket so a silent
	 *     "success" inflation is impossible.
	 *
	 * The legacy keys `count` / `success` / `fail` are still returned
	 * so any unforeseen external consumer doesn't blow up; metabox UI
	 * has migrated to the new three-bucket shape.
	 *
	 * @param int $form_id Form ID.
	 * @return array{count: int, success: int, fail: int, access_success: int, download_success: int, failed_access: int, url: string|null}
	 */
	public static function get_summary( int $form_id ): array {
		$log   = get_post_meta( $form_id, PublicCsvDownload::META_DOWNLOAD_LOG, true );
		$log   = is_array( $log ) ? $log : array();
		$count = count( $log );

		// Validator-emitted "CPF passed" tags. Don't include
		// `download_delivered` here — that tag was added in the
		// post-#241 audit fix and always pairs with a pre-existing
		// `success` / `audit_pass` / `voluntary` row from the
		// validator (or stands alone in 'none' mode without CPF).
		// Adding it would over-count CPF-gated flows. The exported
		// audit CSV still shows the rows; the metabox summary just
		// doesn't double-count them. `download_success` continues to
		// source from META_COUNT, the long-lived counter that
		// survives ring-buffer rotation.
		$access_success_tags = array( 'success', 'audit_pass', 'voluntary' );
		// `download_delivered` (PR #242) records actual file deliveries;
		// `action_early_open` / `action_postpone_close` (#243 Sprint 6)
		// record operator action events. Both are useful in the audit
		// CSV but shouldn't count toward access_success / failed_access
		// in the metabox summary (would double-count flows where the
		// CPF gate also wrote its own validator row).
		$delivery_tags  = array( 'download_delivered', 'action_early_open', 'action_postpone_close' );
		$access_success = 0;
		$failed_access  = 0;
		foreach ( $log as $entry ) {
			$result = is_array( $entry ) && isset( $entry['result'] ) ? (string) $entry['result'] : '';
			if ( in_array( $result, $access_success_tags, true ) ) {
				++$access_success;
			} elseif ( in_array( $result, $delivery_tags, true ) ) {
				continue;
			} else {
				++$failed_access;
			}
		}

		$download_success = (int) get_post_meta( $form_id, PublicCsvDownload::META_COUNT, true );

		$url = null;
		if ( $count > 0 ) {
			$url = add_query_arg(
				array(
					'action'   => PublicCsvDownload::EXPORT_LOG_ACTION,
					'form_id'  => $form_id,
					'_wpnonce' => wp_create_nonce( PublicCsvDownload::EXPORT_LOG_NONCE . '_' . $form_id ),
				),
				admin_url( 'admin-post.php' )
			);
		}
		return array(
			'count'            => $count,
			'success'          => $access_success,
			'fail'             => $failed_access,
			'access_success'   => $access_success,
			'download_success' => $download_success,
			'failed_access'    => $failed_access,
			'url'              => $url,
		);
	}

	/**
	 * Decrypt a single log entry's CPF for display in the export.
	 *
	 * @param array<string, mixed> $entry Log entry row.
	 * @return string Formatted CPF, '' when blank, or a marker for failures.
	 */
	public static function decrypt_log_entry_cpf( array $entry ): string {
		$cipher = isset( $entry['cpf_encrypted'] ) ? (string) $entry['cpf_encrypted'] : '';
		if ( '' === $cipher ) {
			return '';
		}
		if ( ! class_exists( '\FreeFormCertificate\Core\Encryption' )
			|| ! \FreeFormCertificate\Core\Encryption::is_configured() ) {
			return '[encryption disabled]';
		}
		$plain = \FreeFormCertificate\Core\Encryption::decrypt( $cipher );
		if ( ! is_string( $plain ) || '' === $plain ) {
			return '[decrypt failed]';
		}
		if ( class_exists( '\FreeFormCertificate\Core\DocumentFormatter' ) ) {
			return \FreeFormCertificate\Core\DocumentFormatter::format_cpf( $plain );
		}
		return $plain;
	}
}
