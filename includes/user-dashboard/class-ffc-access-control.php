<?php
declare(strict_types=1);

/**
 * AccessControl
 *
 * Controls wp-admin access for FFC users
 *
 * @since 3.1.0
 * @version 3.3.0 - Added strict types and type hints
 * @version 3.2.0 - Migrated to namespace (Phase 2)
 */

namespace FreeFormCertificate\UserDashboard;

if (!defined('ABSPATH')) exit;

class AccessControl {

    /**
     * Initialize access control
     */
    public static function init(): void {
        add_action('admin_init', array(__CLASS__, 'block_wp_admin'));
        add_filter('show_admin_bar', array(__CLASS__, 'hide_admin_bar'));
    }

    /**
     * Block wp-admin access for configured roles
     *
     * Redirects users to configured URL when they try to access wp-admin
     */
    public static function block_wp_admin(): void {
        $settings = get_option('ffc_user_access_settings', array());

        // Check if blocking is enabled
        if (empty($settings['block_wp_admin'])) {
            return;
        }

        // Bypass for admins (if configured)
        if (!empty($settings['bypass_for_admins']) && current_user_can('manage_options')) {
            return;
        }

        // Check if user has blocked role
        $user = wp_get_current_user();
        $blocked_roles = isset($settings['blocked_roles']) ? $settings['blocked_roles'] : array('ffc_user');

        // Block only if ALL of the user's roles are in the blocked list.
        // If the user has at least one non-blocked role (e.g. editor + ffc_user),
        // they should retain wp-admin access.
        if (!empty($user->roles) && !array_diff($user->roles, $blocked_roles)) {
            // Get redirect URL
            $redirect_url = isset($settings['redirect_url']) ? $settings['redirect_url'] : home_url();

            // Add query parameter for notice
            $redirect_url = add_query_arg('ffc_redirect', 'access_denied', $redirect_url);

            // Redirect
            wp_safe_redirect($redirect_url);
            exit;
        }
    }

    /**
     * Hide admin bar for blocked users
     *
     * @param bool $show_admin_bar Whether to show admin bar
     * @return bool
     */
    public static function hide_admin_bar($show_admin_bar): bool {
        $settings = get_option('ffc_user_access_settings', array());

        // Check if hiding admin bar is enabled
        if (!empty($settings['allow_admin_bar'])) {
            return $show_admin_bar; // Allow admin bar
        }

        // Check if user has blocked role
        $user = wp_get_current_user();
        $blocked_roles = isset($settings['blocked_roles']) ? $settings['blocked_roles'] : array('ffc_user');

        // Hide admin bar only if ALL of the user's roles are blocked
        if (!empty($user->roles) && !array_diff($user->roles, $blocked_roles)) {
            return false;
        }

        return $show_admin_bar;
    }

    /**
     * Get default settings
     *
     * @return array Default settings
     */
    public static function get_default_settings(): array {
        return array(
            'block_wp_admin' => false,
            'blocked_roles' => array('ffc_user'),
            'redirect_url' => home_url('/dashboard'),
            'redirect_message' => __('You were redirected from the admin panel. Use this dashboard to access your certificates.', 'ffcertificate'),
            'allow_admin_bar' => false,
            'bypass_for_admins' => true,
        );
    }
}
