<?php
/**
 * FFC_Utils
 * Utility class shared between Frontend and Admin.
 * 
 * v2.9.1: Added CPF validation, document formatting, and helper functions
 * v2.9.11: Added validate_security_fields() and recursive_sanitize()
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FFC_Utils {

    /**
     * Get submissions table name with current prefix
     * 
     * Centralizes table name generation for consistency across all classes.
     * Works correctly with WordPress Multisite (different prefixes per site).
     * 
     * @since 2.9.16
     * @return string Full table name with WordPress prefix
     */
    public static function get_submissions_table() {
    global $wpdb;
    // Retorna o nome real da tabela, SEM chamar a própria função de novo
    return $wpdb->prefix . 'ffc_submissions'; 
    }
    
    /**
     * Returns the list of allowed HTML tags and attributes.
     * Centralized here so Frontend, Email, and PDF Generator use the same validation rules.
     * 
     * @return array Allowed HTML tags with their attributes
     */
    public static function get_allowed_html_tags() {
        $allowed = array(
            'b'      => array(),
            'strong' => array(),
            'i'      => array(),
            'em'     => array(),
            'u'      => array(),
            'br'     => array(),
            'hr'     => array(
                'style' => array(),
                'class' => array(),
            ),
            'p'      => array(
                'style' => array(),
                'class' => array(),
                'align' => array(),
            ),
            'span'   => array(
                'style' => array(),
                'class' => array(),
            ),
            'div'    => array(
                'style' => array(),
                'class' => array(),
                'id'    => array(),
            ),
            'font'   => array(
                'color' => array(),
                'size'  => array(),
                'face'  => array(),
            ),
            'img'    => array(
                'src'    => array(),
                'alt'    => array(),
                'style'  => array(),
                'width'  => array(),
                'height' => array(),
            ),
            // Table tags (essential for signature alignment)
            'table'  => array(
                'style'       => array(),
                'class'       => array(),
                'width'       => array(),
                'border'      => array(),
                'cellpadding' => array(),
                'cellspacing' => array(),
            ),
            'tr'     => array(
                'style' => array(),
                'class' => array(),
            ),
            'td'     => array(
                'style'   => array(),
                'width'   => array(),
                'colspan' => array(),
                'rowspan' => array(),
                'align'   => array(),
                'valign'  => array(),
            ),
            // Headings
            'h1' => array('style' => array(), 'class' => array()),
            'h2' => array('style' => array(), 'class' => array()),
            'h3' => array('style' => array(), 'class' => array()),
            'h4' => array('style' => array(), 'class' => array()),
            
            // Lists (useful for syllabus content on the back or body)
            'ul' => array('style' => array(), 'class' => array()),
            'ol' => array('style' => array(), 'class' => array()),
            'li' => array('style' => array(), 'class' => array()),
        );

        /**
         * Allows developers to filter or add new tags 
         * without modifying the plugin core.
         */
        return apply_filters( 'ffc_allowed_html_tags', $allowed );
    }
    
    /**
     * Validate CPF (Brazilian tax ID)
     * 
     * Uses the official CPF validation algorithm
     * 
     * @param string $cpf CPF to validate (with or without formatting)
     * @return bool True if valid, false otherwise
     */
    public static function validate_cpf( $cpf ) {
        // Remove non-numeric characters
        $cpf = preg_replace( '/\D/', '', $cpf );
        
        // Check length
        if ( strlen( $cpf ) != 11 ) {
            return false;
        }
        
        // Check for known invalid CPFs (all same digit)
        if ( preg_match( '/(\d)\1{10}/', $cpf ) ) {
            return false;
        }
        
        // Validate check digits
        for ( $t = 9; $t < 11; $t++ ) {
            for ( $d = 0, $c = 0; $c < $t; $c++ ) {
                $d += $cpf[$c] * ( ( $t + 1 ) - $c );
            }
            $d = ( ( 10 * $d ) % 11 ) % 10;
            if ( $cpf[$c] != $d ) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Format CPF with mask
     * 
     * @param string $cpf CPF to format
     * @return string Formatted CPF (XXX.XXX.XXX-XX)
     */
    public static function format_cpf( $cpf ) {
        $cpf = preg_replace( '/\D/', '', $cpf );
        
        if ( strlen( $cpf ) === 11 ) {
            return preg_replace( '/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $cpf );
        }
        
        return $cpf;
    }
    
    /**
     * Validate RF (7-digit registration)
     * 
     * @param string $rf RF to validate
     * @return bool True if valid, false otherwise
     */
    public static function validate_rf( $rf ) {
        $rf = preg_replace( '/\D/', '', $rf );
        return strlen( $rf ) === 7 && is_numeric( $rf );
    }
    
    /**
     * Format RF with mask
     * 
     * @param string $rf RF to format
     * @return string Formatted RF (XXX.XXX-X)
     */
    public static function format_rf( $rf ) {
        $rf = preg_replace( '/\D/', '', $rf );
        
        if ( strlen( $rf ) === 7 ) {
            return preg_replace( '/(\d{3})(\d{3})(\d{1})/', '$1.$2-$3', $rf );
        }
        
        return $rf;
    }
    
    /**
     * Format authentication code
     * 
     * @param string $code Auth code to format
     * @return string Formatted code (XXXX-XXXX-XXXX)
     */
    public static function format_auth_code( $code ) {
        $code = strtoupper( preg_replace( '/[^A-Z0-9]/i', '', $code ) );
        
        if ( strlen( $code ) === 12 ) {
            return substr( $code, 0, 4 ) . '-' . substr( $code, 4, 4 ) . '-' . substr( $code, 8, 4 );
        }
        
        return $code;
    }
    
    /**
     * Format any document based on type
     * 
     * Auto-detects document type based on length
     * 
     * @param string $value Document value
     * @param string $type Document type (cpf, rf, auth_code, or 'auto')
     * @return string Formatted document
     */
    public static function format_document( $value, $type = 'auto' ) {
        $clean = preg_replace( '/\D/', '', $value );
        $len = strlen( $clean );
        
        // Auto-detect type based on length
        if ( $type === 'auto' ) {
            if ( $len === 11 ) {
                $type = 'cpf';
            } elseif ( $len === 7 ) {
                $type = 'rf';
            } elseif ( $len === 12 ) {
                $type = 'auth_code';
            }
        }
        
        // Format based on type
        switch ( $type ) {
            case 'cpf':
                return self::format_cpf( $value );
            case 'rf':
                return self::format_rf( $value );
            case 'auth_code':
                return self::format_auth_code( $value );
            default:
                return $value;
        }
    }
    
    /**
     * Get user IP address with proxy support
     * 
     * Checks multiple headers to get real IP even behind proxies/CDNs
     * 
     * @return string IP address
     */
    public static function get_user_ip() {
        $ip_keys = array(
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        );
        
        foreach ( $ip_keys as $key ) {
            if ( array_key_exists( $key, $_SERVER ) ) {
                foreach ( explode( ',', $_SERVER[$key] ) as $ip ) {
                    $ip = trim( $ip );
                    
                    // Validate IP (exclude private/reserved ranges)
                    if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
                        return $ip;
                    }
                }
            }
        }
        
        return '0.0.0.0';
    }
    
    /**
     * Check if running in local development environment
     * 
     * @return bool True if local, false otherwise
     */
    public static function is_local_environment() {
        $server_name = isset( $_SERVER['SERVER_NAME'] ) ? $_SERVER['SERVER_NAME'] : '';
        
        // Common localhost addresses
        $local_hosts = array( 'localhost', '127.0.0.1', '::1' );
        
        if ( in_array( $server_name, $local_hosts ) ) {
            return true;
        }
        
        // Common local domain extensions
        if ( strpos( $server_name, '.local' ) !== false || 
             strpos( $server_name, '.test' ) !== false ||
             strpos( $server_name, '.dev' ) !== false ) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Sanitize filename for safe download
     * 
     * @param string $filename Original filename
     * @return string Sanitized filename
     */
    public static function sanitize_filename( $filename ) {
        // Remove extension temporarily
        $extension = pathinfo( $filename, PATHINFO_EXTENSION );
        $name = pathinfo( $filename, PATHINFO_FILENAME );
        
        // Remove special characters
        $name = preg_replace( '/[^a-zA-Z0-9\-_]/', '-', $name );
        
        // Remove multiple dashes
        $name = preg_replace( '/-+/', '-', $name );
        
        // Trim dashes from start/end
        $name = trim( $name, '-' );
        
        // Lowercase
        $name = strtolower( $name );
        
        // Add extension back if it exists
        if ( $extension ) {
            return $name . '.' . $extension;
        }
        
        return $name;
    }
    
    /**
     * Convert bytes to human-readable format
     * 
     * @param int $bytes Number of bytes
     * @param int $precision Decimal precision
     * @return string Formatted size (e.g., "1.5 MB")
     */
    public static function format_bytes( $bytes, $precision = 2 ) {
        $units = array( 'B', 'KB', 'MB', 'GB', 'TB' );
        
        $bytes = max( $bytes, 0 );
        $pow = floor( ( $bytes ? log( $bytes ) : 0 ) / log( 1024 ) );
        $pow = min( $pow, count( $units ) - 1 );
        
        $bytes /= pow( 1024, $pow );
        
        return round( $bytes, $precision ) . ' ' . $units[$pow];
    }
    
    /**
     * Generate random string
     * 
     * @param int $length Length of random string
     * @param string $chars Characters to use (default: alphanumeric)
     * @return string Random string
     */
    public static function generate_random_string( $length = 12, $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789' ) {
        $string = '';
        $chars_length = strlen( $chars );
        
        for ( $i = 0; $i < $length; $i++ ) {
            $string .= $chars[ rand( 0, $chars_length - 1 ) ];
        }
        
        return $string;
    }
    
    /**
     * Check if email is valid and not disposable
     * 
     * @param string $email Email to validate
     * @param bool $check_disposable Check against disposable email list
     * @return bool True if valid, false otherwise
     */
    public static function validate_email( $email, $check_disposable = false ) {
        if ( ! is_email( $email ) ) {
            return false;
        }
        
        if ( $check_disposable ) {
            $domain = substr( strrchr( $email, '@' ), 1 );
            $disposable_domains = self::get_disposable_email_domains();
            
            if ( in_array( strtolower( $domain ), $disposable_domains ) ) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Get list of common disposable email domains
     * 
     * @return array List of domains
     */
    private static function get_disposable_email_domains() {
        return array(
            'tempmail.com',
            '10minutemail.com',
            'guerrillamail.com',
            'mailinator.com',
            'throwaway.email',
            'temp-mail.org',
            'fakeinbox.com',
            'trashmail.com',
            'yopmail.com',
            'getnada.com'
        );
    }
    
    /**
     * Truncate string to specific length
     * 
     * @param string $text Text to truncate
     * @param int $length Maximum length
     * @param string $suffix Suffix to add (default: '...')
     * @return string Truncated text
     */
    public static function truncate( $text, $length = 100, $suffix = '...' ) {
        if ( strlen( $text ) <= $length ) {
            return $text;
        }
        
        return substr( $text, 0, $length - strlen( $suffix ) ) . $suffix;
    }
    
    /**
     * Check if current user can manage plugin
     * 
     * @return bool True if can manage, false otherwise
     */
    public static function current_user_can_manage() {
        return current_user_can( 'manage_options' );
    }
    
    /**
     * Clean authentication code (remove special chars, uppercase)
     * 
     * Used throughout the plugin for consistent auth code cleaning
     * 
     * @param string $code Auth code to clean
     * @return string Cleaned code (uppercase alphanumeric only)
     */
    public static function clean_auth_code( $code ) {
        return strtoupper( preg_replace( '/[^a-zA-Z0-9]/', '', $code ) );
    }
    
    /**
     * Clean identifier (CPF, RF, ticket) - uppercase alphanumeric only
     * 
     * Used for consistent cleaning of all identifier types
     * 
     * @param string $value Identifier to clean
     * @return string Cleaned identifier (uppercase alphanumeric only)
     */
    public static function clean_identifier( $value ) {
        return strtoupper( preg_replace( '/[^a-zA-Z0-9]/', '', $value ) );
    }
    
    /**
     * Validate IP address
     * 
     * @param string $ip IP to validate
     * @return bool True if valid, false otherwise
     */
    public static function is_valid_ip( $ip ) {
        return filter_var( $ip, FILTER_VALIDATE_IP ) !== false;
    }
    
    /**
     * Log debug message (only if WP_DEBUG is enabled)
     * 
     * @param string $message Message to log
     * @param mixed $data Optional data to log
     */
    public static function debug_log( $message, $data = null ) {
        if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
            return;
        }
        
        $log_message = '[FFC] ' . $message;
        
        if ( $data !== null ) {
            $log_message .= ' | Data: ' . print_r( $data, true );
        }
        
        error_log( $log_message );
    }
    
    /**
     * Generate simple math captcha
     * 
     * @return array Array with 'label' and 'hash'
     */
    public static function generate_simple_captcha() {
        $n1 = rand( 1, 9 );
        $n2 = rand( 1, 9 );
        $answer = $n1 + $n2;
        
        return array(
            'label' => sprintf( esc_html__( 'Security: How much is %d + %d?', 'ffc' ), $n1, $n2 ) . ' <span class="required">*</span>',
            'hash'  => wp_hash( $answer . 'ffc_math_salt' ),
            'answer' => $answer  // For internal use only
        );
    }
    
    /**
     * Verify simple captcha answer
     * 
     * @param string $answer User's answer
     * @param string $hash Expected hash
     * @return bool True if correct, false otherwise
     */
    public static function verify_simple_captcha( $answer, $hash ) {
        if ( empty( $answer ) || empty( $hash ) ) {
            return false;
        }
        
        $check_hash = wp_hash( trim( $answer ) . 'ffc_math_salt' );
        return $check_hash === $hash;
    }

    /**
     * Validate security fields (honeypot + captcha)
     * 
     * Moved from class-ffc-form-processor.php for centralization
     * 
     * @since 2.9.11 - Consolidated validation
     * @param array $data Form data containing security fields
     * @return true|string True if valid, error message string if invalid
     */
    public static function validate_security_fields( $data ) {
        // Check honeypot
        if ( ! empty( $data['ffc_honeypot_trap'] ) ) {
            return __( 'Security Error: Request blocked (Honeypot).', 'ffc' );
        }
        
        // Check captcha presence
        if ( ! isset( $data['ffc_captcha_ans'] ) || ! isset( $data['ffc_captcha_hash'] ) ) {
            return __( 'Error: Please answer the security question.', 'ffc' );
        }
        
        // Validate captcha answer using verify_simple_captcha()
        if ( ! self::verify_simple_captcha( $data['ffc_captcha_ans'], $data['ffc_captcha_hash'] ) ) {
            return __( 'Error: The math answer is incorrect.', 'ffc' );
        }
        
        return true; 
    }

    /**
     * Recursively sanitize data (arrays or strings)
     * 
     * Moved from class-ffc-form-processor.php for centralization
     * 
     * @since 2.9.11 - Consolidated sanitization
     * @param mixed $data Data to sanitize (array or string)
     * @return mixed Sanitized data
     */
    public static function recursive_sanitize( $data ) {
        if ( is_array( $data ) ) {
            $sanitized = array();
            foreach ( $data as $key => $value ) {
                $sanitized[ sanitize_key( $key ) ] = self::recursive_sanitize( $value );
            }
            return $sanitized;
        }
        return wp_kses( $data, self::get_allowed_html_tags() );
    }

    /**
     * Generate success HTML response for frontend form submission
     * 
     * @since 2.9.16
     * @param array  $submission_data Submission data
     * @param int    $form_id Form ID
     * @param string $submission_date Submission date
     * @param string $success_message Success message
     * @return string HTML content
     */
    public static function generate_success_html( $submission_data, $form_id, $submission_date, $success_message = '' ) {
        // Get form configuration
        $form_config = get_post_meta( $form_id, '_ffc_form_config', true );
        if ( ! is_array( $form_config ) ) {
            $form_config = array();
        }
        
        // Get form title
        $form_post = get_post( $form_id );
        $form_title = $form_post ? $form_post->post_title : __( 'Certificate', 'ffc' );
        
        // Default success message
        if ( empty( $success_message ) ) {
            $success_message = isset( $form_config['success_message'] ) && ! empty( $form_config['success_message'] )
                ? $form_config['success_message']
                : __( 'Success! Your certificate has been generated.', 'ffc' );
        }
        
        // Format date
        $date_formatted = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $submission_date ) );
        
        // Build HTML
        $html = '<div class="ffc-success-response">';
        $html .= '<div class="ffc-success-icon"> ✓ </div>';
        $html .= '<h3>' . esc_html( $success_message ) . '</h3>';
        $html .= '<div class="ffc-success-details">';
        $html .= '<p><strong>' . esc_html__( 'Form:', 'ffc' ) . '</strong> ' . esc_html( $form_title ) . '</p>';
        $html .= '<p><strong>' . esc_html__( 'Date:', 'ffc' ) . '</strong> ' . esc_html( $date_formatted ) . '</p>';
        
        // Add auth code if exists
        if ( isset( $submission_data['auth_code'] ) && ! empty( $submission_data['auth_code'] ) ) {
            $html .= '<p><strong>' . esc_html__( 'Authentication Code:', 'ffc' ) . '</strong> <code>' . esc_html( $submission_data['auth_code'] ) . '</code></p>';
        }
        
        $html .= '</div>';
        $html .= '</div>';
        
        return $html;
    }
}