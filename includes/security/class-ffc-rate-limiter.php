<?php
/**
 * FFC_Rate_Limiter v2.10.0
 * Advanced rate limiting system
 */

if (!defined('ABSPATH')) exit;

class FFC_Rate_Limiter {
    
    private static function get_settings() {
        $defaults = array(
            'ip' => array('enabled' => true, 'max_per_hour' => 5, 'max_per_day' => 20, 'cooldown_seconds' => 60, 'apply_to' => 'all', 'message' => 'Limite atingido. Aguarde {time}.'),
            'email' => array('enabled' => true, 'max_per_day' => 3, 'max_per_week' => 10, 'max_per_month' => 30, 'wait_hours' => 24, 'apply_to' => 'all', 'message' => 'Você já possui {count} certificados.', 'check_database' => true),
            'cpf' => array('enabled' => false, 'max_per_month' => 5, 'max_per_year' => 50, 'block_threshold' => 3, 'block_hours' => 1, 'block_duration' => 24, 'apply_to' => 'all', 'message' => 'Limite de CPF/RF atingido.', 'check_database' => true),
            'global' => array('enabled' => false, 'max_per_minute' => 100, 'max_per_hour' => 1000, 'message' => 'Sistema indisponível.'),
            'whitelist' => array('ips' => array(), 'emails' => array(), 'email_domains' => array(), 'cpfs' => array()),
            'blacklist' => array('ips' => array(), 'emails' => array(), 'email_domains' => array(), 'cpfs' => array()),
            'logging' => array('enabled' => true, 'log_allowed' => false, 'log_blocked' => true, 'retention_days' => 30, 'max_logs' => 10000),
            'ui' => array('show_remaining' => true, 'show_wait_time' => true, 'countdown_timer' => true)
        );
        return wp_parse_args(get_option('ffc_rate_limit_settings', $defaults), $defaults);
    }
    
    public static function check_all($ip, $email = null, $cpf = null, $form_id = null) {
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
    
    public static function check_ip_limit($ip, $form_id = null) {
        $s = self::get_settings()['ip'];
        $hk = 'ffc_rate_ip_' . md5($ip . $form_id) . '_hour';
        $hc = get_transient($hk) ?: 0;
        if ($hc >= $s['max_per_hour']) return array('allowed' => false, 'reason' => 'ip_hour_limit', 'message' => self::format_message($s['message'], array('time' => '1 hora')), 'wait_seconds' => 3600);
        
        $dc = self::get_count_from_db('ip', $ip, 'day', $form_id);
        if ($dc >= $s['max_per_day']) return array('allowed' => false, 'reason' => 'ip_day_limit', 'message' => self::format_message($s['message'], array('time' => '24 horas')), 'wait_seconds' => 86400);
        
        $last = get_transient('ffc_rate_ip_' . md5($ip . $form_id) . '_last');
        if ($last && (time() - $last) < $s['cooldown_seconds']) {
            $w = $s['cooldown_seconds'] - (time() - $last);
            return array('allowed' => false, 'reason' => 'ip_cooldown', 'message' => "Aguarde {$w} segundos.", 'wait_seconds' => $w);
        }
        
        return array('allowed' => true);
    }
    
    public static function check_email_limit($email, $form_id = null) {
        $s = self::get_settings()['email'];
        if (!$s['check_database']) return array('allowed' => true);
        
        $dc = self::get_submission_count('email', $email, 'day', $form_id);
        if ($dc >= $s['max_per_day']) return array('allowed' => false, 'reason' => 'email_day_limit', 'message' => self::format_message($s['message'], array('count' => $dc, 'time' => '24 horas')), 'wait_seconds' => 86400);
        
        $wc = self::get_submission_count('email', $email, 'week', $form_id);
        if ($wc >= $s['max_per_week']) return array('allowed' => false, 'reason' => 'email_week_limit', 'message' => self::format_message($s['message'], array('count' => $wc, 'time' => '1 semana')), 'wait_seconds' => 604800);
        
        $mc = self::get_submission_count('email', $email, 'month', $form_id);
        if ($mc >= $s['max_per_month']) return array('allowed' => false, 'reason' => 'email_month_limit', 'message' => self::format_message($s['message'], array('count' => $mc, 'time' => '1 mês')), 'wait_seconds' => 2592000);
        
        return array('allowed' => true);
    }
    
    public static function check_cpf_limit($cpf, $form_id = null) {
        $s = self::get_settings()['cpf'];
        $cc = preg_replace('/[^0-9]/', '', $cpf);
        
        if (self::is_temporarily_blocked('cpf', $cc, $form_id)) return array('allowed' => false, 'reason' => 'cpf_blocked', 'message' => 'CPF bloqueado.', 'wait_seconds' => 86400);
        
        if ($s['check_database']) {
            $mc = self::get_submission_count('cpf', $cc, 'month', $form_id);
            if ($mc >= $s['max_per_month']) return array('allowed' => false, 'reason' => 'cpf_month_limit', 'message' => $s['message'], 'wait_seconds' => 2592000);
            
            $yc = self::get_submission_count('cpf', $cc, 'year', $form_id);
            if ($yc >= $s['max_per_year']) return array('allowed' => false, 'reason' => 'cpf_year_limit', 'message' => $s['message'], 'wait_seconds' => 31536000);
        }
        
        $ac = self::get_count_from_db('cpf', $cc, 'hour', $form_id);
        if ($ac >= $s['block_threshold']) {
            self::block_temporarily('cpf', $cc, $form_id, $s['block_duration']);
            return array('allowed' => false, 'reason' => 'cpf_abuse', 'message' => 'CPF bloqueado.', 'wait_seconds' => $s['block_duration'] * 3600);
        }
        
        return array('allowed' => true);
    }
    
    public static function check_global_limit() {
        $s = self::get_settings()['global'];
        $mk = 'ffc_rate_global_minute_' . floor(time() / 60);
        $mc = get_transient($mk) ?: 0;
        if ($mc >= $s['max_per_minute']) return array('allowed' => false, 'reason' => 'global_minute_limit', 'message' => $s['message'], 'wait_seconds' => 60);
        
        $hc = self::get_count_from_db('global', 'system', 'hour', null);
        if ($hc >= $s['max_per_hour']) return array('allowed' => false, 'reason' => 'global_hour_limit', 'message' => $s['message'], 'wait_seconds' => 3600);
        
        return array('allowed' => true);
    }
    
    
    /**
     * Check rate limit for verification requests (magic links)
     */
    public static function check_verification($ip, $token = null) {
        $settings = self::get_settings();
        
        if (empty($settings['ip']['enabled'])) {
            return array('allowed' => true);
        }
        
        $max_per_hour = 10;
        $max_per_day = 30;
        
        $hour_key = 'ffc_verify_ip_' . md5($ip) . '_hour_' . date('YmdH');
        $hour_count = get_transient($hour_key);
        
        if ($hour_count === false) {
            $hour_count = 0;
        }
        
        if ($hour_count >= $max_per_hour) {
            $wait_seconds = 3600 - (time() % 3600);
            
            return array(
                'allowed' => false,
                'message' => sprintf(__('Too many verification attempts. Please wait %s.', 'ffc'), self::format_wait_time($wait_seconds)),
                'wait_seconds' => $wait_seconds
            );
        }
        
        $day_key = 'ffc_verify_ip_' . md5($ip) . '_day_' . date('Ymd');
        $day_count = get_transient($day_key);
        
        if ($day_count === false) {
            $day_count = 0;
        }
        
        if ($day_count >= $max_per_day) {
            $wait_seconds = 86400 - (time() % 86400);
            
            return array(
                'allowed' => false,
                'message' => __('Daily verification limit reached. Please try again tomorrow.', 'ffc'),
                'wait_seconds' => $wait_seconds
            );
        }
        
        set_transient($hour_key, $hour_count + 1, 3600);
        set_transient($day_key, $day_count + 1, 86400);
        
        if (!empty($settings['logging']['enabled'])) {
            self::log_attempt('ip', $ip, 'allowed', 'verification_attempt', null);
        }
        
        return array('allowed' => true);
    }
    
    private static function format_wait_time($seconds) {
        if ($seconds < 60) {
            return sprintf(_n('%d second', '%d seconds', $seconds, 'ffc'), $seconds);
        }
        
        $minutes = ceil($seconds / 60);
        if ($minutes < 60) {
            return sprintf(_n('%d minute', '%d minutes', $minutes, 'ffc'), $minutes);
        }
        
        $hours = ceil($minutes / 60);
        return sprintf(_n('%d hour', '%d hours', $hours, 'ffc'), $hours);
    }
    public static function record_attempt($type, $identifier, $form_id = null) {
        $s = self::get_settings();
        
        if ($type === 'ip') {
            $hk = 'ffc_rate_ip_' . md5($identifier . $form_id) . '_hour';
            set_transient($hk, (get_transient($hk) ?: 0) + 1, 3600);
            set_transient('ffc_rate_ip_' . md5($identifier . $form_id) . '_last', time(), $s['ip']['cooldown_seconds']);
        }
        
        if ($type === 'global') {
            $mk = 'ffc_rate_global_minute_' . floor(time() / 60);
            set_transient($mk, (get_transient($mk) ?: 0) + 1, 60);
        }
        
        self::increment_counter($type, $identifier, 'day', $form_id);
        if (in_array($type, array('email', 'cpf'))) {
            self::increment_counter($type, $identifier, 'month', $form_id);
            if ($type === 'cpf') self::increment_counter($type, $identifier, 'hour', $form_id);
        }
    }
    
    private static function get_count_from_db($type, $identifier, $window, $form_id) {
        global $wpdb;
        $t = $wpdb->prefix . 'ffc_rate_limits';
        $ws = self::get_window_start($window);
        $c = $wpdb->get_var($wpdb->prepare("SELECT count FROM $t WHERE type=%s AND identifier=%s AND window_type=%s AND form_id " . ($form_id ? "=$form_id" : 'IS NULL') . " AND window_start>=%s ORDER BY id DESC LIMIT 1", $type, $identifier, $window, $ws));
        return $c ? intval($c) : 0;
    }
    
    private static function increment_counter($type, $identifier, $window, $form_id) {
        global $wpdb;
        $t = $wpdb->prefix . 'ffc_rate_limits';
        $ws = self::get_window_start($window);
        $we = self::get_window_end($window);
        
        $e = $wpdb->get_row($wpdb->prepare("SELECT id,count FROM $t WHERE type=%s AND identifier=%s AND window_type=%s AND form_id " . ($form_id ? "=$form_id" : 'IS NULL') . " AND window_start=%s", $type, $identifier, $window, $ws));
        
        if ($e) {
            $wpdb->update($t, array('count' => $e->count + 1, 'last_attempt' => current_time('mysql')), array('id' => $e->id), array('%d', '%s'), array('%d'));
        } else {
            $wpdb->insert($t, array('type' => $type, 'identifier' => $identifier, 'form_id' => $form_id, 'count' => 1, 'window_type' => $window, 'window_start' => $ws, 'window_end' => $we, 'last_attempt' => current_time('mysql')), array('%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s'));
        }
    }
    
    private static function get_submission_count($field, $value, $period, $form_id) {
        global $wpdb;
        $t = $wpdb->prefix . 'ffc_submissions';
        $dw = $period === 'day' ? "AND submission_date >= DATE_SUB(NOW(), INTERVAL 1 DAY)" : ($period === 'week' ? "AND submission_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)" : "AND submission_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
        $fw = $form_id ? $wpdb->prepare("AND form_id=%d", $form_id) : '';
        
        if ($field === 'email') {
            if (class_exists('FFC_Encryption') && FFC_Encryption::is_configured()) {
                return intval($wpdb->get_var("SELECT COUNT(*) FROM $t WHERE email_hash='" . FFC_Encryption::hash($value) . "' $dw $fw"));
            } else {
                return intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE email=%s $dw $fw", $value)));
            }
        } elseif ($field === 'cpf') {
            if (class_exists('FFC_Encryption') && FFC_Encryption::is_configured()) {
                return intval($wpdb->get_var("SELECT COUNT(*) FROM $t WHERE cpf_rf_hash='" . FFC_Encryption::hash($value) . "' $dw $fw"));
            } else {
                return intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE cpf_rf=%s $dw $fw", $value)));
            }
        }
        
        return 0;
    }
    
    private static function check_blacklist($ip, $email, $cpf) {
        $s = self::get_settings();
        $bl = $s['blacklist'];
        
        if (in_array($ip, $bl['ips'])) return array('allowed' => false, 'reason' => 'ip_blacklisted', 'message' => 'IP bloqueado.');
        
        if ($email) {
            if (in_array($email, $bl['emails'])) return array('allowed' => false, 'reason' => 'email_blacklisted', 'message' => 'Email bloqueado.');
            $d = substr(strrchr($email, '@'), 1);
            if (in_array('*@' . $d, $bl['email_domains'])) return array('allowed' => false, 'reason' => 'domain_blacklisted', 'message' => 'Domínio bloqueado.');
        }
        
        if ($cpf && in_array(preg_replace('/[^0-9]/', '', $cpf), $bl['cpfs'])) return array('allowed' => false, 'reason' => 'cpf_blacklisted', 'message' => 'CPF bloqueado.');
        
        return array('allowed' => true);
    }
    
    private static function is_whitelisted($ip, $email, $cpf) {
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
    
    private static function is_temporarily_blocked($type, $identifier, $form_id) {
        global $wpdb;
        $t = $wpdb->prefix . 'ffc_rate_limits';
        return !empty($wpdb->get_var($wpdb->prepare("SELECT blocked_until FROM $t WHERE type=%s AND identifier=%s AND form_id " . ($form_id ? "=$form_id" : 'IS NULL') . " AND is_blocked=1 AND blocked_until>NOW() ORDER BY id DESC LIMIT 1", $type, $identifier)));
    }
    
    private static function block_temporarily($type, $identifier, $form_id, $hours) {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'ffc_rate_limits', array('type' => $type, 'identifier' => $identifier, 'form_id' => $form_id, 'count' => 999, 'window_type' => 'hour', 'window_start' => current_time('mysql'), 'window_end' => date('Y-m-d H:i:s', strtotime("+$hours hours")), 'last_attempt' => current_time('mysql'), 'is_blocked' => 1, 'blocked_until' => date('Y-m-d H:i:s', strtotime("+$hours hours")), 'blocked_reason' => 'abuse'), array('%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s'));
    }
    
    public static function log_attempt($type, $identifier, $action, $reason, $form_id) {
        $s = self::get_settings();
        if (!$s['logging']['enabled'] || (!$s['logging']['log_allowed'] && $action === 'allowed')) return;
        
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'ffc_rate_limit_logs', array('type' => $type, 'identifier' => $identifier, 'form_id' => $form_id, 'action' => $action, 'reason' => $reason, 'ip_address' => self::get_user_ip(), 'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255), 'current_count' => 0, 'max_allowed' => 0), array('%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%d'));
        
        self::cleanup_old_logs();
    }
    
    private static function cleanup_old_logs() {
        global $wpdb;
        $s = self::get_settings();
        $t = $wpdb->prefix . 'ffc_rate_limit_logs';
        $wpdb->query($wpdb->prepare("DELETE FROM $t WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)", $s['logging']['retention_days']));
        $c = $wpdb->get_var("SELECT COUNT(*) FROM $t");
        if ($c > $s['logging']['max_logs']) $wpdb->query($wpdb->prepare("DELETE FROM $t WHERE id NOT IN (SELECT id FROM (SELECT id FROM $t ORDER BY id DESC LIMIT %d) tmp)", $s['logging']['max_logs']));
    }
    
    public static function get_stats() {
        global $wpdb;
        $lt = $wpdb->prefix . 'ffc_rate_limit_logs';
        return array(
            'today' => $wpdb->get_var("SELECT COUNT(*) FROM $lt WHERE action='blocked' AND DATE(created_at)=CURDATE()"),
            'month' => $wpdb->get_var("SELECT COUNT(*) FROM $lt WHERE action='blocked' AND created_at>=DATE_SUB(NOW(), INTERVAL 30 DAY)"),
            'by_type' => $wpdb->get_results("SELECT type,COUNT(*) as count FROM $lt WHERE action='blocked' AND created_at>=DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY type", ARRAY_A),
            'top_ips' => $wpdb->get_results("SELECT identifier,COUNT(*) as count FROM $lt WHERE type='ip' AND action='blocked' AND created_at>=DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY identifier ORDER BY count DESC LIMIT 10", ARRAY_A)
        );
    }
    
    private static function format_message($template, $data) {
        return str_replace(array('{time}', '{count}', '{max}', '{remaining}'), array($data['time'] ?? '', $data['count'] ?? 0, $data['max'] ?? 0, ($data['max'] ?? 0) - ($data['count'] ?? 0)), $template);
    }
    
    private static function applies_to_form($apply_to, $form_id) {
        return $apply_to === 'all' || (is_array($apply_to) && in_array($form_id, $apply_to));
    }
    
    private static function get_window_start($window) {
        switch ($window) {
            case 'minute': return date('Y-m-d H:i:00');
            case 'hour': return date('Y-m-d H:00:00');
            case 'day': return date('Y-m-d 00:00:00');
            case 'week': return date('Y-m-d 00:00:00', strtotime('monday this week'));
            case 'month': return date('Y-m-01 00:00:00');
            case 'year': return date('Y-01-01 00:00:00');
            default: return date('Y-m-d H:i:s');
        }
    }
    
    private static function get_window_end($window) {
        switch ($window) {
            case 'minute': return date('Y-m-d H:i:59');
            case 'hour': return date('Y-m-d H:59:59');
            case 'day': return date('Y-m-d 23:59:59');
            case 'week': return date('Y-m-d 23:59:59', strtotime('sunday this week'));
            case 'month': return date('Y-m-t 23:59:59');
            case 'year': return date('Y-12-31 23:59:59');
            default: return date('Y-m-d H:i:s', strtotime('+1 hour'));
        }
    }
    
    private static function get_user_ip() {
        foreach (array('HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR') as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = $_SERVER[$key];
                if (strpos($ip, ',') !== false) $ip = trim(explode(',', $ip)[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
            }
        }
        return '0.0.0.0';
    }
    
    public static function cleanup_expired() {
        global $wpdb;
        return $wpdb->query("DELETE FROM " . $wpdb->prefix . "ffc_rate_limits WHERE window_end < NOW()");
    }
}