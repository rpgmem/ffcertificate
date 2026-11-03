<?php
/**
 * PrivacyErasers
 *
 * Personal Data Erasers for WordPress Privacy Tools
 * (Tools > Erase Personal Data) for LGPD/GDPR compliance.
 *
 * Removes what identifies the person across all FFC tables, and reports what
 * is retained and why. Split out of
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
	 * Strategy (retain records, drop what identifies the person):
	 * - Submissions: SET user_id = NULL and clear the identifiers (e-mail,
	 *   CPF/RF, their hashes, IP). The answers stay, so the certificate stays
	 *   verifiable and reprintable, and the rows are REPORTED as retained
	 *   with that reason (#2343) -- never described as anonymised.
	 * - Device signals of those submissions: DELETE.
	 * - Appointments: SET user_id = NULL, clear PII fields.
	 * - Rate-limit counters and logs keyed on the person's identifiers: DELETE.
	 * - Account data: memberships, permissions, profile, invitations and
	 *   user meta deleted; attendance, date-message deliveries and activity
	 *   lines kept as records without the person.
	 * - Reregistrations and recruitment candidacies: reported as retained.
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

		// Read before the identifiers are cleared below: the rate-limit
		// tables key on them, and the device signals on the submission ids.
		$documents      = self::documents_of( $subject );
		$submission_ids = self::submission_ids_of( $subject );

		// 1. Submissions: the identifiers go, the answers stay (#2343).
		$submissions_table = $wpdb->prefix . 'ffc_submissions';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Write to one of the plugin's own ffc_* tables, which WordPress has no API for; reads of it are cached by the matching *Reader and invalidated by the *Writer.
		$rows = (int) $wpdb->query(
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
			$items_retained += $rows;
			$messages[]      = sprintf(
				/* translators: %d: number of certificate submissions */
				__( '%d certificate submissions: e-mail, CPF/RF and IP address removed. The form answers are retained so each certificate stays verifiable and can be reprinted; delete the submission if that is no longer needed.', 'ffcertificate' ),
				$rows
			);
		}

		// 1b. Device signals recorded with those submissions.
		$items_removed += self::erase_device_signals( $submission_ids );

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

		// 3. Rate-limit counters and logs keyed on the person's identifiers.
		$limits = \FreeFormCertificate\Security\RateLimitRepository::erase_identifiers( 'email', array( $email_address ) )
			+ \FreeFormCertificate\Security\RateLimitRepository::erase_identifiers( 'cpf', $documents );
		if ( $limits > 0 ) {
			$items_removed += $limits;
			$messages[]     = sprintf(
				/* translators: %d: number of rate-limit records */
				__( '%d rate-limit records removed.', 'ffcertificate' ),
				$limits
			);
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
	 * The CPF/RF values on the subject's submissions and appointments, read
	 * before the eraser clears them, for the rate-limit tables that key on
	 * them.
	 *
	 * @param PrivacySubject $subject Request subject.
	 * @return list<string>
	 */
	private static function documents_of( PrivacySubject $subject ): array {
		global $wpdb;
		if ( ! class_exists( '\FreeFormCertificate\Core\Encryption' ) ) {
			return array();
		}

		$owns   = $subject->owns_row( 't' );
		$values = array();
		foreach ( array( 'ffc_submissions', 'ffc_self_scheduling_appointments' ) as $name ) {
			$table = $wpdb->prefix . $name;
			if ( ! self::table_exists( $table ) ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read of the plugin's own ffc_* table during an erasure request; it must read the live state.
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT t.cpf_encrypted, t.rf_encrypted FROM %i t WHERE {$owns}", $table ),
				ARRAY_A
			);
			foreach ( is_array( $rows ) ? $rows : array() as $row ) {
				foreach ( array( 'cpf_encrypted', 'rf_encrypted' ) as $column ) {
					$cipher = $row[ $column ] ?? null;
					if ( ! is_string( $cipher ) || '' === $cipher ) {
						continue;
					}
					$plain = \FreeFormCertificate\Core\Encryption::decrypt( $cipher );
					if ( is_string( $plain ) && '' !== $plain ) {
						$values[ $plain ] = true;
					}
				}
			}
		}

		return array_map( 'strval', array_keys( $values ) );
	}

	/**
	 * IDs of the subject's submissions, read before they lose the link.
	 *
	 * @param PrivacySubject $subject Request subject.
	 * @return list<int>
	 */
	private static function submission_ids_of( PrivacySubject $subject ): array {
		global $wpdb;
		$owns = $subject->owns_row( 't' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read of the plugin's own ffc_* table during an erasure request; it must read the live state.
		$ids = $wpdb->get_col(
			$wpdb->prepare( "SELECT t.id FROM %i t WHERE {$owns}", $wpdb->prefix . 'ffc_submissions' )
		);

		return array_values( array_map( 'intval', is_array( $ids ) ? $ids : array() ) );
	}

	/**
	 * Delete the device signals recorded with the given submissions.
	 *
	 * @param array<int, int> $submission_ids Submission IDs.
	 * @return int Rows deleted.
	 */
	private static function erase_device_signals( array $submission_ids ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'ffc_device_signals';
		if ( array() === $submission_ids || ! self::table_exists( $table ) ) {
			return 0;
		}

		$deleted = 0;
		foreach ( array_chunk( $submission_ids, 500 ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Write to one of the plugin's own ffc_* tables; $placeholders is a list of %d whose ids are all bound.
			$rows     = $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE submission_id IN ({$placeholders})", array_merge( array( $table ), $chunk ) ) );
			$deleted += is_int( $rows ) ? $rows : 0;
		}

		return $deleted;
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

		// 4b. Audience attendance (#1947): a record, so the line stays and
		// loses the person, as account deletion does.
		$attendance_table = $wpdb->prefix . 'ffc_audience_attendance';
		if ( self::table_exists( $attendance_table ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Write to one of the plugin's own ffc_* tables, which WordPress has no API for; reads of it are cached by the matching *Reader and invalidated by the *Writer.
			$rows = $wpdb->query(
				$wpdb->prepare( 'UPDATE %i SET user_id = NULL WHERE user_id = %d', $attendance_table, $user_id )
			);
			if ( $rows > 0 ) {
				$items_removed += (int) $rows;
			}
		}

		// 4c. Post-event form invitations (#2024): a relationship, deleted
		// with the person as on account deletion.
		$invites_table = $wpdb->prefix . 'ffc_audience_form_invites';
		if ( self::table_exists( $invites_table ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Write to one of the plugin's own ffc_* tables, which WordPress has no API for; reads of it are cached by the matching *Reader and invalidated by the *Writer.
			$rows = $wpdb->delete( $invites_table, array( 'user_id' => $user_id ), array( '%d' ) );
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

		// 6b. Date-message deliveries (#2343): a record of a send, so the
		// line stays and loses the person, as account deletion does.
		$date_log_table = $wpdb->prefix . 'ffc_date_message_log';
		if ( self::table_exists( $date_log_table ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Write to one of the plugin's own ffc_* tables, which WordPress has no API for.
			$rows = $wpdb->query(
				$wpdb->prepare( 'UPDATE %i SET user_id = NULL WHERE user_id = %d', $date_log_table, $user_id )
			);
			if ( $rows > 0 ) {
				$items_removed += (int) $rows;
			}
		}

		// 7. Activity log: the lines stay as the audit record, without the
		// person, the client address or the encrypted context (#2343).
		$items_removed += \FreeFormCertificate\Core\ActivityLogQuery::erase_user_data( $user_id );

		// 8. ffc_* user meta: DELETE.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk statement over the plugin's own ffc_* user-meta keys, matched by prefix — the WordPress meta API cannot filter by key prefix, and doing it per user per key would be thousands of queries.
		$meta_deleted = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE user_id = %d AND meta_key LIKE %s',
				$wpdb->usermeta,
				$user_id,
				$wpdb->esc_like( 'ffc_' ) . '%'
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
					'email'          => \FreeFormCertificate\Core\DocumentFormatter::mask( \FreeFormCertificate\Core\DocumentFormatter::MASK_EMAIL, $email_address ),
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
