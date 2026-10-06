<?php
/**
 * PrivacyErasers
 *
 * Personal Data Erasers for WordPress Privacy Tools
 * (Tools > Erase Personal Data) for LGPD/GDPR compliance.
 *
 * Anonymizes/deletes user data across all FFC tables. Split out of
 * PrivacyHandler (#591 phase-3) so the data-erase concern lives apart
 * from the registration/policy controller. Behaviour is identical —
 * WordPress core invokes this via callable, and the return shape and
 * DB queries are unchanged.
 *
 * @package FreeFormCertificate\Privacy
 * @since 6.12.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Privacy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$owns} is PrivacySubject::owns_row(), a fragment already prepared by $wpdb->prepare() with every value bound.

/**
 * Personal data erasers.
 */
class PrivacyErasers {

	use \FreeFormCertificate\Core\DatabaseHelperTrait;

	// ──────────────────────────────────────.
	// ERASER.
	// ──────────────────────────────────────.

	/**
	 * Erase personal data across all FFC tables
	 *
	 * Strategy:
	 * - Submissions: SET user_id = NULL, clear encrypted PII columns
	 *   (preserve auth_code, magic_token for public certificate verification)
	 * - Appointments: SET user_id = NULL, clear PII fields
	 * - Audience members/booking users/permissions: DELETE
	 * - User profiles: DELETE
	 * - Activity log: SET user_id = NULL
	 *
	 * @param string $email_address User email.
	 * @param int    $page Page number.
	 * @return array<string, mixed>
	 */
	public static function erase_personal_data( string $email_address, int $page = 1 ): array {
		global $wpdb;
		$subject = PrivacySubject::from_email( $email_address );

		if ( $subject->is_empty() ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		$user_id        = $subject->user_id;
		$owns           = $subject->owns_row( 't' );
		$items_removed  = 0;
		$items_retained = 0;
		$messages       = array();

		// 1. Submissions: anonymize (preserve certificate verification)
		$submissions_table = $wpdb->prefix . 'ffc_submissions';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Write to one of the plugin's own ffc_* tables, which WordPress has no API for; reads of it are cached by the matching *Reader and invalidated by the *Writer.
		$rows = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i t
             SET t.user_id = NULL, t.email_encrypted = NULL, t.email_hash = NULL,
                 t.cpf_encrypted = NULL, t.rf_encrypted = NULL,
                 t.cpf_hash = NULL, t.rf_hash = NULL, t.user_ip_encrypted = NULL
             WHERE {$owns}",
				$submissions_table
			)
		);
		if ( $rows > 0 ) {
			$items_removed  += $rows;
			$items_retained += $rows; // Certificate records retained (anonymized).
			$messages[]      = sprintf(
				/* translators: %d: number of submissions */
				__( '%d certificate submissions anonymized (auth codes and verification links preserved).', 'ffcertificate' ),
				$rows
			);
		}

		// 2. Appointments: anonymize PII.
		$appointments_table = $wpdb->prefix . 'ffc_self_scheduling_appointments';
		if ( self::table_exists( $appointments_table ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Write to one of the plugin's own ffc_* tables, which WordPress has no API for; reads of it are cached by the matching *Reader and invalidated by the *Writer.
			$rows = $wpdb->query(
				$wpdb->prepare(
					"UPDATE %i t
                 SET t.user_id = NULL, t.name = NULL, t.email_encrypted = NULL,
                     t.email_hash = NULL, t.phone_encrypted = NULL,
                     t.cpf_encrypted = NULL, t.cpf_hash = NULL,
                     t.rf_encrypted = NULL, t.rf_hash = NULL,
                     t.custom_data_encrypted = NULL,
                     t.user_notes = NULL, t.user_ip_encrypted = NULL,
                     t.user_agent = NULL
                 WHERE {$owns}",
					$appointments_table
				)
			);
			if ( $rows > 0 ) {
				$items_removed += (int) $rows;
				$messages[]     = sprintf(
					/* translators: %d: number of appointments */
					__( '%d appointments anonymized.', 'ffcertificate' ),
					$rows
				);
			}
		}

		// Everything below is keyed on the account, and a subject without
		// one has nothing there; the retained records are reported either way.
		if ( $user_id > 0 ) {
			self::erase_account_data( $user_id, $items_removed, $messages );
		}

		// 9. Records kept by obligation: reported, never altered (#1574).
		$items_retained += self::report_retained_records( $subject, $messages );

		return self::finish( $email_address, $items_removed, $items_retained, $messages );
	}

	/**
	 * Erase what is keyed on the account: memberships, permissions, profile,
	 * activity attribution and `ffc_*` user meta.
	 *
	 * @param int           $user_id       Account ID.
	 * @param int           $items_removed Running count, incremented here.
	 * @param array<string> $messages      Running messages, appended here.
	 */
	private static function erase_account_data( int $user_id, int &$items_removed, array &$messages ): void {
		global $wpdb;

		// 3. Audience members: DELETE.
		$members_table = $wpdb->prefix . 'ffc_audience_members';
		if ( self::table_exists( $members_table ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Write to one of the plugin's own ffc_* tables, which WordPress has no API for; reads of it are cached by the matching *Reader and invalidated by the *Writer.
			$rows = $wpdb->delete( $members_table, array( 'user_id' => $user_id ), array( '%d' ) );
			if ( $rows > 0 ) {
				$items_removed += (int) $rows;
				$messages[]     = sprintf(
					/* translators: %d: number of memberships */
					__( '%d audience memberships removed.', 'ffcertificate' ),
					$rows
				);
			}
		}

		// 4. Audience booking users: DELETE.
		$booking_users_table = $wpdb->prefix . 'ffc_audience_booking_users';
		if ( self::table_exists( $booking_users_table ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Write to one of the plugin's own ffc_* tables, which WordPress has no API for; reads of it are cached by the matching *Reader and invalidated by the *Writer.
			$rows = $wpdb->delete( $booking_users_table, array( 'user_id' => $user_id ), array( '%d' ) );
			if ( $rows > 0 ) {
				$items_removed += (int) $rows;
			}
		}

		// 5. Audience schedule permissions: DELETE.
		$permissions_table = $wpdb->prefix . 'ffc_audience_schedule_permissions';
		if ( self::table_exists( $permissions_table ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Write to one of the plugin's own ffc_* tables, which WordPress has no API for; reads of it are cached by the matching *Reader and invalidated by the *Writer.
			$rows = $wpdb->delete( $permissions_table, array( 'user_id' => $user_id ), array( '%d' ) );
			if ( $rows > 0 ) {
				$items_removed += (int) $rows;
			}
		}

		// 6. User profiles: DELETE.
		$profiles_table = $wpdb->prefix . 'ffc_user_profiles';
		if ( self::table_exists( $profiles_table ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Write to one of the plugin's own ffc_* tables, which WordPress has no API for; reads of it are cached by the matching *Reader and invalidated by the *Writer.
			$rows = $wpdb->delete( $profiles_table, array( 'user_id' => $user_id ), array( '%d' ) );
			if ( $rows > 0 ) {
				++$items_removed;
				$messages[] = __( 'User profile deleted.', 'ffcertificate' );
			}
		}

		// 7. Activity log: SET user_id = NULL.
		\FreeFormCertificate\Core\ActivityLogQuery::redact_user_id( $user_id );

		// 8. ffc_* user meta: DELETE.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk statement over the plugin's own ffc_* user-meta keys, matched by prefix — the WordPress meta API cannot filter by key prefix, and doing it per user per key would be thousands of queries.
		$meta_deleted = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE user_id = %d AND meta_key LIKE %s',
				$wpdb->usermeta,
				$user_id,
				'ffc_%'
			)
		);
		if ( $meta_deleted > 0 ) {
			$items_removed += (int) $meta_deleted;
			$messages[]     = sprintf(
				/* translators: %d: number of settings */
				__( '%d user settings removed.', 'ffcertificate' ),
				$meta_deleted
			);
		}
	}

	/**
	 * Count the records the plugin keeps by obligation, and say so.
	 *
	 * A reregistration and a recruitment candidacy are public-administration
	 * records -- the census answers an institution is required to hold, and
	 * the classification lists of a public tender. LGPD art. 16 lets a
	 * controller keep data it must hold by legal obligation, and the WordPress
	 * eraser API carries exactly that outcome: `items_retained` with a message
	 * the administrator reads before answering the subject. Nothing is altered;
	 * removing them stays a decision for whoever owns the obligation.
	 *
	 * @param PrivacySubject $subject  Request subject.
	 * @param array<string>  $messages Running messages, appended here.
	 * @return int Number of retained records.
	 */
	private static function report_retained_records( PrivacySubject $subject, array &$messages ): int {
		global $wpdb;
		$retained = 0;

		$rereg_table = $wpdb->prefix . 'ffc_reregistration_submissions';
		if ( $subject->user_id > 0 && self::table_exists( $rereg_table ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Count over one of the plugin's own ffc_* tables during an erasure request; it must read the live state.
			$count = (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE user_id = %d', $rereg_table, $subject->user_id )
			);
			if ( $count > 0 ) {
				$retained  += $count;
				$messages[] = sprintf(
					/* translators: %d: number of reregistration submissions */
					__( '%d reregistration submissions retained: they are institutional records kept by legal obligation. Remove them from the campaign if the obligation no longer applies.', 'ffcertificate' ),
					$count
				);
			}
		}

		$candidate_table = $wpdb->prefix . 'ffc_recruitment_candidate';
		if ( self::table_exists( $candidate_table ) ) {
			$owns = $subject->owns_row( 't' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Count over one of the plugin's own ffc_* tables during an erasure request; it must read the live state.
			$count = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM %i t WHERE {$owns}", $candidate_table )
			);
			if ( $count > 0 ) {
				$retained  += $count;
				$messages[] = sprintf(
					/* translators: %d: number of recruitment candidacies */
					__( '%d recruitment candidacies retained: classification lists of a public tender are kept by legal obligation. Remove the candidate in Recruitment if the obligation no longer applies.', 'ffcertificate' ),
					$count
				);
			}
		}

		return $retained;
	}

	/**
	 * Record the erasure and build the response WordPress expects.
	 *
	 * @param string        $email_address  Request address.
	 * @param int           $items_removed  Records removed or anonymised.
	 * @param int           $items_retained Records retained.
	 * @param array<string> $messages       Messages for the administrator.
	 * @return array<string, mixed>
	 */
	private static function finish( string $email_address, int $items_removed, int $items_retained, array $messages ): array {
		// Log the erasure.
		if ( class_exists( '\FreeFormCertificate\Core\ActivityLog' ) ) {
			\FreeFormCertificate\Core\ActivityLog::log(
				'privacy_data_erased',
				\FreeFormCertificate\Core\ActivityLog::LEVEL_WARNING,
				array(
					'email'          => $email_address,
					'items_removed'  => $items_removed,
					'items_retained' => $items_retained,
				)
			);
		}

		return array(
			'items_removed'  => $items_removed > 0,
			'items_retained' => $items_retained > 0,
			'messages'       => $messages,
			'done'           => true,
		);
	}

	// table_exists() provided by DatabaseHelperTrait.
}
