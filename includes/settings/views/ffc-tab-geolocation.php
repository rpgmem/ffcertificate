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

        <!-- Default Geofencing Areas -->
        <div class="card">
            <h2><?php esc_html_e('Default Geofencing Areas', 'ffcertificate'); ?></h2>
            <p class="description">
                <?php esc_html_e('Define default geographic areas used when creating new forms with geolocation restrictions.', 'ffcertificate'); ?>
            </p>

            <table class="form-table" role="presentation"><tbody>
                <tr>
                    <th scope="row">
                        <label for="main_geo_areas"><?php esc_html_e('Address Georeferencing', 'ffcertificate'); ?></label>
                    </th>
                    <td>
                        <?php
                        $ffcertificate_ffc_settings = get_option('ffc_settings', array());
                        $ffcertificate_main_geo_areas = isset($ffcertificate_ffc_settings['main_geo_areas']) ? $ffcertificate_ffc_settings['main_geo_areas'] : '';
                        ?>
                        <textarea name="main_geo_areas" id="main_geo_areas" rows="4" class="large-text" placeholder="-23.5505, -46.6333, 5000"><?php echo esc_textarea($ffcertificate_main_geo_areas); ?></textarea>
                        <p class="description">
                            <?php esc_html_e('Format: latitude, longitude, radius (meters) — one per line.', 'ffcertificate'); ?><br>
                            <?php esc_html_e('Example: -23.5505, -46.6333, 5000', 'ffcertificate'); ?><br>
                            <span class="ffc-text-info ffc-icon-info"><?php esc_html_e('Used as default geofencing area when creating new forms.', 'ffcertificate'); ?></span>
                        </p>
                    </td>
                </tr>
            </tbody></table>
        </div>

        <hr>

        <!-- IP Geolocation API Section -->
        <div class="card">
            <h2><?php esc_html_e('IP Geolocation API', 'ffcertificate'); ?></h2>
            <p class="description">
                <?php esc_html_e('Configure external IP geolocation services for backend validation. These services detect user location by IP address.', 'ffcertificate'); ?>
            </p>

            <table class="form-table" role="presentation"><tbody>
                <!-- Enable IP API -->
                <tr>
                    <th scope="row">
                        <label><?php esc_html_e('IP Geolocation', 'ffcertificate'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="ip_api_enabled" value="1" <?php checked($settings['ip_api_enabled'], true); ?>>
                            <?php esc_html_e('Enable IP geolocation API for backend validation', 'ffcertificate'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('When enabled, validates user location by IP address on the server (in addition to GPS).', 'ffcertificate'); ?>
                        </p>
                    </td>
                </tr>

                <!-- API Service Selection -->
                <tr>
                    <th scope="row">
                        <label for="ffc_ip_api_service"><?php esc_html_e('Primary Service', 'ffcertificate'); ?></label>
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
                            <?php esc_html_e('Select which IP geolocation service to use. ip-api.com is free without API key.', 'ffcertificate'); ?>
                        </p>
                    </td>
                </tr>

                <!-- Cascade/Fallback Between Services -->
                <tr>
                    <th scope="row">
                        <label><?php esc_html_e('Service Cascade', 'ffcertificate'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="ip_api_cascade" value="1" <?php checked($settings['ip_api_cascade'], true); ?>>
                            <?php esc_html_e('Enable cascade: if primary fails, try the other service', 'ffcertificate'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('When enabled, if the primary service fails, automatically try the alternative service.', 'ffcertificate'); ?>
                        </p>
                    </td>
                </tr>

                <!-- IPInfo API Key -->
                <tr>
                    <th scope="row">
                        <label for="ffc_ipinfo_api_key"><?php esc_html_e('IPInfo.io API Key', 'ffcertificate'); ?></label>
                    </th>
                    <td>
                        <input type="text"
                               name="ipinfo_api_key"
                               id="ffc_ipinfo_api_key"
                               value="<?php echo esc_attr($settings['ipinfo_api_key']); ?>"
                               class="regular-text"
                               placeholder="<?php esc_attr_e('Enter your ipinfo.io API key', 'ffcertificate'); ?>">
                        <p class="description">
                            <?php esc_html_e('Required only if using ipinfo.io service. Free tier: 50,000 requests/month.', 'ffcertificate'); ?>
                            <a href="https://ipinfo.io/signup" target="_blank"><?php esc_html_e('Get your free API key', 'ffcertificate'); ?></a>
                        </p>
                    </td>
                </tr>

                <!-- IP Cache Settings -->
                <tr>
                    <th scope="row">
                        <label><?php esc_html_e('IP Cache', 'ffcertificate'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="ip_cache_enabled" value="1" <?php checked($settings['ip_cache_enabled'], true); ?>>
                            <?php esc_html_e('Cache IP geolocation results to reduce API calls', 'ffcertificate'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('Recommended. Caches geolocation by IP to avoid repeated API calls.', 'ffcertificate'); ?>
                        </p>
                    </td>
                </tr>

                <!-- Cache TTL -->
                <tr>
                    <th scope="row">
                        <label for="ffc_ip_cache_ttl"><?php esc_html_e('IP Cache Duration (TTL)', 'ffcertificate'); ?></label>
                    </th>
                    <td>
                        <input type="number"
                               name="ip_cache_ttl"
                               id="ffc_ip_cache_ttl"
                               value="<?php echo absint($settings['ip_cache_ttl']); ?>"
                               min="300"
                               max="3600"
                               step="60">
                        <?php esc_html_e('seconds', 'ffcertificate'); ?>
                        <p class="description">
                            <?php esc_html_e('How long to cache IP location data. Range: 300-3600 seconds (5 min - 1 hour).', 'ffcertificate'); ?>
                        </p>
                    </td>
                </tr>
            </tbody></table>
        </div>

        <hr>

        <!-- GPS Cache Settings Section -->
        <div class="card">
            <h2><?php esc_html_e('GPS Cache Settings', 'ffcertificate'); ?></h2>
            <p class="description">
                <?php esc_html_e('Configure GPS location caching on the frontend (browser localStorage). GPS cache is always enabled for better performance.', 'ffcertificate'); ?>
            </p>

            <table class="form-table" role="presentation"><tbody>
                <!-- GPS Cache TTL -->
                <tr>
                    <th scope="row">
                        <label for="ffc_gps_cache_ttl"><?php esc_html_e('GPS Cache Duration (TTL)', 'ffcertificate'); ?></label>
                    </th>
                    <td>
                        <input type="number"
                               name="gps_cache_ttl"
                               id="ffc_gps_cache_ttl"
                               value="<?php echo absint($settings['gps_cache_ttl']); ?>"
                               min="60"
                               max="3600"
                               step="60">
                        <?php esc_html_e('seconds', 'ffcertificate'); ?>
                        <p class="description">
                            <?php esc_html_e('How long to cache GPS location in browser. Range: 60-3600 seconds (1 min - 1 hour). Default: 600 (10 min).', 'ffcertificate'); ?>
                        </p>
                    </td>
                </tr>
            </tbody></table>
        </div>

        <hr>

        <!-- Fallback Behavior Section -->
        <div class="card">
            <h2><?php esc_html_e('Fallback Behavior', 'ffcertificate'); ?></h2>
            <p class="description">
                <?php esc_html_e('Define what happens when geolocation services fail or are denied by the user.', 'ffcertificate'); ?>
            </p>

            <table class="form-table" role="presentation"><tbody>
                <!-- API Failure Fallback -->
                <tr>
                    <th scope="row">
                        <label for="ffc_api_fallback"><?php esc_html_e('When IP API Fails', 'ffcertificate'); ?></label>
                    </th>
                    <td>
                        <select name="api_fallback" id="ffc_api_fallback">
                            <option value="allow" <?php selected($settings['api_fallback'], 'allow'); ?>>
                                <?php esc_html_e('Allow access (assume valid)', 'ffcertificate'); ?>
                            </option>
                            <option value="block" <?php selected($settings['api_fallback'], 'block'); ?>>
                                <?php esc_html_e('Block access (assume invalid)', 'ffcertificate'); ?>
                            </option>
                            <option value="gps_only" <?php selected($settings['api_fallback'], 'gps_only'); ?>>
                                <?php esc_html_e('Use GPS only (ignore IP validation)', 'ffcertificate'); ?>
                            </option>
                        </select>
                        <p class="description">
                            <?php esc_html_e('What to do when IP geolocation API is unavailable or returns error.', 'ffcertificate'); ?>
                        </p>
                    </td>
                </tr>

                <!-- GPS Failure Fallback -->
                <tr>
                    <th scope="row">
                        <label for="ffc_gps_fallback"><?php esc_html_e('When GPS Fails', 'ffcertificate'); ?></label>
                    </th>
                    <td>
                        <select name="gps_fallback" id="ffc_gps_fallback">
                            <option value="allow" <?php selected($settings['gps_fallback'], 'allow'); ?>>
                                <?php esc_html_e('Allow access', 'ffcertificate'); ?>
                            </option>
                            <option value="block" <?php selected($settings['gps_fallback'], 'block'); ?>>
                                <?php esc_html_e('Block access', 'ffcertificate'); ?>
                            </option>
                        </select>
                        <p class="description">
                            <?php esc_html_e('What to do when user denies GPS permission or browser does not support geolocation.', 'ffcertificate'); ?>
                        </p>
                    </td>
                </tr>

                <!-- Both Fail Fallback -->
                <tr>
                    <th scope="row">
                        <label for="ffc_both_fail_fallback"><?php esc_html_e('When Both GPS & IP Fail', 'ffcertificate'); ?></label>
                    </th>
                    <td>
                        <select name="both_fail_fallback" id="ffc_both_fail_fallback">
                            <option value="allow" <?php selected($settings['both_fail_fallback'], 'allow'); ?>>
                                <?php esc_html_e('Allow access (better UX)', 'ffcertificate'); ?>
                            </option>
                            <option value="block" <?php selected($settings['both_fail_fallback'], 'block'); ?>>
                                <?php esc_html_e('Block access (better security)', 'ffcertificate'); ?>
                            </option>
                        </select>
                        <p class="description">
                            <?php esc_html_e('What to do when both GPS and IP geolocation fail (if both are enabled).', 'ffcertificate'); ?>
                        </p>
                    </td>
                </tr>
            </tbody></table>
        </div>

        <hr>

        <!-- Admin Bypass Section -->
        <div class="card">
            <h2><?php esc_html_e('Administrator Bypass', 'ffcertificate'); ?></h2>
            <p class="description">
                <?php esc_html_e('Allow administrators to bypass geofence restrictions for testing and content management.', 'ffcertificate'); ?>
            </p>

            <table class="form-table" role="presentation"><tbody>
                <!-- Bypass Date/Time -->
                <tr>
                    <th scope="row">
                        <label><?php esc_html_e('Bypass Date/Time', 'ffcertificate'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="admin_bypass_datetime" value="1" <?php checked($settings['admin_bypass_datetime'], true); ?>>
                            <?php esc_html_e('Administrators bypass date/time restrictions', 'ffcertificate'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('Logged-in administrators can access forms regardless of date/time configuration. A visual message will appear indicating bypass is active.', 'ffcertificate'); ?>
                        </p>
                    </td>
                </tr>

                <!-- Bypass Geolocation -->
                <tr>
                    <th scope="row">
                        <label><?php esc_html_e('Bypass Geolocation', 'ffcertificate'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="admin_bypass_geo" value="1" <?php checked($settings['admin_bypass_geo'], true); ?>>
                            <?php esc_html_e('Administrators bypass geolocation restrictions', 'ffcertificate'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('Logged-in administrators can access forms regardless of GPS/IP geolocation configuration. A visual message will appear indicating bypass is active.', 'ffcertificate'); ?>
                        </p>
                    </td>
                </tr>
            </tbody></table>
        </div>

        <hr>

        <!-- Debug Mode Section -->
        <div class="card">
            <h2><?php esc_html_e('Debug Mode', 'ffcertificate'); ?></h2>
            <p class="description">
                <?php esc_html_e('Enable debug mode for testing and troubleshooting geolocation features.', 'ffcertificate'); ?>
            </p>

            <table class="form-table" role="presentation"><tbody>
                <!-- Enable Debug -->
                <tr>
                    <th scope="row">
                        <label><?php esc_html_e('Enable Debug', 'ffcertificate'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="debug_enabled" value="1" <?php checked($settings['debug_enabled'], true); ?>>
                            <?php esc_html_e('Enable geolocation debug mode', 'ffcertificate'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('Shows detailed geolocation information in browser console (F12) for troubleshooting.', 'ffcertificate'); ?>
                        </p>
                    </td>
                </tr>
            </tbody></table>
        </div>

        <p class="submit">
            <button type="submit" name="ffc_save_geolocation" class="button button-primary">
                <?php esc_html_e('Save Geolocation Settings', 'ffcertificate'); ?>
            </button>
        </p>
    </form>

    <!-- Information Box -->
    <div class="card">
        <div class="ffc-info-box">
            <h3><?php esc_html_e('How Geolocation Works', 'ffcertificate'); ?></h3>
            <ul>
                <li>
                    <strong><?php esc_html_e('GPS (Browser):', 'ffcertificate'); ?></strong>
                    <?php esc_html_e('Uses HTML5 Geolocation API. Requires HTTPS and user permission. Accuracy: 10-50 meters.', 'ffcertificate'); ?>
                </li>
                <li>
                    <strong><?php esc_html_e('IP Geolocation:', 'ffcertificate'); ?></strong>
                    <?php esc_html_e('Detects location by IP address on server. No user permission needed. Accuracy: 1-50 km.', 'ffcertificate'); ?>
                </li>
                <li>
                    <strong><?php esc_html_e('Form Configuration:', 'ffcertificate'); ?></strong>
                    <?php esc_html_e('Each form can be configured individually with allowed areas, dates, and display options.', 'ffcertificate'); ?>
                </li>
                <li>
                    <strong><?php esc_html_e('Privacy:', 'ffcertificate'); ?></strong>
                    <?php esc_html_e('GPS coordinates are processed client-side only. IP geolocation results are cached temporarily.', 'ffcertificate'); ?>
                </li>
            </ul>
        </div>
    </div>
</div>