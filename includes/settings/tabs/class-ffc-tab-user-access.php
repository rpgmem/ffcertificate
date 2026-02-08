<?php
declare(strict_types=1);

/**
 * User Access Settings Tab
 *
 * @package FFC
 * @since 3.1.0
 * @version 3.3.0 - Added strict types and type hints
 * @version 3.2.0 - Migrated to namespace (Phase 2)
 */

namespace FreeFormCertificate\Settings\Tabs;

use FreeFormCertificate\Settings\SettingsTab;

if (!defined('ABSPATH')) {
    exit;
}

class TabUserAccess extends SettingsTab {

    protected function init(): void {
        $this->tab_id = 'user_access';
        $this->tab_title = __('User Access', 'ffcertificate');
        $this->tab_icon = 'ffc-icon-users';
        $this->tab_order = 60;

        // Enqueue styles for this tab
        add_action('admin_enqueue_scripts', array($this, 'enqueue_styles'));
    }

    /**
     * Enqueue styles for User Access settings page
     */
    public function enqueue_styles(string $hook): void {
        // Only load on settings page with this tab active
        if ($hook !== 'ffc_form_page_ffc-settings') {
            return;
        }

        $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Tab parameter for conditional script loading.
        // ✅ v3.1.0: User access styles consolidated into ffc-admin-settings.css (already loaded)
        // No need to enqueue separate stylesheet anymore
    }

    public function render(): void {
        // Include view file
        $view_file = FFC_PLUGIN_DIR . 'includes/settings/views/ffc-tab-user-access.php';

        if (file_exists($view_file)) {
            // Make variables available to view
            $settings = $this;

            include $view_file;
        } else {
            echo '<div class="notice notice-error"><p>';
            echo esc_html__('User Access settings view file not found.', 'ffcertificate');
            echo '</p></div>';
        }
    }

    /**
     * Get option value
     */
    public function get_option(string $key, string $default = ''): string {
        $settings = get_option('ffc_user_access_settings', array());
        return isset($settings[$key]) ? $settings[$key] : $default;
    }

    /**
     * Save settings (called by parent class)
     */
    public function save_settings(): void {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified immediately below via wp_verify_nonce.
        if (!isset($_POST['ffc_user_access_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ffc_user_access_nonce'])), 'ffc_user_access_settings')) {
            // phpcs:enable WordPress.Security.NonceVerification.Missing
            return;
        }

        // phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified above via wp_verify_nonce. isset() checks used as boolean only.
        $settings = array(
            'block_wp_admin' => isset($_POST['block_wp_admin']),
            'blocked_roles' => isset($_POST['blocked_roles']) && is_array($_POST['blocked_roles'])
                ? array_map('sanitize_text_field', wp_unslash($_POST['blocked_roles']))
                : array('ffc_user'),
            'redirect_url' => !empty($_POST['redirect_url']) ? esc_url_raw(wp_unslash($_POST['redirect_url'])) : home_url('/dashboard'),
            'redirect_message' => isset($_POST['redirect_message']) ? sanitize_textarea_field(wp_unslash($_POST['redirect_message'])) : '',
            'allow_admin_bar' => isset($_POST['allow_admin_bar']),
            'bypass_for_admins' => isset($_POST['bypass_for_admins']),
        );
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        update_option('ffc_user_access_settings', $settings);

        add_settings_error(
            'ffc_user_access_settings',
            'ffc_user_access_updated',
            __('User Access settings saved successfully.', 'ffcertificate'),
            'updated'
        );
    }
}
