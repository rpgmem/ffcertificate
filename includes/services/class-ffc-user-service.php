<?php
declare(strict_types=1);

/**
 * UserService
 *
 * Centralized service for user data retrieval and operations.
 * Single point of truth used by REST controller, PrivacyHandler, and UserCleanup.
 *
 * @since 4.9.7
 * @package FreeFormCertificate\Services
 */

namespace FreeFormCertificate\Services;

if (!defined('ABSPATH')) exit;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

class UserService {

    use \FreeFormCertificate\Core\DatabaseHelperTrait;

    /**
     * Get full user profile (WP data + FFC profile + capabilities)
     *
     * @param int $user_id WordPress user ID
     * @return array|null Profile data or null if user not found
     */
    public static function get_full_profile(int $user_id): ?array {
        $user = get_userdata($user_id);
        if (!$user) {
            return null;
        }

        $profile = array(
            'user_id' => $user_id,
            'display_name' => $user->display_name,
            'email' => $user->user_email,
            'member_since' => $user->user_registered,
            'roles' => $user->roles,
        );

        // Merge FFC profile data
        if (class_exists('\FreeFormCertificate\UserDashboard\UserManager')) {
            $ffc_profile = \FreeFormCertificate\UserDashboard\UserManager::get_profile($user_id);
            $profile['phone'] = $ffc_profile['phone'] ?? '';
            $profile['department'] = $ffc_profile['department'] ?? '';
            $profile['organization'] = $ffc_profile['organization'] ?? '';
            $profile['notes'] = $ffc_profile['notes'] ?? '';
        }

        // Capabilities
        $profile['capabilities'] = self::get_user_capabilities($user_id);

        return $profile;
    }

    /**
     * Get user's FFC capabilities with their grant status
     *
     * @param int $user_id WordPress user ID
     * @return array Associative array of capability => bool
     */
    public static function get_user_capabilities(int $user_id): array {
        if (!class_exists('\FreeFormCertificate\UserDashboard\UserManager')) {
            return array();
        }

        $all_caps = \FreeFormCertificate\UserDashboard\UserManager::get_all_capabilities();
        $result = array();

        foreach ($all_caps as $cap) {
            $result[$cap] = user_can($user_id, $cap);
        }

        return $result;
    }

    /**
     * Get user statistics (certificate count, appointment count, etc.)
     *
     * @param int $user_id WordPress user ID
     * @return array Statistics
     */
    public static function get_user_statistics(int $user_id): array {
        global $wpdb;

        $stats = array(
            'certificates' => 0,
            'appointments' => 0,
            'audience_groups' => 0,
        );

        // Certificate count
        if (class_exists('\FreeFormCertificate\Core\Utils')) {
            $table = \FreeFormCertificate\Core\Utils::get_submissions_table();
            $stats['certificates'] = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND status != 'trash'",
                $user_id
            ));
        }

        // Appointment count
        $appointments_table = $wpdb->prefix . 'ffc_self_scheduling_appointments';
        if (self::table_exists($appointments_table)) {
            $stats['appointments'] = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM %i WHERE user_id = %d AND status != 'cancelled'",
                $appointments_table,
                $user_id
            ));
        }

        // Audience group count
        $members_table = $wpdb->prefix . 'ffc_audience_members';
        if (self::table_exists($members_table)) {
            $stats['audience_groups'] = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM %i WHERE user_id = %d",
                $members_table,
                $user_id
            ));
        }

        return $stats;
    }

    /**
     * Export all personal data for a user (used by PrivacyHandler)
     *
     * Returns structured data suitable for WordPress Export Personal Data tool.
     *
     * @param int $user_id WordPress user ID
     * @return array Grouped personal data
     */
    public static function export_personal_data(int $user_id): array {
        $data = array();

        // Profile
        $profile = self::get_full_profile($user_id);
        if ($profile) {
            $data['profile'] = $profile;
        }

        // Statistics
        $data['statistics'] = self::get_user_statistics($user_id);

        return $data;
    }

    /**
     * Check if a user has any FFC data
     *
     * Useful for determining if a user needs FFC cleanup on deletion.
     *
     * @param int $user_id WordPress user ID
     * @return bool
     */
    public static function user_has_ffc_data(int $user_id): bool {
        $stats = self::get_user_statistics($user_id);
        return ($stats['certificates'] + $stats['appointments'] + $stats['audience_groups']) > 0;
    }
}
