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
 * @since   6.12.0
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
 * @since 6.12.0
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
	 * `count` is retained (the metabox reads it). The legacy `success` /
	 * `fail` keys were removed in 6.17.0 (#730) after their deprecation
	 * window — consumers use `access_success` / `failed_access` instead.
	 *
	 * @param int $form_id Form ID.
	 * `full` says that failures or records reached their own cap, so older
	 * rows of that kind roll off (#2341).
	 *
	 * @return array{count: int, access_success: int, download_success: int, failed_access: int, full: bool, url: string|null}
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
		$failure_rows   = 0;
		foreach ( $log as $entry ) {
			$result = is_array( $entry ) && isset( $entry['result'] ) ? (string) $entry['result'] : '';
			if ( in_array( $result, $access_success_tags, true ) ) {
				++$access_success;
			} elseif ( in_array( $result, $delivery_tags, true ) ) {
				continue;
			} else {
				// A row folds every repeat of one failure (#2341).
				$failed_access += self::times( is_array( $entry ) ? $entry : array() );
				++$failure_rows;
			}
		}
		$record_rows = $count - $failure_rows;

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
			'access_success'   => $access_success,
			'download_success' => $download_success,
			'failed_access'    => $failed_access,
			'full'             => $failure_rows >= PublicCsvDownload::DOWNLOAD_LOG_MAX || $record_rows >= PublicCsvDownload::DOWNLOAD_LOG_MAX,
			'url'              => $url,
		);
	}

	/**
	 * Results that record something that happened (a CPF gate passed, a
	 * file delivered, an operator action). Every other result is a
	 * failure, an unknown future tag included, so a refusal can never be
	 * filed among the records.
	 */
	private const RECORD_TAGS = array( 'success', 'audit_pass', 'voluntary', 'download_delivered', 'action_early_open', 'action_postpone_close' );

	/**
	 * Whether a result is a failure.
	 *
	 * @param string $result Outcome tag.
	 * @return bool
	 */
	public static function is_failure( string $result ): bool {
		return ! in_array( $result, self::RECORD_TAGS, true );
	}

	/**
	 * How many attempts an entry stands for: a folded failure carries its
	 * count, any other entry one.
	 *
	 * @param array<mixed> $entry Log entry.
	 * @return int
	 */
	public static function times( array $entry ): int {
		return isset( $entry['count'] ) && is_numeric( $entry['count'] ) ? max( 1, (int) $entry['count'] ) : 1;
	}

	/**
	 * Add an entry to the log (#2341).
	 *
	 * A failure repeating one already logged (the same result, gate mode
	 * and address fingerprint) is folded into that row: its count grows,
	 * its time becomes the last attempt's, and `first_ts` keeps the first.
	 * The row moves to the end, so the log stays in order of last attempt.
	 * Failures and records are then capped apart, each at `$max`, so a
	 * stream of bad requests can only push out older failures and never a
	 * delivery.
	 *
	 * @param array<mixed>         $log   The stored log.
	 * @param array<string, mixed> $entry The new entry; `ip_fp` is the key.
	 * @param int                  $max   Rows kept of each kind.
	 * @return array<int, mixed> The log to store.
	 */
	public static function append( array $log, array $entry, int $max ): array {
		$log    = array_values( $log );
		$result = (string) ( $entry['result'] ?? '' );

		if ( self::is_failure( $result ) && '' !== (string) ( $entry['ip_fp'] ?? '' ) ) {
			for ( $i = count( $log ) - 1; $i >= 0; $i-- ) {
				$row = $log[ $i ];
				if ( ! is_array( $row )
					|| (string) ( $row['result'] ?? '' ) !== $result
					|| (string) ( $row['mode'] ?? '' ) !== (string) ( $entry['mode'] ?? '' )
					|| (string) ( $row['ip_fp'] ?? '' ) !== (string) $entry['ip_fp'] ) {
					continue;
				}
				$entry['first_ts'] = isset( $row['first_ts'] ) ? (int) $row['first_ts'] : (int) ( $row['ts'] ?? $entry['ts'] ?? 0 );
				$entry['count']    = self::times( $row ) + 1;
				if ( '' === (string) ( $entry['cpf_encrypted'] ?? '' ) && '' !== (string) ( $row['cpf_encrypted'] ?? '' ) ) {
					$entry['cpf_encrypted'] = $row['cpf_encrypted'];
				}
				array_splice( $log, $i, 1 );
				break;
			}
		}

		$log[] = $entry;

		// Cap each kind apart, dropping its oldest rows.
		$failures = 0;
		$records  = 0;
		foreach ( $log as $row ) {
			if ( is_array( $row ) && self::is_failure( (string) ( $row['result'] ?? '' ) ) ) {
				++$failures;
			} else {
				++$records;
			}
		}
		$drop_failures = max( 0, $failures - $max );
		$drop_records  = max( 0, $records - $max );
		if ( 0 === $drop_failures && 0 === $drop_records ) {
			return $log;
		}
		$kept = array();
		foreach ( $log as $row ) {
			$failure = is_array( $row ) && self::is_failure( (string) ( $row['result'] ?? '' ) );
			if ( $failure && $drop_failures > 0 ) {
				--$drop_failures;
				continue;
			}
			if ( ! $failure && $drop_records > 0 ) {
				--$drop_records;
				continue;
			}
			$kept[] = $row;
		}
		return $kept;
	}

	/**
	 * Encrypt a client IP for a ring-buffer entry (#1574).
	 *
	 * The address is never stored in clear: when it cannot be encrypted the
	 * entry carries '' and the address is lost, which is the safe outcome.
	 *
	 * @param string $ip Client IP.
	 * @return string Ciphertext, or '' when there is nothing to store.
	 */
	public static function encrypt_ip( string $ip ): string {
		if ( '' === $ip
			|| ! class_exists( '\FreeFormCertificate\Core\Encryption' )
			|| ! \FreeFormCertificate\Core\Encryption::is_configured() ) {
			return '';
		}
		$cipher = \FreeFormCertificate\Core\Encryption::encrypt( $ip );
		return is_string( $cipher ) ? $cipher : '';
	}

	/**
	 * Re-write the entries an older release stored with a plaintext `ip`.
	 *
	 * The buffer is rewritten on every new entry, so encrypting what it still
	 * holds in clear at that moment converts a form's whole history on its
	 * next download, with no migration card.
	 *
	 * @param array<mixed> $entries Ring-buffer entries as stored.
	 * @return array<int|string, mixed>
	 */
	public static function encrypt_legacy_ips( array $entries ): array {
		foreach ( $entries as $key => $entry ) {
			if ( ! is_array( $entry ) || ! array_key_exists( 'ip', $entry ) ) {
				continue;
			}
			$entry['ip_encrypted'] = self::encrypt_ip( is_scalar( $entry['ip'] ) ? (string) $entry['ip'] : '' );
			unset( $entry['ip'] );
			$entries[ $key ] = $entry;
		}
		return $entries;
	}

	/**
	 * The client IP of a ring-buffer entry, readable.
	 *
	 * Reads the ciphertext; an entry written before #1574 still carries a
	 * plaintext `ip` until the form's next download rewrites the buffer.
	 *
	 * @param array<string, mixed> $entry Ring-buffer entry.
	 * @return string The address, or '' when there is none or it cannot be read.
	 */
	public static function decrypt_log_entry_ip( array $entry ): string {
		$cipher = isset( $entry['ip_encrypted'] ) && is_string( $entry['ip_encrypted'] ) ? $entry['ip_encrypted'] : '';
		if ( '' !== $cipher ) {
			if ( ! class_exists( '\FreeFormCertificate\Core\Encryption' )
				|| ! \FreeFormCertificate\Core\Encryption::is_configured() ) {
				return '';
			}
			$plain = \FreeFormCertificate\Core\Encryption::decrypt( $cipher );
			return is_string( $plain ) ? $plain : '';
		}
		return isset( $entry['ip'] ) && is_scalar( $entry['ip'] ) ? (string) $entry['ip'] : '';
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
