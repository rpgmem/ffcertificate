<?php
declare(strict_types=1);

/**
 * UserCleanup
 *
 * Handles user deletion (anonymization) and email change events.
 * Integrates with WordPress hooks to keep FFC data consistent.
 *
 * @since 4.9.4
 * @package FreeFormCertificate\UserDashboard
 */

namespace FreeFormCertificate\UserDashboard;

if (!defined('ABSPATH')) exit;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

class UserCleanup {

    /**
     * Initialize hooks
     *
     * @return void
     */
    public static function init(): void {
        add_action('deleted_user', [__CLASS__, 'anonymize_user_data']);
        add_action('profile_update', [__CLASS__, 'handle_email_change'], 10, 2);
    }

    /**
     * Anonymize all FFC data when a WordPress user is deleted
     *
     * Strategy:
     * - Submissions/Appointments/Activity log: SET user_id = NULL (preserve audit trail)
     * - Audience members/booking users/permissions/profiles: DELETE (no audit value)
     *
     * @param int $user_id Deleted WordPress user ID
     * @return void
     */
    public static function anonymize_user_data(int $user_id): void {
        global $wpdb;

        $anonymized = array();

        // 1. Submissions: SET user_id = NULL (preserve certificate records)
        $submissions_table = $wpdb->prefix . 'ffc_submissions';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
        $rows = $wpdb->query($wpdb->prepare(
            "UPDATE {$submissions_table} SET user_id = NULL WHERE user_id = %d",
            $user_id
        ));
        if ($rows > 0) {
            $anonymized['submissions'] = $rows;
        }

        // 2. Self-scheduling appointments: SET user_id = NULL
        $appointments_table = $wpdb->prefix . 'ffc_self_scheduling_appointments';
        if (self::table_exists($appointments_table)) {
            $rows = $wpdb->query($wpdb->prepare(
                "UPDATE {$appointments_table} SET user_id = NULL WHERE user_id = %d",
                $user_id
            ));
            if ($rows > 0) {
                $anonymized['appointments'] = $rows;
            }
        }

        // 3. Activity log: SET user_id = NULL (preserve audit trail)
        $activity_table = $wpdb->prefix . 'ffc_activity_log';
        if (self::table_exists($activity_table)) {
            $rows = $wpdb->query($wpdb->prepare(
                "UPDATE {$activity_table} SET user_id = NULL WHERE user_id = %d",
                $user_id
            ));
            if ($rows > 0) {
                $anonymized['activity_log'] = $rows;
            }
        }

        // 4. Audience members: DELETE
        $members_table = $wpdb->prefix . 'ffc_audience_members';
        if (self::table_exists($members_table)) {
            $rows = $wpdb->delete($members_table, array('user_id' => $user_id), array('%d'));
            if ($rows > 0) {
                $anonymized['audience_members'] = $rows;
            }
        }

        // 5. Audience booking users: DELETE
        $booking_users_table = $wpdb->prefix . 'ffc_audience_booking_users';
        if (self::table_exists($booking_users_table)) {
            $rows = $wpdb->delete($booking_users_table, array('user_id' => $user_id), array('%d'));
            if ($rows > 0) {
                $anonymized['booking_users'] = $rows;
            }
        }

        // 6. Audience schedule permissions: DELETE
        $permissions_table = $wpdb->prefix . 'ffc_audience_schedule_permissions';
        if (self::table_exists($permissions_table)) {
            $rows = $wpdb->delete($permissions_table, array('user_id' => $user_id), array('%d'));
            if ($rows > 0) {
                $anonymized['schedule_permissions'] = $rows;
            }
        }

        // 7. User profiles: DELETE
        $profiles_table = $wpdb->prefix . 'ffc_user_profiles';
        if (self::table_exists($profiles_table)) {
            $wpdb->delete($profiles_table, array('user_id' => $user_id), array('%d'));
            $anonymized['profile'] = 1;
        }

        // Log the anonymization
        if (class_exists('\FreeFormCertificate\Core\ActivityLog')) {
            \FreeFormCertificate\Core\ActivityLog::log(
                'user_data_anonymized',
                \FreeFormCertificate\Core\ActivityLog::LEVEL_WARNING,
                array(
                    'anonymized_user_id' => $user_id,
                    'tables_affected' => $anonymized,
                )
            );
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
     * @param int $user_id Updated user ID
     * @param \WP_User $old_user_data User data before the update
     * @return void
     */
    public static function handle_email_change(int $user_id, \WP_User $old_user_data): void {
        $new_user = get_userdata($user_id);
        if (!$new_user) {
            return;
        }

        $old_email = $old_user_data->user_email;
        $new_email = $new_user->user_email;

        if ($old_email === $new_email) {
            return;
        }

        global $wpdb;
        $submissions_table = $wpdb->prefix . 'ffc_submissions';

        // Reindex email_hash for submissions linked to this user_id
        $new_email_hash = hash('sha256', strtolower(trim($new_email)));

        $wpdb->query($wpdb->prepare(
            "UPDATE {$submissions_table} SET email_hash = %s WHERE user_id = %d",
            $new_email_hash,
            $user_id
        ));

        // Update profile timestamp
        $profiles_table = $wpdb->prefix . 'ffc_user_profiles';
        if (self::table_exists($profiles_table)) {
            $wpdb->update(
                $profiles_table,
                array('updated_at' => current_time('mysql')),
                array('user_id' => $user_id),
                array('%s'),
                array('%d')
            );
        }

        // Log the change
        if (class_exists('\FreeFormCertificate\Core\ActivityLog')) {
            $old_masked = class_exists('\FreeFormCertificate\Core\Utils')
                ? \FreeFormCertificate\Core\Utils::mask_email($old_email)
                : '***';
            $new_masked = class_exists('\FreeFormCertificate\Core\Utils')
                ? \FreeFormCertificate\Core\Utils::mask_email($new_email)
                : '***';

            \FreeFormCertificate\Core\ActivityLog::log(
                'user_email_changed',
                \FreeFormCertificate\Core\ActivityLog::LEVEL_INFO,
                array(
                    'user_id' => $user_id,
                    'old_email_masked' => $old_masked,
                    'new_email_masked' => $new_masked,
                )
            );
        }
    }

    /**
     * Check if a database table exists
     *
     * @param string $table_name Full table name with prefix
     * @return bool
     */
    private static function table_exists(string $table_name): bool {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name));
    }
}
