<?php
declare(strict_types=1);

/**
 * RateLimiter v3.3.0
 * Advanced rate limiting system with WordPress Object Cache API
 *
 * v3.3.0: Added strict types and type hints
 * v3.2.0: Migrated to namespace (Phase 2)
 *         Migrated from transients to WordPress Object Cache API
 *         - Automatically uses Redis/Memcached if available (via LiteSpeed Cache, etc.)
 *         - Falls back to transients if no object cache plugin is installed
 *         - Significant performance improvement for high-traffic sites
 */

namespace FreeFormCertificate\Security;

if (!defined('ABSPATH')) exit;

class RateLimiter {

    /**
     * Cache group for WordPress Object Cache API
     *
     * @since 3.2.0
     */
    const CACHE_GROUP = 'ffc_rate_limit';

    /**
     * Cached settings for the current request
     *
     * @since 4.6.13
     * @var array|null
     */
    private static ?array $settings_cache = null;

    private static function get_settings(): array {
        if ( self::$settings_cache !== null ) {
            return self::$settings_cache;
        }
        $defaults = array(
            'ip' => array('enabled' => true, 'max_per_hour' => 5, 'max_per_day' => 20, 'cooldown_seconds' => 60, 'apply_to' => 'all', 'message' => __( 'Limit reached. Please wait {time}.', 'ffcertificate' )),
            'email' => array('enabled' => true, 'max_per_day' => 3, 'max_per_week' => 10, 'max_per_month' => 30, 'wait_hours' => 24, 'apply_to' => 'all', 'message' => __( 'You already have {count} certificates.', 'ffcertificate' ), 'check_database' => true),
            'cpf' => array('enabled' => false, 'max_per_month' => 5, 'max_per_year' => 50, 'block_threshold' => 3, 'block_hours' => 1, 'block_duration' => 24, 'apply_to' => 'all', 'message' => __( 'CPF/RF limit reached.', 'ffcertificate' ), 'check_database' => true),
            'global' => array('enabled' => false, 'max_per_minute' => 100, 'max_per_hour' => 1000, 'message' => __( 'System unavailable.', 'ffcertificate' )),
            'whitelist' => array('ips' => array(), 'emails' => array(), 'email_domains' => array(), 'cpfs' => array()),
            'blacklist' => array('ips' => array(), 'emails' => array(), 'email_domains' => array(), 'cpfs' => array()),
            'logging' => array('enabled' => true, 'log_allowed' => false, 'log_blocked' => true, 'retention_days' => 30, 'max_logs' => 10000),
            'ui' => array('show_remaining' => true, 'show_wait_time' => true, 'countdown_timer' => true)
        );
        self::$settings_cache = wp_parse_args(get_option('ffc_rate_limit_settings', $defaults), $defaults);
        return self::$settings_cache;
    }
    
    public static function check_all(string $ip, ?string $email = null, ?string $cpf = null, ?int $form_id = null): array {
        $s = self::get_settings();
        
        $bl = self::check_blacklist($ip, $email, $cpf);
        if (!$bl['allowed']) { self::log_attempt('blacklist', $ip, 'blocked', $bl['reason'], $form_id); return $bl; }
        
        if (self::is_whitelisted($ip, $email, $cpf)) { self::log_attempt('whitelist', $ip, 'whitelisted', 'In whitelist', $form_id); return array('allowed' => true); }
        
        if ($s['global']['enabled']) {
            $g = self::check_global_limit();
            if (!$g['allowed']) { self::log_attempt('global', 'system', 'blocked', $g['reason'], $form_id); return $g; }
        }
        
        if ($s['ip']['enabled'] && self::applies_to_form($s['ip']['apply_to'], $form_id)) {
            $i = self::check_ip_limit($ip, $form_id);
            if (!$i['allowed']) { self::log_attempt('ip', $ip, 'blocked', $i['reason'], $form_id); return $i; }
        }
        
        if ($email && $s['email']['enabled'] && self::applies_to_form($s['email']['apply_to'], $form_id)) {
            $e = self::check_email_limit($email, $form_id);
            if (!$e['allowed']) { self::log_attempt('email', $email, 'blocked', $e['reason'], $form_id); return $e; }
        }
        
        if ($cpf && $s['cpf']['enabled'] && self::applies_to_form($s['cpf']['apply_to'], $form_id)) {
            $c = self::check_cpf_limit($cpf, $form_id);
            if (!$c['allowed']) { self::log_attempt('cpf', $cpf, 'blocked', $c['reason'], $form_id); return $c; }
        }
        
        if ($s['logging']['log_allowed']) self::log_attempt('ip', $ip, 'allowed', 'Passed', $form_id);
        
        return array('allowed' => true);
    }
    
    public static function check_ip_limit(string $ip, ?int $form_id = null): array {
        $s = self::get_settings()['ip'];
        $hk = 'ffc_rate_ip_' . md5($ip . $form_id) . '_hour';
        // v3.2.0: Use Object Cache API (auto Redis/Memcached if available)
        $hc = wp_cache_get($hk, self::CACHE_GROUP);
        $hc = $hc !== false ? $hc : 0;
        if ($hc >= $s['max_per_hour']) return array('allowed' => false, 'reason' => 'ip_hour_limit', 'message' => self::format_message($s['message'], array('time' => __( '1 hour', 'ffcertificate' ))), 'wait_seconds' => 3600);

        $dc = self::get_count_from_db('ip', $ip, 'day', $form_id);
        if ($dc >= $s['max_per_day']) return array('allowed' => false, 'reason' => 'ip_day_limit', 'message' => self::format_message($s['message'], array('time' => __( '24 hours', 'ffcertificate' ))), 'wait_seconds' => 86400);

        $last = wp_cache_get('ffc_rate_ip_' . md5($ip . $form_id) . '_last', self::CACHE_GROUP);
        if ($last && (time() - $last) < $s['cooldown_seconds']) {
            $w = $s['cooldown_seconds'] - (time() - $last);
            /* translators: %d: number of seconds to wait */
            return array('allowed' => false, 'reason' => 'ip_cooldown', 'message' => sprintf( __( 'Please wait %d seconds.', 'ffcertificate' ), $w ), 'wait_seconds' => $w);
        }

        return array('allowed' => true);
    }
    
    public static function check_email_limit(string $email, ?int $form_id = null): array {
        $s = self::get_settings()['email'];
        if (!$s['check_database']) return array('allowed' => true);
        
        $dc = self::get_submission_count('email', $email, 'day', $form_id);
        if ($dc >= $s['max_per_day']) return array('allowed' => false, 'reason' => 'email_day_limit', 'message' => self::format_message($s['message'], array('count' => $dc, 'time' => __( '24 hours', 'ffcertificate' ))), 'wait_seconds' => 86400);
        
        $wc = self::get_submission_count('email', $email, 'week', $form_id);
        if ($wc >= $s['max_per_week']) return array('allowed' => false, 'reason' => 'email_week_limit', 'message' => self::format_message($s['message'], array('count' => $wc, 'time' => __( '1 week', 'ffcertificate' ))), 'wait_seconds' => 604800);
        
        $mc = self::get_submission_count('email', $email, 'month', $form_id);
        if ($mc >= $s['max_per_month']) return array('allowed' => false, 'reason' => 'email_month_limit', 'message' => self::format_message($s['message'], array('count' => $mc, 'time' => __( '1 month', 'ffcertificate' ))), 'wait_seconds' => 2592000);
        
        return array('allowed' => true);
    }
    
    public static function check_cpf_limit(string $cpf, ?int $form_id = null): array {
        $s = self::get_settings()['cpf'];
        $cc = preg_replace('/[^0-9]/', '', $cpf);
        
        if (self::is_temporarily_blocked('cpf', $cc, $form_id)) return array('allowed' => false, 'reason' => 'cpf_blocked', 'message' => __( 'CPF blocked.', 'ffcertificate' ), 'wait_seconds' => 86400);
        
        if ($s['check_database']) {
            $mc = self::get_submission_count('cpf', $cc, 'month', $form_id);
            if ($mc >= $s['max_per_month']) return array('allowed' => false, 'reason' => 'cpf_month_limit', 'message' => $s['message'], 'wait_seconds' => 2592000);
            
            $yc = self::get_submission_count('cpf', $cc, 'year', $form_id);
            if ($yc >= $s['max_per_year']) return array('allowed' => false, 'reason' => 'cpf_year_limit', 'message' => $s['message'], 'wait_seconds' => 31536000);
        }
        
        $ac = self::get_count_from_db('cpf', $cc, 'hour', $form_id);
        if ($ac >= $s['block_threshold']) {
            self::block_temporarily('cpf', $cc, $form_id, $s['block_duration']);
            return array('allowed' => false, 'reason' => 'cpf_abuse', 'message' => __( 'CPF blocked.', 'ffcertificate' ), 'wait_seconds' => $s['block_duration'] * 3600);
        }
        
        return array('allowed' => true);
    }
    
    public static function check_global_limit(): array {
        $s = self::get_settings()['global'];
        $mk = 'ffc_rate_global_minute_' . floor(time() / 60);
        // v3.2.0: Use Object Cache API
        $mc = wp_cache_get($mk, self::CACHE_GROUP);
        $mc = $mc !== false ? $mc : 0;
        if ($mc >= $s['max_per_minute']) return array('allowed' => false, 'reason' => 'global_minute_limit', 'message' => $s['message'], 'wait_seconds' => 60);

        $hc = self::get_count_from_db('global', 'system', 'hour', null);
        if ($hc >= $s['max_per_hour']) return array('allowed' => false, 'reason' => 'global_hour_limit', 'message' => $s['message'], 'wait_seconds' => 3600);

        return array('allowed' => true);
    }
    
    
    /**
     * Check rate limit for verification requests (magic links)
     */
    public static function check_verification(string $ip, ?string $token = null): array {
        $settings = self::get_settings();
        
        if (empty($settings['ip']['enabled'])) {
            return array('allowed' => true);
        }
        
        $max_per_hour = 10;
        $max_per_day = 30;
        
        $hour_key = 'ffc_verify_ip_' . md5($ip) . '_hour_' . gmdate('YmdH');
        // v3.2.0: Use Object Cache API
        $hour_count = wp_cache_get($hour_key, self::CACHE_GROUP);

        if ($hour_count === false) {
            $hour_count = 0;
        }

        if ($hour_count >= $max_per_hour) {
            $wait_seconds = 3600 - (time() % 3600);

            return array(
                'allowed' => false,
                /* translators: %s: formatted wait time */
                'message' => sprintf(__('Too many verification attempts. Please wait %s.', 'ffcertificate'), self::format_wait_time($wait_seconds)),
                'wait_seconds' => $wait_seconds
            );
        }

        $day_key = 'ffc_verify_ip_' . md5($ip) . '_day_' . gmdate('Ymd');
        $day_count = wp_cache_get($day_key, self::CACHE_GROUP);

        if ($day_count === false) {
            $day_count = 0;
        }

        if ($day_count >= $max_per_day) {
            $wait_seconds = 86400 - (time() % 86400);

            return array(
                'allowed' => false,
                'message' => __('Daily verification limit reached. Please try again tomorrow.', 'ffcertificate'),
                'wait_seconds' => $wait_seconds
            );
        }

        wp_cache_set($hour_key, $hour_count + 1, self::CACHE_GROUP, 3600);
        wp_cache_set($day_key, $day_count + 1, self::CACHE_GROUP, 86400);
        
        if (!empty($settings['logging']['enabled'])) {
            self::log_attempt('ip', $ip, 'allowed', 'verification_attempt', null);
        }
        
        return array('allowed' => true);
    }
    
    private static function format_wait_time(int $seconds): string {
        if ($seconds < 60) {
            /* translators: %d: number of seconds */
            return sprintf(_n('%d second', '%d seconds', $seconds, 'ffcertificate'), $seconds);
        }
        
        $minutes = ceil($seconds / 60);
        if ($minutes < 60) {
            /* translators: %d: number of minutes */
            return sprintf(_n('%d minute', '%d minutes', $minutes, 'ffcertificate'), $minutes);
        }
        
        $hours = ceil($minutes / 60);
        /* translators: %d: number of hours */
        return sprintf(_n('%d hour', '%d hours', $hours, 'ffcertificate'), $hours);
    }
    public static function record_attempt(string $type, string $identifier, ?int $form_id = null): void {
        $s = self::get_settings();

        if ($type === 'ip') {
            $hk = 'ffc_rate_ip_' . md5($identifier . $form_id) . '_hour';
            // v3.2.0: Use Object Cache API for better performance
            $current = wp_cache_get($hk, self::CACHE_GROUP);
            $current = $current !== false ? $current : 0;
            wp_cache_set($hk, $current + 1, self::CACHE_GROUP, 3600);

            $last_key = 'ffc_rate_ip_' . md5($identifier . $form_id) . '_last';
            wp_cache_set($last_key, time(), self::CACHE_GROUP, $s['ip']['cooldown_seconds']);
        }

        if ($type === 'global') {
            $mk = 'ffc_rate_global_minute_' . floor(time() / 60);
            $current = wp_cache_get($mk, self::CACHE_GROUP);
            $current = $current !== false ? $current : 0;
            wp_cache_set($mk, $current + 1, self::CACHE_GROUP, 60);
        }

        self::increment_counter($type, $identifier, 'day', $form_id);
        if (in_array($type, array('email', 'cpf'))) {
            self::increment_counter($type, $identifier, 'month', $form_id);
            if ($type === 'cpf') self::increment_counter($type, $identifier, 'hour', $form_id);
        }
    }
    
    private static function get_count_from_db(string $type, string $identifier, string $window, ?int $form_id): int {
        global $wpdb;
        $t = $wpdb->prefix . 'ffc_rate_limits';
        $ws = self::get_window_start($window);
        $form_clause = $form_id ? $wpdb->prepare( 'AND form_id = %d', $form_id ) : 'AND form_id IS NULL';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $c = $wpdb->get_var( $wpdb->prepare( "SELECT count FROM %i WHERE type=%s AND identifier=%s AND window_type=%s $form_clause AND window_start>=%s ORDER BY id DESC LIMIT 1", $t, $type, $identifier, $window, $ws ) );
        return $c ? intval($c) : 0;
    }
    
    private static function increment_counter(string $type, string $identifier, string $window, ?int $form_id): void {
        global $wpdb;
        $t = $wpdb->prefix . 'ffc_rate_limits';
        $ws = self::get_window_start($window);
        $we = self::get_window_end($window);
        
        $form_clause = $form_id ? $wpdb->prepare( 'AND form_id = %d', $form_id ) : 'AND form_id IS NULL';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $e = $wpdb->get_row( $wpdb->prepare( "SELECT id,count FROM %i WHERE type=%s AND identifier=%s AND window_type=%s $form_clause AND window_start=%s", $t, $type, $identifier, $window, $ws ) );
        
        if ($e) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $wpdb->update($t, array('count' => $e->count + 1, 'last_attempt' => current_time('mysql')), array('id' => $e->id), array('%d', '%s'), array('%d'));
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $wpdb->insert($t, array('type' => $type, 'identifier' => $identifier, 'form_id' => $form_id, 'count' => 1, 'window_type' => $window, 'window_start' => $ws, 'window_end' => $we, 'last_attempt' => current_time('mysql')), array('%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s'));
        }
    }
    
    private static function get_submission_count(string $field, string $value, string $period, ?int $form_id): int {
        global $wpdb;
        $t = $wpdb->prefix . 'ffc_submissions';
        $dw = $period === 'day' ? "AND submission_date >= DATE_SUB(NOW(), INTERVAL 1 DAY)" : ($period === 'week' ? "AND submission_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)" : "AND submission_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
        $fw = $form_id ? $wpdb->prepare("AND form_id=%d", $form_id) : '';
        
        if ($field === 'email') {
            if (class_exists('\FreeFormCertificate\Core\Encryption') && \FreeFormCertificate\Core\Encryption::is_configured()) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
                return intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE email_hash=%s $dw $fw", $t, \FreeFormCertificate\Core\Encryption::hash($value))));
            } else {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
                return intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE email=%s $dw $fw", $t, $value)));
            }
        } elseif ($field === 'cpf') {
            if (class_exists('\FreeFormCertificate\Core\Encryption') && \FreeFormCertificate\Core\Encryption::is_configured()) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
                return intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE cpf_rf_hash=%s $dw $fw", $t, \FreeFormCertificate\Core\Encryption::hash($value))));
            } else {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
                return intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE cpf_rf=%s $dw $fw", $t, $value)));
            }
        }
        
        return 0;
    }
    
    private static function check_blacklist(string $ip, ?string $email, ?string $cpf): array {
        $s = self::get_settings();
        $bl = $s['blacklist'];
        
        if (in_array($ip, $bl['ips'])) return array('allowed' => false, 'reason' => 'ip_blacklisted', 'message' => __( 'IP blocked.', 'ffcertificate' ));
        
        if ($email) {
            if (in_array($email, $bl['emails'])) return array('allowed' => false, 'reason' => 'email_blacklisted', 'message' => __( 'Email blocked.', 'ffcertificate' ));
            $d = substr(strrchr($email, '@'), 1);
            if (in_array('*@' . $d, $bl['email_domains'])) return array('allowed' => false, 'reason' => 'domain_blacklisted', 'message' => __( 'Domain blocked.', 'ffcertificate' ));
        }

        if ($cpf && in_array(preg_replace('/[^0-9]/', '', $cpf), $bl['cpfs'])) return array('allowed' => false, 'reason' => 'cpf_blacklisted', 'message' => __( 'CPF blocked.', 'ffcertificate' ));
        
        return array('allowed' => true);
    }
    
    private static function is_whitelisted(string $ip, ?string $email, ?string $cpf): bool {
        $s = self::get_settings();
        $wl = $s['whitelist'];
        
        if (in_array($ip, $wl['ips'])) return true;
        
        if ($email) {
            if (in_array($email, $wl['emails'])) return true;
            $d = substr(strrchr($email, '@'), 1);
            if (in_array('*@' . $d, $wl['email_domains'])) return true;
        }
        
        if ($cpf && in_array(preg_replace('/[^0-9]/', '', $cpf), $wl['cpfs'])) return true;
        
        return false;
    }
    
    private static function is_temporarily_blocked(string $type, string $identifier, ?int $form_id): bool {
        global $wpdb;
        $t = $wpdb->prefix . 'ffc_rate_limits';
        $form_clause = $form_id ? $wpdb->prepare( 'AND form_id = %d', $form_id ) : 'AND form_id IS NULL';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        return ! empty( $wpdb->get_var( $wpdb->prepare( "SELECT blocked_until FROM %i WHERE type=%s AND identifier=%s $form_clause AND is_blocked=1 AND blocked_until>NOW() ORDER BY id DESC LIMIT 1", $t, $type, $identifier ) ) );
    }
    
    private static function block_temporarily(string $type, string $identifier, ?int $form_id, int $hours): void {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $wpdb->insert($wpdb->prefix . 'ffc_rate_limits', array('type' => $type, 'identifier' => $identifier, 'form_id' => $form_id, 'count' => 999, 'window_type' => 'hour', 'window_start' => current_time('mysql'), 'window_end' => gmdate('Y-m-d H:i:s', strtotime("+$hours hours")), 'last_attempt' => current_time('mysql'), 'is_blocked' => 1, 'blocked_until' => gmdate('Y-m-d H:i:s', strtotime("+$hours hours")), 'blocked_reason' => 'abuse'), array('%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s'));
    }
    
    public static function log_attempt(string $type, string $identifier, string $action, string $reason, ?int $form_id): void {
        $s = self::get_settings();
        if (!$s['logging']['enabled'] || (!$s['logging']['log_allowed'] && $action === 'allowed')) return;
        
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $wpdb->insert($wpdb->prefix . 'ffc_rate_limit_logs', array('type' => $type, 'identifier' => $identifier, 'form_id' => $form_id, 'action' => $action, 'reason' => $reason, 'ip_address' => self::get_user_ip(), 'user_agent' => substr(isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '', 0, 255), 'current_count' => 0, 'max_allowed' => 0), array('%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%d'));
        
        self::cleanup_old_logs();
    }
    
    private static function cleanup_old_logs(): void {
        global $wpdb;
        $s = self::get_settings();
        $t = $wpdb->prefix . 'ffc_rate_limit_logs';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query($wpdb->prepare("DELETE FROM %i WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)", $t, $s['logging']['retention_days']));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $c = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i", $t));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        if ($c > $s['logging']['max_logs']) $wpdb->query($wpdb->prepare("DELETE FROM %i WHERE id NOT IN (SELECT id FROM (SELECT id FROM %i ORDER BY id DESC LIMIT %d) tmp)", $t, $t, $s['logging']['max_logs']));
    }
    
    public static function get_stats(): array {
        global $wpdb;
        $lt = $wpdb->prefix . 'ffc_rate_limit_logs';
        return array(
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            'today' => $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE action='blocked' AND DATE(created_at)=CURDATE()", $lt)),
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            'month' => $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE action='blocked' AND created_at>=DATE_SUB(NOW(), INTERVAL 30 DAY)", $lt)),
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            'by_type' => $wpdb->get_results($wpdb->prepare("SELECT type,COUNT(*) as count FROM %i WHERE action='blocked' AND created_at>=DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY type", $lt), ARRAY_A),
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            'top_ips' => $wpdb->get_results($wpdb->prepare("SELECT identifier,COUNT(*) as count FROM %i WHERE type='ip' AND action='blocked' AND created_at>=DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY identifier ORDER BY count DESC LIMIT 10", $lt), ARRAY_A)
        );
    }
    
    private static function format_message(string $template, array $data): string {
        return str_replace(array('{time}', '{count}', '{max}', '{remaining}'), array($data['time'] ?? '', $data['count'] ?? 0, $data['max'] ?? 0, ($data['max'] ?? 0) - ($data['count'] ?? 0)), $template);
    }
    
    private static function applies_to_form($apply_to, ?int $form_id): bool {
        return $apply_to === 'all' || (is_array($apply_to) && in_array($form_id, $apply_to));
    }
    
    private static function get_window_start(string $window): string {
        switch ($window) {
            case 'minute': return gmdate('Y-m-d H:i:00');
            case 'hour': return gmdate('Y-m-d H:00:00');
            case 'day': return gmdate('Y-m-d 00:00:00');
            case 'week': return gmdate('Y-m-d 00:00:00', strtotime('monday this week'));
            case 'month': return gmdate('Y-m-01 00:00:00');
            case 'year': return gmdate('Y-01-01 00:00:00');
            default: return gmdate('Y-m-d H:i:s');
        }
    }
    
    private static function get_window_end(string $window): string {
        switch ($window) {
            case 'minute': return gmdate('Y-m-d H:i:59');
            case 'hour': return gmdate('Y-m-d H:59:59');
            case 'day': return gmdate('Y-m-d 23:59:59');
            case 'week': return gmdate('Y-m-d 23:59:59', strtotime('sunday this week'));
            case 'month': return gmdate('Y-m-t 23:59:59');
            case 'year': return gmdate('Y-12-31 23:59:59');
            default: return gmdate('Y-m-d H:i:s', strtotime('+1 hour'));
        }
    }
    
    private static function get_user_ip(): string {
        foreach (array('HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR') as $key) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Value unslashed and sanitized on next line.
            if (!empty($_SERVER[$key])) {
                $ip = sanitize_text_field(wp_unslash($_SERVER[$key]));
                if (strpos($ip, ',') !== false) $ip = trim(explode(',', $ip)[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
            }
        }
        return '0.0.0.0';
    }
    
    /**
     * Check rate limit for authenticated user actions (password change, privacy request)
     *
     * @since 4.9.9
     * @param int    $user_id WordPress user ID
     * @param string $action  Action identifier (e.g. 'password_change', 'privacy_request')
     * @param int    $max_per_hour Max attempts per hour (default 5)
     * @param int    $max_per_day  Max attempts per day (default 10)
     * @return array{allowed: bool, message?: string, wait_seconds?: int}
     */
    public static function check_user_limit(int $user_id, string $action = 'default', int $max_per_hour = 5, int $max_per_day = 10): array {
        if ($user_id <= 0) {
            return array('allowed' => true);
        }

        $hour_key = 'ffc_rate_user_' . $user_id . '_' . $action . '_hour_' . gmdate('YmdH');
        $hour_count = wp_cache_get($hour_key, self::CACHE_GROUP);
        $hour_count = $hour_count !== false ? (int) $hour_count : 0;

        if ($hour_count >= $max_per_hour) {
            $wait = 3600 - (time() % 3600);
            return array(
                'allowed' => false,
                /* translators: %s: formatted wait time */
                'message' => sprintf(__('Too many attempts. Please wait %s.', 'ffcertificate'), self::format_wait_time($wait)),
                'wait_seconds' => $wait,
            );
        }

        $day_key = 'ffc_rate_user_' . $user_id . '_' . $action . '_day_' . gmdate('Ymd');
        $day_count = wp_cache_get($day_key, self::CACHE_GROUP);
        $day_count = $day_count !== false ? (int) $day_count : 0;

        if ($day_count >= $max_per_day) {
            return array(
                'allowed' => false,
                'message' => __('Daily limit reached. Please try again tomorrow.', 'ffcertificate'),
                'wait_seconds' => 86400 - (time() % 86400),
            );
        }

        // Increment counters
        wp_cache_set($hour_key, $hour_count + 1, self::CACHE_GROUP, 3600);
        wp_cache_set($day_key, $day_count + 1, self::CACHE_GROUP, 86400);

        return array('allowed' => true);
    }

    public static function cleanup_expired(): int {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return $wpdb->query($wpdb->prepare("DELETE FROM %i WHERE window_end < NOW()", $wpdb->prefix . 'ffc_rate_limits'));
    }
}