<?php
/**
 * CsvDownloadValidator
 *
 * Validation helpers extracted from {@see PublicCsvDownload} as part of the
 * S5 god-object split (issue #141). Holds the business-logic gates that the
 * shortcode's admin-post handler and the AJAX info endpoint share:
 *
 *   - validate_form_access(): full 5–9 gate (form exists, feature on, hash
 *     matches, form ended, quota available).
 *   - validate_hash_only(): the subset 5–7 used by the info screen.
 *   - validate_cpf_requirement(): the per-form CPF gate (none / audit /
 *     participants / owner / whitelist) plus the audit-log writer that the
 *     gate emits on every decision.
 *
 * Constants and the public API still live on {@see PublicCsvDownload}; this
 * class is purely an internal collaborator instantiated by the facade.
 *
 * @package FreeFormCertificate
 * @since   6.4.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Public-CSV-download validation helpers.
 *
 * @since 6.4.0
 */
final class CsvDownloadValidator {

	/**
	 * Validate form access for CSV download (steps 5–9).
	 *
	 * Checks that the form exists, has the feature enabled, the hash matches,
	 * the form has expired, and the download quota has not been exceeded.
	 *
	 * @param int    $form_id     Sanitized form ID.
	 * @param string $posted_hash Sanitized access hash.
	 * @return string|null Error message on failure, null on success.
	 */
	public function validate_form_access( int $form_id, string $posted_hash ): ?string {
		// 5. Form exists and is a ffc_form.
		if ( get_post_type( $form_id ) !== 'ffc_form' ) {
			return __( 'Form not found.', 'ffcertificate' );
		}

		// 6. Feature enabled on this form.
		$enabled = (string) get_post_meta( $form_id, PublicCsvDownload::META_ENABLED, true );
		if ( '1' !== $enabled ) {
			return __( 'Public CSV download is not enabled for this form.', 'ffcertificate' );
		}

		// 7. Hash match (constant-time).
		$stored_hash = (string) get_post_meta( $form_id, PublicCsvDownload::META_HASH, true );
		if ( '' === $stored_hash || ! hash_equals( $stored_hash, $posted_hash ) ) {
			return __( 'Invalid access hash.', 'ffcertificate' );
		}

		// 8. Form must have ended.
		$end_ts = \FreeFormCertificate\Security\Geofence::get_form_end_timestamp( $form_id );
		if ( null === $end_ts ) {
			return __( 'This form has no end date configured. The administrator must set a Geolocation "End Date" to enable public downloads.', 'ffcertificate' );
		}
		if ( time() <= $end_ts ) {
			return __( 'This form is still active. Downloads are only allowed after the form end date has passed.', 'ffcertificate' );
		}

		// 9. Quota.
		$limit = (int) get_post_meta( $form_id, PublicCsvDownload::META_LIMIT, true );
		if ( $limit <= 0 ) {
			$settings_default = 0;
			$settings         = get_option( 'ffc_settings', array() );
			if ( is_array( $settings ) && isset( $settings['public_csv_default_limit'] ) ) {
				$settings_default = (int) $settings['public_csv_default_limit'];
			}
			$limit = $settings_default > 0 ? $settings_default : 1;
		}

		$count = (int) get_post_meta( $form_id, PublicCsvDownload::META_COUNT, true );
		if ( $count >= $limit ) {
			return sprintf(
				/* translators: %d is the configured download limit */
				__( 'The maximum number of downloads (%d) for this form has been reached.', 'ffcertificate' ),
				$limit
			);
		}

		return null;
	}

	/**
	 * Validate only steps 5–7 (form exists, feature enabled, hash match).
	 *
	 * Used by the info endpoint which must succeed even when the form is
	 * still active or the download quota is exhausted.
	 *
	 * @param int    $form_id     Sanitized form ID.
	 * @param string $posted_hash Sanitized access hash.
	 * @return string|null Error message on failure, null on success.
	 */
	public function validate_hash_only( int $form_id, string $posted_hash ): ?string {
		if ( $form_id <= 0 ) {
			return __( 'Form not found.', 'ffcertificate' );
		}
		if ( get_post_type( $form_id ) !== 'ffc_form' ) {
			return __( 'Form not found.', 'ffcertificate' );
		}

		$enabled = (string) get_post_meta( $form_id, PublicCsvDownload::META_ENABLED, true );
		if ( '1' !== $enabled ) {
			return __( 'Public CSV download is not enabled for this form.', 'ffcertificate' );
		}

		$stored_hash = (string) get_post_meta( $form_id, PublicCsvDownload::META_HASH, true );
		if ( '' === $stored_hash || ! hash_equals( $stored_hash, $posted_hash ) ) {
			return __( 'Invalid access hash.', 'ffcertificate' );
		}

		return null;
	}

	/**
	 * Apply the per-form CPF gate.
	 *
	 * Modes (configured per form via _ffc_csv_public_cpf_mode):
	 *   - none         : skip everything (legacy behaviour).
	 *   - audit        : require a valid-format CPF, log it, never block.
	 *   - participants : CPF must hash-match a submission of this form.
	 *   - owner        : CPF must match the form author's stored CPF.
	 *   - whitelist    : CPF must be present in _ffc_csv_public_cpf_whitelist.
	 *
	 * Always records a single audit log row (success or failure) when the
	 * mode is anything other than `none`.
	 *
	 * @param int    $form_id   Form post ID.
	 * @param string $cpf_input Raw CPF as posted by the user.
	 * @return string|null Error message on block, null when allowed.
	 */
	public function validate_cpf_requirement( int $form_id, string $cpf_input ): ?string {
		$mode = (string) get_post_meta( $form_id, PublicCsvDownload::META_CPF_MODE, true );
		if ( '' === $mode ) {
			$mode = 'none';
		}
		if ( 'none' === $mode ) {
			// 6.3.3: even when CPF is not required for this form, if the user
			// volunteered a syntactically valid one, audit it. Useful when the
			// shortcode renders the field for safety (no prefill in URL) and a
			// well-meaning user fills it anyway. Junk input is silently dropped
			// — we don't want garbage rows competing for DOWNLOAD_LOG_MAX slots.
			$voluntary_digits = preg_replace( '/\D/', '', $cpf_input );
			$voluntary_digits = is_string( $voluntary_digits ) ? $voluntary_digits : '';
			if ( '' !== $voluntary_digits
				&& \FreeFormCertificate\Core\DocumentFormatter::validate_cpf( $voluntary_digits ) ) {
				$this->record_download_log_entry( $form_id, 'none', $voluntary_digits, 'voluntary' );
			}
			return null;
		}

		$digits = preg_replace( '/\D/', '', $cpf_input );
		$digits = is_string( $digits ) ? $digits : '';

		// Format gate: we require a syntactically valid 11-digit CPF before
		// touching the database.
		if ( '' === $digits ) {
			$this->record_download_log_entry( $form_id, $mode, '', 'fail_missing' );
			return __( 'CPF is required to download this CSV.', 'ffcertificate' );
		}
		if ( ! \FreeFormCertificate\Core\DocumentFormatter::validate_cpf( $digits ) ) {
			$this->record_download_log_entry( $form_id, $mode, $digits, 'fail_format' );
			return __( 'Invalid CPF.', 'ffcertificate' );
		}

		if ( 'audit' === $mode ) {
			$this->record_download_log_entry( $form_id, $mode, $digits, 'audit_pass' );
			return null;
		}

		if ( 'whitelist' === $mode ) {
			$wl_raw = (string) get_post_meta( $form_id, PublicCsvDownload::META_CPF_WHITELIST, true );
			$found  = false;
			$lines  = preg_split( '/[\r\n,]+/', $wl_raw );
			$lines  = is_array( $lines ) ? $lines : array();
			foreach ( $lines as $line ) {
				$candidate = preg_replace( '/\D/', '', (string) $line );
				if ( $candidate === $digits ) {
					$found = true;
					break;
				}
			}
			if ( ! $found ) {
				$this->record_download_log_entry( $form_id, $mode, $digits, 'fail_match' );
				return __( 'This CPF is not authorized to download this CSV.', 'ffcertificate' );
			}
			$this->record_download_log_entry( $form_id, $mode, $digits, 'success' );
			return null;
		}

		if ( 'owner' === $mode ) {
			$author_id = (int) get_post_field( 'post_author', $form_id );
			if ( $author_id <= 0 ) {
				$this->record_download_log_entry( $form_id, $mode, $digits, 'fail_match' );
				return __( 'Form has no author to validate against.', 'ffcertificate' );
			}
			$author_cpf = (string) get_user_meta( $author_id, 'ffc_user_cpf', true );
			$author_dig = preg_replace( '/\D/', '', $author_cpf );
			if ( ! is_string( $author_dig ) || $author_dig !== $digits ) {
				$this->record_download_log_entry( $form_id, $mode, $digits, 'fail_match' );
				return __( 'CPF does not match the form author.', 'ffcertificate' );
			}
			$this->record_download_log_entry( $form_id, $mode, $digits, 'success' );
			return null;
		}

		if ( 'participants' === $mode ) {
			global $wpdb;
			$encryption_class = '\FreeFormCertificate\Core\Encryption';
			$cpf_hash         = ( class_exists( $encryption_class ) && $encryption_class::is_configured() )
				? $encryption_class::hash( $digits )
				: hash( 'sha256', $digits );
			$table            = $wpdb->prefix . 'ffc_submissions';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE form_id = %d AND cpf_hash = %s', $table, $form_id, $cpf_hash ) );
			if ( $count <= 0 ) {
				$this->record_download_log_entry( $form_id, $mode, $digits, 'fail_match' );
				return __( 'No submission with this CPF was found for this form.', 'ffcertificate' );
			}
			$this->record_download_log_entry( $form_id, $mode, $digits, 'success' );
			return null;
		}

		// Unknown mode -> fail closed.
		$this->record_download_log_entry( $form_id, $mode, $digits, 'fail_unknown_mode' );
		return __( 'CPF gate misconfigured. Contact the administrator.', 'ffcertificate' );
	}

	/**
	 * Append an entry to the per-form download audit log.
	 *
	 * Stores the latest DOWNLOAD_LOG_MAX rows in a single post meta. CPF
	 * is encrypted at-rest via the plugin's Encryption helper (same pipeline
	 * that protects ffc_submissions.cpf_encrypted) so the form owner can
	 * later decrypt and audit who downloaded the CSV. When Encryption is not
	 * configured on the site, the CPF field falls back to '' and the export
	 * shows a placeholder — admins can configure encryption to retroactively
	 * make new entries auditable.
	 *
	 * Schema (6.3.3): { ts, ip, mode, cpf_encrypted, result }. Pre-6.3.3
	 * entries (which used cpf_hash) are wiped on the first plugins_loaded
	 * after upgrade by maybe_wipe_legacy_logs() — see that method for the
	 * justification (install base reality + clean break).
	 *
	 * Made `public` in 6.5.13 so `PublicCsvDownload` can record audit
	 * rows for non-CPF outcomes too (captcha rejection, hash mismatch,
	 * form-closed, quota exhausted). The encryption + ring-buffer
	 * pruning belongs here, so promoting visibility beats duplicating
	 * the logic into the AJAX handlers.
	 *
	 * @param int    $form_id Form ID.
	 * @param string $mode    CPF gate mode that was active (or a synthetic
	 *                        label like 'captcha' / 'access' when this is
	 *                        called outside the CPF gate).
	 * @param string $digits  Digits-only CPF (may be '' for fail_missing
	 *                        or for non-CPF outcomes).
	 * @param string $result  Outcome tag: success | fail_missing |
	 *                        fail_format | fail_match | fail_unknown_mode |
	 *                        audit_pass | voluntary | fail_captcha |
	 *                        fail_other.
	 */
	public function record_download_log_entry( int $form_id, string $mode, string $digits, string $result ): void {
		$cpf_encrypted = '';
		if ( '' !== $digits
			&& class_exists( '\FreeFormCertificate\Core\Encryption' )
			&& \FreeFormCertificate\Core\Encryption::is_configured() ) {
			$encrypted = \FreeFormCertificate\Core\Encryption::encrypt( $digits );
			if ( is_string( $encrypted ) ) {
				$cpf_encrypted = $encrypted;
			}
		}

		$existing   = get_post_meta( $form_id, PublicCsvDownload::META_DOWNLOAD_LOG, true );
		$existing   = is_array( $existing ) ? $existing : array();
		$existing[] = array(
			'ts'            => time(),
			'ip'            => \FreeFormCertificate\Core\Utils::get_user_ip(),
			'mode'          => $mode,
			'cpf_encrypted' => $cpf_encrypted,
			'result'        => $result,
		);
		if ( count( $existing ) > PublicCsvDownload::DOWNLOAD_LOG_MAX ) {
			$existing = array_slice( $existing, -PublicCsvDownload::DOWNLOAD_LOG_MAX );
		}
		update_post_meta( $form_id, PublicCsvDownload::META_DOWNLOAD_LOG, $existing );
	}
}
