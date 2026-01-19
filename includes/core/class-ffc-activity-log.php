<?php
/**
 * FFC_Activity_Log
 * Tracks important activities for audit and debugging
 * 
 * Features:
 * - Multiple log levels (info, warning, error, debug)
 * - Automatic context capture (user, IP, timestamp)
 * - Query helpers for admin dashboard
 * - Automatic table creation on activation
 * - Cleanup of old logs
 * - Convenience methods for common events
 * - LGPD-specific logging methods (v2.10.0)
 * - Optional context encryption (v2.10.0)
 * 
 * @since 2.9.1
 * @version 2.10.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FFC_Activity_Log {
    
    /**
     * Log levels
     */
    const LEVEL_INFO = 'info';
    const LEVEL_WARNING = 'warning';
    const LEVEL_ERROR = 'error';
    const LEVEL_DEBUG = 'debug';
    
    /**
     * Log an activity
     * 
     * @param string $action Action performed (e.g., 'submission_created', 'pdf_generated')
     * @param string $level Log level (info, warning, error, debug)
     * @param array $context Additional context data
     * @param int $user_id User ID (0 for anonymous/system)
     * @param int $submission_id Submission ID (0 if not related to submission) - v2.10.0
     * @return bool Success
     */
    public static function log( $action, $level = self::LEVEL_INFO, $context = array(), $user_id = 0, $submission_id = 0 ) {
        global $wpdb;
        
        // Check if logging is enabled in settings
        $settings = get_option( 'ffc_settings', array() );
        if ( ! isset( $settings['enable_activity_log'] ) || $settings['enable_activity_log'] != 1 ) {
            return false; // Logging disabled
        }
        
        // Validate level
        $valid_levels = array( self::LEVEL_INFO, self::LEVEL_WARNING, self::LEVEL_ERROR, self::LEVEL_DEBUG );
        if ( ! in_array( $level, $valid_levels ) ) {
            $level = self::LEVEL_INFO;
        }
        
        // ✅ v2.10.0: Encrypt context if contains sensitive data
        $context_json = wp_json_encode( $context );
        $context_encrypted = null;
        
        if ( class_exists( 'FFC_Encryption' ) && FFC_Encryption::is_configured() ) {
            // Encrypt context for sensitive operations
            $sensitive_actions = array(
                'submission_created',
                'data_accessed',
                'data_modified',
                'admin_searched',
                'encryption_migration_batch'
            );
            
            if ( in_array( $action, $sensitive_actions ) ) {
                $context_encrypted = FFC_Encryption::encrypt( $context_json );
            }
        }
        
        // Prepare data
        $log_data = array(
            'action' => sanitize_text_field( $action ),
            'level' => sanitize_key( $level ),
            'context' => $context_json,
            'user_id' => absint( $user_id ),
            'user_ip' => FFC_Utils::get_user_ip(),
            'created_at' => current_time( 'mysql' )
        );
        
        // ✅ v2.10.0: Add new fields if columns exist
        $table_name = $wpdb->prefix . 'ffc_activity_log';
        
        // Check if new columns exist
        $columns = $wpdb->get_col( "DESCRIBE {$table_name}", 0 );
        
        if ( in_array( 'submission_id', $columns ) ) {
            $log_data['submission_id'] = absint( $submission_id );
        }
        
        if ( in_array( 'context_encrypted', $columns ) && $context_encrypted !== null ) {
            $log_data['context_encrypted'] = $context_encrypted;
        }
        
        // Insert into database
        $result = $wpdb->insert( $table_name, $log_data );
        
        // Also log to error_log if WP_DEBUG enabled
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            $context_str = ! empty( $context ) ? wp_json_encode( $context ) : 'none';
            error_log( sprintf(
                '[FFC Activity] %s | %s | User: %d | IP: %s | Submission: %d | Context: %s',
                strtoupper( $level ),
                $action,
                $user_id,
                $log_data['user_ip'],
                $submission_id,
                $context_str
            ) );
        }
        
        return $result !== false;
    }
    
    /**
     * Create activity log table
     * Called during plugin activation
     * 
     * @return bool Success
     */
    public static function create_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ffc_activity_log';
        $charset_collate = $wpdb->get_charset_collate();
        
        // Check if table already exists
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table_name}'" ) === $table_name ) {
            return true; // Table exists
        }
        
        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            action varchar(100) NOT NULL,
            level varchar(20) NOT NULL DEFAULT 'info',
            context longtext,
            user_id bigint(20) unsigned DEFAULT 0,
            user_ip varchar(100),
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY action (action),
            KEY level (level),
            KEY user_id (user_id),
            KEY created_at (created_at),
            KEY user_ip (user_ip)
        ) {$charset_collate};";
        
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
        
        return true;
    }
    
    /**
     * Get recent activities with filters
     * 
     * @param array $args Query arguments
     * @return array Activities
     */
    public static function get_activities( $args = array() ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ffc_activity_log';
        
        // Default arguments
        $defaults = array(
            'limit' => 50,
            'offset' => 0,
            'level' => null,
            'action' => null,
            'user_id' => null,
            'user_ip' => null,
            'date_from' => null,
            'date_to' => null,
            'search' => null,
            'orderby' => 'created_at',
            'order' => 'DESC'
        );
        
        $args = wp_parse_args( $args, $defaults );
        
        // Build WHERE clause
        $where = array( '1=1' );
        
        if ( $args['level'] ) {
            $where[] = $wpdb->prepare( 'level = %s', sanitize_key( $args['level'] ) );
        }
        
        if ( $args['action'] ) {
            $where[] = $wpdb->prepare( 'action = %s', sanitize_text_field( $args['action'] ) );
        }
        
        if ( $args['user_id'] ) {
            $where[] = $wpdb->prepare( 'user_id = %d', absint( $args['user_id'] ) );
        }
        
        if ( $args['user_ip'] ) {
            $where[] = $wpdb->prepare( 'user_ip = %s', sanitize_text_field( $args['user_ip'] ) );
        }
        
        if ( $args['date_from'] ) {
            $where[] = $wpdb->prepare( 'created_at >= %s', sanitize_text_field( $args['date_from'] ) );
        }
        
        if ( $args['date_to'] ) {
            $where[] = $wpdb->prepare( 'created_at <= %s', sanitize_text_field( $args['date_to'] ) );
        }
        
        if ( $args['search'] ) {
            $search = '%' . $wpdb->esc_like( sanitize_text_field( $args['search'] ) ) . '%';
            $where[] = $wpdb->prepare( '(action LIKE %s OR context LIKE %s)', $search, $search );
        }
        
        $where_clause = implode( ' AND ', $where );
        
        // Validate orderby
        $allowed_orderby = array( 'id', 'action', 'level', 'user_id', 'user_ip', 'created_at' );
        $orderby = in_array( $args['orderby'], $allowed_orderby ) ? $args['orderby'] : 'created_at';
        
        // Validate order
        $order = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';
        
        // Execute query
        $query = "SELECT * FROM {$table_name} 
                  WHERE {$where_clause} 
                  ORDER BY {$orderby} {$order} 
                  LIMIT {$args['offset']}, {$args['limit']}";
        
        $results = $wpdb->get_results( $query, ARRAY_A );
        
        // Decode context JSON
        foreach ( $results as &$result ) {
            $result['context'] = json_decode( $result['context'], true );
            if ( ! is_array( $result['context'] ) ) {
                $result['context'] = array();
            }
        }
        
        return $results;
    }
    
    /**
     * Get activity count with filters
     * 
     * @param array $args Same as get_activities()
     * @return int Count
     */
    public static function count_activities( $args = array() ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ffc_activity_log';
        
        // Use same WHERE logic as get_activities
        $defaults = array(
            'level' => null,
            'action' => null,
            'user_id' => null,
            'user_ip' => null,
            'date_from' => null,
            'date_to' => null,
            'search' => null
        );
        
        $args = wp_parse_args( $args, $defaults );
        
        $where = array( '1=1' );
        
        if ( $args['level'] ) {
            $where[] = $wpdb->prepare( 'level = %s', sanitize_key( $args['level'] ) );
        }
        
        if ( $args['action'] ) {
            $where[] = $wpdb->prepare( 'action = %s', sanitize_text_field( $args['action'] ) );
        }
        
        if ( $args['user_id'] ) {
            $where[] = $wpdb->prepare( 'user_id = %d', absint( $args['user_id'] ) );
        }
        
        if ( $args['user_ip'] ) {
            $where[] = $wpdb->prepare( 'user_ip = %s', sanitize_text_field( $args['user_ip'] ) );
        }
        
        if ( $args['date_from'] ) {
            $where[] = $wpdb->prepare( 'created_at >= %s', sanitize_text_field( $args['date_from'] ) );
        }
        
        if ( $args['date_to'] ) {
            $where[] = $wpdb->prepare( 'created_at <= %s', sanitize_text_field( $args['date_to'] ) );
        }
        
        if ( $args['search'] ) {
            $search = '%' . $wpdb->esc_like( sanitize_text_field( $args['search'] ) ) . '%';
            $where[] = $wpdb->prepare( '(action LIKE %s OR context LIKE %s)', $search, $search );
        }
        
        $where_clause = implode( ' AND ', $where );
        
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name} WHERE {$where_clause}" );
    }
    
    /**
     * Clean old logs
     * 
     * @param int $days Keep logs from last N days (default: 90)
     * @return int Number of deleted rows
     */
    public static function cleanup( $days = 90 ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ffc_activity_log';
        
        $cutoff_date = date( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
        
        $deleted = $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$table_name} WHERE created_at < %s",
            $cutoff_date
        ) );
        
        return (int) $deleted;
    }
    
    /**
     * Get statistics
     * 
     * @param int $days Number of days to analyze (default: 30)
     * @return array Statistics
     */
    public static function get_stats( $days = 30 ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ffc_activity_log';
        
        $date_from = date( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
        
        // Total activities
        $total = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_name} WHERE created_at >= %s",
            $date_from
        ) );
        
        // By level
        $by_level = $wpdb->get_results( $wpdb->prepare(
            "SELECT level, COUNT(*) as count FROM {$table_name} 
             WHERE created_at >= %s 
             GROUP BY level",
            $date_from
        ), ARRAY_A );
        
        // Top actions
        $top_actions = $wpdb->get_results( $wpdb->prepare(
            "SELECT action, COUNT(*) as count FROM {$table_name} 
             WHERE created_at >= %s 
             GROUP BY action 
             ORDER BY count DESC 
             LIMIT 10",
            $date_from
        ), ARRAY_A );
        
        // Top users
        $top_users = $wpdb->get_results( $wpdb->prepare(
            "SELECT user_id, COUNT(*) as count FROM {$table_name} 
             WHERE created_at >= %s AND user_id > 0
             GROUP BY user_id 
             ORDER BY count DESC 
             LIMIT 10",
            $date_from
        ), ARRAY_A );
        
        return array(
            'total' => (int) $total,
            'by_level' => $by_level,
            'top_actions' => $top_actions,
            'top_users' => $top_users,
            'period_days' => $days
        );
    }
    
    // ============================================
    // CONVENIENCE METHODS FOR COMMON ACTIONS
    // ============================================
    
    /**
     * Log submission updated
     */
    public static function log_submission_updated( $submission_id, $admin_user_id ) {
        return self::log( 'submission_updated', self::LEVEL_INFO, array(
            'submission_id' => $submission_id
        ), $admin_user_id );
    }
    
    /**
     * Log submission deleted
     */
    public static function log_submission_deleted( $submission_id, $admin_user_id ) {
        return self::log( 'submission_deleted', self::LEVEL_WARNING, array(
            'submission_id' => $submission_id
        ), $admin_user_id );
    }
    
    /**
     * Log ticket consumed
     */
    public static function log_ticket_used( $ticket, $form_id ) {
        return self::log( 'ticket_consumed', self::LEVEL_INFO, array(
            'ticket' => substr( $ticket, 0, 4 ) . '****', // Partial for security
            'form_id' => $form_id
        ) );
    }
    
    /**
     * Log reprint detected
     */
    public static function log_reprint_detected( $submission_id, $identifier ) {
        return self::log( 'reprint_detected', self::LEVEL_INFO, array(
            'submission_id' => $submission_id,
            'identifier' => $identifier
        ) );
    }
    
    /**
     * Log access denied
     */
    public static function log_access_denied( $reason, $identifier ) {
        return self::log( 'access_denied', self::LEVEL_WARNING, array(
            'reason' => $reason,
            'identifier' => $identifier
        ) );
    }
    
    /**
     * Log PDF generated
     */
    public static function log_pdf_generated( $submission_id, $success = true ) {
        $level = $success ? self::LEVEL_INFO : self::LEVEL_ERROR;
        return self::log( 'pdf_generated', $level, array(
            'submission_id' => $submission_id,
            'success' => $success
        ) );
    }
    
    /**
     * Log email sent
     */
    public static function log_email_sent( $to, $type, $success = true ) {
        $level = $success ? self::LEVEL_INFO : self::LEVEL_ERROR;
        return self::log( 'email_sent', $level, array(
            'to' => $to,
            'type' => $type,
            'success' => $success
        ) );
    }
    
    /**
     * Log magic link accessed
     */
    public static function log_magic_link_accessed( $token, $success = true ) {
        $level = $success ? self::LEVEL_INFO : self::LEVEL_WARNING;
        return self::log( 'magic_link_accessed', $level, array(
            'token' => substr( $token, 0, 8 ) . '...', // Partial token for security
            'success' => $success
        ) );
    }
    
    /**
     * Log verification attempted
     */
    public static function log_verification_attempted( $auth_code, $success = true ) {
        $level = $success ? self::LEVEL_INFO : self::LEVEL_WARNING;
        return self::log( 'verification_attempted', $level, array(
            'auth_code' => $auth_code,
            'success' => $success
        ) );
    }
    
    /**
     * Log form edited
     */
    public static function log_form_edited( $form_id, $admin_user_id ) {
        return self::log( 'form_edited', self::LEVEL_INFO, array(
            'form_id' => $form_id
        ), $admin_user_id );
    }
    
    /**
     * Log settings changed
     */
    public static function log_settings_changed( $setting_key, $admin_user_id ) {
        return self::log( 'settings_changed', self::LEVEL_INFO, array(
            'setting' => $setting_key
        ), $admin_user_id );
    }
    
    /**
     * Log security event
     */
    public static function log_security_event( $event_type, $details = array() ) {
        return self::log( 'security_event', self::LEVEL_WARNING, array_merge(
            array( 'event_type' => $event_type ),
            $details
        ) );
    }
    
    /**
     * Log rate limit triggered
     */
    public static function log_rate_limit_triggered( $action, $identifier ) {
        return self::log( 'rate_limit_triggered', self::LEVEL_WARNING, array(
            'action' => $action,
            'identifier' => $identifier
        ) );
    }
    
    /**
     * Log error
     */
    public static function log_error( $error_message, $context = array() ) {
        return self::log( 'error_occurred', self::LEVEL_ERROR, array_merge(
            array( 'message' => $error_message ),
            $context
        ) );
    }

    /**
     * ✅ v2.10.0: Log submission creation
     * 
     * @param int $submission_id Submission ID
     * @param array $data Additional data (form_id, encrypted status, etc)
     * @return bool Success
     */
    public static function log_submission_created( $submission_id, $data = array() ) {
        return self::log(
            'submission_created',
            self::LEVEL_INFO,
            $data,
            get_current_user_id(),
            $submission_id
        );
    }
    
    /**
     * ✅ v2.10.0: Log data access (magic link, admin view, etc)
     * 
     * @param int $submission_id Submission ID
     * @param array $context Access context (method, IP, etc)
     * @return bool Success
     */
    public static function log_data_accessed( $submission_id, $context = array() ) {
        return self::log(
            'data_accessed',
            self::LEVEL_INFO,
            $context,
            get_current_user_id(),
            $submission_id
        );
    }
    
    /**
     * ✅ v2.10.0: Log data modification
     * 
     * @param int $submission_id Submission ID
     * @param array $changes What was changed
     * @return bool Success
     */
    public static function log_data_modified( $submission_id, $changes = array() ) {
        return self::log(
            'data_modified',
            self::LEVEL_INFO,
            $changes,
            get_current_user_id(),
            $submission_id
        );
    }
    
    /**
     * ✅ v2.10.0: Log LGPD consent given
     * 
     * @param int $submission_id Submission ID
     * @param string $ip IP address of consent
     * @return bool Success
     */
    public static function log_consent_given( $submission_id, $ip ) {
        return self::log(
            'consent_given',
            self::LEVEL_INFO,
            array(
                'ip' => $ip,
                'timestamp' => current_time( 'mysql' )
            ),
            0, // Anonymous user
            $submission_id
        );
    }
    
    /**
     * ✅ v2.10.0: Log admin search
     * 
     * @param string $query Search query
     * @param int $results Number of results found
     * @return bool Success
     */
    public static function log_admin_searched( $query, $results = 0 ) {
        return self::log(
            'admin_searched',
            self::LEVEL_INFO,
            array(
                'query' => substr( $query, 0, 50 ), // Truncate for privacy
                'results' => $results
            ),
            get_current_user_id()
        );
    }
    
    /**
     * ✅ v2.10.0: Log encryption migration batch
     * 
     * @param array $batch_info Batch information (offset, migrated, etc)
     * @return bool Success
     */
    public static function log_encryption_migration( $batch_info = array() ) {
        return self::log(
            'encryption_migration_batch',
            self::LEVEL_INFO,
            $batch_info,
            get_current_user_id()
        );
    }
    
    /**
     * ✅ v2.10.0: Get logs for specific submission (LGPD audit trail)
     * 
     * @param int $submission_id Submission ID
     * @param int $limit Maximum number of logs to retrieve
     * @return array Logs related to this submission
     */
    public static function get_submission_logs( $submission_id, $limit = 100 ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ffc_activity_log';
        
        // Check if submission_id column exists
        $columns = $wpdb->get_col( "DESCRIBE {$table_name}", 0 );
        if ( ! in_array( 'submission_id', $columns ) ) {
            return array(); // Column doesn't exist yet
        }
        
        $logs = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table_name} 
                 WHERE submission_id = %d 
                 ORDER BY created_at DESC 
                 LIMIT %d",
                $submission_id,
                $limit
            ),
            ARRAY_A
        );
        
        // Decrypt encrypted contexts if available
        if ( class_exists( 'FFC_Encryption' ) && FFC_Encryption::is_configured() ) {
            foreach ( $logs as &$log ) {
                if ( ! empty( $log['context_encrypted'] ) ) {
                    $decrypted = FFC_Encryption::decrypt( $log['context_encrypted'] );
                    if ( $decrypted !== null ) {
                        $log['context_decrypted'] = $decrypted;
                    }
                }
            }
        }
        
        return $logs;
    }
}