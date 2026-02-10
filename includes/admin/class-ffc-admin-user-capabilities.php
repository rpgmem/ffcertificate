<?php
declare(strict_types=1);

/**
 * AdminUserCapabilities
 *
 * Adds FFC capability management to WordPress user edit page.
 * Allows admins to toggle certificate and appointment capabilities per user.
 *
 * @since 4.4.0
 */

namespace FreeFormCertificate\Admin;

if (!defined('ABSPATH')) exit;

class AdminUserCapabilities {

    /**
     * Initialize the class
     */
    public static function init(): void {
        // Add capability section to user edit page
        add_action('show_user_profile', array(__CLASS__, 'render_capability_fields'));
        add_action('edit_user_profile', array(__CLASS__, 'render_capability_fields'));

        // Save capability changes
        add_action('personal_options_update', array(__CLASS__, 'save_capability_fields'));
        add_action('edit_user_profile_update', array(__CLASS__, 'save_capability_fields'));

        // Enqueue scripts on user profile pages
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_scripts'));
    }

    /**
     * Enqueue scripts on user profile pages
     *
     * @param string $hook_suffix Admin page hook suffix
     */
    public static function enqueue_scripts( string $hook_suffix ): void {
        if ( $hook_suffix !== 'user-edit.php' && $hook_suffix !== 'profile.php' ) {
            return;
        }
        $s = \FreeFormCertificate\Core\Utils::asset_suffix();
        wp_enqueue_script(
            'ffc-user-capabilities',
            FFC_PLUGIN_URL . "assets/js/ffc-user-capabilities{$s}.js",
            array( 'jquery' ),
            FFC_VERSION,
            true
        );
    }

    /**
     * Render capability management fields on user profile page
     *
     * @param \WP_User $user User object
     * @return void
     */
    public static function render_capability_fields(\WP_User $user): void {
        // Only show for admins
        if (!current_user_can('manage_options')) {
            return;
        }

        // Only show for users with ffc_user role
        if (!in_array('ffc_user', $user->roles, true) && !self::has_any_ffc_capability($user->ID)) {
            return;
        }

        // Get current capabilities
        $capabilities = \FreeFormCertificate\UserDashboard\UserManager::get_user_ffc_capabilities($user->ID);

        // Add nonce
        wp_nonce_field('ffc_user_capabilities', 'ffc_capabilities_nonce');

        ?>
        <h2><?php esc_html_e('FFC Permissions', 'ffcertificate'); ?></h2>
        <p class="description">
            <?php esc_html_e('Manage which FFC features this user can access. Capabilities are checked in addition to role permissions.', 'ffcertificate'); ?>
        </p>

        <table class="form-table" role="presentation">
            <tbody>
                <!-- Certificate Capabilities -->
                <tr>
                    <th scope="row"><?php esc_html_e('Certificate Permissions', 'ffcertificate'); ?></th>
                    <td>
                        <fieldset>
                            <legend class="screen-reader-text">
                                <span><?php esc_html_e('Certificate Permissions', 'ffcertificate'); ?></span>
                            </legend>

                            <label>
                                <input type="checkbox" name="ffc_cap_view_own_certificates" value="1"
                                    <?php checked($capabilities['view_own_certificates'] ?? false); ?>>
                                <?php esc_html_e('View own certificates', 'ffcertificate'); ?>
                            </label>
                            <br>

                            <label>
                                <input type="checkbox" name="ffc_cap_download_own_certificates" value="1"
                                    <?php checked($capabilities['download_own_certificates'] ?? false); ?>>
                                <?php esc_html_e('Download own certificates', 'ffcertificate'); ?>
                            </label>
                            <br>

                            <label>
                                <input type="checkbox" name="ffc_cap_view_certificate_history" value="1"
                                    <?php checked($capabilities['view_certificate_history'] ?? false); ?>>
                                <?php esc_html_e('View certificate history', 'ffcertificate'); ?>
                            </label>

                            <p class="description">
                                <?php esc_html_e('Allow access to certificate-related features in the user dashboard.', 'ffcertificate'); ?>
                            </p>
                        </fieldset>
                    </td>
                </tr>

                <!-- Appointment Capabilities -->
                <tr>
                    <th scope="row"><?php esc_html_e('Appointment Permissions', 'ffcertificate'); ?></th>
                    <td>
                        <fieldset>
                            <legend class="screen-reader-text">
                                <span><?php esc_html_e('Appointment Permissions', 'ffcertificate'); ?></span>
                            </legend>

                            <label>
                                <input type="checkbox" name="ffc_cap_ffc_book_appointments" value="1"
                                    <?php checked($capabilities['ffc_book_appointments'] ?? false); ?>>
                                <?php esc_html_e('Book appointments', 'ffcertificate'); ?>
                            </label>
                            <br>

                            <label>
                                <input type="checkbox" name="ffc_cap_ffc_view_self_scheduling" value="1"
                                    <?php checked($capabilities['ffc_view_self_scheduling'] ?? false); ?>>
                                <?php esc_html_e('View own appointments', 'ffcertificate'); ?>
                            </label>
                            <br>

                            <label>
                                <input type="checkbox" name="ffc_cap_ffc_cancel_own_appointments" value="1"
                                    <?php checked($capabilities['ffc_cancel_own_appointments'] ?? false); ?>>
                                <?php esc_html_e('Cancel own appointments', 'ffcertificate'); ?>
                            </label>
                            <br>

                            <label>
                                <input type="checkbox" name="ffc_cap_ffc_scheduling_bypass" value="1"
                                    <?php checked($capabilities['ffc_scheduling_bypass'] ?? false); ?>>
                                <?php esc_html_e('Scheduling bypass (admin-level access)', 'ffcertificate'); ?>
                            </label>
                            <span class="description"><?php esc_html_e('Allows viewing private calendars, booking past dates, out-of-hours, and blocked dates.', 'ffcertificate'); ?></span>

                            <p class="description">
                                <?php esc_html_e('Allow access to appointment-related features. Calendar-specific settings also apply.', 'ffcertificate'); ?>
                            </p>
                        </fieldset>
                    </td>
                </tr>

                <!-- Audience Capabilities -->
                <tr>
                    <th scope="row"><?php esc_html_e('Audience Permissions', 'ffcertificate'); ?></th>
                    <td>
                        <fieldset>
                            <legend class="screen-reader-text">
                                <span><?php esc_html_e('Audience Permissions', 'ffcertificate'); ?></span>
                            </legend>

                            <label>
                                <input type="checkbox" name="ffc_cap_ffc_view_audience_bookings" value="1"
                                    <?php checked($capabilities['ffc_view_audience_bookings'] ?? false); ?>>
                                <?php esc_html_e('View audience bookings', 'ffcertificate'); ?>
                            </label>
                            <span class="description"><?php esc_html_e('Allows viewing group/audience bookings in the dashboard.', 'ffcertificate'); ?></span>

                            <p class="description">
                                <?php esc_html_e('Allow access to audience/group scheduling features.', 'ffcertificate'); ?>
                            </p>
                        </fieldset>
                    </td>
                </tr>

                <!-- Future Capabilities -->
                <tr>
                    <th scope="row"><?php esc_html_e('Advanced Permissions', 'ffcertificate'); ?></th>
                    <td>
                        <fieldset>
                            <legend class="screen-reader-text">
                                <span><?php esc_html_e('Advanced Permissions', 'ffcertificate'); ?></span>
                            </legend>

                            <label>
                                <input type="checkbox" name="ffc_cap_ffc_reregistration" value="1"
                                    <?php checked($capabilities['ffc_reregistration'] ?? false); ?>>
                                <?php esc_html_e('Re-registration', 'ffcertificate'); ?>
                            </label>
                            <span class="description"><?php esc_html_e('(Future feature)', 'ffcertificate'); ?></span>
                            <br>

                            <label>
                                <input type="checkbox" name="ffc_cap_ffc_certificate_update" value="1"
                                    <?php checked($capabilities['ffc_certificate_update'] ?? false); ?>>
                                <?php esc_html_e('Certificate update', 'ffcertificate'); ?>
                            </label>
                            <span class="description"><?php esc_html_e('(Future feature)', 'ffcertificate'); ?></span>
                        </fieldset>
                    </td>
                </tr>

                <!-- Quick Actions -->
                <tr>
                    <th scope="row"><?php esc_html_e('Quick Actions', 'ffcertificate'); ?></th>
                    <td>
                        <button type="button" class="button" id="ffc-grant-all-caps">
                            <?php esc_html_e('Grant All', 'ffcertificate'); ?>
                        </button>
                        <button type="button" class="button" id="ffc-revoke-all-caps">
                            <?php esc_html_e('Revoke All', 'ffcertificate'); ?>
                        </button>
                        <button type="button" class="button" id="ffc-grant-certificates">
                            <?php esc_html_e('Grant Certificates Only', 'ffcertificate'); ?>
                        </button>
                        <button type="button" class="button" id="ffc-grant-appointments">
                            <?php esc_html_e('Grant Appointments Only', 'ffcertificate'); ?>
                        </button>
                        <!-- Scripts in ffc-user-capabilities.js -->
                    </td>
                </tr>
            </tbody>
        </table>
        <?php
    }

    /**
     * Save capability field changes
     *
     * @param int $user_id User ID
     * @return void
     */
    public static function save_capability_fields(int $user_id): void {
        // Verify nonce
        if (!isset($_POST['ffc_capabilities_nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ffc_capabilities_nonce'])), 'ffc_user_capabilities')) {
            return;
        }

        // Only admins can edit
        if (!current_user_can('manage_options')) {
            return;
        }

        // Define all FFC capabilities to check
        $all_capabilities = array(
            // Certificate capabilities
            'view_own_certificates',
            'download_own_certificates',
            'view_certificate_history',
            // Appointment capabilities
            'ffc_book_appointments',
            'ffc_view_self_scheduling',
            'ffc_cancel_own_appointments',
            'ffc_scheduling_bypass',
            // Audience capabilities
            'ffc_view_audience_bookings',
            // Future capabilities
            'ffc_reregistration',
            'ffc_certificate_update',
        );

        // Get user
        $user = get_userdata($user_id);
        if (!$user) {
            return;
        }

        // Process each capability
        foreach ($all_capabilities as $cap) {
            $field_name = 'ffc_cap_' . $cap;
            $grant = isset($_POST[$field_name]) && $_POST[$field_name] === '1';

            // Set capability
            $user->add_cap($cap, $grant);
        }

        // Log the change
        if (class_exists('\FreeFormCertificate\Core\Debug')) {
            \FreeFormCertificate\Core\Debug::log_user_manager(
                'Admin updated user capabilities',
                array(
                    'user_id' => $user_id,
                    'admin_id' => get_current_user_id(),
                    'capabilities' => \FreeFormCertificate\UserDashboard\UserManager::get_user_ffc_capabilities($user_id),
                )
            );
        }
    }

    /**
     * Check if user has any FFC capability
     *
     * @param int $user_id User ID
     * @return bool True if user has any FFC capability
     */
    private static function has_any_ffc_capability(int $user_id): bool {
        return \FreeFormCertificate\UserDashboard\UserManager::has_certificate_access($user_id) ||
               \FreeFormCertificate\UserDashboard\UserManager::has_appointment_access($user_id);
    }
}
