<?php
declare(strict_types=1);

/**
 * AdminUserColumns
 *
 * Adds "View Certificates" link to WordPress users list
 *
 * @since 3.1.0
 * @version 3.3.0 - Added strict types and type hints
 * @version 3.2.0 - Migrated to namespace (Phase 2)
 */

namespace FreeFormCertificate\Admin;

if (!defined('ABSPATH')) exit;

class AdminUserColumns {

    /**
     * Initialize user columns
     */
    public static function init(): void {
        // Add custom column to users list
        add_filter('manage_users_columns', array(__CLASS__, 'add_certificates_column'));
        add_filter('manage_users_custom_column', array(__CLASS__, 'render_certificates_column'), 10, 3);

        // Enqueue styles for the column
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_styles'));
    }

    /**
     * Add "Certificates" column to users table
     *
     * @param array $columns Existing columns
     * @return array Modified columns
     */
    public static function add_certificates_column( array $columns ): array {
        // Add after "Posts" column
        $new_columns = array();

        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;

            if ($key === 'posts') {
                $new_columns['ffc_certificates'] = __('FFC Certificates', 'ffc');
            }
        }

        return $new_columns;
    }

    /**
     * Render content for certificates column
     *
     * @param string $output Custom column output
     * @param string $column_name Column name
     * @param int $user_id User ID
     * @return string Column HTML
     */
    public static function render_certificates_column( string $output, string $column_name, int $user_id ): string {
        if ($column_name !== 'ffc_certificates') {
            return $output;
        }

        // Get certificate count for user
        $count = self::get_user_certificate_count($user_id);

        if ($count === 0) {
            return '<span class="ffc-empty-value">—</span>';
        }

        // ✅ v3.1.1: Get dashboard URL from User Access Settings
        $user_access_settings = get_option('ffc_user_access_settings', array());
        $dashboard_url = isset($user_access_settings['redirect_url']) && !empty($user_access_settings['redirect_url'])
            ? $user_access_settings['redirect_url']
            : home_url('/dashboard'); // Fallback if not configured

        // Create view-as link with nonce
        $view_as_url = add_query_arg(array(
            'ffc_view_as_user' => $user_id,
            'ffc_view_nonce' => wp_create_nonce('ffc_view_as_user_' . $user_id)
        ), $dashboard_url);

        // Build output
        $output = sprintf(
            '<strong>%d</strong> %s<br>',
            $count,
            _n('certificate', 'certificates', $count, 'ffc')
        );

        $output .= sprintf(
            '<a href="%s" class="ffc-view-as-user" target="_blank" title="%s">%s</a>',
            esc_url($view_as_url),
            esc_attr__('View dashboard as this user', 'ffc'),
            __('View Dashboard', 'ffc')
        );

        return $output;
    }

    /**
     * Get certificate count for user
     *
     * @param int $user_id User ID
     * @return int Certificate count
     */
    private static function get_user_certificate_count( int $user_id ): int {
        global $wpdb;
        $table = \FFC_Utils::get_submissions_table();

        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND status != 'trash'",
            $user_id
        ));

        return (int) $count;
    }

    /**
     * Enqueue CSS for certificates column
     */
    public static function enqueue_styles( string $hook ): void {
        // Only load on users.php page
        if ($hook !== 'users.php') {
            return;
        }

        // ✅ v3.1.0: User columns styles consolidated into ffc-admin.css
        wp_enqueue_style( 'ffc-admin', FFC_PLUGIN_URL . 'assets/css/ffc-admin.css', array(), FFC_VERSION );
    }
}

// Initialize (via alias for backward compatibility)
AdminUserColumns::init();
