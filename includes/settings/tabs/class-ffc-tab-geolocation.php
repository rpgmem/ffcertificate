<?php
declare(strict_types=1);

/**
 * Geolocation Settings Tab
 *
 * Manages global geolocation and IP geolocation API settings
 *
 * @package FFC
 * @since 3.0.0
 * @version 3.3.0 - Added strict types and type hints
 * @version 3.2.0 - Migrated to namespace (Phase 2)
 */

namespace FreeFormCertificate\Settings\Tabs;

use FreeFormCertificate\Settings\SettingsTab;

if (!defined('ABSPATH')) exit;

class TabGeolocation extends SettingsTab {

    protected function init(): void {
        $this->tab_id = 'geolocation';
        $this->tab_title = __('Geolocation', 'ffcertificate');
        $this->tab_icon = 'ffc-icon-globe';
        $this->tab_order = 65;
    }

    /**
     * Get default settings
     */
    private function get_default_settings(): array {
        return array(
            // IP Geolocation API Settings
            'ip_api_enabled' => false,
            'ip_api_service' => 'ip-api', // 'ip-api' or 'ipinfo'
            'ip_api_cascade' => false, // Use both with fallback
            'ipinfo_api_key' => '',
            'ip_cache_enabled' => true,
            'ip_cache_ttl' => 600, // 10 minutes in seconds (300-3600)

            // GPS Cache Settings
            'gps_cache_ttl' => 600, // 10 minutes in seconds (60-3600)

            // Fallback behavior when API fails
            'api_fallback' => 'gps_only', // 'allow', 'block', 'gps_only'
            'gps_fallback' => 'allow', // When GPS fails: 'allow' or 'block'
            'both_fail_fallback' => 'block', // When GPS + IP both fail: 'allow' or 'block'

            // Admin Bypass (independent of debug mode)
            'admin_bypass_datetime' => false, // Admins bypass datetime restrictions
            'admin_bypass_geo' => false, // Admins bypass geolocation restrictions

            // Debug Mode
            'debug_enabled' => false,
        );
    }

    /**
     * Get current settings
     */
    private function get_settings(): array {
        return wp_parse_args(
            get_option('ffc_geolocation_settings', array()),
            $this->get_default_settings()
        );
    }

    /**
     * Render tab content
     */
    public function render(): void {
        // Handle form submission
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified below via check_admin_referer.
        if ($_POST && isset($_POST['ffc_save_geolocation'])) {
            check_admin_referer('ffc_geolocation_nonce');
            $this->save_settings();
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Geolocation settings saved successfully!', 'ffcertificate') . '</p></div>';
        }

        $settings = $this->get_settings();
        include FFC_PLUGIN_DIR . 'includes/settings/views/ffc-tab-geolocation.php';
    }

    /**
     * Save settings
     */
    private function save_settings(): void {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in render() via check_admin_referer.
        $ffc_ip_api_service = sanitize_key(wp_unslash($_POST['ip_api_service'] ?? ''));
        $ffc_api_fallback = sanitize_key(wp_unslash($_POST['api_fallback'] ?? ''));
        $ffc_gps_fallback = sanitize_key(wp_unslash($_POST['gps_fallback'] ?? ''));
        $ffc_both_fail_fallback = sanitize_key(wp_unslash($_POST['both_fail_fallback'] ?? ''));

        $settings = array(
            'ip_api_enabled' => isset($_POST['ip_api_enabled']),
            'ip_api_service' => in_array($ffc_ip_api_service, array('ip-api', 'ipinfo'))
                ? $ffc_ip_api_service
                : 'ip-api',
            'ip_api_cascade' => isset($_POST['ip_api_cascade']),
            'ipinfo_api_key' => sanitize_text_field(wp_unslash($_POST['ipinfo_api_key'] ?? '')),
            'ip_cache_enabled' => isset($_POST['ip_cache_enabled']),
            'ip_cache_ttl' => max(300, min(3600, absint(wp_unslash($_POST['ip_cache_ttl'] ?? 600)))),

            'gps_cache_ttl' => max(60, min(3600, absint(wp_unslash($_POST['gps_cache_ttl'] ?? 600)))),

            'api_fallback' => in_array($ffc_api_fallback, array('allow', 'block', 'gps_only'))
                ? $ffc_api_fallback
                : 'gps_only',
            'gps_fallback' => in_array($ffc_gps_fallback, array('allow', 'block'))
                ? $ffc_gps_fallback
                : 'allow',
            'both_fail_fallback' => in_array($ffc_both_fail_fallback, array('allow', 'block'))
                ? $ffc_both_fail_fallback
                : 'block',

            'admin_bypass_datetime' => isset($_POST['admin_bypass_datetime']),
            'admin_bypass_geo' => isset($_POST['admin_bypass_geo']),

            'debug_enabled' => isset($_POST['debug_enabled']),
        );
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        update_option('ffc_geolocation_settings', $settings);

        // Log settings change
        if (class_exists('\FreeFormCertificate\Core\ActivityLog')) {
            \FreeFormCertificate\Core\ActivityLog::log_settings_changed('geolocation', get_current_user_id());
        }
    }
}
