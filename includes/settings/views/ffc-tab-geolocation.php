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
            <h2><?php esc_html_e('IP Geolocation API', 'wp-ffcertificate'); ?></h2>
            <p class="description">
                <?php esc_html_e('Configure external IP geolocation services for backend validation. These services detect user location by IP address.', 'wp-ffcertificate'); ?>
            </p>

            <table class="form-table" role="presentation"><tbody>
                <!-- Enable IP API -->
                <tr>
                    <th scope="row">
                        <label><?php esc_html_e('IP Geolocation', 'wp-ffcertificate'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="ip_api_enabled" value="1" <?php checked($settings['ip_api_enabled'], true); ?>>
                            <?php esc_html_e('Enable IP geolocation API for backend validation', 'wp-ffcertificate'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('When enabled, validates user location by IP address on the server (in addition to GPS).', 'wp-ffcertificate'); ?>
                        </p>
                    </td>
                </tr>

                <!-- API Service Selection -->
                <tr>
                    <th scope="row">
                        <label for="ffc_ip_api_service"><?php esc_html_e('Primary Service', 'wp-ffcertificate'); ?></label>
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
                            <?php esc_html_e('Select which IP geolocation service to use. ip-api.com is free without API key.', 'wp-ffcertificate'); ?>
                        </p>
                    </td>
                </tr>

                <!-- Cascade/Fallback Between Services -->
                <tr>
                    <th scope="row">
                        <label><?php esc_html_e('Service Cascade', 'wp-ffcertificate'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="ip_api_cascade" value="1" <?php checked($settings['ip_api_cascade'], true); ?>>
                            <?php esc_html_e('Enable cascade: if primary fails, try the other service', 'wp-ffcertificate'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('When enabled, if the primary service fails, automatically try the alternative service.', 'wp-ffcertificate'); ?>
                        </p>
                    </td>
                </tr>

                <!-- IPInfo API Key -->
                <tr>
                    <th scope="row">
                        <label for="ffc_ipinfo_api_key"><?php esc_html_e('IPInfo.io API Key', 'wp-ffcertificate'); ?></label>
                    </th>
                    <td>
                        <input type="text"
                               name="ipinfo_api_key"
                               id="ffc_ipinfo_api_key"
                               value="<?php echo esc_attr($settings['ipinfo_api_key']); ?>"
                               class="regular-text"
                               placeholder="<?php esc_attr_e('Enter your ipinfo.io API key', 'wp-ffcertificate'); ?>">
                        <p class="description">
                            <?php esc_html_e('Required only if using ipinfo.io service. Free tier: 50,000 requests/month.', 'wp-ffcertificate'); ?>
                            <a href="https://ipinfo.io/signup" target="_blank"><?php esc_html_e('Get your free API key', 'wp-ffcertificate'); ?></a>
                        </p>
                    </td>
                </tr>

                <!-- IP Cache Settings -->
                <tr>
                    <th scope="row">
                        <label><?php esc_html_e('IP Cache', 'wp-ffcertificate'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="ip_cache_enabled" value="1" <?php checked($settings['ip_cache_enabled'], true); ?>>
                            <?php esc_html_e('Cache IP geolocation results to reduce API calls', 'wp-ffcertificate'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('Recommended. Caches geolocation by IP to avoid repeated API calls.', 'wp-ffcertificate'); ?>
                        </p>
                    </td>
                </tr>

                <!-- Cache TTL -->
                <tr>
                    <th scope="row">
                        <label for="ffc_ip_cache_ttl"><?php esc_html_e('IP Cache Duration (TTL)', 'wp-ffcertificate'); ?></label>
                    </th>
                    <td>
                        <input type="number"
                               name="ip_cache_ttl"
                               id="ffc_ip_cache_ttl"
                               value="<?php echo absint($settings['ip_cache_ttl']); ?>"
                               min="300"
                               max="3600"
                               step="60">
                        <?php esc_html_e('seconds', 'wp-ffcertificate'); ?>
                        <p class="description">
                            <?php esc_html_e('How long to cache IP location data. Range: 300-3600 seconds (5 min - 1 hour).', 'wp-ffcertificate'); ?>
                        </p>
                    </td>
                </tr>
            </tbody></table>
        </div>

        <hr>

        <!-- GPS Cache Settings Section -->
        <div class="card">
            <h2><?php esc_html_e('GPS Cache Settings', 'wp-ffcertificate'); ?></h2>
            <p class="description">
                <?php esc_html_e('Configure GPS location caching on the frontend (browser localStorage). GPS cache is always enabled for better performance.', 'wp-ffcertificate'); ?>
            </p>

            <table class="form-table" role="presentation"><tbody>
                <!-- GPS Cache TTL -->
                <tr>
                    <th scope="row">
                        <label for="ffc_gps_cache_ttl"><?php esc_html_e('GPS Cache Duration (TTL)', 'wp-ffcertificate'); ?></label>
                    </th>
                    <td>
                        <input type="number"
                               name="gps_cache_ttl"
                               id="ffc_gps_cache_ttl"
                               value="<?php echo absint($settings['gps_cache_ttl']); ?>"
                               min="60"
                               max="3600"
                               step="60">
                        <?php esc_html_e('seconds', 'wp-ffcertificate'); ?>
                        <p class="description">
                            <?php esc_html_e('How long to cache GPS location in browser. Range: 60-3600 seconds (1 min - 1 hour). Default: 600 (10 min).', 'wp-ffcertificate'); ?>
                        </p>
                    </td>
                </tr>
            </tbody></table>
        </div>

        <hr>

        <!-- Fallback Behavior Section -->
        <div class="card">
            <h2><?php esc_html_e('Fallback Behavior', 'wp-ffcertificate'); ?></h2>
            <p class="description">
                <?php esc_html_e('Define what happens when geolocation services fail or are denied by the user.', 'wp-ffcertificate'); ?>
            </p>

            <table class="form-table" role="presentation"><tbody>
                <!-- API Failure Fallback -->
                <tr>
                    <th scope="row">
                        <label for="ffc_api_fallback"><?php esc_html_e('When IP API Fails', 'wp-ffcertificate'); ?></label>
                    </th>
                    <td>
                        <select name="api_fallback" id="ffc_api_fallback">
                            <option value="allow" <?php selected($settings['api_fallback'], 'allow'); ?>>
                                <?php esc_html_e('Allow access (assume valid)', 'wp-ffcertificate'); ?>
                            </option>
                            <option value="block" <?php selected($settings['api_fallback'], 'block'); ?>>
                                <?php esc_html_e('Block access (assume invalid)', 'wp-ffcertificate'); ?>
                            </option>
                            <option value="gps_only" <?php selected($settings['api_fallback'], 'gps_only'); ?>>
                                <?php esc_html_e('Use GPS only (ignore IP validation)', 'wp-ffcertificate'); ?>
                            </option>
                        </select>
                        <p class="description">
                            <?php esc_html_e('What to do when IP geolocation API is unavailable or returns error.', 'wp-ffcertificate'); ?>
                        </p>
                    </td>
                </tr>

                <!-- GPS Failure Fallback -->
                <tr>
                    <th scope="row">
                        <label for="ffc_gps_fallback"><?php esc_html_e('When GPS Fails', 'wp-ffcertificate'); ?></label>
                    </th>
                    <td>
                        <select name="gps_fallback" id="ffc_gps_fallback">
                            <option value="allow" <?php selected($settings['gps_fallback'], 'allow'); ?>>
                                <?php esc_html_e('Allow access', 'wp-ffcertificate'); ?>
                            </option>
                            <option value="block" <?php selected($settings['gps_fallback'], 'block'); ?>>
                                <?php esc_html_e('Block access', 'wp-ffcertificate'); ?>
                            </option>
                        </select>
                        <p class="description">
                            <?php esc_html_e('What to do when user denies GPS permission or browser does not support geolocation.', 'wp-ffcertificate'); ?>
                        </p>
                    </td>
                </tr>

                <!-- Both Fail Fallback -->
                <tr>
                    <th scope="row">
                        <label for="ffc_both_fail_fallback"><?php esc_html_e('When Both GPS & IP Fail', 'wp-ffcertificate'); ?></label>
                    </th>
                    <td>
                        <select name="both_fail_fallback" id="ffc_both_fail_fallback">
                            <option value="allow" <?php selected($settings['both_fail_fallback'], 'allow'); ?>>
                                <?php esc_html_e('Allow access (better UX)', 'wp-ffcertificate'); ?>
                            </option>
                            <option value="block" <?php selected($settings['both_fail_fallback'], 'block'); ?>>
                                <?php esc_html_e('Block access (better security)', 'wp-ffcertificate'); ?>
                            </option>
                        </select>
                        <p class="description">
                            <?php esc_html_e('What to do when both GPS and IP geolocation fail (if both are enabled).', 'wp-ffcertificate'); ?>
                        </p>
                    </td>
                </tr>
            </tbody></table>
        </div>

        <hr>

        <!-- Admin Bypass Section -->
        <div class="card">
            <h2><?php esc_html_e('Administrator Bypass', 'wp-ffcertificate'); ?></h2>
            <p class="description">
                <?php esc_html_e('Allow administrators to bypass geofence restrictions for testing and content management.', 'wp-ffcertificate'); ?>
            </p>

            <table class="form-table" role="presentation"><tbody>
                <!-- Bypass Date/Time -->
                <tr>
                    <th scope="row">
                        <label><?php esc_html_e('Bypass Date/Time', 'wp-ffcertificate'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="admin_bypass_datetime" value="1" <?php checked($settings['admin_bypass_datetime'], true); ?>>
                            <?php esc_html_e('Administrators bypass date/time restrictions', 'wp-ffcertificate'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('Logged-in administrators can access forms regardless of date/time configuration. A visual message will appear indicating bypass is active.', 'wp-ffcertificate'); ?>
                        </p>
                    </td>
                </tr>

                <!-- Bypass Geolocation -->
                <tr>
                    <th scope="row">
                        <label><?php esc_html_e('Bypass Geolocation', 'wp-ffcertificate'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="admin_bypass_geo" value="1" <?php checked($settings['admin_bypass_geo'], true); ?>>
                            <?php esc_html_e('Administrators bypass geolocation restrictions', 'wp-ffcertificate'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('Logged-in administrators can access forms regardless of GPS/IP geolocation configuration. A visual message will appear indicating bypass is active.', 'wp-ffcertificate'); ?>
                        </p>
                    </td>
                </tr>
            </tbody></table>
        </div>

        <hr>

        <!-- Debug Mode Section -->
        <div class="card">
            <h2><?php esc_html_e('Debug Mode', 'wp-ffcertificate'); ?></h2>
            <p class="description">
                <?php esc_html_e('Enable debug mode for testing and troubleshooting geolocation features.', 'wp-ffcertificate'); ?>
            </p>

            <table class="form-table" role="presentation"><tbody>
                <!-- Enable Debug -->
                <tr>
                    <th scope="row">
                        <label><?php esc_html_e('Enable Debug', 'wp-ffcertificate'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="debug_enabled" value="1" <?php checked($settings['debug_enabled'], true); ?>>
                            <?php esc_html_e('Enable geolocation debug mode', 'wp-ffcertificate'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('Shows detailed geolocation information in browser console (F12) for troubleshooting.', 'wp-ffcertificate'); ?>
                        </p>
                    </td>
                </tr>
            </tbody></table>
        </div>

        <p class="submit">
            <button type="submit" name="ffc_save_geolocation" class="button button-primary">
                <?php esc_html_e('Save Geolocation Settings', 'wp-ffcertificate'); ?>
            </button>
        </p>
    </form>

    <!-- Information Box -->
    <div class="card">
        <div class="ffc-info-box">
            <h3><?php esc_html_e('How Geolocation Works', 'wp-ffcertificate'); ?></h3>
            <ul>
                <li>
                    <strong><?php esc_html_e('GPS (Browser):', 'wp-ffcertificate'); ?></strong>
                    <?php esc_html_e('Uses HTML5 Geolocation API. Requires HTTPS and user permission. Accuracy: 10-50 meters.', 'wp-ffcertificate'); ?>
                </li>
                <li>
                    <strong><?php esc_html_e('IP Geolocation:', 'wp-ffcertificate'); ?></strong>
                    <?php esc_html_e('Detects location by IP address on server. No user permission needed. Accuracy: 1-50 km.', 'wp-ffcertificate'); ?>
                </li>
                <li>
                    <strong><?php esc_html_e('Form Configuration:', 'wp-ffcertificate'); ?></strong>
                    <?php esc_html_e('Each form can be configured individually with allowed areas, dates, and display options.', 'wp-ffcertificate'); ?>
                </li>
                <li>
                    <strong><?php esc_html_e('Privacy:', 'wp-ffcertificate'); ?></strong>
                    <?php esc_html_e('GPS coordinates are processed client-side only. IP geolocation results are cached temporarily.', 'wp-ffcertificate'); ?>
                </li>
            </ul>
        </div>
    </div>
</div>