<?php
/**
 * UserService
 *
 * Centralized aggregator for user data retrieval and operations: merges
 * WP core user data, the FFC `ffc_user_profiles` row, and the granted
 * capability map into a single view-model.
 *
 * Production consumers (post-#322 wire-up):
 *   - `Api\UserProfileRestController::get_user_profile()` — sources the
 *     `/me/profile` GET response via `get_full_profile()`.
 *   - `Privacy\PrivacyHandler::export_profile()` — sources the LGPD
 *     personal-data export via `export_personal_data()`.
 *   - `UserDashboard\UserCleanup::anonymize_user_data()` — short-
 *     circuits when `user_has_ffc_data()` returns false so users with
 *     no FFC footprint skip the per-table UPDATE / DELETE scans.
 *
 * @package FreeFormCertificate\Services
 * @since 4.9.7
 */

declare(strict_types=1);

namespace FreeFormCertificate\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Every statement in this class runs against one of the plugin's own ffc_* tables, which WordPress exposes no API for. Caching is decided per read at the repository layer, not per statement (#1042).
/**
 * Service class for user operations.
 */
class UserService {

	use \FreeFormCertificate\Core\DatabaseHelperTrait;

	/**
	 * Get full user profile (WP data + FFC profile + capabilities)
	 *
	 * @param int $user_id WordPress user ID.
	 * @return array<string, mixed>|null Profile data or null if user not found
	 */
	public static function get_full_profile( int $user_id ): ?array {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return null;
		}

		$profile = array(
			'user_id'      => $user_id,
			'display_name' => $user->display_name,
			'email'        => $user->user_email,
			'member_since' => $user->user_registered,
			'roles'        => $user->roles,
		);

		// Merge FFC profile data.
		if ( class_exists( '\FreeFormCertificate\UserDashboard\UserManager' ) ) {
			$ffc_profile             = \FreeFormCertificate\UserDashboard\UserManager::get_profile( $user_id );
			$profile['phone']        = $ffc_profile['phone'] ?? '';
			$profile['department']   = $ffc_profile['department'] ?? '';
			$profile['organization'] = $ffc_profile['organization'] ?? '';
			$profile['notes']        = $ffc_profile['notes'] ?? '';

			// Display-name override from `ffc_user_profiles` takes
			// precedence when non-empty (#322 — matches the merge
			// previously inlined in `UserProfileRestController`).
			if ( ! empty( $ffc_profile['display_name'] ) ) {
				$profile['display_name'] = (string) $ffc_profile['display_name'];
			}

			// Preferences blob (raw — callers decode if they need JSON).
			// Surfaced so consumers don't re-fetch the row just for this.
			if ( array_key_exists( 'preferences', $ffc_profile ) ) {
				$profile['preferences'] = $ffc_profile['preferences'];
			}

			// The canonical birth date (#1538), decrypted, in ISO `Y-m-d`,
			// or '' when none is stored. Read here so the dashboard and the
			// LGPD export share one read instead of each decrypting it.
			$extended              = \FreeFormCertificate\UserDashboard\UserManager::get_extended_profile( $user_id, array( 'birth_date' ) );
			$stored                = $extended['birth_date'] ?? '';
			$profile['birth_date'] = is_string( $stored ) ? (string) \FreeFormCertificate\Core\BirthDate::normalize( $stored ) : '';
		}

		// Capabilities.
		$profile['capabilities'] = self::get_user_capabilities( $user_id );

		return $profile;
	}

	/**
	 * Get user's FFC capabilities with their grant status
	 *
	 * @param int $user_id WordPress user ID.
	 * @return array<string, bool> Associative array of capability => bool
	 */
	public static function get_user_capabilities( int $user_id ): array {
		if ( ! class_exists( '\FreeFormCertificate\UserDashboard\UserManager' ) ) {
			return array();
		}

		$all_caps = \FreeFormCertificate\UserDashboard\CapabilityManager::get_all_capabilities();
		$result   = array();

		foreach ( $all_caps as $cap ) {
			$result[ $cap ] = user_can( $user_id, $cap );
		}

		return $result;
	}

	/**
	 * Get user statistics (certificate count, appointment count, etc.)
	 *
	 * @param int $user_id WordPress user ID.
	 * @return array<string, int> Statistics
	 */
	public static function get_user_statistics( int $user_id ): array {
		global $wpdb;

		$stats = array(
			'certificates'    => 0,
			'appointments'    => 0,
			'audience_groups' => 0,
		);

		// Certificate count.
		if ( class_exists( '\FreeFormCertificate\Core\Utils' ) ) {
			$table                 = \FreeFormCertificate\Repositories\SubmissionRepository::get_submissions_table();
			$stats['certificates'] = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM %i WHERE user_id = %d AND status != 'trash'",
					$table,
					$user_id
				)
			);
		}

		// Appointment count.
		$appointments_table = $wpdb->prefix . 'ffc_self_scheduling_appointments';
		if ( self::table_exists( $appointments_table ) ) {
			$stats['appointments'] = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM %i WHERE user_id = %d AND status != 'cancelled'",
					$appointments_table,
					$user_id
				)
			);
		}

		// Audience group count.
		$members_table = $wpdb->prefix . 'ffc_audience_members';
		if ( self::table_exists( $members_table ) ) {
			$stats['audience_groups'] = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE user_id = %d',
					$members_table,
					$user_id
				)
			);
		}

		return $stats;
	}

	/**
	 * Export all personal data for a user (used by PrivacyHandler)
	 *
	 * Returns structured data suitable for WordPress Export Personal Data tool.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return array<string, mixed> Grouped personal data
	 */
	public static function export_personal_data( int $user_id ): array {
		$data = array();

		// Profile.
		$profile = self::get_full_profile( $user_id );
		if ( $profile ) {
			$data['profile'] = $profile;
		}

		// Statistics.
		$data['statistics'] = self::get_user_statistics( $user_id );

		return $data;
	}

	/**
	 * Tables the deletion cleanup touches by `user_id`, under the prefix.
	 *
	 * The footprint check below reads exactly these, so a row the cleanup
	 * would change is never skipped because a check looked elsewhere (#2246).
	 */
	private const FOOTPRINT_TABLES = array(
		'ffc_self_scheduling_appointments',
		'ffc_activity_log',
		'ffc_audience_members',
		'ffc_audience_booking_users',
		'ffc_audience_schedule_permissions',
		'ffc_user_profiles',
	);

	/**
	 * Whether the user has any row the deletion cleanup would change.
	 *
	 * `UserCleanup::anonymize_user_data()` skips its per-table UPDATE and
	 * DELETE statements when this is false, so it has to look where those
	 * statements look, with no status filter: a trashed certificate or a
	 * cancelled appointment still carries the `user_id` the cleanup nulls, and
	 * a profile row, a booking or a schedule permission with no membership is
	 * still a row the cleanup deletes (#2246). That is why it does not reuse
	 * `get_user_statistics()`, which counts what a person sees on the
	 * dashboard. Each table answers with an indexed `LIMIT 1` and the check
	 * stops at the first hit.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return bool
	 */
	public static function user_has_ffc_data( int $user_id ): bool {
		global $wpdb;

		$tables = array( \FreeFormCertificate\Repositories\SubmissionRepository::get_submissions_table() );
		foreach ( self::FOOTPRINT_TABLES as $basename ) {
			$table = $wpdb->prefix . $basename;
			if ( self::table_exists( $table ) ) {
				$tables[] = $table;
			}
		}

		foreach ( $tables as $table ) {
			$hit = $wpdb->get_var(
				$wpdb->prepare( 'SELECT 1 FROM %i WHERE user_id = %d LIMIT 1', $table, $user_id )
			);
			if ( null !== $hit ) {
				return true;
			}
		}

		return false;
	}
}
