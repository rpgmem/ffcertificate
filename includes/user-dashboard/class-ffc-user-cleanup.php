<?php
/**
 * UserCleanup
 *
 * Handles user deletion (anonymization) and email change events.
 * Integrates with WordPress hooks to keep FFC data consistent.
 *
 * @package FreeFormCertificate\UserDashboard
 * @since 4.9.4
 */

declare(strict_types=1);

namespace FreeFormCertificate\UserDashboard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * User Cleanup.
 */
class UserCleanup {

	use \FreeFormCertificate\Core\DatabaseHelperTrait;

	/**
	 * Initialize hooks
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'deleted_user', array( __CLASS__, 'anonymize_user_data' ) );
		add_action( 'profile_update', array( __CLASS__, 'handle_email_change' ), 10, 2 );
	}

	/**
	 * Anonymize all FFC data when a WordPress user is deleted
	 *
	 * Strategy:
	 * - Submissions/Appointments/Activity log: SET user_id = NULL (preserve audit trail)
	 * - Audience members/booking users/permissions/profiles: DELETE (no audit value)
	 *
	 * @param int $user_id Deleted WordPress user ID.
	 * @return void
	 */
	public static function anonymize_user_data( int $user_id ): void {
		global $wpdb;

		$anonymized = array();

		// Data-subject footprint cleanup. Issue #322 short-circuit: when the
		// user has no FFC footprint at all (no submissions, no appointments, no
		// audience memberships) every UPDATE / DELETE below would be a no-op, so
		// skip them. UserService::user_has_ffc_data() does one COUNT per relevant
		// table — far cheaper than scanning those tables with WHERE user_id = %d
		// when there's nothing to update.
		if ( \FreeFormCertificate\Services\UserService::user_has_ffc_data( $user_id ) ) {
			// 1. Submissions: SET user_id = NULL (preserve certificate records)
			$submissions_table = $wpdb->prefix . 'ffc_submissions';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Write to one of the plugin's own ffc_* tables, which WordPress has no API for; reads of it are cached by the matching *Reader and invalidated by the *Writer.
			$rows = $wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET user_id = NULL WHERE user_id = %d',
					$submissions_table,
					$user_id
				)
			);
			if ( $rows > 0 ) {
				$anonymized['submissions'] = $rows;
			}

			// 2. Self-scheduling appointments: SET user_id = NULL.
			$appointments_table = $wpdb->prefix . 'ffc_self_scheduling_appointments';
			if ( self::table_exists( $appointments_table ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Write to one of the plugin's own ffc_* tables, which WordPress has no API for; reads of it are cached by the matching *Reader and invalidated by the *Writer.
				$rows = $wpdb->query(
					$wpdb->prepare(
						'UPDATE %i SET user_id = NULL WHERE user_id = %d',
						$appointments_table,
						$user_id
					)
				);
				if ( $rows > 0 ) {
					$anonymized['appointments'] = $rows;
				}
			}

			// 3. Activity log: SET user_id = NULL (preserve audit trail)
			$rows = \FreeFormCertificate\Core\ActivityLogQuery::redact_user_id( $user_id );
			if ( $rows > 0 ) {
				$anonymized['activity_log'] = $rows;
			}

			// 4. Audience members: DELETE.
			$members_table = $wpdb->prefix . 'ffc_audience_members';
			if ( self::table_exists( $members_table ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Write to one of the plugin's own ffc_* tables, which WordPress has no API for; reads of it are cached by the matching *Reader and invalidated by the *Writer.
				$rows = $wpdb->delete( $members_table, array( 'user_id' => $user_id ), array( '%d' ) );
				if ( $rows > 0 ) {
					$anonymized['audience_members'] = $rows;
				}
			}

			// 5. Audience booking users: DELETE.
			$booking_users_table = $wpdb->prefix . 'ffc_audience_booking_users';
			if ( self::table_exists( $booking_users_table ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Write to one of the plugin's own ffc_* tables, which WordPress has no API for; reads of it are cached by the matching *Reader and invalidated by the *Writer.
				$rows = $wpdb->delete( $booking_users_table, array( 'user_id' => $user_id ), array( '%d' ) );
				if ( $rows > 0 ) {
					$anonymized['booking_users'] = $rows;
				}
			}

			// 6. Audience schedule permissions: DELETE.
			$permissions_table = $wpdb->prefix . 'ffc_audience_schedule_permissions';
			if ( self::table_exists( $permissions_table ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Write to one of the plugin's own ffc_* tables, which WordPress has no API for; reads of it are cached by the matching *Reader and invalidated by the *Writer.
				$rows = $wpdb->delete( $permissions_table, array( 'user_id' => $user_id ), array( '%d' ) );
				if ( $rows > 0 ) {
					$anonymized['schedule_permissions'] = $rows;
				}
			}

			// 7. User profiles: DELETE.
			$profiles_table = $wpdb->prefix . 'ffc_user_profiles';
			if ( self::table_exists( $profiles_table ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Write to one of the plugin's own ffc_* tables, which WordPress has no API for; reads of it are cached by the matching *Reader and invalidated by the *Writer.
				$wpdb->delete( $profiles_table, array( 'user_id' => $user_id ), array( '%d' ) );
				$anonymized['profile'] = 1;
			}
		}

		// Actor-attribution + promoted-candidate links (#834). These key on
		// *_by columns / recruitment_candidate.user_id, which the data-subject
		// footprint check above does NOT detect — a pure actor (an admin/operator
		// who only created or approved) or a promoted candidate can have zero
		// footprint of their own — so this runs on every deletion. Only nullable
		// columns are touched; the NOT NULL attribution columns are accepted
		// orphans on retained records (see CLAUDE.md §4). The encrypted row-body
		// PII is deliberately NOT scrubbed here — that is the manual PrivacyErasers
		// (LGPD erasure) path, kept distinct from routine account deletion.
		self::anonymize_authorship( $user_id, $anonymized );

		// Log the anonymization only when something actually changed.
		if ( ! empty( $anonymized ) && class_exists( '\FreeFormCertificate\Core\ActivityLog' ) ) {
			\FreeFormCertificate\Core\ActivityLog::log(
				'user_data_anonymized',
				\FreeFormCertificate\Core\ActivityLog::LEVEL_WARNING,
				array(
					'anonymized_user_id' => $user_id,
					'tables_affected'    => $anonymized,
				)
			);
		}
	}

	/**
	 * Null the nullable actor-attribution columns and the promoted-candidate
	 * link for a deleted user (#834 clusters 1 + 2).
	 *
	 * Map: `table basename => [ nullable columns ]`. Every UPDATE is guarded by
	 * table_exists() + column_exists(), so a module whose table was never
	 * created, or an older install missing a migration-added column (e.g.
	 * `submissions.edited_by`), is silently skipped. Identifiers go through the
	 * `%i` placeholder — no interpolation.
	 *
	 * NOT NULL attribution columns are intentionally absent from the map —
	 * `ffc_audiences` / `ffc_audience_schedules` / `ffc_audience_holidays` /
	 * `ffc_audience_bookings.created_by`, `ffc_reregistrations.created_by`,
	 * `ffc_recruitment_call.created_by` — because a plain `SET … = NULL` would be
	 * rejected; they are accepted orphans on retained records.
	 *
	 * Runs on every deletion (an actor/candidate need not have data-subject
	 * footprint). The `*_by` columns are unindexed, so a bulk user deletion pays
	 * a scan per column on large tables — acceptable on this cold admin path;
	 * index the columns if it ever bites.
	 *
	 * @param int                  $user_id    Deleted user id.
	 * @param array<string, mixed> $anonymized Accumulator (by reference).
	 * @return void
	 */
	private static function anonymize_authorship( int $user_id, array &$anonymized ): void {
		global $wpdb;

		$map = array(
			'ffc_submissions'                   => array( 'edited_by' ),
			'ffc_self_scheduling_appointments'  => array( 'approved_by', 'cancelled_by' ),
			'ffc_self_scheduling_calendars'     => array( 'created_by', 'updated_by' ),
			'ffc_self_scheduling_blocked_dates' => array( 'created_by' ),
			'ffc_audience_bookings'             => array( 'cancelled_by' ),
			'ffc_reregistration_submissions'    => array( 'reviewed_by' ),
			'ffc_recruitment_call'              => array( 'cancelled_by' ),
			'ffc_short_urls'                    => array( 'created_by' ),
			// Cluster 2: drop the promoted-candidate → WP-user link, retain the
			// candidacy record (its PII stays; PrivacyErasers scrubs the body).
			'ffc_recruitment_candidate'         => array( 'user_id' ),
		);

		foreach ( $map as $basename => $columns ) {
			$table = $wpdb->prefix . $basename;
			if ( ! self::table_exists( $table ) ) {
				continue;
			}

			$affected = 0;
			foreach ( $columns as $column ) {
				if ( ! self::column_exists( $table, $column ) ) {
					continue;
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Write to one of the plugin's own ffc_* tables, which WordPress has no API for; reads of it are cached by the matching *Reader and invalidated by the *Writer.
				$rows = $wpdb->query(
					$wpdb->prepare(
						'UPDATE %i SET %i = NULL WHERE %i = %d',
						$table,
						$column,
						$column,
						$user_id
					)
				);
				if ( $rows > 0 ) {
					$affected += (int) $rows;
				}
			}

			if ( $affected > 0 ) {
				$anonymized[ $basename ] = $affected;
			}
		}
	}

	/**
	 * Handle email change in WordPress
	 *
	 * When a user's email changes, reindex email_hash on their submissions
	 * so that searches by the new email find their records.
	 *
	 * Does NOT alter email_encrypted (historical record of email at submission time).
	 *
	 * @param int      $user_id Updated user ID.
	 * @param \WP_User $old_user_data User data before the update.
	 * @return void
	 */
	public static function handle_email_change( int $user_id, \WP_User $old_user_data ): void {
		$new_user = get_userdata( $user_id );
		if ( ! $new_user ) {
			return;
		}

		$old_email = $old_user_data->user_email;
		$new_email = $new_user->user_email;

		if ( $old_email === $new_email ) {
			return;
		}

		global $wpdb;
		$submissions_table = $wpdb->prefix . 'ffc_submissions';

		// Reindex email_hash for submissions linked to this user_id.
		// Must mirror SubmissionHandler exactly: Encryption::hash without normalization.
		$new_email_hash = \FreeFormCertificate\Core\Encryption::hash( $new_email );

		if ( null !== $new_email_hash ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Write to one of the plugin's own ffc_* tables, which WordPress has no API for; reads of it are cached by the matching *Reader and invalidated by the *Writer.
			$wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET email_hash = %s WHERE user_id = %d',
					$submissions_table,
					$new_email_hash,
					$user_id
				)
			);
		}

		// Update profile timestamp.
		$profiles_table = $wpdb->prefix . 'ffc_user_profiles';
		if ( self::table_exists( $profiles_table ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Write to one of the plugin's own ffc_* tables, which WordPress has no API for; reads of it are cached by the matching *Reader and invalidated by the *Writer.
			$wpdb->update(
				$profiles_table,
				array( 'updated_at' => current_time( 'mysql' ) ),
				array( 'user_id' => $user_id ),
				array( '%s' ),
				array( '%d' )
			);
		}

		// Log the change.
		if ( class_exists( '\FreeFormCertificate\Core\ActivityLog' ) ) {
			$old_masked = class_exists( '\FreeFormCertificate\Core\Utils' )
				? \FreeFormCertificate\Core\DocumentFormatter::mask_email( $old_email )
				: '***';
			$new_masked = class_exists( '\FreeFormCertificate\Core\Utils' )
				? \FreeFormCertificate\Core\DocumentFormatter::mask_email( $new_email )
				: '***';

			\FreeFormCertificate\Core\ActivityLog::log(
				'user_email_changed',
				\FreeFormCertificate\Core\ActivityLog::LEVEL_INFO,
				array(
					'user_id'          => $user_id,
					'old_email_masked' => $old_masked,
					'new_email_masked' => $new_masked,
				)
			);
		}
	}

	// table_exists() provided by DatabaseHelperTrait.
}
