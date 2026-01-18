<?php
/**
 * FFC_Dashboard_Shortcode
 *
 * Renders the user dashboard via [user_dashboard_personal] shortcode
 *
 * @since 3.1.0
 */

if (!defined('ABSPATH')) exit;

class FFC_Dashboard_Shortcode {

    /**
     * Register shortcode
     */
    public static function init() {
        add_shortcode('user_dashboard_personal', array(__CLASS__, 'render'));
    }

    /**
     * Render dashboard
     *
     * @param array $atts Shortcode attributes
     * @return string HTML output
     */
    public static function render($atts = array()) {
        // Check if user is logged in
        if (!is_user_logged_in()) {
            return self::render_login_required();
        }

        // Check for admin view-as mode
        $view_as_user_id = self::get_view_as_user_id();
        $is_admin_viewing = $view_as_user_id && $view_as_user_id !== get_current_user_id();

        // Enqueue assets
        self::enqueue_assets($view_as_user_id);

        // Get current tab
        $current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'certificates';

        // Start output buffering
        ob_start();

        ?>
        <div class="ffc-user-dashboard" id="ffc-user-dashboard">

            <?php
            if ($is_admin_viewing) {
                echo self::render_admin_viewing_banner($view_as_user_id);
            }
            echo self::render_redirect_message();
            ?>

            <nav class="ffc-dashboard-tabs">
                <button class="ffc-tab <?php echo $current_tab === 'certificates' ? 'active' : ''; ?>"
                        data-tab="certificates">
                    📜 <?php esc_html_e('My Certificates', 'ffc'); ?>
                </button>
                <button class="ffc-tab <?php echo $current_tab === 'profile' ? 'active' : ''; ?>"
                        data-tab="profile">
                    👤 <?php esc_html_e('My Profile', 'ffc'); ?>
                </button>
            </nav>

            <div class="ffc-tab-content <?php echo $current_tab === 'certificates' ? 'active' : ''; ?>"
                 id="tab-certificates">
                <div class="ffc-loading">
                    <?php esc_html_e('Loading certificates...', 'ffc'); ?>
                </div>
            </div>

            <div class="ffc-tab-content <?php echo $current_tab === 'profile' ? 'active' : ''; ?>"
                 id="tab-profile">
                <div class="ffc-loading">
                    <?php esc_html_e('Loading profile...', 'ffc'); ?>
                </div>
            </div>
        </div>
        <?php

        return ob_get_clean();
    }

    /**
     * Get user ID for view-as mode
     *
     * @return int|false User ID if valid view-as mode, false otherwise
     */
    private static function get_view_as_user_id() {
        // Check if admin is trying to view as another user
        if (!isset($_GET['ffc_view_as_user']) || !isset($_GET['ffc_view_nonce'])) {
            return false;
        }

        // Only admins can use view-as mode
        if (!current_user_can('manage_options')) {
            return false;
        }

        $target_user_id = absint($_GET['ffc_view_as_user']);
        $nonce = sanitize_text_field($_GET['ffc_view_nonce']);

        // Verify nonce
        if (!wp_verify_nonce($nonce, 'ffc_view_as_user_' . $target_user_id)) {
            return false;
        }

        // Verify user exists
        $user = get_user_by('id', $target_user_id);
        if (!$user) {
            return false;
        }

        return $target_user_id;
    }

    /**
     * Render admin viewing banner
     *
     * @param int $user_id User ID being viewed
     * @return string HTML output
     */
    private static function render_admin_viewing_banner($user_id) {
        $user = get_user_by('id', $user_id);
        $admin = wp_get_current_user();

        // Get dashboard URL without view-as parameters
        $dashboard_page_id = get_option('ffc_dashboard_page_id');
        $exit_url = $dashboard_page_id ? get_permalink($dashboard_page_id) : home_url('/dashboard');

        ob_start();
        ?>
        <div class="ffc-dashboard-notice ffc-notice-admin-viewing">
            <div style="display: flex; align-items: center; justify-content: space-between;">
                <div>
                    <strong>🔍 <?php esc_html_e('Admin View Mode', 'ffc'); ?></strong>
                    <p style="margin: 5px 0 0 0;">
                        <?php
                        printf(
                            /* translators: 1: Admin name, 2: User name */
                            esc_html__('You (%1$s) are viewing the dashboard as: %2$s', 'ffc'),
                            '<strong>' . esc_html($admin->display_name) . '</strong>',
                            '<strong>' . esc_html($user->display_name) . ' (' . esc_html($user->user_email) . ')</strong>'
                        );
                        ?>
                    </p>
                </div>
                <div>
                    <a href="<?php echo esc_url($exit_url); ?>" class="button button-primary">
                        <?php esc_html_e('Exit View Mode', 'ffc'); ?>
                    </a>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render login required message
     *
     * @return string HTML output
     */
    private static function render_login_required() {
        ob_start();
        ?>
        <div class="ffc-dashboard-notice ffc-notice-warning">
            <p><?php esc_html_e('You must be logged in to view your dashboard.', 'ffc'); ?></p>
            <p>
                <a href="<?php echo esc_url(wp_login_url(get_permalink())); ?>" class="button">
                    <?php esc_html_e('Login', 'ffc'); ?>
                </a>
            </p>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render redirect message (from wp-admin block)
     *
     * @return string HTML output
     */
    private static function render_redirect_message() {
        if (!isset($_GET['ffc_redirect']) || $_GET['ffc_redirect'] !== 'access_denied') {
            return '';
        }

        $settings = get_option('ffc_user_access_settings', array());
        $message = $settings['redirect_message'] ?? __('You were redirected from the admin panel. Use this dashboard to access your certificates.', 'ffc');

        ob_start();
        ?>
        <div class="ffc-dashboard-notice ffc-notice-info">
            <p><?php echo esc_html($message); ?></p>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Enqueue dashboard assets
     *
     * @param int|false $view_as_user_id User ID in view-as mode
     */
    private static function enqueue_assets($view_as_user_id = false) {
        // Enqueue CSS
        wp_enqueue_style(
            'ffc-dashboard',
            FFC_PLUGIN_URL . 'assets/css/user-dashboard.css',
            array(),
            FFC_VERSION
        );

        // Enqueue JavaScript
        wp_enqueue_script(
            'ffc-dashboard',
            FFC_PLUGIN_URL . 'assets/js/user-dashboard.js',
            array('jquery'),
            FFC_VERSION,
            true
        );

        // Localize script
        wp_localize_script('ffc-dashboard', 'ffcDashboard', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'restUrl' => rest_url('ffc/v1/'),
            'nonce' => wp_create_nonce('wp_rest'),
            'viewAsUserId' => $view_as_user_id ? $view_as_user_id : false,
            'isAdminViewing' => $view_as_user_id && $view_as_user_id !== get_current_user_id(),
            'strings' => array(
                'loading' => __('Loading...', 'ffc'),
                'error' => __('Error loading data', 'ffc'),
                'noCertificates' => __('No certificates found', 'ffc'),
                'downloadPdf' => __('View PDF', 'ffc'),
                'yes' => __('Yes', 'ffc'),
                'no' => __('No', 'ffc'),
            ),
        ));
    }
}

// Initialize shortcode
FFC_Dashboard_Shortcode::init();
