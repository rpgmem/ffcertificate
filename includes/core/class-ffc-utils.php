<?php
/**
 * Utils
 * Utility class shared between Frontend and Admin.
 *
 * v3.3.0: Added strict types and type hints for better code safety
 * v3.2.0: Migrated to namespace (Phase 2) + Added mask_email() for privacy masking
 * v2.9.1: Added CPF validation, document formatting, and helper functions
 * v2.9.11: Added validate_security_fields() and recursive_sanitize()
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Utils {

    /**
     * Get minified asset suffix based on SCRIPT_DEBUG constant
     *
     * Returns '.min' when SCRIPT_DEBUG is off (production),
     * or '' when SCRIPT_DEBUG is on (development).
     *
     * @since 4.6.12
     * @return string '.min' or ''
     */
    public static function asset_suffix(): string {
        return defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
    }

    /**
     * Enqueue dark mode script if enabled
     *
     * Shared between admin and frontend to avoid duplicate logic.
     *
     * @since 4.6.17
     */
    public static function enqueue_dark_mode(): void {
        $settings  = get_option( 'ffc_settings', array() );
        $dark_mode = isset( $settings['dark_mode'] ) ? $settings['dark_mode'] : 'off';

        if ( $dark_mode === 'off' ) {
            return;
        }

        $s = self::asset_suffix();
        wp_enqueue_script(
            'ffc-dark-mode',
            FFC_PLUGIN_URL . "assets/js/ffc-dark-mode{$s}.js",
            array(),
            FFC_VERSION,
            false
        );
        wp_localize_script( 'ffc-dark-mode', 'ffcDarkMode', array(
            'mode' => $dark_mode,
        ) );
    }

    /**
     * Get submissions table name with current prefix
     *
     * Centralizes table name generation for consistency across all classes.
     * Works correctly with WordPress Multisite (different prefixes per site).
     *
     * @since 2.9.16
     * @return string Full table name with WordPress prefix
     */
    public static function get_submissions_table(): string {
    global $wpdb;
    // Returns the real table name, WITHOUT calling this function again
    return $wpdb->prefix . 'ffc_submissions';
    }
    
    /**
     * Returns the list of allowed HTML tags and attributes.
     * Centralized here so Frontend, Email, and PDF Generator use the same validation rules.
     *
     * @return array Allowed HTML tags with their attributes
     */
    public static function get_allowed_html_tags(): array {
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
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- ffcertificate is the plugin prefix
        return apply_filters( 'ffcertificate_allowed_html_tags', $allowed );
    }
    
    /**
     * Validate CPF (Brazilian tax ID)
     *
     * Uses the official CPF validation algorithm
     *
     * @param string $cpf CPF to validate (with or without formatting)
     * @return bool True if valid, false otherwise
     */
    public static function validate_cpf( string $cpf ): bool {
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
     * Validate Brazilian phone number.
     *
     * Accepts: (XX) XXXXX-XXXX or (XX) XXXX-XXXX, with or without
     * parentheses, spaces, and hyphens.
     *
     * @since 4.11.0
     * @param string $phone Phone string.
     * @return bool True if valid, false otherwise.
     */
    public static function validate_phone( string $phone ): bool {
        $phone = preg_replace( '/\s+/', '', $phone );
        return (bool) preg_match( '/^\(?\d{2}\)?\s?\d{4,5}-?\d{4}$/', $phone );
    }

    /**
     * Phone validation regex pattern (without delimiters).
     */
    public const PHONE_REGEX = '^\(?\d{2}\)?\s?\d{4,5}-?\d{4}$';

    /**
     * Format CPF with mask
     *
     * @param string $cpf CPF to format
     * @return string Formatted CPF (XXX.XXX.XXX-XX)
     */
    public static function format_cpf( string $cpf ): string {
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
    public static function validate_rf( string $rf ): bool {
        $rf = preg_replace( '/\D/', '', $rf );
        return strlen( $rf ) === 7 && is_numeric( $rf );
    }
    
    /**
     * Format RF with mask
     *
     * @param string $rf RF to format
     * @return string Formatted RF (XXX.XXX-X)
     */
    public static function format_rf( string $rf ): string {
        $rf = preg_replace( '/\D/', '', $rf );
        
        if ( strlen( $rf ) === 7 ) {
            return preg_replace( '/(\d{3})(\d{3})(\d{1})/', '$1.$2-$3', $rf );
        }
        
        return $rf;
    }
    
    /**
     * Mask CPF/RF for privacy
     *
     * Masks document while keeping first and last digits visible.
     * Useful for displaying in admin lists, emails, or public pages
     * where privacy is required but some identification is needed.
     *
     * Examples:
     * - CPF (11 digits): 12345678909 → 123.***.***-09
     * - RF (7 digits): 1234567 → 123.***-7
     *
     * @since 2.9.17
     * @param string $value CPF or RF to mask
     * @return string Masked document or original if invalid length
     */
    public static function mask_cpf( string $value ): string {
        if ( empty( $value ) ) {
            return '';
        }
        
        // Clean document (remove non-numeric)
        $clean = preg_replace( '/[^0-9]/', '', $value );
        
        // Mask based on length
        if ( strlen( $clean ) === 11 ) {
            // CPF: 123.***.***-09
            return substr( $clean, 0, 3 ) . '.***.***-' . substr( $clean, -2 );
        } elseif ( strlen( $clean ) === 7 ) {
            // RF: 123.***-7
            return substr( $clean, 0, 3 ) . '.***-' . substr( $clean, -1 );
        }
        
        // Return original if not CPF or RF
        return $value;
    }

    /**
     * Mask email address for privacy
     *
     * Shows first character of local part + *** + @domain
     * Example: joao@gmail.com → j***@gmail.com
     *
     * @since 3.2.0
     * @param string $email Email address to mask
     * @return string Masked email or original if invalid
     */
    public static function mask_email( string $email ): string {
        if ( empty( $email ) || ! is_email( $email ) ) {
            return $email;
        }

        $parts = explode( '@', $email );
        if ( count( $parts ) !== 2 ) {
            return $email;
        }

        $local = $parts[0];
        $domain = $parts[1];

        // Show first character + *** + @domain
        $masked_local = substr( $local, 0, 1 ) . '***';

        return $masked_local . '@' . $domain;
    }

    /**
     * Format authentication code
     *
     * @param string $code Auth code to format
     * @return string Formatted code (XXXX-XXXX-XXXX)
     */
    public static function format_auth_code( string $code ): string {
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
    public static function format_document( string $value, string $type = 'auto' ): string {
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
    public static function get_user_ip(): string {
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
                foreach ( explode( ',', sanitize_text_field( wp_unslash( $_SERVER[$key] ) ) ) as $ip ) {
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
    public static function is_local_environment(): bool {
        $server_name = isset( $_SERVER['SERVER_NAME'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_NAME'] ) ) : '';
        
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
    public static function sanitize_filename( string $filename ): string {
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
    public static function format_bytes( int $bytes, int $precision = 2 ): string {
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
    public static function generate_random_string( int $length = 12, string $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789' ): string {
        $string = '';
        $chars_length = strlen( $chars );
        
        for ( $i = 0; $i < $length; $i++ ) {
            $string .= $chars[ wp_rand( 0, $chars_length - 1 ) ];
        }
        
        return $string;
    }

    /**
     * Generate authentication code in format XXXX-XXXX-XXXX
     *
     * @since 3.0.0
     * @return string Auth code (e.g., "A1B2-C3D4-E5F6")
     */
    public static function generate_auth_code(): string {
        return strtoupper(
            self::generate_random_string(4) . '-' . 
            self::generate_random_string(4) . '-' . 
            self::generate_random_string(4)
        );
    }

    
    /**
     * Check if email is valid and not disposable
     *
     * @param string $email Email to validate
     * @param bool $check_disposable Check against disposable email list
     * @return bool True if valid, false otherwise
     */
    public static function validate_email( string $email, bool $check_disposable = false ): bool {
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
    private static function get_disposable_email_domains(): array {
        return array(
            'tempmail.com',
            '10minutemail.com',
            'guerrillamail.com',
            'mailinator.com',
            'throwaway.email',
            'temp-mail.org',
            'fakeinbox.com',
            'test.com',
            'teste.com.br',
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
    public static function truncate( string $text, int $length = 100, string $suffix = '...' ): string {
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
    public static function current_user_can_manage(): bool {
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
    public static function clean_auth_code( string $code ): string {
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
    public static function clean_identifier( string $value ): string {
        return strtoupper( preg_replace( '/[^a-zA-Z0-9]/', '', $value ) );
    }
    
    /**
     * Validate IP address
     *
     * @param string $ip IP to validate
     * @return bool True if valid, false otherwise
     */
    public static function is_valid_ip( string $ip ): bool {
        return filter_var( $ip, FILTER_VALIDATE_IP ) !== false;
    }
    
    /**
     * Log debug message (only if WP_DEBUG is enabled)
     *
     * @param string $message Message to log
     * @param mixed $data Optional data to log
     * @return void
     */
    public static function debug_log( string $message, $data = null ): void {
        if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
            return;
        }
        
        $log_message = '[FFC] ' . $message;
        
        if ( $data !== null ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions
            $log_message .= ' | Data: ' . print_r( $data, true );
        }

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions
        error_log( $log_message );
    }
    
    /**
     * Generate simple math captcha
     *
     * @return array Array with 'label' and 'hash'
     */
    public static function generate_simple_captcha(): array {
        $n1 = wp_rand( 1, 9 );
        $n2 = wp_rand( 1, 9 );
        $answer = $n1 + $n2;
        
        return array(
            /* translators: 1: first number, 2: second number */
            'label' => sprintf( esc_html__( 'Security: How much is %1$d + %2$d?', 'ffcertificate' ), $n1, $n2 ) . ' <span class="required">*</span>',
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
    public static function verify_simple_captcha( string $answer, string $hash ): bool {
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
     * @return bool|string True if valid, error message string if invalid
     */
    public static function validate_security_fields( array $data ) {
        // Check honeypot
        if ( ! empty( $data['ffc_honeypot_trap'] ) ) {
            return __( 'Security Error: Request blocked (Honeypot).', 'ffcertificate' );
        }
        
        // Check captcha presence
        if ( ! isset( $data['ffc_captcha_ans'] ) || ! isset( $data['ffc_captcha_hash'] ) ) {
            return __( 'Error: Please answer the security question.', 'ffcertificate' );
        }
        
        // Validate captcha answer using verify_simple_captcha()
        if ( ! self::verify_simple_captcha( $data['ffc_captcha_ans'], $data['ffc_captcha_hash'] ) ) {
            return __( 'Error: The math answer is incorrect.', 'ffcertificate' );
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
     * Normalize Brazilian name with proper capitalization
     *
     * Capitalizes the first letter of each word, except for common
     * Portuguese connectives (prepositions) which remain lowercase.
     *
     * Examples:
     * - "ALEX PEREIRA DA SILVA" → "Alex Pereira da Silva"
     * - "maria dos santos e oliveira" → "Maria dos Santos e Oliveira"
     * - "JOÃO DE SOUZA FILHO" → "João de Souza Filho"
     *
     * @since 4.3.0
     * @param string $name Name to normalize
     * @return string Normalized name
     */
    public static function normalize_brazilian_name( string $name ): string {
        if ( empty( $name ) ) {
            return '';
        }

        // Brazilian Portuguese connectives that should remain lowercase
        $connectives = array(
            'da', 'das', 'de', 'do', 'dos',  // Most common
            'e',                              // "and" between names
            'di', 'du',                       // Italian/French origin
        );

        // Convert entire string to lowercase first, then apply mb_convert_case
        // Use mb functions for proper UTF-8 handling (accented chars like ã, é, ç)
        $name = mb_strtolower( trim( $name ), 'UTF-8' );

        // Split into words
        $words = preg_split( '/\s+/', $name );

        $normalized_words = array();
        foreach ( $words as $word ) {
            if ( empty( $word ) ) {
                continue;
            }

            // Check if word is a connective (case-insensitive comparison)
            if ( in_array( mb_strtolower( $word, 'UTF-8' ), $connectives, true ) ) {
                // Keep connective lowercase
                $normalized_words[] = mb_strtolower( $word, 'UTF-8' );
            } else {
                // Capitalize first letter
                $normalized_words[] = mb_strtoupper( mb_substr( $word, 0, 1, 'UTF-8' ), 'UTF-8' )
                    . mb_substr( $word, 1, null, 'UTF-8' );
            }
        }

        // Handle edge case: if first word is a connective, capitalize it anyway
        // (Names shouldn't start with lowercase connective)
        if ( ! empty( $normalized_words ) && in_array( $normalized_words[0], $connectives, true ) ) {
            $normalized_words[0] = mb_strtoupper( mb_substr( $normalized_words[0], 0, 1, 'UTF-8' ), 'UTF-8' )
                . mb_substr( $normalized_words[0], 1, null, 'UTF-8' );
        }

        return implode( ' ', $normalized_words );
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
    public static function generate_success_html( array $submission_data, int $form_id, string $submission_date, string $success_message = '' ): string {
        // Get form configuration
        $form_config = get_post_meta( $form_id, '_ffc_form_config', true );
        if ( ! is_array( $form_config ) ) {
            $form_config = array();
        }

        // Get form title
        $form_post = get_post( $form_id );
        $form_title = $form_post ? $form_post->post_title : __( 'Certificate', 'ffcertificate' );

        // Default success message
        if ( empty( $success_message ) ) {
            $success_message = isset( $form_config['success_message'] ) && ! empty( $form_config['success_message'] )
                ? $form_config['success_message']
                : __( 'Success! Your certificate has been generated.', 'ffcertificate' );
        }

        // Format date
        $date_formatted = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $submission_date ) );

        // Auth code
        $auth_code = isset( $submission_data['auth_code'] ) ? $submission_data['auth_code'] : '';

        // Load template
        ob_start();
        include FFC_PLUGIN_DIR . 'templates/submission-success.php';
        return ob_get_clean();
    }
}