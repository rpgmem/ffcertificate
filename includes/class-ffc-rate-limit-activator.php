<?php
/**
 * FFC_Rate_Limit_Activator v3.0.0
 * Creates database tables - dbDelta compatible
 */

if (!defined('ABSPATH')) exit;

class FFC_Rate_Limit_Activator {
    
    public static function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        $table_limits = $wpdb->prefix . 'ffc_rate_limits';
        $table_logs = $wpdb->prefix . 'ffc_rate_limit_logs';
        
        // Check if tables exist
        $limits_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_limits'") === $table_limits;
        $logs_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_logs'") === $table_logs;
        
        // TABLE 1: Rate Limits
        if (!$limits_exists) {
            $sql_limits = "CREATE TABLE $table_limits (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                type varchar(20) NOT NULL,
                identifier varchar(255) NOT NULL,
                form_id bigint(20) unsigned DEFAULT NULL,
                count int(10) unsigned NOT NULL DEFAULT 1,
                window_type varchar(20) NOT NULL,
                window_start datetime NOT NULL,
                window_end datetime NOT NULL,
                last_attempt datetime NOT NULL,
                is_blocked tinyint(1) DEFAULT 0,
                blocked_until datetime DEFAULT NULL,
                blocked_reason varchar(255) DEFAULT NULL,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                KEY idx_type_identifier (type,identifier),
                KEY idx_form (form_id),
                KEY idx_window (window_end),
                KEY idx_blocked (is_blocked,blocked_until),
                KEY idx_cleanup (window_end,updated_at),
                UNIQUE KEY unique_tracking (type,identifier,form_id,window_type,window_start)
            ) $charset_collate;";
            
            dbDelta($sql_limits);
        }
        
        // TABLE 2: Logs
        if (!$logs_exists) {
            $sql_logs = "CREATE TABLE $table_logs (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                type varchar(20) NOT NULL,
                identifier varchar(255) NOT NULL,
                form_id bigint(20) unsigned DEFAULT NULL,
                action varchar(20) NOT NULL,
                reason varchar(255) DEFAULT NULL,
                ip_address varchar(45) DEFAULT NULL,
                user_agent text DEFAULT NULL,
                current_count int(10) unsigned NOT NULL,
                max_allowed int(10) unsigned NOT NULL,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                KEY idx_type (type),
                KEY idx_identifier (identifier),
                KEY idx_action (action),
                KEY idx_form (form_id),
                KEY idx_created (created_at),
                KEY idx_cleanup (created_at)
            ) $charset_collate;";
            
            dbDelta($sql_logs);
        }
        
        update_option('ffc_rate_limit_db_version', '1.0.0');
        return true;
    }
    
    public static function tables_exist() {
        global $wpdb;
        
        $table_limits = $wpdb->prefix . 'ffc_rate_limits';
        $table_logs = $wpdb->prefix . 'ffc_rate_limit_logs';
        
        $limits_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_limits'") === $table_limits;
        $logs_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_logs'") === $table_logs;
        
        return $limits_exists && $logs_exists;
    }
    
    public static function drop_tables() {
        global $wpdb;
        
        $table_limits = $wpdb->prefix . 'ffc_rate_limits';
        $table_logs = $wpdb->prefix . 'ffc_rate_limit_logs';
        
        $wpdb->query("DROP TABLE IF EXISTS $table_limits");
        $wpdb->query("DROP TABLE IF EXISTS $table_logs");
        
        delete_option('ffc_rate_limit_db_version');
        return true;
    }
}