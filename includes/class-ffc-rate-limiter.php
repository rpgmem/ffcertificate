<?php
/**
 * FFC_Rate_Limiter
 * Prevents spam and abuse through intelligent rate limiting
 * 
 * Features:
 * - Configurable attempts and time windows
 * - Per-action and per-identifier limits
 * - Automatic cleanup via transients
 * - Graceful degradation
 * 
 * @since 2.9.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FFC_Rate_Limiter {
    
    /**
     * Check if action is allowed for given identifier
     * 
     * @param string $action Action identifier (e.g., 'form_submit', 'verification')
     * @param string $identifier User identifier (IP, email, user_id, etc.)
     * @param int $max_attempts Maximum attempts allowed
     * @param int $time_window Time window in seconds (default: 1 hour)
     * @return array Result with 'allowed' boolean and additional info
     */
    public static function check( $action, $identifier, $max_attempts = 5, $time_window = 3600 ) {
        $transient_key = self::get_transient_key( $action, $identifier );
        $attempts = get_transient( $transient_key );
        
        // Initialize attempts array if not exists
        if ( ! $attempts || ! is_array( $attempts ) ) {
            $attempts = array(
                'count' => 0,
                'first_attempt' => time(),
                'last_attempt' => 0
            );
        }
        
        // Reset if time window has passed
        if ( time() - $attempts['first_attempt'] > $time_window ) {
            $attempts = array(
                'count' => 0,
                'first_attempt' => time(),
                'last_attempt' => 0
            );
        }
        
        // Check if limit exceeded
        if ( $attempts['count'] >= $max_attempts ) {
            $retry_after = $attempts['first_attempt'] + $time_window;
            $wait_seconds = $retry_after - time();
            
            return array(
                'allowed' => false,
                'attempts' => $attempts['count'],
                'max_attempts' => $max_attempts,
                'retry_after' => $retry_after,
                'wait_seconds' => max( 0, $wait_seconds ),
                'wait_minutes' => ceil( max( 0, $wait_seconds ) / 60 )
            );
        }
        
        // Allowed
        return array(
            'allowed' => true,
            'attempts' => $attempts['count'],
            'max_attempts' => $max_attempts,
            'attempts_remaining' => $max_attempts - $attempts['count'],
            'time_window' => $time_window
        );
    }
    
    /**
     * Record an attempt
     * 
     * Call this AFTER the action is attempted (success or fail)
     * 
     * @param string $action Action identifier
     * @param string $identifier User identifier
     * @param int $time_window Time window in seconds
     * @return bool Success
     */
    public static function record_attempt( $action, $identifier, $time_window = 3600 ) {
        $transient_key = self::get_transient_key( $action, $identifier );
        $attempts = get_transient( $transient_key );
        
        // Initialize or reset if needed
        if ( ! $attempts || ! is_array( $attempts ) ) {
            $attempts = array(
                'count' => 0,
                'first_attempt' => time(),
                'last_attempt' => 0
            );
        }
        
        // Reset if time window passed
        if ( time() - $attempts['first_attempt'] > $time_window ) {
            $attempts = array(
                'count' => 0,
                'first_attempt' => time(),
                'last_attempt' => 0
            );
        }
        
        // Increment counter
        $attempts['count']++;
        $attempts['last_attempt'] = time();
        
        // Save with expiration
        return set_transient( $transient_key, $attempts, $time_window );
    }
    
    /**
     * Clear rate limit for specific identifier
     * 
     * Useful for resetting after successful authentication or admin action
     * 
     * @param string $action Action identifier
     * @param string $identifier User identifier
     * @return bool Success
     */
    public static function clear( $action, $identifier ) {
        $transient_key = self::get_transient_key( $action, $identifier );
        return delete_transient( $transient_key );
    }
    
    /**
     * Clear all rate limits for an action
     * 
     * WARNING: This clears ALL identifiers for the action
     * Use sparingly, typically only in admin settings
     * 
     * @param string $action Action identifier
     * @return int Number of cleared transients
     */
    public static function clear_action( $action ) {
        global $wpdb;
        
        $transient_like = $wpdb->esc_like( '_transient_ffc_rate_' . md5( $action ) ) . '%';
        
        $deleted = $wpdb->query( 
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $transient_like
            )
        );
        
        return $deleted;
    }
    
    /**
     * Get current status for identifier
     * 
     * @param string $action Action identifier
     * @param string $identifier User identifier
     * @return array|false Status array or false if no data
     */
    public static function get_status( $action, $identifier ) {
        $transient_key = self::get_transient_key( $action, $identifier );
        $attempts = get_transient( $transient_key );
        
        if ( ! $attempts || ! is_array( $attempts ) ) {
            return false;
        }
        
        return array(
            'count' => $attempts['count'],
            'first_attempt' => $attempts['first_attempt'],
            'last_attempt' => $attempts['last_attempt'],
            'first_attempt_date' => date( 'Y-m-d H:i:s', $attempts['first_attempt'] ),
            'last_attempt_date' => $attempts['last_attempt'] ? date( 'Y-m-d H:i:s', $attempts['last_attempt'] ) : null
        );
    }
    
    /**
     * Generate consistent transient key
     * 
     * @param string $action Action identifier
     * @param string $identifier User identifier
     * @return string Transient key
     */
    private static function get_transient_key( $action, $identifier ) {
        return 'ffc_rate_' . md5( $action . $identifier );
    }
    
    /**
     * Get formatted error message
     * 
     * @param array $result Result from check() method
     * @return string Error message
     */
    public static function get_error_message( $result ) {
        if ( $result['allowed'] ) {
            return '';
        }
        
        $wait_minutes = isset( $result['wait_minutes'] ) ? $result['wait_minutes'] : 0;
        
        if ( $wait_minutes > 60 ) {
            $hours = ceil( $wait_minutes / 60 );
            return sprintf(
                __( 'Too many attempts. Please try again in %d hour(s).', 'ffc' ),
                $hours
            );
        } elseif ( $wait_minutes > 1 ) {
            return sprintf(
                __( 'Too many attempts. Please try again in %d minutes.', 'ffc' ),
                $wait_minutes
            );
        } else {
            return __( 'Too many attempts. Please try again in a few moments.', 'ffc' );
        }
    }
    
    /**
     * Wrapper method for form submissions
     * 
     * @param string $identifier User identifier (IP or email)
     * @return array Check result
     */
    public static function check_form_submission( $identifier ) {
        return self::check( 'form_submit', $identifier, 5, 3600 ); // 5 attempts per hour
    }
    
    /**
     * Wrapper method for certificate verification
     * 
     * @param string $identifier User identifier (IP)
     * @return array Check result
     */
    public static function check_verification( $identifier ) {
        return self::check( 'verification', $identifier, 10, 600 ); // 10 attempts per 10 minutes
    }
    
    /**
     * Wrapper method for PDF downloads
     * 
     * @param string $identifier User identifier (IP)
     * @return array Check result
     */
    public static function check_pdf_download( $identifier ) {
        return self::check( 'pdf_download', $identifier, 20, 3600 ); // 20 downloads per hour
    }
    
    /**
     * Wrapper method for email sending
     * 
     * @param string $identifier Email address
     * @return array Check result
     */
    public static function check_email_send( $identifier ) {
        return self::check( 'email_send', $identifier, 3, 86400 ); // 3 emails per day per address
    }
    
    /**
     * Get all active rate limits (for admin dashboard)
     * 
     * @return array Active limits
     */
    public static function get_all_active_limits() {
        global $wpdb;
        
        $transients = $wpdb->get_results(
            "SELECT option_name, option_value 
             FROM {$wpdb->options} 
             WHERE option_name LIKE '_transient_ffc_rate_%' 
             ORDER BY option_name 
             LIMIT 100"
        );
        
        $active_limits = array();
        
        foreach ( $transients as $transient ) {
            $data = maybe_unserialize( $transient->option_value );
            
            if ( is_array( $data ) && isset( $data['count'] ) ) {
                $key = str_replace( '_transient_ffc_rate_', '', $transient->option_name );
                
                $active_limits[] = array(
                    'key' => $key,
                    'count' => $data['count'],
                    'first_attempt' => date( 'Y-m-d H:i:s', $data['first_attempt'] ),
                    'last_attempt' => $data['last_attempt'] ? date( 'Y-m-d H:i:s', $data['last_attempt'] ) : null
                );
            }
        }
        
        return $active_limits;
    }
    
    /**
     * Clean expired transients (optional housekeeping)
     * 
     * Note: WordPress handles this automatically, but you can call this
     * if you want immediate cleanup
     * 
     * @return int Number of cleaned transients
     */
    public static function cleanup_expired() {
        global $wpdb;
        
        // Delete expired transients
        $deleted = $wpdb->query(
            "DELETE FROM {$wpdb->options} 
             WHERE option_name LIKE '_transient_timeout_ffc_rate_%' 
             AND option_value < UNIX_TIMESTAMP()"
        );
        
        // Delete corresponding transient values
        if ( $deleted ) {
            $wpdb->query(
                "DELETE FROM {$wpdb->options} 
                 WHERE option_name LIKE '_transient_ffc_rate_%' 
                 AND option_name NOT IN (
                     SELECT REPLACE(option_name, '_transient_timeout_', '_transient_') 
                     FROM {$wpdb->options} 
                     WHERE option_name LIKE '_transient_timeout_ffc_rate_%'
                 )"
            );
        }
        
        return $deleted;
    }
}