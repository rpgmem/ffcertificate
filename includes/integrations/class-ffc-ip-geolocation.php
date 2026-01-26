<?php
declare(strict_types=1);

/**
 * IpGeolocation
 *
 * Handles IP-based geolocation using external APIs
 *
 * Supported services:
 * - ip-api.com (free, 45 req/min, no API key)
 * - ipinfo.io (50k/month free, requires API key)
 *
 * @package FFC
 * @version 3.3.0 - Added strict types and type hints
 * @version 3.2.0 - Migrated to namespace (Phase 2)
 * @since 3.0.0
 */

namespace FreeFormCertificate\Integrations;

if (!defined('ABSPATH')) exit;

class IpGeolocation {

    /**
     * Get location data by IP address
     *
     * @param string|null $ip IP address (optional, defaults to current user IP)
     * @param bool $use_cache Whether to use cached results
     * @return array|WP_Error Array with location data or WP_Error on failure
     */
    public static function get_location( ?string $ip = null, bool $use_cache = true ) {
        // Get settings
        $settings = get_option('ffc_geolocation_settings', array());

        if (empty($settings['ip_api_enabled'])) {
            return new WP_Error('ip_api_disabled', __('IP Geolocation API is disabled.', 'ffc'));
        }

        // Get user IP if not provided
        if (empty($ip)) {
            $ip = FFC_Utils::get_user_ip();
        }

        // Validate IP
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return new WP_Error('invalid_ip', __('Invalid IP address for geolocation.', 'ffc'));
        }

        // Check cache
        if ($use_cache && !empty($settings['ip_cache_enabled'])) {
            $cached = self::get_cached_location($ip);
            if ($cached !== false) {
                self::debug_log('IP location from cache', array('ip' => $ip, 'location' => $cached));
                return $cached;
            }
        }

        // Try primary service
        $primary_service = $settings['ip_api_service'] ?? 'ip-api';
        $location = self::fetch_from_service($ip, $primary_service, $settings);

        // If primary failed and cascade is enabled, try alternative
        if (is_wp_error($location) && !empty($settings['ip_api_cascade'])) {
            $alternative_service = ($primary_service === 'ip-api') ? 'ipinfo' : 'ip-api';
            self::debug_log('Primary service failed, trying alternative', array(
                'primary' => $primary_service,
                'alternative' => $alternative_service,
                'error' => $location->get_error_message()
            ));
            $location = self::fetch_from_service($ip, $alternative_service, $settings);
        }

        // Cache successful result
        if (!is_wp_error($location) && $use_cache && !empty($settings['ip_cache_enabled'])) {
            self::cache_location($ip, $location, $settings['ip_cache_ttl'] ?? 600);
        }

        return $location;
    }

    /**
     * Fetch location from specific service
     *
     * @param string $ip IP address
     * @param string $service Service name ('ip-api' or 'ipinfo')
     * @param array $settings Plugin settings
     * @return array|WP_Error Location data or error
     */
    private static function fetch_from_service( string $ip, string $service, array $settings ) {
        self::debug_log('Fetching from service', array('service' => $service, 'ip' => $ip));

        if ($service === 'ip-api') {
            return self::fetch_from_ipapi($ip);
        } elseif ($service === 'ipinfo') {
            $api_key = $settings['ipinfo_api_key'] ?? '';
            return self::fetch_from_ipinfo($ip, $api_key);
        }

        return new WP_Error('unknown_service', __('Unknown geolocation service.', 'ffc'));
    }

    /**
     * Fetch location from ip-api.com
     *
     * @param string $ip IP address
     * @return array|WP_Error
     */
    private static function fetch_from_ipapi( string $ip ) {
        $url = sprintf('http://ip-api.com/json/%s?fields=status,message,country,countryCode,region,regionName,city,lat,lon', $ip);

        $response = wp_remote_get($url, array(
            'timeout' => 5,
            'sslverify' => false, // ip-api uses HTTP
        ));

        if (is_wp_error($response)) {
            self::debug_log('ip-api request failed', array('error' => $response->get_error_message()));
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (empty($data) || !is_array($data)) {
            return new WP_Error('invalid_response', __('Invalid response from ip-api.com', 'ffc'));
        }

        if ($data['status'] !== 'success') {
            return new WP_Error('api_error', $data['message'] ?? __('Unknown error from ip-api.com', 'ffc'));
        }

        // Normalize response format
        $location = array(
            'ip' => $ip,
            'country' => $data['country'] ?? '',
            'country_code' => $data['countryCode'] ?? '',
            'region' => $data['regionName'] ?? '',
            'region_code' => $data['region'] ?? '',
            'city' => $data['city'] ?? '',
            'latitude' => floatval($data['lat'] ?? 0),
            'longitude' => floatval($data['lon'] ?? 0),
            'service' => 'ip-api',
            'timestamp' => current_time('timestamp'),
        );

        self::debug_log('ip-api success', $location);
        return $location;
    }

    /**
     * Fetch location from ipinfo.io
     *
     * @param string $ip IP address
     * @param string $api_key API key (optional for free tier)
     * @return array|WP_Error
     */
    private static function fetch_from_ipinfo( string $ip, string $api_key = '' ) {
        $url = sprintf('https://ipinfo.io/%s/json', $ip);

        if (!empty($api_key)) {
            $url .= '?token=' . urlencode($api_key);
        }

        $response = wp_remote_get($url, array(
            'timeout' => 5,
            'sslverify' => true,
        ));

        if (is_wp_error($response)) {
            self::debug_log('ipinfo request failed', array('error' => $response->get_error_message()));
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (empty($data) || !is_array($data)) {
            return new WP_Error('invalid_response', __('Invalid response from ipinfo.io', 'ffc'));
        }

        // Check for API errors
        if (isset($data['error'])) {
            return new WP_Error('api_error', $data['error']['title'] ?? __('Unknown error from ipinfo.io', 'ffc'));
        }

        // Parse location coordinates
        $loc_parts = explode(',', $data['loc'] ?? '0,0');
        $latitude = floatval($loc_parts[0] ?? 0);
        $longitude = floatval($loc_parts[1] ?? 0);

        // Normalize response format
        $location = array(
            'ip' => $ip,
            'country' => $data['country'] ?? '',
            'country_code' => $data['country'] ?? '',
            'region' => $data['region'] ?? '',
            'region_code' => $data['region'] ?? '',
            'city' => $data['city'] ?? '',
            'latitude' => $latitude,
            'longitude' => $longitude,
            'service' => 'ipinfo',
            'timestamp' => current_time('timestamp'),
        );

        self::debug_log('ipinfo success', $location);
        return $location;
    }

    /**
     * Get cached location for IP
     *
     * @param string $ip IP address
     * @return array|false Location data or false if not cached
     */
    private static function get_cached_location( string $ip ) {
        $cache_key = 'ffc_ip_geo_' . md5($ip);
        return get_transient($cache_key);
    }

    /**
     * Cache location for IP
     *
     * @param string $ip IP address
     * @param array $location Location data
     * @param int $ttl Cache duration in seconds
     * @return bool Success
     */
    private static function cache_location( string $ip, array $location, int $ttl = 600 ): bool {
        $cache_key = 'ffc_ip_geo_' . md5($ip);
        return set_transient($cache_key, $location, absint($ttl));
    }

    /**
     * Calculate distance between two coordinates using Haversine formula
     *
     * @param float $lat1 Latitude of point 1
     * @param float $lon1 Longitude of point 1
     * @param float $lat2 Latitude of point 2
     * @param float $lon2 Longitude of point 2
     * @return float Distance in meters
     */
    public static function calculate_distance( float $lat1, float $lon1, float $lat2, float $lon2 ): float {
        $earth_radius = 6371000; // Earth radius in meters

        $lat1 = deg2rad($lat1);
        $lon1 = deg2rad($lon1);
        $lat2 = deg2rad($lat2);
        $lon2 = deg2rad($lon2);

        $dlat = $lat2 - $lat1;
        $dlon = $lon2 - $lon1;

        $a = sin($dlat / 2) * sin($dlat / 2) +
             cos($lat1) * cos($lat2) *
             sin($dlon / 2) * sin($dlon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        $distance = $earth_radius * $c;

        return $distance;
    }

    /**
     * Check if location is within allowed areas
     *
     * @param array $location Location data (must have 'latitude' and 'longitude')
     * @param array $areas Array of areas (each with 'lat', 'lng', 'radius')
     * @param string $logic 'or' or 'and' (default: 'or')
     * @return bool True if location is within allowed areas
     */
    public static function is_within_areas( array $location, array $areas, string $logic = 'or' ): bool {
        if (empty($location['latitude']) || empty($location['longitude'])) {
            return false;
        }

        if (empty($areas) || !is_array($areas)) {
            return false;
        }

        $matches = array();

        foreach ($areas as $area) {
            $distance = self::calculate_distance(
                $location['latitude'],
                $location['longitude'],
                $area['lat'],
                $area['lng']
            );

            $within = ($distance <= $area['radius']);
            $matches[] = $within;

            self::debug_log('Area check', array(
                'user_location' => array('lat' => $location['latitude'], 'lng' => $location['longitude']),
                'area_center' => array('lat' => $area['lat'], 'lng' => $area['lng']),
                'radius_km' => $area['radius'],
                'distance_km' => round($distance, 2),
                'within' => $within
            ));
        }

        // Apply logic
        if ($logic === 'and') {
            return !in_array(false, $matches, true); // All must match
        } else {
            return in_array(true, $matches, true); // At least one must match
        }
    }

    /**
     * Debug logging
     *
     * @param string $message Log message
     * @param array $context Additional context
     */
    private static function debug_log( string $message, array $context = array() ): void {
        $settings = get_option('ffc_geolocation_settings', array());

        if (empty($settings['debug_enabled'])) {
            return;
        }

        // Log via centralized debug system
        if (class_exists('FFC_Debug')) {
            FFC_Debug::log_geofence($message, $context);
        }

        // Log to activity log
        if (class_exists('FFC_Activity_Log')) {
            FFC_Activity_Log::log(
                'ip_geolocation_debug',
                FFC_Activity_Log::LEVEL_DEBUG,
                array_merge(array('message' => $message), $context)
            );
        }
    }

    /**
     * Clear IP geolocation cache
     *
     * @param string|null $ip Specific IP to clear, or null to clear all
     * @return int Number of entries cleared
     */
    public static function clear_cache( ?string $ip = null ): int {
        global $wpdb;

        if ($ip !== null) {
            // Clear specific IP
            $cache_key = 'ffc_ip_geo_' . md5($ip);
            delete_transient($cache_key);
            return 1;
        } else {
            // Clear all IP geolocation transients
            $sql = "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_ffc_ip_geo_%' OR option_name LIKE '_transient_timeout_ffc_ip_geo_%'";
            return $wpdb->query($sql);
        }
    }
}
