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

        $can_view_audience_bookings = $user && (
            user_can($user, 'ffc_view_audience_bookings') ||
            user_can($user, 'manage_options')
        );

        // Only show audience tab if user actually belongs to at least one audience group
        if ($can_view_audience_bookings) {
            $can_view_audience_bookings = self::user_has_audience_groups($user_id);
        }

        // Check if user has any reregistration submissions
        $can_view_reregistrations = class_exists('\FreeFormCertificate\Reregistration\ReregistrationSubmissionRepository')
            && !empty(\FreeFormCertificate\Reregistration\ReregistrationSubmissionRepository::get_all_by_user($user_id));

        // Get current tab - default to first available tab
        $default_tab = $can_view_certificates ? 'certificates' : ($can_view_appointments ? 'appointments' : ($can_view_audience_bookings ? 'audience' : ($can_view_reregistrations ? 'reregistrations' : 'profile')));
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
            echo wp_kses_post( self::render_reregistration_banners($user_id) );
            ?>

            <div class="ffc-dashboard-summary" id="ffc-dashboard-summary"></div>

            <nav class="ffc-dashboard-tabs" role="tablist" aria-label="<?php esc_attr_e('Dashboard', 'ffcertificate'); ?>">
                <?php if ($can_view_certificates) : ?>
                    <button class="ffc-tab <?php echo esc_attr( $current_tab === 'certificates' ? 'active' : '' ); ?>"
                            data-tab="certificates"
                            role="tab"
                            id="ffc-tab-certificates"
                            aria-selected="<?php echo esc_attr( $current_tab === 'certificates' ? 'true' : 'false' ); ?>"
                            aria-controls="tab-certificates"
                            tabindex="<?php echo esc_attr( $current_tab === 'certificates' ? '0' : '-1' ); ?>">
                        <span class="ffc-icon-scroll" aria-hidden="true"></span> <?php esc_html_e('Certificates', 'ffcertificate'); ?>
                    </button>
                <?php endif; ?>

                <?php if ($can_view_appointments) : ?>
                    <button class="ffc-tab <?php echo esc_attr( $current_tab === 'appointments' ? 'active' : '' ); ?>"
                            data-tab="appointments"
                            role="tab"
                            id="ffc-tab-appointments"
                            aria-selected="<?php echo esc_attr( $current_tab === 'appointments' ? 'true' : 'false' ); ?>"
                            aria-controls="tab-appointments"
                            tabindex="<?php echo esc_attr( $current_tab === 'appointments' ? '0' : '-1' ); ?>">
                        <span class="ffc-icon-calendar" aria-hidden="true"></span> <?php esc_html_e('Personal Schedule', 'ffcertificate'); ?>
                    </button>
                <?php endif; ?>

                <?php if ($can_view_audience_bookings) : ?>
                    <button class="ffc-tab <?php echo esc_attr( $current_tab === 'audience' ? 'active' : '' ); ?>"
                            data-tab="audience"
                            role="tab"
                            id="ffc-tab-audience"
                            aria-selected="<?php echo esc_attr( $current_tab === 'audience' ? 'true' : 'false' ); ?>"
                            aria-controls="tab-audience"
                            tabindex="<?php echo esc_attr( $current_tab === 'audience' ? '0' : '-1' ); ?>">
                        <span class="ffc-icon-users" aria-hidden="true"></span> <?php esc_html_e('Group Schedule', 'ffcertificate'); ?>
                    </button>
                <?php endif; ?>

                <?php if ($can_view_reregistrations) : ?>
                    <button class="ffc-tab <?php echo esc_attr( $current_tab === 'reregistrations' ? 'active' : '' ); ?>"
                            data-tab="reregistrations"
                            role="tab"
                            id="ffc-tab-reregistrations"
                            aria-selected="<?php echo esc_attr( $current_tab === 'reregistrations' ? 'true' : 'false' ); ?>"
                            aria-controls="tab-reregistrations"
                            tabindex="<?php echo esc_attr( $current_tab === 'reregistrations' ? '0' : '-1' ); ?>">
                        <span class="ffc-icon-file" aria-hidden="true"></span> <?php esc_html_e('Reregistration', 'ffcertificate'); ?>
                    </button>
                <?php endif; ?>

                <button class="ffc-tab <?php echo esc_attr( $current_tab === 'profile' ? 'active' : '' ); ?>"
                        data-tab="profile"
                        role="tab"
                        id="ffc-tab-profile"
                        aria-selected="<?php echo esc_attr( $current_tab === 'profile' ? 'true' : 'false' ); ?>"
                        aria-controls="tab-profile"
                        tabindex="<?php echo esc_attr( $current_tab === 'profile' ? '0' : '-1' ); ?>">
                    <span aria-hidden="true">👤</span> <?php esc_html_e('Profile', 'ffcertificate'); ?>
                </button>
            </nav>

            <?php if ($can_view_certificates) : ?>
                <div class="ffc-tab-content <?php echo esc_attr( $current_tab === 'certificates' ? 'active' : '' ); ?>"
                     id="tab-certificates"
                     role="tabpanel"
                     aria-labelledby="ffc-tab-certificates">
                    <div class="ffc-loading" role="status">
                        <?php esc_html_e('Loading certificates...', 'ffcertificate'); ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($can_view_appointments) : ?>
                <div class="ffc-tab-content <?php echo esc_attr( $current_tab === 'appointments' ? 'active' : '' ); ?>"
                     id="tab-appointments"
                     role="tabpanel"
                     aria-labelledby="ffc-tab-appointments">
                    <div class="ffc-loading" role="status">
                        <?php esc_html_e('Loading appointments...', 'ffcertificate'); ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($can_view_audience_bookings) : ?>
                <div class="ffc-tab-content <?php echo esc_attr( $current_tab === 'audience' ? 'active' : '' ); ?>"
                     id="tab-audience"
                     role="tabpanel"
                     aria-labelledby="ffc-tab-audience">
                    <div class="ffc-loading" role="status">
                        <?php esc_html_e('Loading scheduled activities...', 'ffcertificate'); ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($can_view_reregistrations) : ?>
                <div class="ffc-tab-content <?php echo esc_attr( $current_tab === 'reregistrations' ? 'active' : '' ); ?>"
                     id="tab-reregistrations"
                     role="tabpanel"
                     aria-labelledby="ffc-tab-reregistrations">
                    <div class="ffc-loading" role="status">
                        <?php esc_html_e('Loading reregistrations...', 'ffcertificate'); ?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="ffc-tab-content <?php echo esc_attr( $current_tab === 'profile' ? 'active' : '' ); ?>"
                 id="tab-profile"
                 role="tabpanel"
                 aria-labelledby="ffc-tab-profile">
                <div class="ffc-loading" role="status">
                    <?php esc_html_e('Loading profile...', 'ffcertificate'); ?>
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
                    <strong>🔍 <?php esc_html_e('Admin View Mode', 'ffcertificate'); ?></strong>
                    <p class="ffc-m-5-0">
                        <?php
                        echo wp_kses_post( sprintf(
                            /* translators: 1: Admin name, 2: User name */
                            esc_html__('You (%1$s) are viewing the dashboard as: %2$s', 'ffcertificate'),
                            '<strong>' . esc_html($admin->display_name) . '</strong>',
                            '<strong>' . esc_html($user->display_name) . ' (' . esc_html($user->user_email) . ')</strong>'
                        ) );
                        ?>
                    </p>
                </div>
                <div>
                    <a href="<?php echo esc_url($exit_url); ?>" class="button button-primary">
                        <?php esc_html_e('Exit View Mode', 'ffcertificate'); ?>
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
            <p><?php esc_html_e('You must be logged in to view your dashboard.', 'ffcertificate'); ?></p>
            <p>
                <a href="<?php echo esc_url(wp_login_url(get_permalink())); ?>" class="button">
                    <?php esc_html_e('Login', 'ffcertificate'); ?>
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
        $message = $settings['redirect_message'] ?? __('You were redirected from the admin panel. Use this dashboard to access your certificates.', 'ffcertificate');

        ob_start();
        ?>
        <div class="ffc-dashboard-notice ffc-notice-info">
            <p><?php echo esc_html($message); ?></p>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Check if user belongs to at least one audience group
     *
     * @since 4.9.7
     * @param int $user_id WordPress user ID
     * @return bool
     */
    private static function user_has_audience_groups(int $user_id): bool {
        if (!class_exists('\FreeFormCertificate\Audience\AudienceRepository')) {
            return false;
        }

        // Admins always see the audience tab (they can manage all audiences)
        if (user_can($user_id, 'manage_options')) {
            return true;
        }

        $audiences = \FreeFormCertificate\Audience\AudienceRepository::get_user_audiences($user_id);
        return !empty($audiences);
    }

    /**
     * Render reregistration banners for active campaigns.
     *
     * @since 4.11.0
     * @param int $user_id User ID.
     * @return string HTML output.
     */
    private static function render_reregistration_banners(int $user_id): string {
        if (!class_exists('\FreeFormCertificate\Reregistration\ReregistrationFrontend')) {
            return '';
        }

        $reregistrations = \FreeFormCertificate\Reregistration\ReregistrationFrontend::get_user_reregistrations($user_id);

        if (empty($reregistrations)) {
            return '';
        }

        ob_start();
        foreach ($reregistrations as $rereg) {
            if (!$rereg['can_submit']) {
                // Show completed status
                if ($rereg['submission_status'] === 'approved') {
                    ?>
                    <div class="ffc-dashboard-notice ffc-notice-info ffc-rereg-banner ffc-rereg-completed">
                        <div class="ffc-dashboard-header">
                            <div>
                                <strong><?php echo esc_html($rereg['title']); ?></strong>
                                <p class="ffc-m-5-0"><?php esc_html_e('Your reregistration has been approved.', 'ffcertificate'); ?></p>
                            </div>
                            <?php if (!empty($rereg['magic_link'])) : ?>
                            <div>
                                <a href="<?php echo esc_url($rereg['magic_link']); ?>" class="button ffc-btn-pdf" target="_blank" rel="noopener">
                                    <?php esc_html_e('Download Ficha', 'ffcertificate'); ?>
                                </a>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php
                } elseif ($rereg['submission_status'] === 'submitted') {
                    ?>
                    <div class="ffc-dashboard-notice ffc-notice-info ffc-rereg-banner ffc-rereg-pending-review">
                        <div class="ffc-dashboard-header">
                            <div>
                                <strong><?php echo esc_html($rereg['title']); ?></strong>
                                <p class="ffc-m-5-0"><?php esc_html_e('Your reregistration has been submitted and is pending review.', 'ffcertificate'); ?></p>
                            </div>
                            <?php if (!empty($rereg['magic_link'])) : ?>
                            <div>
                                <a href="<?php echo esc_url($rereg['magic_link']); ?>" class="button ffc-btn-pdf" target="_blank" rel="noopener">
                                    <?php esc_html_e('Download Ficha', 'ffcertificate'); ?>
                                </a>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php
                }
                continue;
            }

            $end_date = wp_date(get_option('date_format'), strtotime($rereg['end_date']));
            $days_left = max(0, (int) ((strtotime($rereg['end_date']) - time()) / 86400));
            $urgency = $days_left <= 3 ? 'ffc-rereg-urgent' : '';
            ?>
            <div class="ffc-dashboard-notice ffc-notice-warning ffc-rereg-banner <?php echo esc_attr($urgency); ?>"
                 data-reregistration-id="<?php echo esc_attr($rereg['id']); ?>">
                <div class="ffc-dashboard-header">
                    <div>
                        <strong><?php echo esc_html($rereg['title']); ?></strong>
                        <p class="ffc-m-5-0">
                            <?php
                            /* translators: %s: deadline date */
                            echo esc_html(sprintf(__('Deadline: %s', 'ffcertificate'), $end_date));
                            if ($days_left <= 7) {
                                echo ' — ';
                                /* translators: %d: number of days remaining */
                                echo '<strong>' . esc_html(sprintf(_n('%d day left', '%d days left', $days_left, 'ffcertificate'), $days_left)) . '</strong>';
                            }
                            ?>
                        </p>
                    </div>
                    <div>
                        <button type="button" class="button button-primary ffc-rereg-open-form"
                                data-reregistration-id="<?php echo esc_attr($rereg['id']); ?>">
                            <?php
                            if ($rereg['submission_status'] === 'in_progress') {
                                esc_html_e('Continue Reregistration', 'ffcertificate');
                            } elseif ($rereg['submission_status'] === 'rejected') {
                                esc_html_e('Resubmit Reregistration', 'ffcertificate');
                            } else {
                                esc_html_e('Complete Reregistration', 'ffcertificate');
                            }
                            ?>
                        </button>
                    </div>
                </div>
            </div>
            <?php
        }
        ?>
        <div id="ffc-rereg-form-panel" style="display:none;"></div>
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

        $can_view_audience_bookings = $user && (
            user_can($user, 'ffc_view_audience_bookings') ||
            user_can($user, 'manage_options')
        );

        // Only show audience tab if user actually belongs to at least one audience group
        if ($can_view_audience_bookings) {
            $can_view_audience_bookings = self::user_has_audience_groups($user_id);
        }

        $can_view_reregistrations = class_exists('\FreeFormCertificate\Reregistration\ReregistrationSubmissionRepository')
            && !empty(\FreeFormCertificate\Reregistration\ReregistrationSubmissionRepository::get_all_by_user($user_id));

        $s = \FreeFormCertificate\Core\Utils::asset_suffix();

        // Enqueue CSS (ffc-common provides icon classes)
        wp_enqueue_style( 'ffc-common', FFC_PLUGIN_URL . "assets/css/ffc-common{$s}.css", array(), FFC_VERSION );
        wp_enqueue_style( 'ffc-dashboard', FFC_PLUGIN_URL . "assets/css/ffc-user-dashboard{$s}.css", array( 'ffc-common' ), FFC_VERSION );

        // Dark mode
        \FreeFormCertificate\Core\Utils::enqueue_dark_mode();

        // Enqueue JavaScript
        wp_enqueue_script( 'ffc-dashboard', FFC_PLUGIN_URL . "assets/js/ffc-user-dashboard{$s}.js", array('jquery'), FFC_VERSION, true );

        // Working hours field component (shared)
        wp_enqueue_style('ffc-working-hours', FFC_PLUGIN_URL . "assets/css/ffc-working-hours{$s}.css", array(), FFC_VERSION);
        wp_enqueue_script('ffc-working-hours', FFC_PLUGIN_URL . "assets/js/ffc-working-hours{$s}.js", array('jquery'), FFC_VERSION, true);
        wp_localize_script('ffc-working-hours', 'ffcWorkingHours', array(
            'days' => array(
                array('value' => 0, 'label' => __('Sunday', 'ffcertificate')),
                array('value' => 1, 'label' => __('Monday', 'ffcertificate')),
                array('value' => 2, 'label' => __('Tuesday', 'ffcertificate')),
                array('value' => 3, 'label' => __('Wednesday', 'ffcertificate')),
                array('value' => 4, 'label' => __('Thursday', 'ffcertificate')),
                array('value' => 5, 'label' => __('Friday', 'ffcertificate')),
                array('value' => 6, 'label' => __('Saturday', 'ffcertificate')),
            ),
        ));

        // Reregistration frontend assets
        wp_enqueue_style('ffc-reregistration-frontend', FFC_PLUGIN_URL . "assets/css/ffc-reregistration-frontend{$s}.css", array('ffc-dashboard'), FFC_VERSION);
        wp_enqueue_script('ffc-reregistration-frontend', FFC_PLUGIN_URL . "assets/js/ffc-reregistration-frontend{$s}.js", array('jquery', 'ffc-dashboard', 'ffc-working-hours'), FFC_VERSION, true);
        wp_localize_script('ffc-reregistration-frontend', 'ffcReregistration', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('ffc_reregistration_frontend'),
            'strings' => array(
                'loading'         => __('Loading form...', 'ffcertificate'),
                'saving'          => __('Saving...', 'ffcertificate'),
                'submitting'      => __('Submitting...', 'ffcertificate'),
                'saveDraft'       => __('Save Draft', 'ffcertificate'),
                'submit'          => __('Submit', 'ffcertificate'),
                'draftSaved'      => __('Draft saved.', 'ffcertificate'),
                'submitted'       => __('Reregistration submitted successfully!', 'ffcertificate'),
                'errorLoading'    => __('Error loading form.', 'ffcertificate'),
                'errorSaving'     => __('Error saving draft.', 'ffcertificate'),
                'errorSubmitting' => __('Error submitting.', 'ffcertificate'),
                'fixErrors'       => __('Please fix the errors below.', 'ffcertificate'),
                'required'        => __('This field is required.', 'ffcertificate'),
                'invalidCpf'      => __('Invalid CPF.', 'ffcertificate'),
                'invalidEmail'    => __('Invalid email.', 'ffcertificate'),
                'invalidPhone'    => __('Invalid phone number.', 'ffcertificate'),
                'invalidFormat'   => __('Invalid format.', 'ffcertificate'),
                'select'          => __('Select', 'ffcertificate'),
                'selectDivisao'   => __('Select Division / Location', 'ffcertificate'),
                'selectSetor'     => __('Select', 'ffcertificate'),
                'sunday'          => __('Sunday', 'ffcertificate'),
                'monday'          => __('Monday', 'ffcertificate'),
                'tuesday'         => __('Tuesday', 'ffcertificate'),
                'wednesday'       => __('Wednesday', 'ffcertificate'),
                'thursday'        => __('Thursday', 'ffcertificate'),
                'friday'          => __('Friday', 'ffcertificate'),
                'saturday'        => __('Saturday', 'ffcertificate'),
                'acumuloShowValue' => __('I hold', 'ffcertificate'),
            ),
        ));

        // Localize script
        wp_localize_script('ffc-dashboard', 'ffcDashboard', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'restUrl' => rest_url('ffc/v1/'),
            'nonce' => wp_create_nonce('wp_rest'),
            'viewAsUserId' => $view_as_user_id ? $view_as_user_id : false,
            'isAdminViewing' => $view_as_user_id && $view_as_user_id !== get_current_user_id(),
            'canViewCertificates' => $can_view_certificates,
            'canViewAppointments' => $can_view_appointments,
            'canViewAudienceBookings' => $can_view_audience_bookings,
            'canViewReregistrations' => $can_view_reregistrations,
            'siteName' => get_bloginfo('name'),
            'wpTimezone' => wp_timezone_string(),
            'mainAddress' => (get_option('ffc_settings', array()))['main_address'] ?? '',
            'strings' => array(
                'loading' => __('Loading...', 'ffcertificate'),
                'error' => __('Error loading data', 'ffcertificate'),
                'noCertificates' => __('No certificates found', 'ffcertificate'),
                'noAppointments' => __('No appointments found', 'ffcertificate'),
                'noAudienceBookings' => __('No scheduled activities found', 'ffcertificate'),
                'downloadPdf' => __('View PDF', 'ffcertificate'),
                'yes' => __('Yes', 'ffcertificate'),
                'no' => __('No', 'ffcertificate'),
                // Table headers
                'eventName' => __('Event Name', 'ffcertificate'),
                'calendar' => __('Calendar', 'ffcertificate'),
                'date' => __('Date', 'ffcertificate'),
                'time' => __('Time', 'ffcertificate'),
                'status' => __('Status', 'ffcertificate'),
                'consent' => __('Consent (LGPD)', 'ffcertificate'),
                'email' => __('Email', 'ffcertificate'),
                'code' => __('Code', 'ffcertificate'),
                'actions' => __('Actions', 'ffcertificate'),
                'notes' => __('Notes', 'ffcertificate'),
                // Profile fields
                'name' => __('Name:', 'ffcertificate'),
                'linkedEmails' => __('Linked Emails:', 'ffcertificate'),
                'cpfRf' => __('CPF/RF:', 'ffcertificate'),
                'memberSince' => __('Member Since:', 'ffcertificate'),
                // Appointment actions
                'cancelAppointment' => __('Cancel', 'ffcertificate'),
                'viewReceipt' => __('View Receipt', 'ffcertificate'),
                'viewDetails' => __('View Details', 'ffcertificate'),
                'confirmCancel' => __('Are you sure you want to cancel this appointment?', 'ffcertificate'),
                'cancelSuccess' => __('Appointment cancelled successfully', 'ffcertificate'),
                'cancelError' => __('Error cancelling appointment', 'ffcertificate'),
                'noPermission' => __('You do not have permission to view this content.', 'ffcertificate'),
                // Calendar export
                'exportToCalendar' => __('Export to Calendar', 'ffcertificate'),
                'otherIcs' => __('Other (.ics)', 'ffcertificate'),
                // Audience bookings
                'environment' => __('Environment', 'ffcertificate'),
                'description' => __('Description', 'ffcertificate'),
                'audiences' => __('Audiences', 'ffcertificate'),
                'upcoming' => __('Upcoming', 'ffcertificate'),
                'past' => __('Past', 'ffcertificate'),
                'cancelled' => __('Cancelled', 'ffcertificate'),
                // Profile
                'audienceGroups' => __('Groups:', 'ffcertificate'),
                'notesLabel' => __('Notes:', 'ffcertificate'),
                'notesPlaceholder' => __('Personal notes...', 'ffcertificate'),
                'phone' => __('Phone:', 'ffcertificate'),
                'department' => __('Department:', 'ffcertificate'),
                'organization' => __('Organization:', 'ffcertificate'),
                'editProfile' => __('Edit Profile', 'ffcertificate'),
                'save' => __('Save', 'ffcertificate'),
                'cancel' => __('Cancel', 'ffcertificate'),
                'saving' => __('Saving...', 'ffcertificate'),
                'saveError' => __('Error saving profile', 'ffcertificate'),
                // Password change
                'securitySection' => __('Security', 'ffcertificate'),
                'changePassword' => __('Change Password', 'ffcertificate'),
                'currentPassword' => __('Current Password', 'ffcertificate'),
                'newPassword' => __('New Password', 'ffcertificate'),
                'confirmPassword' => __('Confirm New Password', 'ffcertificate'),
                'passwordChanged' => __('Password changed successfully!', 'ffcertificate'),
                'passwordMismatch' => __('Passwords do not match', 'ffcertificate'),
                'passwordTooShort' => __('Password must be at least 8 characters', 'ffcertificate'),
                'passwordError' => __('Error changing password', 'ffcertificate'),
                // LGPD
                'privacySection' => __('Privacy & Data (LGPD)', 'ffcertificate'),
                'exportData' => __('Export My Data', 'ffcertificate'),
                'requestDeletion' => __('Request Data Deletion', 'ffcertificate'),
                'exportDataDesc' => __('Request a copy of all your personal data stored in the system.', 'ffcertificate'),
                'deletionDataDesc' => __('Request deletion of your personal data. An administrator will review your request.', 'ffcertificate'),
                'privacyRequestSent' => __('Request sent! The administrator will review it.', 'ffcertificate'),
                'privacyRequestError' => __('Error sending request', 'ffcertificate'),
                'confirmDeletion' => __('Are you sure you want to request deletion of your personal data? This will be reviewed by an administrator.', 'ffcertificate'),
                // Audience self-join
                'joinGroups' => __('Join Groups', 'ffcertificate'),
                'joinGroupsDesc' => __('Select up to {max} groups to participate in collective calendars.', 'ffcertificate'),
                'joinGroup' => __('Join', 'ffcertificate'),
                'leaveGroup' => __('Leave', 'ffcertificate'),
                'confirmLeaveGroup' => __('Are you sure you want to leave this group?', 'ffcertificate'),
                // Summary
                'summaryTitle' => __('Overview', 'ffcertificate'),
                'totalCertificates' => __('Certificates', 'ffcertificate'),
                'nextAppointment' => __('Next Appointment', 'ffcertificate'),
                'upcomingGroupEvents' => __('Group Events', 'ffcertificate'),
                'noUpcoming' => __('None scheduled', 'ffcertificate'),
                // Filters
                'filterFrom' => __('From:', 'ffcertificate'),
                'filterTo' => __('To:', 'ffcertificate'),
                'filterSearch' => __('Search...', 'ffcertificate'),
                'filterApply' => __('Filter', 'ffcertificate'),
                'filterClear' => __('Clear', 'ffcertificate'),
                // Notification preferences
                'notificationSection' => __('Notification Preferences', 'ffcertificate'),
                'notifAppointmentConfirm' => __('Appointment confirmation', 'ffcertificate'),
                'notifAppointmentReminder' => __('Appointment reminder', 'ffcertificate'),
                'notifNewCertificate' => __('New certificate issued', 'ffcertificate'),
                'notifSaved' => __('Preferences saved', 'ffcertificate'),
                // Pagination
                'previous' => __('Previous', 'ffcertificate'),
                'next' => __('Next', 'ffcertificate'),
                'pageOf' => __('Page {current} of {total}', 'ffcertificate'),
                'perPage' => __('Per page:', 'ffcertificate'),
                // Reregistrations tab
                'noReregistrations' => __('No reregistrations found.', 'ffcertificate'),
                'reregistrationTitle' => __('Campaign', 'ffcertificate'),
                'period' => __('Period', 'ffcertificate'),
                'submittedAt' => __('Submitted', 'ffcertificate'),
                'validationCode' => __('Validation Code', 'ffcertificate'),
                'downloadFicha' => __('Download Ficha', 'ffcertificate'),
                'active' => __('Active', 'ffcertificate'),
                'completed' => __('Completed', 'ffcertificate'),
                'editReregistration' => __('Edit', 'ffcertificate'),
            ),
        ));
    }
}
