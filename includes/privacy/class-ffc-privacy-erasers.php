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
 * @since 6.7.x
 */

declare(strict_types=1);

namespace FreeFormCertificate\Privacy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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
		$user = get_user_by( 'email', $email_address );

		if ( ! $user ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		$user_id        = $user->ID;
		$items_removed  = 0;
		$items_retained = 0;
		$messages       = array();

		// 1. Submissions: anonymize (preserve certificate verification)
		$submissions_table = $wpdb->prefix . 'ffc_submissions';
		$rows              = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i
             SET user_id = NULL, email_encrypted = NULL,
                 cpf_encrypted = NULL, rf_encrypted = NULL,
                 cpf_hash = NULL, rf_hash = NULL
             WHERE user_id = %d',
				$submissions_table,
				$user_id
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
			$rows = $wpdb->query(
				$wpdb->prepare(
					'UPDATE %i
                 SET user_id = NULL, name = NULL, email_encrypted = NULL,
                     email_hash = NULL, phone_encrypted = NULL,
                     cpf_encrypted = NULL, cpf_hash = NULL,
                     rf_encrypted = NULL, rf_hash = NULL,
                     custom_data_encrypted = NULL,
                     user_notes = NULL, user_ip_encrypted = NULL,
                     user_agent = NULL
                 WHERE user_id = %d',
					$appointments_table,
					$user_id
				)
			);
			if ( $rows > 0 ) {
				$items_removed += $rows;
				$messages[]     = sprintf(
					/* translators: %d: number of appointments */
					__( '%d appointments anonymized.', 'ffcertificate' ),
					$rows
				);
			}
		}

		// 3. Audience members: DELETE.
		$members_table = $wpdb->prefix . 'ffc_audience_members';
		if ( self::table_exists( $members_table ) ) {
			$rows = $wpdb->delete( $members_table, array( 'user_id' => $user_id ), array( '%d' ) );
			if ( $rows > 0 ) {
				$items_removed += $rows;
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
			$rows = $wpdb->delete( $booking_users_table, array( 'user_id' => $user_id ), array( '%d' ) );
			if ( $rows > 0 ) {
				$items_removed += $rows;
			}
		}

		// 5. Audience schedule permissions: DELETE.
		$permissions_table = $wpdb->prefix . 'ffc_audience_schedule_permissions';
		if ( self::table_exists( $permissions_table ) ) {
			$rows = $wpdb->delete( $permissions_table, array( 'user_id' => $user_id ), array( '%d' ) );
			if ( $rows > 0 ) {
				$items_removed += $rows;
			}
		}

		// 6. User profiles: DELETE.
		$profiles_table = $wpdb->prefix . 'ffc_user_profiles';
		if ( self::table_exists( $profiles_table ) ) {
			$rows = $wpdb->delete( $profiles_table, array( 'user_id' => $user_id ), array( '%d' ) );
			if ( $rows > 0 ) {
				++$items_removed;
				$messages[] = __( 'User profile deleted.', 'ffcertificate' );
			}
		}

		// 7. Activity log: SET user_id = NULL.
		\FreeFormCertificate\Core\ActivityLogQuery::redact_user_id( $user_id );

		// 8. ffc_* user meta: DELETE.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$meta_deleted = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE user_id = %d AND meta_key LIKE %s',
				$wpdb->usermeta,
				$user_id,
				'ffc_%'
			)
		);
		if ( $meta_deleted > 0 ) {
			$items_removed += $meta_deleted;
			$messages[]     = sprintf(
				/* translators: %d: number of settings */
				__( '%d user settings removed.', 'ffcertificate' ),
				$meta_deleted
			);
		}

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
