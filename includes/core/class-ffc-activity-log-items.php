<?php
/**
 * ActivityLogItems
 *
 * What the activity log records, item by item (#2340). Every action belongs
 * to exactly one item, and every item to one of two groups:
 *
 * - **audit**: who saw personal data, who deleted or suppressed data, who
 *   changed permissions. On by default, written whatever the minimum level,
 *   and switched off only with the Danger Zone capability.
 * - **secondary**: the day-to-day of each module. Off by default, each item
 *   its own toggle, filtered by the minimum level.
 *
 * Each item is stored as `ffc_settings['activity_log_item_<key>']`. The keys
 * are stored values and do not rename.
 *
 * It replaced the seven `activity_log_cat_*` categories, which mixed both
 * kinds in one switch: turning off "Users & Privacy" also stopped recording
 * permission changes. {@see self::migrate()} carries each category's state
 * onto the items it split into.
 *
 * @package FreeFormCertificate\Core
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The activity log's items and the action-to-item map.
 */
final class ActivityLogItems {

	/**
	 * Group of the items that are always recorded unless the Danger Zone
	 * capability switches them off.
	 */
	public const GROUP_AUDIT = 'audit';

	/**
	 * Group of the items that are off by default.
	 */
	public const GROUP_SECONDARY = 'secondary';

	/**
	 * Prefix of an item's key in `ffc_settings`.
	 */
	public const SETTING_PREFIX = 'activity_log_item_';

	/**
	 * Key in `ffc_settings` recording that the categories were carried onto
	 * the items. Kept in the settings array so it needs no option of its own.
	 */
	public const MIGRATED_KEY = 'activity_log_items_version';

	/**
	 * Current version of {@see self::MIGRATED_KEY}.
	 */
	public const VERSION = 1;

	/**
	 * Every item: its group, the section it is listed under, and, for a
	 * secondary item, the category it came from (read by {@see self::migrate()}).
	 *
	 * Ordered as the settings screen lists them.
	 *
	 * @var array<string, array{group: string, section: string, legacy?: string}>
	 */
	public const ITEMS = array(
		'pii_reveal'           => array(
			'group'   => self::GROUP_AUDIT,
			'section' => 'pii',
		),
		'pii_export'           => array(
			'group'   => self::GROUP_AUDIT,
			'section' => 'pii',
		),
		'identity_tools'       => array(
			'group'   => self::GROUP_AUDIT,
			'section' => 'pii',
		),
		'data_deletion'        => array(
			'group'   => self::GROUP_AUDIT,
			'section' => 'dangerzone',
		),
		'cleanups'             => array(
			'group'   => self::GROUP_AUDIT,
			'section' => 'dangerzone',
		),
		'migrations'           => array(
			'group'   => self::GROUP_AUDIT,
			'section' => 'dangerzone',
		),
		'destructive_settings' => array(
			'group'   => self::GROUP_AUDIT,
			'section' => 'dangerzone',
		),
		'role_changes'         => array(
			'group'   => self::GROUP_AUDIT,
			'section' => 'permissions',
		),
		'user_permissions'     => array(
			'group'   => self::GROUP_AUDIT,
			'section' => 'permissions',
		),
		'users_accounts'       => array(
			'group'   => self::GROUP_SECONDARY,
			'section' => 'users',
			'legacy'  => 'users',
		),
		'users_privacy'        => array(
			'group'   => self::GROUP_SECONDARY,
			'section' => 'users',
			'legacy'  => 'users',
		),
		'cert_submissions'     => array(
			'group'   => self::GROUP_SECONDARY,
			'section' => 'certificates',
			'legacy'  => 'submissions',
		),
		'cert_changes'         => array(
			'group'   => self::GROUP_SECONDARY,
			'section' => 'certificates',
			'legacy'  => 'submissions',
		),
		'cert_grades'          => array(
			'group'   => self::GROUP_SECONDARY,
			'section' => 'certificates',
			'legacy'  => 'submissions',
		),
		'cert_bulk_issue'      => array(
			'group'   => self::GROUP_SECONDARY,
			'section' => 'certificates',
			'legacy'  => 'submissions',
		),
		'sched_appointments'   => array(
			'group'   => self::GROUP_SECONDARY,
			'section' => 'scheduling',
			'legacy'  => 'scheduling',
		),
		'sched_bookings'       => array(
			'group'   => self::GROUP_SECONDARY,
			'section' => 'scheduling',
			'legacy'  => 'scheduling',
		),
		'sched_attendance'     => array(
			'group'   => self::GROUP_SECONDARY,
			'section' => 'scheduling',
			'legacy'  => 'scheduling',
		),
		'rereg_reviews'        => array(
			'group'   => self::GROUP_SECONDARY,
			'section' => 'rereg_recruitment',
			'legacy'  => 'submissions',
		),
		'recruit_imports'      => array(
			'group'   => self::GROUP_SECONDARY,
			'section' => 'rereg_recruitment',
			'legacy'  => 'recruitment',
		),
		'recruit_calls'        => array(
			'group'   => self::GROUP_SECONDARY,
			'section' => 'rereg_recruitment',
			'legacy'  => 'recruitment',
		),
		'public_overrides'     => array(
			'group'   => self::GROUP_SECONDARY,
			'section' => 'public_access',
			'legacy'  => 'public_access',
		),
		'public_preflight'     => array(
			'group'   => self::GROUP_SECONDARY,
			'section' => 'public_access',
			'legacy'  => 'public_access',
		),
		'public_blocks'        => array(
			'group'   => self::GROUP_SECONDARY,
			'section' => 'public_access',
			'legacy'  => 'public_access',
		),
		'system_settings'      => array(
			'group'   => self::GROUP_SECONDARY,
			'section' => 'system',
			'legacy'  => 'system',
		),
		'system_tasks'         => array(
			'group'   => self::GROUP_SECONDARY,
			'section' => 'system',
			'legacy'  => 'system',
		),
		'system_errors'        => array(
			'group'   => self::GROUP_SECONDARY,
			'section' => 'system',
			'legacy'  => 'system',
		),
	);

	/**
	 * Actions named one by one. Read before the prefix and suffix rules in
	 * {@see self::for_action()}, so an entry here overrides them.
	 *
	 * @var array<string, string> Action => item key.
	 */
	public const ACTIONS = array(
		'pii_exported'                            => 'pii_export',

		'danger_zone_data_deleted'                => 'data_deletion',
		'data_cleanup'                            => 'data_deletion',
		'bulk_delete'                             => 'data_deletion',
		'submission_deleted'                      => 'data_deletion',
		'appointment_bulk_purged'                 => 'data_deletion',
		'appointment_deleted'                     => 'data_deletion',

		'obsolete_shortcodes_removed'             => 'cleanups',
		'short_urls_cleaned'                      => 'cleanups',
		'public_access_bulk_disabled'             => 'cleanups',
		'tickets_purged_expired'                  => 'cleanups',
		// The calendar cleanup tool cancels or closes other people's
		// appointments in bulk: maintenance, not the day-to-day.
		'appointment_bulk_cancelled'              => 'cleanups',
		'appointment_bulk_closed'                 => 'cleanups',

		'migration_foreign_keys'                  => 'migrations',
		'auth_code_duplicates_removed'            => 'migrations',
		'legacy_columns_dropped'                  => 'migrations',
		'cpf_rf_split_migration_batch'            => 'migrations',
		'cpf_rf_split_unknown_length'             => 'migrations',
		'email_hash_rehash_migration_batch'       => 'migrations',
		'key_rotation_migration_batch'            => 'migrations',
		'activity_log_clear_plaintext_batch'      => 'migrations',
		'rewrite_html_image_refs_errors'          => 'migrations',

		'dangerzone_setting_changed'              => 'destructive_settings',

		'role_capability_changed'                 => 'role_changes',
		'role_default_restored'                   => 'role_changes',

		'capabilities_granted'                    => 'user_permissions',
		'user_ffc_roles_removed'                  => 'user_permissions',
		'user_role_changed'                       => 'user_permissions',
		'user_capabilities_changed'               => 'user_permissions',
		'user_audiences_changed'                  => 'user_permissions',

		'user_linked'                             => 'users_accounts',
		'user_unlinked'                           => 'users_accounts',
		'password_changed'                        => 'users_accounts',
		'profile_updated'                         => 'users_accounts',
		'user_email_changed'                      => 'users_accounts',
		'user_profile_read_full'                  => 'users_accounts',

		'privacy_request_created'                 => 'users_privacy',
		'privacy_data_erased'                     => 'users_privacy',
		'user_data_anonymized'                    => 'users_privacy',

		'submission_created'                      => 'cert_submissions',
		'submission'                              => 'cert_submissions',
		'pdf_generated'                           => 'cert_submissions',
		'certificate_emailed'                     => 'cert_submissions',
		'data_accessed'                           => 'cert_submissions',

		'submission_updated'                      => 'cert_changes',
		'submission_trashed'                      => 'cert_changes',
		'submission_restored'                     => 'cert_changes',
		'submission_moved'                        => 'cert_changes',
		'submission_answer_corrected'             => 'cert_changes',
		'bulk_trash'                              => 'cert_changes',
		'bulk_restore'                            => 'cert_changes',

		'quiz_graded'                             => 'cert_grades',
		'quiz_regraded'                           => 'cert_grades',

		'bulk_issue_finished'                     => 'cert_bulk_issue',
		'operator_certificate_issued'             => 'cert_bulk_issue',

		'audience_booking_override'               => 'sched_bookings',

		'recruitment_csv_imported'                => 'recruit_imports',
		'recruitment_csv_import_failed'           => 'recruit_imports',

		'schedule_override_created'               => 'public_overrides',
		'operator_ip_bypass'                      => 'public_overrides',
		'early_open_executed'                     => 'public_overrides',
		'end_postponed'                           => 'public_overrides',
		'csv_downloaded'                          => 'public_overrides',

		'preflight_blocked'                       => 'public_preflight',

		'rate_limit_blocked'                      => 'public_blocks',
		'captcha_failed'                          => 'public_blocks',
		'captcha_fallback_used'                   => 'public_blocks',

		'attendance_recorded'                     => 'sched_attendance',
		'attendance_removed'                      => 'sched_attendance',
		'checkin_window_opened'                   => 'sched_attendance',
		'checkin_window_closed'                   => 'sched_attendance',

		'settings_changed'                        => 'system_settings',
		'scheduled_task_failed'                   => 'system_tasks',

		'access_denied'                           => 'system_errors',
		'decrypt_failure'                         => 'system_errors',
		'decrypt_failure_suppressed'              => 'system_errors',
		'ip_geolocation_debug'                    => 'system_errors',
		'custom_field_sensitive_demotion_refused' => 'system_errors',
		'custom_field_locked_change_refused'      => 'system_errors',
	);

	/**
	 * Action prefixes, read after {@see self::ACTIONS} and the `_revealed`
	 * suffix: a module's own actions follow its prefix.
	 *
	 * @var array<string, string> Prefix => item key.
	 */
	public const PREFIXES = array(
		'identity_'       => 'identity_tools',
		'appointment_'    => 'sched_appointments',
		'reregistration_' => 'rereg_reviews',
		'recruitment_'    => 'recruit_calls',
	);

	/**
	 * Item of an action no rule names. Its errors and refusals are where an
	 * unrecognised event is least likely to be noise.
	 */
	public const FALLBACK = 'system_errors';

	/**
	 * Translated names of the sections and the items, for the settings screen
	 * and the documentation.
	 *
	 * @return array{sections: array<string, string>, items: array<string, string>}
	 */
	public static function labels(): array {
		return array(
			'sections' => array(
				'pii'               => __( 'Access to personal data', 'ffcertificate' ),
				'dangerzone'        => __( 'Danger Zone and maintenance', 'ffcertificate' ),
				'permissions'       => __( 'Permissions', 'ffcertificate' ),
				'users'             => __( 'Users', 'ffcertificate' ),
				'certificates'      => __( 'Certificates', 'ffcertificate' ),
				'scheduling'        => __( 'Personal and audience calendars', 'ffcertificate' ),
				'rereg_recruitment' => __( 'Reregistration and recruitment', 'ffcertificate' ),
				'public_access'     => __( 'Public access and security', 'ffcertificate' ),
				'system'            => __( 'System', 'ffcertificate' ),
			),
			'items'    => array(
				'pii_reveal'           => __( 'Reveal of CPF, RF, e-mail or address (one value or a page)', 'ffcertificate' ),
				'pii_export'           => __( 'CSV exports with unmasked data', 'ffcertificate' ),
				'identity_tools'       => __( 'Identity tools (merge, split, relink, repair)', 'ffcertificate' ),
				'data_deletion'        => __( 'Data deleted (one submission, by form or everything)', 'ffcertificate' ),
				'cleanups'             => __( 'Cleanups (short URLs, shortcodes, public access, expired tickets, calendar cleanups)', 'ffcertificate' ),
				'migrations'           => __( 'Migrations run and key rotation', 'ffcertificate' ),
				'destructive_settings' => __( 'Settings that delete or suppress data (auto-delete, uninstall wipe, retention, switching the log or an audit item off)', 'ffcertificate' ),
				'role_changes'         => __( 'A role\'s capabilities changed (role editor, Restore role default)', 'ffcertificate' ),
				'user_permissions'     => __( 'A user\'s roles, capabilities or audiences changed (profile, "Grant all")', 'ffcertificate' ),
				'users_accounts'       => __( 'Accounts linked, profiles and passwords changed', 'ffcertificate' ),
				'users_privacy'        => __( 'Privacy requests (export, erasure, anonymization)', 'ffcertificate' ),
				'cert_submissions'     => __( 'Submissions received, PDFs generated and e-mailed', 'ffcertificate' ),
				'cert_changes'         => __( 'Submissions edited, corrected, moved, trashed or restored', 'ffcertificate' ),
				'cert_grades'          => __( 'Quiz grading', 'ffcertificate' ),
				'cert_bulk_issue'      => __( 'Bulk issue and certificates issued by an operator', 'ffcertificate' ),
				'sched_appointments'   => __( 'Appointments booked, cancelled and closed', 'ffcertificate' ),
				'sched_bookings'       => __( 'Audience bookings confirmed over the calendar\'s rules', 'ffcertificate' ),
				'sched_attendance'     => __( 'Attendance marked or removed, check-in windows opened and closed', 'ffcertificate' ),
				'rereg_reviews'        => __( 'Reregistration submissions, reviews and invitations', 'ffcertificate' ),
				'recruit_imports'      => __( 'Candidate imports', 'ffcertificate' ),
				'recruit_calls'        => __( 'Calls, promotions and candidate changes', 'ffcertificate' ),
				'public_overrides'     => __( 'Schedule exceptions, operator bypass and public downloads', 'ffcertificate' ),
				'public_preflight'     => __( 'Preflight and browser blocks', 'ffcertificate' ),
				'public_blocks'        => __( 'Rate-limit blocks and failed captchas', 'ffcertificate' ),
				'system_settings'      => __( 'Settings changed outside the Danger Zone (which settings, never their values)', 'ffcertificate' ),
				'system_tasks'         => __( 'Scheduled tasks that ended in an error', 'ffcertificate' ),
				'system_errors'        => __( 'Errors and refusals (decryption failures, access denied, refused field changes)', 'ffcertificate' ),
			),
		);
	}

	/**
	 * The item an action belongs to.
	 *
	 * Every disclosure of personal data ends in `_revealed` (one value, a
	 * page, a PDF, an address), so the suffix sends them all to the audit
	 * item and a new reveal type is covered the day it is written.
	 *
	 * @param string $action Action key.
	 * @return string Item key.
	 */
	public static function for_action( string $action ): string {
		if ( isset( self::ACTIONS[ $action ] ) ) {
			return self::ACTIONS[ $action ];
		}
		if ( '_revealed' === substr( $action, -9 ) ) {
			return 'pii_reveal';
		}
		foreach ( self::PREFIXES as $prefix => $item ) {
			if ( 0 === strpos( $action, $prefix ) ) {
				return $item;
			}
		}

		return self::FALLBACK;
	}

	/**
	 * Whether an item belongs to the audit group.
	 *
	 * @param string $item Item key.
	 * @return bool
	 */
	public static function is_audit( string $item ): bool {
		return self::GROUP_AUDIT === ( self::ITEMS[ $item ]['group'] ?? '' );
	}

	/**
	 * An item's default: audit items on, secondary items off.
	 *
	 * @param string $item Item key.
	 * @return bool
	 */
	public static function default_for( string $item ): bool {
		return self::is_audit( $item );
	}

	/**
	 * An item's key in `ffc_settings`.
	 *
	 * @param string $item Item key.
	 * @return string
	 */
	public static function setting_key( string $item ): string {
		return self::SETTING_PREFIX . $item;
	}

	/**
	 * Every item key of one group, in screen order.
	 *
	 * @param string $group {@see self::GROUP_AUDIT} or {@see self::GROUP_SECONDARY}.
	 * @return array<int, string>
	 */
	public static function keys_of( string $group ): array {
		return array_keys(
			array_filter(
				self::ITEMS,
				static fn( array $item ): bool => $group === $item['group']
			)
		);
	}

	/**
	 * The settings an install carries once the categories are on the items.
	 *
	 * Runs once ({@see self::MIGRATED_KEY}). The log is switched on, on
	 * upgrade as on a fresh install (#2340). Each secondary item takes the
	 * state its category had in effect: a category the administrator saved
	 * keeps that value, and one never saved was on only while the log itself
	 * was on, because the categories defaulted to on and the log to off. The
	 * audit items keep their default, on. The category keys are dropped.
	 *
	 * Pure: it reads and returns the array, so the caller decides when it is
	 * written.
	 *
	 * @param array<string, mixed> $settings Stored `ffc_settings`.
	 * @return array<string, mixed>|null The migrated settings, or null when
	 *                                   there is nothing to do.
	 */
	public static function migrate( array $settings ): ?array {
		$done = $settings[ self::MIGRATED_KEY ] ?? 0;
		if ( is_numeric( $done ) && (int) $done >= self::VERSION ) {
			return null;
		}

		$log_was_on = ! empty( $settings['enable_activity_log'] );

		foreach ( self::ITEMS as $item => $definition ) {
			$key = self::setting_key( $item );
			if ( array_key_exists( $key, $settings ) || ! isset( $definition['legacy'] ) ) {
				continue;
			}
			$legacy = 'activity_log_cat_' . $definition['legacy'];
			$on     = array_key_exists( $legacy, $settings ) ? ! empty( $settings[ $legacy ] ) : $log_was_on;
			// Only a log that was on recorded anything, whatever a category said.
			$settings[ $key ] = ( $on && $log_was_on ) ? 1 : 0;
		}

		foreach ( array_keys( $settings ) as $key ) {
			if ( 0 === strpos( (string) $key, 'activity_log_cat_' ) ) {
				unset( $settings[ $key ] );
			}
		}

		$settings['enable_activity_log'] = 1;
		$settings[ self::MIGRATED_KEY ]  = self::VERSION;

		return $settings;
	}

	/**
	 * Carry the categories onto the items once, in wp-admin, a cron run or
	 * WP-CLI (the same requests the capability migrations run in).
	 *
	 * @return void
	 */
	public static function maybe_migrate(): void {
		$stored   = get_option( 'ffc_settings', array() );
		$settings = is_array( $stored ) ? $stored : array();
		$migrated = self::migrate( $settings );
		if ( null !== $migrated ) {
			update_option( 'ffc_settings', $migrated );
		}
	}
}
