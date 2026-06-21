<?php
/**
 * UserIdentifiersQueryService
 *
 * Cross-table aggregator for the user-level identifiers (CPF, RF, email)
 * that live encrypted on `wp_ffc_submissions` and
 * `wp_ffc_self_scheduling_appointments`. Concentrates the UNION ALL
 * queries that used to be inlined in `UserManager` (#343 group A).
 *
 * Rationale (from #343's body): the UNIONs straddle two tables owned
 * by different repositories (`SubmissionRepository` and
 * `AppointmentRepository`). Pushing the cross-table SQL into either
 * repo would break single-table ownership. A dedicated query service
 * sits at the right layer — read-only, scoped to "user identifiers",
 * and easy to test in isolation.
 *
 * @package FreeFormCertificate\Services
 * @since   6.6.2
 */

declare(strict_types=1);

namespace FreeFormCertificate\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
/**
 * Stateless service. Public API mirrors what `UserManager` exposed
 * pre-#343 group A — callers route through here now.
 */
final class UserIdentifiersQueryService {

	/**
	 * Return every masked CPF / RF the user appears with, deduped.
	 * Each row is decrypted, the CPF takes precedence when both
	 * columns are populated, and the resulting plaintext is masked
	 * through `DocumentFormatter::mask_cpf()`.
	 *
	 * @since 6.6.2
	 * @param int $user_id WordPress user ID.
	 * @return array<int, string>
	 */
	public static function get_cpfs_masked_for_user( int $user_id ): array {
		$rows = self::fetch_encrypted_cpf_rf_rows( $user_id );

		$out = array();
		foreach ( $rows as $row ) {
			try {
				$plain = null;
				if ( ! empty( $row['cpf_encrypted'] ) ) {
					$plain = \FreeFormCertificate\Core\Encryption::decrypt( $row['cpf_encrypted'] );
				} elseif ( ! empty( $row['rf_encrypted'] ) ) {
					$plain = \FreeFormCertificate\Core\Encryption::decrypt( $row['rf_encrypted'] );
				}

				if ( ! empty( $plain ) ) {
					$masked = \FreeFormCertificate\Core\DocumentFormatter::mask_cpf( $plain );
					if ( ! empty( $masked ) && ! in_array( $masked, $out, true ) ) {
						$out[] = $masked;
					}
				}
			} catch ( \Exception $e ) {
				if ( class_exists( '\FreeFormCertificate\Core\Debug' ) ) {
					\FreeFormCertificate\Core\Debug::log_user_manager(
						'Failed to decrypt CPF/RF',
						array(
							'user_id' => $user_id,
							'error'   => $e->getMessage(),
						)
					);
				}
				continue;
			}
		}
		return $out;
	}

	/**
	 * Return the user's masked identifiers split by type — `cpfs` and
	 * `rfs` keys. Decryption + masking match
	 * {@see self::get_cpfs_masked_for_user()}; the difference is the
	 * shape (typed buckets rather than a flat list).
	 *
	 * @since 6.6.2
	 * @param int $user_id WordPress user ID.
	 * @return array{cpfs: array<int, string>, rfs: array<int, string>}
	 */
	public static function get_typed_identifiers_for_user( int $user_id ): array {
		$rows = self::fetch_encrypted_cpf_rf_rows( $user_id );

		$cpfs = array();
		$rfs  = array();

		foreach ( $rows as $row ) {
			try {
				if ( ! empty( $row['cpf_encrypted'] ) ) {
					$plain = \FreeFormCertificate\Core\Encryption::decrypt( $row['cpf_encrypted'] );
					if ( ! empty( $plain ) ) {
						$masked = \FreeFormCertificate\Core\DocumentFormatter::mask_cpf( $plain );
						if ( ! empty( $masked ) && ! in_array( $masked, $cpfs, true ) ) {
							$cpfs[] = $masked;
						}
					}
				} elseif ( ! empty( $row['rf_encrypted'] ) ) {
					$plain = \FreeFormCertificate\Core\Encryption::decrypt( $row['rf_encrypted'] );
					if ( ! empty( $plain ) ) {
						$masked = \FreeFormCertificate\Core\DocumentFormatter::mask_cpf( $plain );
						if ( ! empty( $masked ) && ! in_array( $masked, $rfs, true ) ) {
							$rfs[] = $masked;
						}
					}
				}
			} catch ( \Exception $e ) {
				continue;
			}
		}

		return array(
			'cpfs' => $cpfs,
			'rfs'  => $rfs,
		);
	}

	/**
	 * Return every distinct email the user appears with — across
	 * submissions, appointments, AND the WP account itself. Decryption
	 * is best-effort; rows whose blob doesn't decrypt to a valid email
	 * are silently dropped. The WP account email is appended last so
	 * it's always included when valid.
	 *
	 * @since 6.6.2
	 * @param int $user_id WordPress user ID.
	 * @return array<int, string>
	 */
	public static function get_emails_for_user( int $user_id ): array {
		$encrypted_emails = self::fetch_encrypted_emails( $user_id );

		if ( empty( $encrypted_emails ) ) {
			$user = get_user_by( 'id', $user_id );
			return $user ? array( $user->user_email ) : array();
		}

		$emails = array();
		foreach ( $encrypted_emails as $encrypted ) {
			try {
				$email = \FreeFormCertificate\Core\Encryption::decrypt( $encrypted );
				if ( is_string( $email ) && is_email( $email ) ) {
					$emails[] = $email;
				}
			} catch ( \Exception $e ) {
				continue;
			}
		}

		$user = get_user_by( 'id', $user_id );
		if ( $user && is_email( $user->user_email ) ) {
			$emails[] = $user->user_email;
		}

		return array_values( array_unique( $emails ) );
	}

	/**
	 * UNION ALL across `ffc_submissions` ⊕ `ffc_self_scheduling_appointments`
	 * returning every row carrying a CPF or RF blob for the supplied
	 * user. Used by both
	 * {@see self::get_cpfs_masked_for_user()} and
	 * {@see self::get_typed_identifiers_for_user()} — the only
	 * difference between those two is how they shape the decrypted
	 * output, so the SQL stays single-source.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return list<array{cpf_encrypted: ?string, rf_encrypted: ?string}>
	 */
	private static function fetch_encrypted_cpf_rf_rows( int $user_id ): array {
		global $wpdb;
		$submissions_table  = \FreeFormCertificate\Repositories\SubmissionRepository::get_submissions_table();
		$appointments_table = $wpdb->prefix . 'ffc_self_scheduling_appointments';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cross-table UNION; bounded by (user_id) filter on both halves.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT cpf_encrypted, rf_encrypted FROM %i
				WHERE user_id = %d
				AND (cpf_encrypted IS NOT NULL OR rf_encrypted IS NOT NULL)
				UNION ALL
				SELECT cpf_encrypted, rf_encrypted FROM %i
				WHERE user_id = %d
				AND (cpf_encrypted IS NOT NULL OR rf_encrypted IS NOT NULL)',
				$submissions_table,
				$user_id,
				$appointments_table,
				$user_id
			),
			ARRAY_A
		);

		return is_array( $rows ) ? array_values( $rows ) : array();
	}

	/**
	 * UNION ALL across `ffc_submissions` ⊕ `ffc_self_scheduling_appointments`
	 * returning every encrypted-email blob the user appears with.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return list<string>
	 */
	private static function fetch_encrypted_emails( int $user_id ): array {
		global $wpdb;
		$submissions_table  = \FreeFormCertificate\Repositories\SubmissionRepository::get_submissions_table();
		$appointments_table = $wpdb->prefix . 'ffc_self_scheduling_appointments';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cross-table UNION; bounded by (user_id) filter on both halves.
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT email_encrypted FROM %i
				WHERE user_id = %d
				AND email_encrypted IS NOT NULL
				AND email_encrypted != ''
				UNION ALL
				SELECT email_encrypted FROM %i
				WHERE user_id = %d
				AND email_encrypted IS NOT NULL
				AND email_encrypted != ''",
				$submissions_table,
				$user_id,
				$appointments_table,
				$user_id
			)
		);

		return is_array( $rows ) ? array_values( array_map( 'strval', $rows ) ) : array();
	}
}
