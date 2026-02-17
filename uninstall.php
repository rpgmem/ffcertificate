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
// 1. Drop all plugin database tables
//    (order: child tables first to avoid FK issues)
// ──────────────────────────────────────
$ffcertificate_tables = array(
    // Reregistration (children first)
    $wpdb->prefix . 'ffc_reregistration_submissions',
    $wpdb->prefix . 'ffc_reregistrations',
    // Custom fields (depends on audiences)
    $wpdb->prefix . 'ffc_custom_fields',
    // Audience (children first)
    $wpdb->prefix . 'ffc_audience_booking_users',
    $wpdb->prefix . 'ffc_audience_booking_audiences',
    $wpdb->prefix . 'ffc_audience_bookings',
    $wpdb->prefix . 'ffc_audience_members',
    $wpdb->prefix . 'ffc_audiences',
    $wpdb->prefix . 'ffc_audience_holidays',
    $wpdb->prefix . 'ffc_audience_environments',
    $wpdb->prefix . 'ffc_audience_schedule_permissions',
    $wpdb->prefix . 'ffc_audience_schedules',
    // Self-scheduling
    $wpdb->prefix . 'ffc_self_scheduling_blocked_dates',
    $wpdb->prefix . 'ffc_self_scheduling_appointments',
    $wpdb->prefix . 'ffc_self_scheduling_calendars',
    // Rate limiting
    $wpdb->prefix . 'ffc_rate_limit_logs',
    $wpdb->prefix . 'ffc_rate_limits',
    // User profiles
    $wpdb->prefix . 'ffc_user_profiles',
    // Core
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
);

foreach ( $ffcertificate_options as $ffcertificate_option ) {
    delete_option( $ffcertificate_option );
}

// ──────────────────────────────────────
// 3. Delete transients
// ──────────────────────────────────────
delete_transient( 'ffc_activity_stats_7' );
delete_transient( 'ffc_activity_stats_30' );
delete_transient( 'ffc_activity_stats_90' );

// ──────────────────────────────────────
// 4. Clear scheduled cron hooks
// ──────────────────────────────────────
wp_clear_scheduled_hook( 'ffcertificate_daily_cleanup_hook' );
wp_clear_scheduled_hook( 'ffcertificate_process_submission_hook' );
wp_clear_scheduled_hook( 'ffcertificate_warm_cache_hook' );
wp_clear_scheduled_hook( 'ffcertificate_reregistration_expire_hook' );

// Clear legacy cron hooks from pre-4.6.15 versions
wp_clear_scheduled_hook( 'ffc_daily_cleanup_hook' );
wp_clear_scheduled_hook( 'ffc_process_submission_hook' );
wp_clear_scheduled_hook( 'ffc_warm_cache_hook' );

// ──────────────────────────────────────
// 5. Delete all ffc_form custom posts
// ──────────────────────────────────────
$ffcertificate_forms = get_posts( array(
    'post_type'      => 'ffc_form',
    'posts_per_page' => -1,
    'post_status'    => 'any',
    'fields'         => 'ids',
) );

if ( ! empty( $ffcertificate_forms ) ) {
    foreach ( $ffcertificate_forms as $ffcertificate_form_id ) {
        wp_delete_post( $ffcertificate_form_id, true );
    }
}

// ──────────────────────────────────────
// 6. Remove ffc_user role
// ──────────────────────────────────────
remove_role( 'ffc_user' );

// ──────────────────────────────────────
// 7. Clean up user meta
// ──────────────────────────────────────
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key
$wpdb->delete( $wpdb->usermeta, array( 'meta_key' => 'ffc_registration_date' ) );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key
$wpdb->delete( $wpdb->usermeta, array( 'meta_key' => 'ffc_custom_fields_data' ) );

// Remove FFC-specific capabilities from all users
$ffcertificate_caps = array(
    'view_own_certificates',
    'download_own_certificates',
    'view_certificate_history',
    'ffc_book_appointments',
    'ffc_view_self_scheduling',
    'ffc_cancel_own_appointments',
    'ffc_view_audience_bookings',
    'ffc_scheduling_bypass',
    'ffc_manage_reregistration',
    'ffc_reregistration',
    'ffc_certificate_update',
);

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$ffcertificate_user_ids = $wpdb->get_col( $wpdb->prepare(
    "SELECT user_id FROM %i WHERE meta_key = %s AND meta_value LIKE %s",
    $wpdb->usermeta,
    $wpdb->prefix . 'capabilities',
    '%ffc_%'
) );

foreach ( $ffcertificate_user_ids as $ffcertificate_uid ) {
    $ffcertificate_user = new WP_User( (int) $ffcertificate_uid );
    foreach ( $ffcertificate_caps as $ffcertificate_cap ) {
        $ffcertificate_user->remove_cap( $ffcertificate_cap );
    }
}
