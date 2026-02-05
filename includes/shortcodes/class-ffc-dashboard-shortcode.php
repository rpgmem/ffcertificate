<?php
declare(strict_types=1);

/**
 * DashboardShortcode
 *
 * Renders the user dashboard via [user_dashboard_personal] shortcode
 *
 * @since 3.1.0
 * @version 3.3.0 - Added strict types and type hints
 * @version 3.2.0 - Migrated to namespace (Phase 2)
 */

namespace FreeFormCertificate\Shortcodes;

if (!defined('ABSPATH')) exit;

class DashboardShortcode {

    /**
     * Register shortcode
     */
    public static function init(): void {
        add_shortcode('user_dashboard_personal', array(__CLASS__, 'render'));
    }

    /**
     * Render dashboard
     *
     * @param array $atts Shortcode attributes
     * @return string HTML output
     */
    public static function render(array $atts = array()): string {
        // Check if user is logged in
        if (!is_user_logged_in()) {
            return self::render_login_required();
        }

        // Check for admin view-as mode
        $view_as_user_id = self::get_view_as_user_id();
        $is_admin_viewing = $view_as_user_id && $view_as_user_id !== get_current_user_id();

        // Enqueue assets
        self::enqueue_assets($view_as_user_id);

        // Check user permissions
        $user_id = $view_as_user_id ?: get_current_user_id();
        $user = get_user_by('id', $user_id);

        // Check if user has FFC permissions (based on capabilities, not just role)
        $can_view_certificates = $user && (
            user_can($user, 'view_own_certificates') ||
            user_can($user, 'manage_options')
        );

        $can_view_appointments = $user && (
            user_can($user, 'ffc_view_self_scheduling') ||
            user_can($user, 'manage_options')
        );

        // Get current tab - default to first available tab
        $default_tab = $can_view_certificates ? 'certificates' : ($can_view_appointments ? 'appointments' : 'profile');
        $current_tab = isset($_GET['tab']) ? sanitize_text_field(wp_unslash($_GET['tab'])) : $default_tab; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Tab parameter for display only.

        // Start output buffering
        ob_start();

        ?>
        <div class="ffc-user-dashboard" id="ffc-user-dashboard">

            <?php
            if ($is_admin_viewing) {
                echo wp_kses_post( self::render_admin_viewing_banner($view_as_user_id) );
            }
            echo wp_kses_post( self::render_redirect_message() );
            ?>

            <nav class="ffc-dashboard-tabs">
                <?php if ($can_view_certificates) : ?>
                    <button class="ffc-tab <?php echo esc_attr( $current_tab === 'certificates' ? 'active' : '' ); ?>"
                            data-tab="certificates">
                        📜 <?php esc_html_e('My Certificates', 'wp-ffcertificate'); ?>
                    </button>
                <?php endif; ?>

                <?php if ($can_view_appointments) : ?>
                    <button class="ffc-tab <?php echo esc_attr( $current_tab === 'appointments' ? 'active' : '' ); ?>"
                            data-tab="appointments">
                        📅 <?php esc_html_e('My Appointments', 'wp-ffcertificate'); ?>
                    </button>
                <?php endif; ?>

                <button class="ffc-tab <?php echo esc_attr( $current_tab === 'profile' ? 'active' : '' ); ?>"
                        data-tab="profile">
                    👤 <?php esc_html_e('My Profile', 'wp-ffcertificate'); ?>
                </button>
            </nav>

            <?php if ($can_view_certificates) : ?>
                <div class="ffc-tab-content <?php echo esc_attr( $current_tab === 'certificates' ? 'active' : '' ); ?>"
                     id="tab-certificates">
                    <div class="ffc-loading">
                        <?php esc_html_e('Loading certificates...', 'wp-ffcertificate'); ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($can_view_appointments) : ?>
                <div class="ffc-tab-content <?php echo esc_attr( $current_tab === 'appointments' ? 'active' : '' ); ?>"
                     id="tab-appointments">
                    <div class="ffc-loading">
                        <?php esc_html_e('Loading appointments...', 'wp-ffcertificate'); ?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="ffc-tab-content <?php echo esc_attr( $current_tab === 'profile' ? 'active' : '' ); ?>"
                 id="tab-profile">
                <div class="ffc-loading">
                    <?php esc_html_e('Loading profile...', 'wp-ffcertificate'); ?>
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
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified below via wp_verify_nonce; isset() check only.
        if (!isset($_GET['ffc_view_as_user']) || !isset($_GET['ffc_view_nonce'])) {
            return false;
        }

        // Only admins can use view-as mode
        if (!current_user_can('manage_options')) {
            return false;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified below via wp_verify_nonce.
        $target_user_id = absint(wp_unslash($_GET['ffc_view_as_user']));
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This IS the nonce value being extracted for verification.
        $nonce = sanitize_text_field(wp_unslash($_GET['ffc_view_nonce']));

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
    private static function render_admin_viewing_banner(int $user_id): string {
        $user = get_user_by('id', $user_id);
        $admin = wp_get_current_user();

        // Get dashboard URL without view-as parameters
        $dashboard_page_id = get_option('ffc_dashboard_page_id');
        $exit_url = $dashboard_page_id ? get_permalink($dashboard_page_id) : home_url('/dashboard');

        ob_start();
        ?>
        <div class="ffc-dashboard-notice ffc-notice-admin-viewing">
            <div class="ffc-dashboard-header">
                <div>
                    <strong>🔍 <?php esc_html_e('Admin View Mode', 'wp-ffcertificate'); ?></strong>
                    <p class="ffc-m-5-0">
                        <?php
                        echo wp_kses_post( sprintf(
                            /* translators: 1: Admin name, 2: User name */
                            esc_html__('You (%1$s) are viewing the dashboard as: %2$s', 'wp-ffcertificate'),
                            '<strong>' . esc_html($admin->display_name) . '</strong>',
                            '<strong>' . esc_html($user->display_name) . ' (' . esc_html($user->user_email) . ')</strong>'
                        ) );
                        ?>
                    </p>
                </div>
                <div>
                    <a href="<?php echo esc_url($exit_url); ?>" class="button button-primary">
                        <?php esc_html_e('Exit View Mode', 'wp-ffcertificate'); ?>
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
    private static function render_login_required(): string {
        ob_start();
        ?>
        <div class="ffc-dashboard-notice ffc-notice-warning">
            <p><?php esc_html_e('You must be logged in to view your dashboard.', 'wp-ffcertificate'); ?></p>
            <p>
                <a href="<?php echo esc_url(wp_login_url(get_permalink())); ?>" class="button">
                    <?php esc_html_e('Login', 'wp-ffcertificate'); ?>
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
    private static function render_redirect_message(): string {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only parameter for redirect message.
        if (!isset($_GET['ffc_redirect']) || sanitize_text_field(wp_unslash($_GET['ffc_redirect'])) !== 'access_denied') {
            return '';
        }

        $settings = get_option('ffc_user_access_settings', array());
        $message = $settings['redirect_message'] ?? __('You were redirected from the admin panel. Use this dashboard to access your certificates.', 'wp-ffcertificate');

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
    private static function enqueue_assets($view_as_user_id = false): void {
        // Get user permissions (based on capabilities, not just role)
        $user_id = $view_as_user_id ?: get_current_user_id();
        $user = get_user_by('id', $user_id);

        $can_view_certificates = $user && (
            user_can($user, 'view_own_certificates') ||
            user_can($user, 'manage_options')
        );

        $can_view_appointments = $user && (
            user_can($user, 'ffc_view_self_scheduling') ||
            user_can($user, 'manage_options')
        );

        // Enqueue CSS
        wp_enqueue_style( 'ffc-dashboard', FFC_PLUGIN_URL . 'assets/css/ffc-user-dashboard.css', array(), FFC_VERSION );

        // Enqueue JavaScript
        wp_enqueue_script( 'ffc-dashboard', FFC_PLUGIN_URL . 'assets/js/ffc-user-dashboard.js', array('jquery'), FFC_VERSION, true );

        // Localize script
        wp_localize_script('ffc-dashboard', 'ffcDashboard', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'restUrl' => rest_url('ffc/v1/'),
            'nonce' => wp_create_nonce('wp_rest'),
            'viewAsUserId' => $view_as_user_id ? $view_as_user_id : false,
            'isAdminViewing' => $view_as_user_id && $view_as_user_id !== get_current_user_id(),
            'canViewCertificates' => $can_view_certificates,
            'canViewAppointments' => $can_view_appointments,
            'strings' => array(
                'loading' => __('Loading...', 'wp-ffcertificate'),
                'error' => __('Error loading data', 'wp-ffcertificate'),
                'noCertificates' => __('No certificates found', 'wp-ffcertificate'),
                'noAppointments' => __('No appointments found', 'wp-ffcertificate'),
                'downloadPdf' => __('View PDF', 'wp-ffcertificate'),
                'yes' => __('Yes', 'wp-ffcertificate'),
                'no' => __('No', 'wp-ffcertificate'),
                // Table headers
                'eventName' => __('Event Name', 'wp-ffcertificate'),
                'calendar' => __('Calendar', 'wp-ffcertificate'),
                'date' => __('Date', 'wp-ffcertificate'),
                'time' => __('Time', 'wp-ffcertificate'),
                'status' => __('Status', 'wp-ffcertificate'),
                'consent' => __('Consent (LGPD)', 'wp-ffcertificate'),
                'email' => __('Email', 'wp-ffcertificate'),
                'code' => __('Code', 'wp-ffcertificate'),
                'actions' => __('Actions', 'wp-ffcertificate'),
                'notes' => __('Notes', 'wp-ffcertificate'),
                // Profile fields
                'name' => __('Name:', 'wp-ffcertificate'),
                'linkedEmails' => __('Linked Emails:', 'wp-ffcertificate'),
                'cpfRf' => __('CPF/RF:', 'wp-ffcertificate'),
                'memberSince' => __('Member Since:', 'wp-ffcertificate'),
                // Appointment actions
                'cancelAppointment' => __('Cancel', 'wp-ffcertificate'),
                'viewReceipt' => __('View Receipt', 'wp-ffcertificate'),
                'viewDetails' => __('View Details', 'wp-ffcertificate'),
                'confirmCancel' => __('Are you sure you want to cancel this appointment?', 'wp-ffcertificate'),
                'cancelSuccess' => __('Appointment cancelled successfully', 'wp-ffcertificate'),
                'cancelError' => __('Error cancelling appointment', 'wp-ffcertificate'),
                'noPermission' => __('You do not have permission to view this content.', 'wp-ffcertificate'),
            ),
        ));
    }
}
