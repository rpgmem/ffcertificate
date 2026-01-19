<?php
/**
 * FFC_Admin_User_Columns
 *
 * Adds "View Certificates" link to WordPress users list
 *
 * @since 3.1.0
 */

if (!defined('ABSPATH')) exit;

class FFC_Admin_User_Columns {

    /**
     * Initialize user columns
     */
    public static function init() {
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
    public static function add_certificates_column($columns) {
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
    public static function render_certificates_column($output, $column_name, $user_id) {
        if ($column_name !== 'ffc_certificates') {
            return $output;
        }

        // Get certificate count for user
        $count = self::get_user_certificate_count($user_id);

        if ($count === 0) {
            return '<span class="ffc-empty-value">—</span>';
        }

        // Get dashboard page URL
        $dashboard_page_id = get_option('ffc_dashboard_page_id');
        $dashboard_url = $dashboard_page_id ? get_permalink($dashboard_page_id) : home_url('/dashboard');

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
    private static function get_user_certificate_count($user_id) {
        global $wpdb;
        $table = FFC_Utils::get_submissions_table();

        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND status != 'trash'",
            $user_id
        ));

        return (int) $count;
    }

    /**
     * Enqueue CSS for certificates column
     */
    public static function enqueue_styles($hook) {
        // Only load on users.php page
        if ($hook !== 'users.php') {
            return;
        }

        wp_enqueue_style( 'ffc-admin-user-columns', FFC_PLUGIN_URL . 'assets/css/ffc-admin-user-columns.css', array(), FFC_VERSION );
    }
}

// Initialize
FFC_Admin_User_Columns::init();
