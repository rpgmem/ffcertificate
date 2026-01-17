<?php
/**
 * Geolocation Settings Tab
 *
 * Manages global geolocation and IP geolocation API settings
 *
 * @package FFC
 * @since 3.0.0
 */

if (!defined('ABSPATH')) exit;

class FFC_Tab_Geolocation extends FFC_Settings_Tab {

    protected function init() {
        $this->tab_id = 'geolocation';
        $this->tab_title = __('Geolocation', 'ffc');
        $this->tab_icon = '🌍';
        $this->tab_order = 65;
    }

    /**
     * Get default settings
     */
    private function get_default_settings() {
        return array(
            // IP Geolocation API Settings
            'ip_api_enabled' => false,
            'ip_api_service' => 'ip-api', // 'ip-api' or 'ipinfo'
            'ip_api_cascade' => false, // Use both with fallback
            'ipinfo_api_key' => '',
            'ip_cache_enabled' => true,
            'ip_cache_ttl' => 600, // 10 minutes in seconds (300-3600)

            // Fallback behavior when API fails
            'api_fallback' => 'gps_only', // 'allow', 'block', 'gps_only'
            'gps_fallback' => 'allow', // When GPS fails: 'allow' or 'block'
            'both_fail_fallback' => 'block', // When GPS + IP both fail: 'allow' or 'block'

            // Debug Mode
            'debug_enabled' => false,
            'debug_admin_bypass' => true, // Admins bypass restrictions when debug enabled
        );
    }

    /**
     * Get current settings
     */
    private function get_settings() {
        return wp_parse_args(
            get_option('ffc_geolocation_settings', array()),
            $this->get_default_settings()
        );
    }

    /**
     * Render tab content
     */
    public function render() {
        // Handle form submission
        if ($_POST && isset($_POST['ffc_save_geolocation'])) {
            check_admin_referer('ffc_geolocation_nonce');
            $this->save_settings();
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Geolocation settings saved successfully!', 'ffc') . '</p></div>';
        }

        $settings = $this->get_settings();

        ?>
        <div class="ffc-settings-tab-content">
            <form method="POST" action="">
                <?php wp_nonce_field('ffc_geolocation_nonce'); ?>

                <!-- IP Geolocation API Section -->
                <?php $this->render_section_header(
                    __('IP Geolocation API', 'ffc'),
                    __('Configure external IP geolocation services for backend validation. These services detect user location by IP address.', 'ffc')
                ); ?>

                <table class="form-table">
                    <!-- Enable IP API -->
                    <?php
                    $enabled_field = sprintf(
                        '<label><input type="checkbox" name="ip_api_enabled" value="1" %s> %s</label>',
                        checked($settings['ip_api_enabled'], true, false),
                        esc_html__('Enable IP geolocation API for backend validation', 'ffc')
                    );
                    $this->render_field_row(
                        __('IP Geolocation', 'ffc'),
                        $enabled_field,
                        __('When enabled, validates user location by IP address on the server (in addition to GPS).', 'ffc')
                    );
                    ?>

                    <!-- API Service Selection -->
                    <?php
                    $service_field = sprintf(
                        '<select name="ip_api_service" id="ffc_ip_api_service">
                            <option value="ip-api" %s>ip-api.com (Free, 45 req/min, no key)</option>
                            <option value="ipinfo" %s>ipinfo.io (50k/month free, requires key)</option>
                        </select>',
                        selected($settings['ip_api_service'], 'ip-api', false),
                        selected($settings['ip_api_service'], 'ipinfo', false)
                    );
                    $this->render_field_row(
                        __('Primary Service', 'ffc'),
                        $service_field,
                        __('Select which IP geolocation service to use. ip-api.com is free without API key.', 'ffc')
                    );
                    ?>

                    <!-- Cascade/Fallback Between Services -->
                    <?php
                    $cascade_field = sprintf(
                        '<label><input type="checkbox" name="ip_api_cascade" value="1" %s> %s</label>',
                        checked($settings['ip_api_cascade'], true, false),
                        esc_html__('Enable cascade: if primary fails, try the other service', 'ffc')
                    );
                    $this->render_field_row(
                        __('Service Cascade', 'ffc'),
                        $cascade_field,
                        __('When enabled, if the primary service fails, automatically try the alternative service.', 'ffc')
                    );
                    ?>

                    <!-- IPInfo API Key -->
                    <?php
                    $apikey_field = sprintf(
                        '<input type="text" name="ipinfo_api_key" value="%s" class="regular-text" placeholder="Enter your ipinfo.io API key">
                        <p class="description">Get your free API key at <a href="https://ipinfo.io/signup" target="_blank">ipinfo.io/signup</a></p>',
                        esc_attr($settings['ipinfo_api_key'])
                    );
                    $this->render_field_row(
                        __('IPInfo.io API Key', 'ffc'),
                        $apikey_field,
                        __('Required only if using ipinfo.io service. Free tier: 50,000 requests/month.', 'ffc')
                    );
                    ?>

                    <!-- IP Cache Settings -->
                    <?php
                    $cache_field = sprintf(
                        '<label><input type="checkbox" name="ip_cache_enabled" value="1" %s> %s</label>',
                        checked($settings['ip_cache_enabled'], true, false),
                        esc_html__('Cache IP geolocation results to reduce API calls', 'ffc')
                    );
                    $this->render_field_row(
                        __('IP Cache', 'ffc'),
                        $cache_field,
                        __('Recommended. Caches geolocation by IP to avoid repeated API calls.', 'ffc')
                    );
                    ?>

                    <!-- Cache TTL -->
                    <?php
                    $ttl_field = sprintf(
                        '<input type="number" name="ip_cache_ttl" value="%d" min="300" max="3600" step="60"> %s
                        <p class="description">%s</p>',
                        absint($settings['ip_cache_ttl']),
                        esc_html__('seconds', 'ffc'),
                        esc_html__('How long to cache IP location data. Range: 300-3600 seconds (5 min - 1 hour).', 'ffc')
                    );
                    $this->render_field_row(
                        __('Cache Duration (TTL)', 'ffc'),
                        $ttl_field
                    );
                    ?>
                </table>

                <hr>

                <!-- Fallback Behavior Section -->
                <?php $this->render_section_header(
                    __('Fallback Behavior', 'ffc'),
                    __('Define what happens when geolocation services fail or are denied by the user.', 'ffc')
                ); ?>

                <table class="form-table">
                    <!-- API Failure Fallback -->
                    <?php
                    $api_fallback_field = sprintf(
                        '<select name="api_fallback">
                            <option value="allow" %s>%s</option>
                            <option value="block" %s>%s</option>
                            <option value="gps_only" %s>%s</option>
                        </select>',
                        selected($settings['api_fallback'], 'allow', false),
                        esc_html__('Allow access (assume valid)', 'ffc'),
                        selected($settings['api_fallback'], 'block', false),
                        esc_html__('Block access (assume invalid)', 'ffc'),
                        selected($settings['api_fallback'], 'gps_only', false),
                        esc_html__('Use GPS only (ignore IP validation)', 'ffc')
                    );
                    $this->render_field_row(
                        __('When IP API Fails', 'ffc'),
                        $api_fallback_field,
                        __('What to do when IP geolocation API is unavailable or returns error.', 'ffc')
                    );
                    ?>

                    <!-- GPS Failure Fallback -->
                    <?php
                    $gps_fallback_field = sprintf(
                        '<select name="gps_fallback">
                            <option value="allow" %s>%s</option>
                            <option value="block" %s>%s</option>
                        </select>',
                        selected($settings['gps_fallback'], 'allow', false),
                        esc_html__('Allow access', 'ffc'),
                        selected($settings['gps_fallback'], 'block', false),
                        esc_html__('Block access', 'ffc')
                    );
                    $this->render_field_row(
                        __('When GPS Fails', 'ffc'),
                        $gps_fallback_field,
                        __('What to do when user denies GPS permission or browser does not support geolocation.', 'ffc')
                    );
                    ?>

                    <!-- Both Fail Fallback -->
                    <?php
                    $both_fail_field = sprintf(
                        '<select name="both_fail_fallback">
                            <option value="allow" %s>%s</option>
                            <option value="block" %s>%s</option>
                        </select>',
                        selected($settings['both_fail_fallback'], 'allow', false),
                        esc_html__('Allow access (better UX)', 'ffc'),
                        selected($settings['both_fail_fallback'], 'block', false),
                        esc_html__('Block access (better security)', 'ffc')
                    );
                    $this->render_field_row(
                        __('When Both GPS & IP Fail', 'ffc'),
                        $both_fail_field,
                        __('What to do when both GPS and IP geolocation fail (if both are enabled).', 'ffc')
                    );
                    ?>
                </table>

                <hr>

                <!-- Debug Mode Section -->
                <?php $this->render_section_header(
                    __('Debug & Testing', 'ffc'),
                    __('Enable debug mode for testing and troubleshooting geolocation features.', 'ffc')
                ); ?>

                <table class="form-table">
                    <!-- Enable Debug -->
                    <?php
                    $debug_field = sprintf(
                        '<label><input type="checkbox" name="debug_enabled" value="1" %s> %s</label>',
                        checked($settings['debug_enabled'], true, false),
                        esc_html__('Enable geolocation debug mode', 'ffc')
                    );
                    $this->render_field_row(
                        __('Debug Mode', 'ffc'),
                        $debug_field,
                        __('Shows detailed geolocation information in browser console and WordPress debug log.', 'ffc')
                    );
                    ?>

                    <!-- Admin Bypass -->
                    <?php
                    $bypass_field = sprintf(
                        '<label><input type="checkbox" name="debug_admin_bypass" value="1" %s> %s</label>',
                        checked($settings['debug_admin_bypass'], true, false),
                        esc_html__('Administrators bypass geolocation restrictions when debug is enabled', 'ffc')
                    );
                    $this->render_field_row(
                        __('Admin Bypass', 'ffc'),
                        $bypass_field,
                        __('When enabled, logged-in administrators can access all forms regardless of geolocation restrictions.', 'ffc')
                    );
                    ?>
                </table>

                <p class="submit">
                    <button type="submit" name="ffc_save_geolocation" class="button button-primary">
                        <?php esc_html_e('Save Geolocation Settings', 'ffc'); ?>
                    </button>
                </p>
            </form>

            <!-- Information Box -->
            <div class="ffc-info-box" style="background: #f0f6fc; border-left: 4px solid #0073aa; padding: 15px; margin-top: 20px;">
                <h3 style="margin-top: 0;"><?php esc_html_e('How Geolocation Works', 'ffc'); ?></h3>
                <ul style="margin-left: 20px;">
                    <li><strong><?php esc_html_e('GPS (Browser):', 'ffc'); ?></strong> <?php esc_html_e('Uses HTML5 Geolocation API. Requires HTTPS and user permission. Accuracy: 10-50 meters.', 'ffc'); ?></li>
                    <li><strong><?php esc_html_e('IP Geolocation:', 'ffc'); ?></strong> <?php esc_html_e('Detects location by IP address on server. No user permission needed. Accuracy: 1-50 km.', 'ffc'); ?></li>
                    <li><strong><?php esc_html_e('Form Configuration:', 'ffc'); ?></strong> <?php esc_html_e('Each form can be configured individually with allowed areas, dates, and display options.', 'ffc'); ?></li>
                    <li><strong><?php esc_html_e('Privacy:', 'ffc'); ?></strong> <?php esc_html_e('GPS coordinates are processed client-side only. IP geolocation results are cached temporarily.', 'ffc'); ?></li>
                </ul>
            </div>
        </div>

        <style>
            .ffc-settings-tab-content {
                max-width: 1000px;
            }
            .ffc-section-header {
                margin-top: 30px;
                margin-bottom: 20px;
            }
            .ffc-section-header h2 {
                font-size: 20px;
                margin-bottom: 5px;
            }
            .ffc-section-header .description {
                color: #666;
                font-size: 13px;
            }
        </style>
        <?php
    }

    /**
     * Save settings
     */
    private function save_settings() {
        $settings = array(
            'ip_api_enabled' => isset($_POST['ip_api_enabled']),
            'ip_api_service' => in_array($_POST['ip_api_service'] ?? '', array('ip-api', 'ipinfo'))
                ? sanitize_key($_POST['ip_api_service'])
                : 'ip-api',
            'ip_api_cascade' => isset($_POST['ip_api_cascade']),
            'ipinfo_api_key' => sanitize_text_field($_POST['ipinfo_api_key'] ?? ''),
            'ip_cache_enabled' => isset($_POST['ip_cache_enabled']),
            'ip_cache_ttl' => max(300, min(3600, absint($_POST['ip_cache_ttl'] ?? 600))),

            'api_fallback' => in_array($_POST['api_fallback'] ?? '', array('allow', 'block', 'gps_only'))
                ? sanitize_key($_POST['api_fallback'])
                : 'gps_only',
            'gps_fallback' => in_array($_POST['gps_fallback'] ?? '', array('allow', 'block'))
                ? sanitize_key($_POST['gps_fallback'])
                : 'allow',
            'both_fail_fallback' => in_array($_POST['both_fail_fallback'] ?? '', array('allow', 'block'))
                ? sanitize_key($_POST['both_fail_fallback'])
                : 'block',

            'debug_enabled' => isset($_POST['debug_enabled']),
            'debug_admin_bypass' => isset($_POST['debug_admin_bypass']),
        );

        update_option('ffc_geolocation_settings', $settings);

        // Log settings change
        if (class_exists('FFC_Activity_Log')) {
            FFC_Activity_Log::log_settings_changed('geolocation', get_current_user_id());
        }
    }
}
