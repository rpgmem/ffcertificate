<?php
declare(strict_types=1);

/**
 * AdminUserColumns
 *
 * Adds custom columns to WordPress users list:
 * - Certificates count
 * - Appointments count
 * - Login as User action link
 *
 * @since 3.1.0
 * @version 4.2.0 - Added appointments column and separate user actions column
 * @version 3.3.0 - Added strict types and type hints
 * @version 3.2.0 - Migrated to namespace (Phase 2)
 */

namespace FreeFormCertificate\Admin;

if (!defined('ABSPATH')) exit;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

class AdminUserColumns {

    /**
     * Cached flag for appointments table existence
     *
     * @since 4.6.13
     * @var bool|null
     */
    private static ?bool $appointments_table_exists = null;

    /**
     * Cached dashboard URL for user actions column
     *
     * @since 4.6.13
     * @var string|null
     */
    private static ?string $dashboard_url_cache = null;

    /**
     * Initialize user columns
     */
    public static function init(): void {
        // Add custom columns to users list
        add_filter('manage_users_columns', array(__CLASS__, 'add_custom_columns'));
        add_filter('manage_users_custom_column', array(__CLASS__, 'render_custom_column'), 10, 3);

        // Enqueue styles for the column
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_styles'));
    }

    /**
     * Add custom columns to users table
     *
     * @param array $columns Existing columns
     * @return array Modified columns
     */
    public static function add_custom_columns( array $columns ): array {
        // Add after "Posts" column
        $new_columns = array();

        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;

            if ($key === 'posts') {
                $new_columns['ffc_certificates'] = __('Certificates', 'ffcertificate');
                $new_columns['ffc_appointments'] = __('Appointments', 'ffcertificate');
                $new_columns['ffc_user_actions'] = __('User Actions', 'ffcertificate');
            }
        }

        return $new_columns;
    }

    /**
     * Render content for custom columns
     *
     * @param string $output Custom column output
     * @param string $column_name Column name
     * @param int $user_id User ID
     * @return string Column HTML
     */
    public static function render_custom_column( string $output, string $column_name, int $user_id ): string {
        switch ($column_name) {
            case 'ffc_certificates':
                return self::render_certificates_count($user_id);

            case 'ffc_appointments':
                return self::render_appointments_count($user_id);

            case 'ffc_user_actions':
                return self::render_user_actions($user_id);

            default:
                return $output;
        }
    }

    /**
     * Render certificates count
     *
     * @param int $user_id User ID
     * @return string Column HTML
     */
    private static function render_certificates_count( int $user_id ): string {
        $count = self::get_user_certificate_count($user_id);

        if ($count === 0) {
            return '<span class="ffc-empty-value">—</span>';
        }

        return sprintf(
            '<strong>%d</strong> %s',
            $count,
            _n('certificate', 'certificates', $count, 'ffcertificate')
        );
    }

    /**
     * Render appointments count
     *
     * @param int $user_id User ID
     * @return string Column HTML
     */
    private static function render_appointments_count( int $user_id ): string {
        $count = self::get_user_appointment_count($user_id);

        if ($count === 0) {
            return '<span class="ffc-empty-value">—</span>';
        }

        return sprintf(
            '<strong>%d</strong> %s',
            $count,
            _n('appointment', 'appointments', $count, 'ffcertificate')
        );
    }

    /**
     * Render user actions (login as user link)
     *
     * @param int $user_id User ID
     * @return string Column HTML
     */
    private static function render_user_actions( int $user_id ): string {
        // Get dashboard URL from User Access Settings (cached per request)
        if ( self::$dashboard_url_cache === null ) {
            $user_access_settings = get_option('ffc_user_access_settings', array());
            self::$dashboard_url_cache = isset($user_access_settings['redirect_url']) && !empty($user_access_settings['redirect_url'])
                ? $user_access_settings['redirect_url']
                : home_url('/dashboard');
        }
        $dashboard_url = self::$dashboard_url_cache;

        // Create view-as link with nonce
        $view_as_url = add_query_arg(array(
            'ffc_view_as_user' => $user_id,
            'ffc_view_nonce' => wp_create_nonce('ffc_view_as_user_' . $user_id)
        ), $dashboard_url);

        return sprintf(
            '<a href="%s" class="ffc-view-as-user button button-small" target="_blank" title="%s">%s</a>',
            esc_url($view_as_url),
            esc_attr__('View dashboard as this user', 'ffcertificate'),
            __('Login as User', 'ffcertificate')
        );
    }

    /**
     * Get certificate count for user
     *
     * @param int $user_id User ID
     * @return int Certificate count
     */
    private static function get_user_certificate_count( int $user_id ): int {
        global $wpdb;
        $table = \FreeFormCertificate\Core\Utils::get_submissions_table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND status != 'trash'",
            $user_id
        ));

        return (int) $count;
    }

    /**
     * Get appointment count for user
     *
     * @param int $user_id User ID
     * @return int Appointment count
     */
    private static function get_user_appointment_count( int $user_id ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'ffc_self_scheduling_appointments';

        // Check if table exists (cached per request to avoid N+1 SHOW TABLES queries)
        if ( self::$appointments_table_exists === null ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            self::$appointments_table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) == $table;
        }
        if ( ! self::$appointments_table_exists ) {
            return 0;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM %i WHERE user_id = %d AND status != 'cancelled'",
            $table,
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
        $s = \FreeFormCertificate\Core\Utils::asset_suffix();
        wp_enqueue_style( 'ffc-admin', FFC_PLUGIN_URL . "assets/css/ffc-admin{$s}.css", array(), FFC_VERSION );
    }
}
