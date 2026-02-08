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
$tables = array(
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
    // Core
    $wpdb->prefix . 'ffc_activity_log',
    $wpdb->prefix . 'ffc_submissions',
);

foreach ( $tables as $table ) {
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $wpdb->query( "DROP TABLE IF EXISTS {$table}" );
}

// ──────────────────────────────────────
// 2. Delete all plugin options
// ──────────────────────────────────────
$options = array(
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
);

foreach ( $options as $option ) {
    delete_option( $option );
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

// Clear legacy cron hooks from pre-4.6.15 versions
wp_clear_scheduled_hook( 'ffc_daily_cleanup_hook' );
wp_clear_scheduled_hook( 'ffc_process_submission_hook' );
wp_clear_scheduled_hook( 'ffc_warm_cache_hook' );

// ──────────────────────────────────────
// 5. Delete all ffc_form custom posts
// ──────────────────────────────────────
$forms = get_posts( array(
    'post_type'      => 'ffc_form',
    'posts_per_page' => -1,
    'post_status'    => 'any',
    'fields'         => 'ids',
) );

if ( ! empty( $forms ) ) {
    foreach ( $forms as $form_id ) {
        wp_delete_post( $form_id, true );
    }
}

// ──────────────────────────────────────
// 6. Remove ffc_user role
// ──────────────────────────────────────
remove_role( 'ffc_user' );

// ──────────────────────────────────────
// 7. Clean up user meta
// ──────────────────────────────────────
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->delete( $wpdb->usermeta, array( 'meta_key' => 'ffc_registration_date' ) );

// Remove FFC-specific capabilities from all users
$ffc_caps = array(
    'view_own_certificates',
    'download_own_certificates',
    'view_certificate_history',
    'ffc_book_appointments',
    'ffc_view_self_scheduling',
    'ffc_cancel_own_appointments',
);

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$users_with_role = $wpdb->get_col(
    "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = '{$wpdb->prefix}capabilities' AND meta_value LIKE '%ffc_%'"
);

foreach ( $users_with_role as $user_id ) {
    $user = new WP_User( (int) $user_id );
    foreach ( $ffc_caps as $cap ) {
        $user->remove_cap( $cap );
    }
}
