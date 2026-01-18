<?php
/**
 * FFC_Geofence
 *
 * Main geofence validation class
 * Handles date/time and geolocation restrictions for forms
 *
 * @package FFC
 * @since 3.0.0
 */

if (!defined('ABSPATH')) exit;

class FFC_Geofence {

    /**
     * Check if user can access form (complete validation)
     *
     * @param int $form_id Form ID
     * @param array $options Validation options
     * @return array ['allowed' => bool, 'reason' => string, 'message' => string]
     */
    public static function can_access_form($form_id, $options = array()) {
        $defaults = array(
            'check_datetime' => true,
            'check_geo' => false, // GPS validation is frontend-only by default
            'user_location' => null, // For manual location override (testing)
        );

        $options = wp_parse_args($options, $defaults);

        // Get form geofence config
        $config = self::get_form_config($form_id);

        if (empty($config)) {
            // No restrictions configured
            return array(
                'allowed' => true,
                'reason' => 'no_restrictions',
                'message' => ''
            );
        }

        // PRIORITY 1: Date/Time Validation (always server-side)
        if ($options['check_datetime'] && $config['datetime_enabled']) {
            $datetime_check = self::validate_datetime($config);

            if (!$datetime_check['valid']) {
                self::log_access_denied($form_id, 'datetime_invalid', $datetime_check);

                return array(
                    'allowed' => false,
                    'reason' => 'datetime_invalid',
                    'message' => $datetime_check['message']
                );
            }
        }

        // PRIORITY 2: Geolocation Validation (if explicitly requested)
        // Note: GPS validation happens on frontend, IP validation can happen here
        if ($options['check_geo'] && $config['geo_enabled']) {
            $geo_check = self::validate_geolocation($config, $options['user_location']);

            if (!$geo_check['valid']) {
                self::log_access_denied($form_id, 'geolocation_invalid', $geo_check);

                return array(
                    'allowed' => false,
                    'reason' => 'geolocation_invalid',
                    'message' => $geo_check['message']
                );
            }
        }

        // All checks passed
        return array(
            'allowed' => true,
            'reason' => 'validated',
            'message' => ''
        );
    }

    /**
     * Validate date/time restrictions
     *
     * @param array $config Form geofence configuration
     * @return array ['valid' => bool, 'message' => string, 'details' => array]
     */
    public static function validate_datetime($config) {
        $now = current_time('timestamp');
        $current_date = date('Y-m-d', $now);
        $current_time = date('H:i', $now);

        // Check date range
        if (!empty($config['date_start']) && $current_date < $config['date_start']) {
            return array(
                'valid' => false,
                'message' => $config['msg_datetime'] ?? __('This form is not yet available.', 'ffc'),
                'details' => array(
                    'reason' => 'before_start_date',
                    'current_date' => $current_date,
                    'start_date' => $config['date_start']
                )
            );
        }

        if (!empty($config['date_end']) && $current_date > $config['date_end']) {
            return array(
                'valid' => false,
                'message' => $config['msg_datetime'] ?? __('This form is no longer available.', 'ffc'),
                'details' => array(
                    'reason' => 'after_end_date',
                    'current_date' => $current_date,
                    'end_date' => $config['date_end']
                )
            );
        }

        // Check time range (daily)
        if (!empty($config['time_start']) && !empty($config['time_end'])) {
            if ($current_time < $config['time_start'] || $current_time > $config['time_end']) {
                return array(
                    'valid' => false,
                    'message' => $config['msg_datetime'] ?? __('This form is only available during specific hours.', 'ffc'),
                    'details' => array(
                        'reason' => 'outside_time_range',
                        'current_time' => $current_time,
                        'time_start' => $config['time_start'],
                        'time_end' => $config['time_end']
                    )
                );
            }
        }

        return array(
            'valid' => true,
            'message' => '',
            'details' => array()
        );
    }

    /**
     * Validate geolocation restrictions (IP-based backend validation)
     *
     * @param array $config Form geofence configuration
     * @param array|null $user_location Manual location override
     * @return array ['valid' => bool, 'message' => string, 'details' => array]
     */
    public static function validate_geolocation($config, $user_location = null) {
        // Parse areas
        $areas = self::parse_areas($config['geo_areas'] ?? '');

        if (empty($areas)) {
            return array(
                'valid' => true, // No areas defined = no restriction
                'message' => '',
                'details' => array('reason' => 'no_areas_defined')
            );
        }

        // Get user location (IP-based or provided)
        if ($user_location === null && !empty($config['geo_ip_enabled'])) {
            $user_location = FFC_IP_Geolocation::get_location();

            if (is_wp_error($user_location)) {
                // IP API failed - apply fallback
                return self::handle_ip_fallback($config, $user_location);
            }
        }

        if (empty($user_location) || empty($user_location['latitude']) || empty($user_location['longitude'])) {
            return array(
                'valid' => false,
                'message' => $config['msg_geo_error'] ?? __('Unable to determine your location.', 'ffc'),
                'details' => array('reason' => 'location_unavailable')
            );
        }

        // Determine areas to check (IP areas or GPS areas)
        $check_areas = $areas; // Default: same areas

        if (!empty($config['geo_ip_enabled']) && !empty($config['geo_ip_areas_permissive'])) {
            // Use more permissive IP-specific areas if configured
            $ip_areas = self::parse_areas($config['geo_ip_areas'] ?? '');
            if (!empty($ip_areas)) {
                $check_areas = $ip_areas;
            }
        }

        // Check if within allowed areas
        $within = FFC_IP_Geolocation::is_within_areas($user_location, $check_areas, 'or'); // Always OR logic for multiple areas

        if (!$within) {
            return array(
                'valid' => false,
                'message' => $config['msg_geo_blocked'] ?? __('This form is not available in your location.', 'ffc'),
                'details' => array(
                    'reason' => 'outside_allowed_areas',
                    'user_location' => $user_location,
                    'areas_count' => count($check_areas)
                )
            );
        }

        return array(
            'valid' => true,
            'message' => '',
            'details' => array('user_location' => $user_location)
        );
    }

    /**
     * Handle IP geolocation fallback when API fails
     *
     * @param array $config Form configuration
     * @param WP_Error $error Error from IP API
     * @return array Validation result
     */
    private static function handle_ip_fallback($config, $error) {
        $global_settings = get_option('ffc_geolocation_settings', array());
        $fallback = $global_settings['api_fallback'] ?? 'gps_only';

        self::debug_log('IP API failed, applying fallback', array(
            'error' => $error->get_error_message(),
            'fallback' => $fallback
        ));

        switch ($fallback) {
            case 'allow':
                return array(
                    'valid' => true,
                    'message' => '',
                    'details' => array('reason' => 'ip_fallback_allow', 'error' => $error->get_error_message())
                );

            case 'block':
                return array(
                    'valid' => false,
                    'message' => $config['msg_geo_error'] ?? __('Location verification failed.', 'ffc'),
                    'details' => array('reason' => 'ip_fallback_block', 'error' => $error->get_error_message())
                );

            case 'gps_only':
            default:
                // GPS validation happens on frontend, so we can't validate here
                return array(
                    'valid' => true, // Allow through, GPS will validate on frontend
                    'message' => '',
                    'details' => array('reason' => 'ip_fallback_gps', 'note' => 'GPS validation on frontend')
                );
        }
    }

    /**
     * Get form geofence configuration
     *
     * @param int $form_id Form ID
     * @return array|null Configuration array or null if none
     */
    public static function get_form_config($form_id) {
        $config = get_post_meta($form_id, '_ffc_geofence_config', true);

        if (empty($config) || !is_array($config)) {
            return null;
        }

        // Ensure boolean fields are properly typed
        $config['datetime_enabled'] = !empty($config['datetime_enabled']);
        $config['geo_enabled'] = !empty($config['geo_enabled']);
        $config['geo_gps_enabled'] = !empty($config['geo_gps_enabled']);
        $config['geo_ip_enabled'] = !empty($config['geo_ip_enabled']);
        $config['geo_ip_areas_permissive'] = !empty($config['geo_ip_areas_permissive']);

        return $config;
    }

    /**
     * Parse areas from textarea format
     *
     * Format: "lat, lng, radius" (one per line)
     *
     * @param string $areas_text Raw textarea content
     * @return array Array of areas with 'lat', 'lng', 'radius'
     */
    public static function parse_areas($areas_text) {
        if (empty($areas_text)) {
            return array();
        }

        $lines = explode("\n", $areas_text);
        $areas = array();

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            $parts = array_map('trim', explode(',', $line));

            if (count($parts) !== 3) {
                continue; // Invalid format
            }

            $lat = floatval($parts[0]);
            $lng = floatval($parts[1]);
            $radius = floatval($parts[2]);

            // Validate coordinates
            if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || $radius <= 0) {
                continue;
            }

            $areas[] = array(
                'lat' => $lat,
                'lng' => $lng,
                'radius' => $radius
            );
        }

        return $areas;
    }

    /**
     * Check if admin should bypass datetime restrictions
     *
     * @return bool True if current user is admin and bypass is enabled
     */
    public static function should_bypass_datetime() {
        if (!is_user_logged_in() || !current_user_can('manage_options')) {
            return false;
        }

        $settings = get_option('ffc_geolocation_settings', array());
        return !empty($settings['admin_bypass_datetime']);
    }

    /**
     * Check if admin should bypass geolocation restrictions
     *
     * @return bool True if current user is admin and bypass is enabled
     */
    public static function should_bypass_geo() {
        if (!is_user_logged_in() || !current_user_can('manage_options')) {
            return false;
        }

        $settings = get_option('ffc_geolocation_settings', array());
        return !empty($settings['admin_bypass_geo']);
    }

    /**
     * Get frontend configuration for form (JavaScript)
     *
     * @param int $form_id Form ID
     * @return array|null Configuration for frontend or null if no restrictions
     */
    public static function get_frontend_config($form_id) {
        $config = self::get_form_config($form_id);

        if (empty($config)) {
            return null;
        }

        // Check admin bypass for each restriction type
        $bypass_datetime = self::should_bypass_datetime();
        $bypass_geo = self::should_bypass_geo();

        // If both restrictions are bypassed, return special config
        if ($bypass_datetime && $bypass_geo) {
            return array(
                'formId' => $form_id,
                'adminBypass' => true,
                'bypassInfo' => array(
                    'hasDatetime' => $config['datetime_enabled'] == '1',
                    'hasGeo' => $config['geo_enabled'] == '1',
                ),
                'datetime' => array('enabled' => false),
                'geo' => array('enabled' => false),
                'global' => array(
                    'debug' => !empty(get_option('ffc_geolocation_settings')['debug_enabled']),
                ),
            );
        }

        // Build frontend config
        // Check if any bypass is active
        $has_partial_bypass = $bypass_datetime || $bypass_geo;

        $frontend_config = array(
            'formId' => $form_id,
            'adminBypass' => $has_partial_bypass,
            'bypassInfo' => $has_partial_bypass ? array(
                'hasDatetime' => $bypass_datetime && $config['datetime_enabled'] == '1',
                'hasGeo' => $bypass_geo && $config['geo_enabled'] == '1',
            ) : null,
            'datetime' => array(
                'enabled' => !$bypass_datetime && $config['datetime_enabled'] == '1',
                'dateStart' => $config['date_start'] ?? '',
                'dateEnd' => $config['date_end'] ?? '',
                'timeStart' => $config['time_start'] ?? '',
                'timeEnd' => $config['time_end'] ?? '',
                'message' => $config['msg_datetime'] ?? '',
                'hideMode' => $config['datetime_hide_mode'] ?? 'message', // 'hide' or 'message'
            ),
            'geo' => array(
                'enabled' => !$bypass_geo && $config['geo_enabled'] == '1',
                'gpsEnabled' => !$bypass_geo && $config['geo_gps_enabled'] == '1',
                'areas' => self::parse_areas($config['geo_areas'] ?? ''),
                'gpsIpLogic' => $config['geo_gps_ip_logic'] ?? 'or', // 'and' or 'or'
                'messageBlocked' => $config['msg_geo_blocked'] ?? '',
                'messageError' => $config['msg_geo_error'] ?? '',
                'hideMode' => $config['geo_hide_mode'] ?? 'message', // 'hide' or 'message'
                'cacheEnabled' => true, // Always enable frontend cache
                'cacheTtl' => 600, // 10 minutes
            ),
            'global' => array(
                'debug' => !empty(get_option('ffc_geolocation_settings')['debug_enabled']),
            ),
        );

        return $frontend_config;
    }

    /**
     * Log access denied event
     *
     * @param int $form_id Form ID
     * @param string $reason Denial reason
     * @param array $details Additional details
     */
    private static function log_access_denied($form_id, $reason, $details = array()) {
        if (!class_exists('FFC_Activity_Log')) {
            return;
        }

        FFC_Activity_Log::log_access_denied($reason, FFC_Utils::get_user_ip());

        self::debug_log('Access denied', array_merge(array(
            'form_id' => $form_id,
            'reason' => $reason,
        ), $details));
    }

    /**
     * Debug logging
     *
     * @param string $message Log message
     * @param array $context Additional context
     */
    private static function debug_log($message, $context = array()) {
        $settings = get_option('ffc_geolocation_settings', array());

        if (empty($settings['debug_enabled'])) {
            return;
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[FFC Geofence] %s | %s',
                $message,
                !empty($context) ? json_encode($context) : 'no context'
            ));
        }

        if (class_exists('FFC_Activity_Log')) {
            FFC_Activity_Log::log(
                'geofence_debug',
                FFC_Activity_Log::LEVEL_DEBUG,
                array_merge(array('message' => $message), $context)
            );
        }
    }
}
