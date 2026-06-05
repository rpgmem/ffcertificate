<?php
/**
 * Uninstall handler for FFCertificate plugin.
 *
 * Removes all plugin data when the plugin is deleted via the WordPress admin.
 * This file is called automatically by WordPress — do NOT call it directly.
 *
 * @since 4.6.11
 * @package FreeFormCertificate
 */

// Abort if not called by WordPress uninstall.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// ──────────────────────────────────────
// 0. Danger Zone opt-in gate
// ──────────────────────────────────────
// The heavy cleanup below (tables, options, CPT posts, roles, caps,
// user meta) only runs when the admin opted in via Settings →
// Advanced → Danger Zone → "Delete all plugin data on uninstall".
// Default OFF, matching the WooCommerce / EDD / Yoast convention so
// deleting the plugin never wipes data unintentionally.
//
// Cron hooks are cleared regardless — their callbacks reference plugin
// code that's about to be gone, so leaving them registered would
// produce "no callback" warnings until WP self-heals on the next visit.
//
// SettingsReader isn't available here — uninstall.php runs in a
// stripped-down context that doesn't load the plugin's autoloader —
// so read the option directly from ffc_settings.
$ffcertificate_settings = get_option( 'ffc_settings', array() );
$ffcertificate_purge    = is_array( $ffcertificate_settings )
	&& '1' === (string) ( $ffcertificate_settings['delete_data_on_uninstall'] ?? '0' );

if ( ! $ffcertificate_purge ) {
	// Cron-only cleanup: leave data + structure untouched but clear
	// scheduled events that point at code about to be removed.
	wp_clear_scheduled_hook( 'ffcertificate_daily_cleanup_hook' );
	wp_clear_scheduled_hook( 'ffcertificate_process_submission_hook' );
	wp_clear_scheduled_hook( 'ffcertificate_warm_cache_hook' );
	wp_clear_scheduled_hook( 'ffcertificate_reregistration_expire_hook' );
	wp_clear_scheduled_hook( 'ffc_daily_cleanup_hook' );
	wp_clear_scheduled_hook( 'ffc_process_submission_hook' );
	wp_clear_scheduled_hook( 'ffc_warm_cache_hook' );
	return;
}

// ──────────────────────────────────────
// 1. Drop all plugin database tables
// (order: child tables first to avoid FK issues)
// ──────────────────────────────────────
$ffcertificate_tables = array(
	// Recruitment (children first).
	$wpdb->prefix . 'ffc_recruitment_call',
	$wpdb->prefix . 'ffc_recruitment_classification',
	$wpdb->prefix . 'ffc_recruitment_notice_adjutancy',
	$wpdb->prefix . 'ffc_recruitment_candidate',
	$wpdb->prefix . 'ffc_recruitment_notice',
	$wpdb->prefix . 'ffc_recruitment_adjutancy',
	$wpdb->prefix . 'ffc_recruitment_reason',
	// Reregistration (children first).
	$wpdb->prefix . 'ffc_reregistration_submissions',
	$wpdb->prefix . 'ffc_reregistration_audiences',
	$wpdb->prefix . 'ffc_reregistrations',
	// Custom fields (depends on audiences).
	$wpdb->prefix . 'ffc_custom_fields',
	// Audience (children first).
	$wpdb->prefix . 'ffc_audience_booking_users',
	$wpdb->prefix . 'ffc_audience_booking_audiences',
	$wpdb->prefix . 'ffc_audience_bookings',
	$wpdb->prefix . 'ffc_audience_members',
	$wpdb->prefix . 'ffc_audiences',
	$wpdb->prefix . 'ffc_audience_holidays',
	$wpdb->prefix . 'ffc_audience_environments',
	$wpdb->prefix . 'ffc_audience_schedule_permissions',
	$wpdb->prefix . 'ffc_audience_schedules',
	// Self-scheduling.
	$wpdb->prefix . 'ffc_self_scheduling_blocked_dates',
	$wpdb->prefix . 'ffc_self_scheduling_appointments',
	$wpdb->prefix . 'ffc_self_scheduling_calendars',
	// Rate limiting + device fingerprinting.
	$wpdb->prefix . 'ffc_rate_limit_logs',
	$wpdb->prefix . 'ffc_rate_limits',
	$wpdb->prefix . 'ffc_device_signals',
	// URL Shortener.
	$wpdb->prefix . 'ffc_short_urls',
	// User profiles.
	$wpdb->prefix . 'ffc_user_profiles',
	// Core.
	$wpdb->prefix . 'ffc_activity_log',
	$wpdb->prefix . 'ffc_submissions',
);

foreach ( $ffcertificate_tables as $ffcertificate_table ) {
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $ffcertificate_table ) );
}

// ──────────────────────────────────────
// 2. Delete all plugin options
// ──────────────────────────────────────
$ffcertificate_options = array(
	'ffc_settings',
	'ffc_db_version',
	'ffc_verification_page_id',
	'ffc_dashboard_page_id',
	'ffc_geolocation_settings',
	'ffc_rate_limit_settings',
	'ffc_rate_limit_db_version',
	'ffc_user_access_settings',
	'ffc_global_holidays',
	'ffc_cleanup_days',
	'ffc_migration_data_cleanup_completed',
	'ffc_migration_name_normalization_errors',
	'ffc_migration_name_normalization_changes',
	'ffc_migration_name_normalization_last_run',
	'ffc_encryption_migration_completed_date',
	'ffc_migration_user_link_errors',
	'ffc_migration_user_capabilities_errors',
	'ffc_migration_user_capabilities_changes',
	'ffc_migration_user_capabilities_last_run',
	'ffc_columns_dropped_date',
	'ffc_migration_user_profiles_errors',
	'ffc_migration_user_profiles_last_run',
	// Schema-version markers (audited gap — were never on the list).
	'ffc_activity_log_db_version',
	'ffc_perf_indexes_db_version',
	'ffc_submissions_db_version',
	// Per-feature migration completion markers (audited gap).
	'ffc_sibling_instants_unix_migrated',
	'ffc_submission_date_unix_migrated',
	'ffc_submitted_at_unix_migrated',
	// Audience module options (audited gap — were never on the list).
	'ffc_aud_multiple_audiences_color',
	'ffc_aud_private_display_mode',
	'ffc_aud_scheduling_message',
	'ffc_aud_visibility_message',
	// Self-scheduling module options (audited gap).
	'ffc_ss_business_hours_booking_message',
	'ffc_ss_business_hours_viewing_message',
	'ffc_ss_private_display_mode',
	'ffc_ss_scheduling_message',
	'ffc_ss_visibility_message',
	// Recruitment module (v6.0.0).
	'ffc_recruitment_settings',
	'ffc_recruitment_schema_version',
	'ffc_recruitment_public_cache_version',
);

foreach ( $ffcertificate_options as $ffcertificate_option ) {
	delete_option( $ffcertificate_option );
}

// ──────────────────────────────────────
// 3. Delete transients
// ──────────────────────────────────────
// The activity-log stats triple is explicitly named to keep the obvious
// case in code; the wildcard sweep below catches every other ffc_* /
// _ffc_* transient (per-user error transients, per-form caches, etc.)
// without having to enumerate them.
delete_transient( 'ffc_activity_stats_7' );
delete_transient( 'ffc_activity_stats_30' );
delete_transient( 'ffc_activity_stats_90' );

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Uninstall-time wildcard delete; bounded by table scope, no user input.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_ffc\\_%' OR option_name LIKE '\\_transient\\_timeout\\_ffc\\_%' OR option_name LIKE '\\_transient\\_\\_ffc\\_%' OR option_name LIKE '\\_transient\\_timeout\\_\\_ffc\\_%'" );

// ──────────────────────────────────────
// 4. Clear scheduled cron hooks
// ──────────────────────────────────────
wp_clear_scheduled_hook( 'ffcertificate_daily_cleanup_hook' );
wp_clear_scheduled_hook( 'ffcertificate_process_submission_hook' );
wp_clear_scheduled_hook( 'ffcertificate_warm_cache_hook' );
wp_clear_scheduled_hook( 'ffcertificate_reregistration_expire_hook' );

// Clear legacy cron hooks from pre-4.6.15 versions.
wp_clear_scheduled_hook( 'ffc_daily_cleanup_hook' );
wp_clear_scheduled_hook( 'ffc_process_submission_hook' );
wp_clear_scheduled_hook( 'ffc_warm_cache_hook' );

// ──────────────────────────────────────
// 5. Delete all FFC custom posts (CPTs)
// ──────────────────────────────────────
// Both CPTs registered by the plugin — `ffc_form` (certificate forms)
// and `ffc_self_scheduling` (self-scheduling calendars). wp_delete_post
// with force=true also clears each post's post_meta + revisions.
foreach ( array( 'ffc_form', 'ffc_self_scheduling' ) as $ffcertificate_cpt ) {
	$ffcertificate_post_ids = get_posts(
		array(
			'post_type'      => $ffcertificate_cpt,
			'posts_per_page' => -1,
			'post_status'    => 'any',
			'fields'         => 'ids',
		)
	);

	if ( ! empty( $ffcertificate_post_ids ) ) {
		foreach ( $ffcertificate_post_ids as $ffcertificate_post_id ) {
			wp_delete_post( $ffcertificate_post_id, true );
		}
	}
}

// ──────────────────────────────────────
// 6. Remove FFC roles
// ──────────────────────────────────────
remove_role( 'ffc_user' );
remove_role( 'ffc_recruitment_manager' );

// 6.2.0 module-manager + recruitment-tier roles. Listed inline rather than
// through `CapabilityManager::remove_module_roles()` because uninstall.php
// runs in a stripped-down context that doesn't load the plugin's autoloader.
foreach (
	array(
		'ffc_administrator',
		'ffc_certificate_manager',
		'ffc_self_scheduling_manager',
		'ffc_audience_manager',
		'ffc_reregistration_manager',
		'ffc_operator',
		'ffc_recruitment_auditor',
		'ffc_recruitment_operator',
		'ffc_recruitment_admin',
	) as $ffc_legacy_role
) {
	remove_role( $ffc_legacy_role );
}

// ──────────────────────────────────────
// 7. Clean up user meta
// ──────────────────────────────────────
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key
$wpdb->delete( $wpdb->usermeta, array( 'meta_key' => 'ffc_registration_date' ) );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key
$wpdb->delete( $wpdb->usermeta, array( 'meta_key' => 'ffc_custom_fields_data' ) );

// Remove FFC-specific capabilities from all users.
$ffcertificate_caps = array(
	// Legacy (pre-6.2.0) cap names — kept here so uninstalls of installs
	// that never ran the 6.2.0 rename migration still strip the old caps.
	'view_own_certificates',
	'download_own_certificates',
	'view_certificate_history',
	// 6.2.0 namespaced replacements.
	'ffc_view_own_certificates',
	'ffc_download_own_certificates',
	'ffc_view_certificate_history',

	'ffc_book_appointments',
	'ffc_view_self_scheduling',
	'ffc_cancel_own_appointments',
	'ffc_view_audience_bookings',
	'ffc_scheduling_bypass',
	'ffc_manage_reregistration',
	'ffc_manage_recruitment',

	// 6.2.0 module-management caps.
	'ffc_manage_certificates',
	'ffc_export_certificates',
	'ffc_manage_self_scheduling',
	'ffc_manage_audiences',
	'ffc_view_activity_log',
	'ffc_manage_user_custom_fields',
	'ffc_view_as_user',
	'ffc_manage_settings',

	// 6.2.0 per-domain recruitment caps.
	'ffc_view_recruitment',
	'ffc_import_recruitment_csv',
	'ffc_call_recruitment_candidates',
	'ffc_view_recruitment_pii',
	'ffc_manage_recruitment_settings',
	'ffc_manage_recruitment_reasons',

	// Reactivated submission-edit cap (6.2.0).
	'ffc_certificate_update',

	// Granular export tier (GAP G).
	'ffc_export_appointments',
	'ffc_export_reregistration',
	'ffc_export_audiences',

	// Removed 6.2.0 placeholder, kept here for cleanup on installs
	// that activated it before the placeholder was retired.
	'ffc_reregistration',
);

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$ffcertificate_user_ids = $wpdb->get_col(
	$wpdb->prepare(
		'SELECT user_id FROM %i WHERE meta_key = %s AND meta_value LIKE %s',
		$wpdb->usermeta,
		$wpdb->prefix . 'capabilities',
		'%ffc_%'
	)
);

foreach ( $ffcertificate_user_ids as $ffcertificate_uid ) {
	$ffcertificate_user = new WP_User( (int) $ffcertificate_uid );
	foreach ( $ffcertificate_caps as $ffcertificate_cap ) {
		$ffcertificate_user->remove_cap( $ffcertificate_cap );
	}
}
