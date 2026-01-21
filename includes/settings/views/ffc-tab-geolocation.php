<?php
/**
 * Geolocation Settings Tab Template
 *
 * @package FFC
 * @since 3.0.0
 */

if (!defined('ABSPATH')) exit;
?>

<div class="ffc-settings-wrap">

<div class="ffc-settings-tab-content">
    <form method="POST" action="">
        <?php wp_nonce_field('ffc_geolocation_nonce'); ?>

        <!-- IP Geolocation API Section -->
        <div class="card">
            <h2><?php esc_html_e('IP Geolocation API', 'ffc'); ?></h2>
            <p class="description">
                <?php esc_html_e('Configure external IP geolocation services for backend validation. These services detect user location by IP address.', 'ffc'); ?>
            </p>

            <table class="form-table">
                <!-- Enable IP API -->
                <tr>
                    <th scope="row">
                        <label><?php esc_html_e('IP Geolocation', 'ffc'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="ip_api_enabled" value="1" <?php checked($settings['ip_api_enabled'], true); ?>>
                            <?php esc_html_e('Enable IP geolocation API for backend validation', 'ffc'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('When enabled, validates user location by IP address on the server (in addition to GPS).', 'ffc'); ?>
                        </p>
                    </td>
                </tr>

                <!-- API Service Selection -->
                <tr>
                    <th scope="row">
                        <label for="ffc_ip_api_service"><?php esc_html_e('Primary Service', 'ffc'); ?></label>
                    </th>
                    <td>
                        <select name="ip_api_service" id="ffc_ip_api_service">
                            <option value="ip-api" <?php selected($settings['ip_api_service'], 'ip-api'); ?>>
                                ip-api.com (Free, 45 req/min, no key)
                            </option>
                            <option value="ipinfo" <?php selected($settings['ip_api_service'], 'ipinfo'); ?>>
                                ipinfo.io (50k/month free, requires key)
                            </option>
                        </select>
                        <p class="description">
                            <?php esc_html_e('Select which IP geolocation service to use. ip-api.com is free without API key.', 'ffc'); ?>
                        </p>
                    </td>
                </tr>

                <!-- Cascade/Fallback Between Services -->
                <tr>
                    <th scope="row">
                        <label><?php esc_html_e('Service Cascade', 'ffc'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="ip_api_cascade" value="1" <?php checked($settings['ip_api_cascade'], true); ?>>
                            <?php esc_html_e('Enable cascade: if primary fails, try the other service', 'ffc'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('When enabled, if the primary service fails, automatically try the alternative service.', 'ffc'); ?>
                        </p>
                    </td>
                </tr>

                <!-- IPInfo API Key -->
                <tr>
                    <th scope="row">
                        <label for="ffc_ipinfo_api_key"><?php esc_html_e('IPInfo.io API Key', 'ffc'); ?></label>
                    </th>
                    <td>
                        <input type="text"
                               name="ipinfo_api_key"
                               id="ffc_ipinfo_api_key"
                               value="<?php echo esc_attr($settings['ipinfo_api_key']); ?>"
                               class="regular-text"
                               placeholder="<?php esc_attr_e('Enter your ipinfo.io API key', 'ffc'); ?>">
                        <p class="description">
                            <?php esc_html_e('Required only if using ipinfo.io service. Free tier: 50,000 requests/month.', 'ffc'); ?>
                            <a href="https://ipinfo.io/signup" target="_blank"><?php esc_html_e('Get your free API key', 'ffc'); ?></a>
                        </p>
                    </td>
                </tr>

                <!-- IP Cache Settings -->
                <tr>
                    <th scope="row">
                        <label><?php esc_html_e('IP Cache', 'ffc'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="ip_cache_enabled" value="1" <?php checked($settings['ip_cache_enabled'], true); ?>>
                            <?php esc_html_e('Cache IP geolocation results to reduce API calls', 'ffc'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('Recommended. Caches geolocation by IP to avoid repeated API calls.', 'ffc'); ?>
                        </p>
                    </td>
                </tr>

                <!-- Cache TTL -->
                <tr>
                    <th scope="row">
                        <label for="ffc_ip_cache_ttl"><?php esc_html_e('IP Cache Duration (TTL)', 'ffc'); ?></label>
                    </th>
                    <td>
                        <input type="number"
                               name="ip_cache_ttl"
                               id="ffc_ip_cache_ttl"
                               value="<?php echo absint($settings['ip_cache_ttl']); ?>"
                               min="300"
                               max="3600"
                               step="60">
                        <?php esc_html_e('seconds', 'ffc'); ?>
                        <p class="description">
                            <?php esc_html_e('How long to cache IP location data. Range: 300-3600 seconds (5 min - 1 hour).', 'ffc'); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <hr>

        <!-- GPS Cache Settings Section -->
        <div class="card">
            <h2><?php esc_html_e('GPS Cache Settings', 'ffc'); ?></h2>
            <p class="description">
                <?php esc_html_e('Configure GPS location caching on the frontend (browser localStorage). GPS cache is always enabled for better performance.', 'ffc'); ?>
            </p>

            <table class="form-table">
                <!-- GPS Cache TTL -->
                <tr>
                    <th scope="row">
                        <label for="ffc_gps_cache_ttl"><?php esc_html_e('GPS Cache Duration (TTL)', 'ffc'); ?></label>
                    </th>
                    <td>
                        <input type="number"
                               name="gps_cache_ttl"
                               id="ffc_gps_cache_ttl"
                               value="<?php echo absint($settings['gps_cache_ttl']); ?>"
                               min="60"
                               max="3600"
                               step="60">
                        <?php esc_html_e('seconds', 'ffc'); ?>
                        <p class="description">
                            <?php esc_html_e('How long to cache GPS location in browser. Range: 60-3600 seconds (1 min - 1 hour). Default: 600 (10 min).', 'ffc'); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <hr>

        <!-- Fallback Behavior Section -->
        <div class="card">
            <h2><?php esc_html_e('Fallback Behavior', 'ffc'); ?></h2>
            <p class="description">
                <?php esc_html_e('Define what happens when geolocation services fail or are denied by the user.', 'ffc'); ?>
            </p>

            <table class="form-table">
                <!-- API Failure Fallback -->
                <tr>
                    <th scope="row">
                        <label for="ffc_api_fallback"><?php esc_html_e('When IP API Fails', 'ffc'); ?></label>
                    </th>
                    <td>
                        <select name="api_fallback" id="ffc_api_fallback">
                            <option value="allow" <?php selected($settings['api_fallback'], 'allow'); ?>>
                                <?php esc_html_e('Allow access (assume valid)', 'ffc'); ?>
                            </option>
                            <option value="block" <?php selected($settings['api_fallback'], 'block'); ?>>
                                <?php esc_html_e('Block access (assume invalid)', 'ffc'); ?>
                            </option>
                            <option value="gps_only" <?php selected($settings['api_fallback'], 'gps_only'); ?>>
                                <?php esc_html_e('Use GPS only (ignore IP validation)', 'ffc'); ?>
                            </option>
                        </select>
                        <p class="description">
                            <?php esc_html_e('What to do when IP geolocation API is unavailable or returns error.', 'ffc'); ?>
                        </p>
                    </td>
                </tr>

                <!-- GPS Failure Fallback -->
                <tr>
                    <th scope="row">
                        <label for="ffc_gps_fallback"><?php esc_html_e('When GPS Fails', 'ffc'); ?></label>
                    </th>
                    <td>
                        <select name="gps_fallback" id="ffc_gps_fallback">
                            <option value="allow" <?php selected($settings['gps_fallback'], 'allow'); ?>>
                                <?php esc_html_e('Allow access', 'ffc'); ?>
                            </option>
                            <option value="block" <?php selected($settings['gps_fallback'], 'block'); ?>>
                                <?php esc_html_e('Block access', 'ffc'); ?>
                            </option>
                        </select>
                        <p class="description">
                            <?php esc_html_e('What to do when user denies GPS permission or browser does not support geolocation.', 'ffc'); ?>
                        </p>
                    </td>
                </tr>

                <!-- Both Fail Fallback -->
                <tr>
                    <th scope="row">
                        <label for="ffc_both_fail_fallback"><?php esc_html_e('When Both GPS & IP Fail', 'ffc'); ?></label>
                    </th>
                    <td>
                        <select name="both_fail_fallback" id="ffc_both_fail_fallback">
                            <option value="allow" <?php selected($settings['both_fail_fallback'], 'allow'); ?>>
                                <?php esc_html_e('Allow access (better UX)', 'ffc'); ?>
                            </option>
                            <option value="block" <?php selected($settings['both_fail_fallback'], 'block'); ?>>
                                <?php esc_html_e('Block access (better security)', 'ffc'); ?>
                            </option>
                        </select>
                        <p class="description">
                            <?php esc_html_e('What to do when both GPS and IP geolocation fail (if both are enabled).', 'ffc'); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <hr>

        <!-- Admin Bypass Section -->
        <div class="card">
            <h2><?php esc_html_e('Administrator Bypass', 'ffc'); ?></h2>
            <p class="description">
                <?php esc_html_e('Allow administrators to bypass geofence restrictions for testing and content management.', 'ffc'); ?>
            </p>

            <table class="form-table">
                <!-- Bypass Date/Time -->
                <tr>
                    <th scope="row">
                        <label><?php esc_html_e('Bypass Date/Time', 'ffc'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="admin_bypass_datetime" value="1" <?php checked($settings['admin_bypass_datetime'], true); ?>>
                            <?php esc_html_e('Administrators bypass date/time restrictions', 'ffc'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('Logged-in administrators can access forms regardless of date/time configuration. A visual message will appear indicating bypass is active.', 'ffc'); ?>
                        </p>
                    </td>
                </tr>

                <!-- Bypass Geolocation -->
                <tr>
                    <th scope="row">
                        <label><?php esc_html_e('Bypass Geolocation', 'ffc'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="admin_bypass_geo" value="1" <?php checked($settings['admin_bypass_geo'], true); ?>>
                            <?php esc_html_e('Administrators bypass geolocation restrictions', 'ffc'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('Logged-in administrators can access forms regardless of GPS/IP geolocation configuration. A visual message will appear indicating bypass is active.', 'ffc'); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <hr>

        <!-- Debug Mode Section -->
        <div class="card">
            <h2><?php esc_html_e('Debug Mode', 'ffc'); ?></h2>
            <p class="description">
                <?php esc_html_e('Enable debug mode for testing and troubleshooting geolocation features.', 'ffc'); ?>
            </p>

            <table class="form-table">
                <!-- Enable Debug -->
                <tr>
                    <th scope="row">
                        <label><?php esc_html_e('Enable Debug', 'ffc'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="debug_enabled" value="1" <?php checked($settings['debug_enabled'], true); ?>>
                            <?php esc_html_e('Enable geolocation debug mode', 'ffc'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('Shows detailed geolocation information in browser console (F12) for troubleshooting.', 'ffc'); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <p class="submit">
            <button type="submit" name="ffc_save_geolocation" class="button button-primary">
                <?php esc_html_e('Save Geolocation Settings', 'ffc'); ?>
            </button>
        </p>
    </form>

    <!-- Information Box -->
    <div class="card">
        <div class="ffc-info-box">
            <h3><?php esc_html_e('How Geolocation Works', 'ffc'); ?></h3>
            <ul>
                <li>
                    <strong><?php esc_html_e('GPS (Browser):', 'ffc'); ?></strong>
                    <?php esc_html_e('Uses HTML5 Geolocation API. Requires HTTPS and user permission. Accuracy: 10-50 meters.', 'ffc'); ?>
                </li>
                <li>
                    <strong><?php esc_html_e('IP Geolocation:', 'ffc'); ?></strong>
                    <?php esc_html_e('Detects location by IP address on server. No user permission needed. Accuracy: 1-50 km.', 'ffc'); ?>
                </li>
                <li>
                    <strong><?php esc_html_e('Form Configuration:', 'ffc'); ?></strong>
                    <?php esc_html_e('Each form can be configured individually with allowed areas, dates, and display options.', 'ffc'); ?>
                </li>
                <li>
                    <strong><?php esc_html_e('Privacy:', 'ffc'); ?></strong>
                    <?php esc_html_e('GPS coordinates are processed client-side only. IP geolocation results are cached temporarily.', 'ffc'); ?>
                </li>
            </ul>
        </div>
    </div>
</div>