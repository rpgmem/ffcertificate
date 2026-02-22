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
     * @return array<string, array<string, array<never, never>>> Allowed HTML tags with their attributes
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
                'role'        => array(),
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
            'th'     => array(
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
    
    // ── Document methods delegated to DocumentFormatter (Sprint 30) ──

    /** @deprecated Use DocumentFormatter::validate_cpf() */
    public static function validate_cpf( string $cpf ): bool {
        return DocumentFormatter::validate_cpf( $cpf );
    }

    /** @deprecated Use DocumentFormatter::validate_phone() */
    public static function validate_phone( string $phone ): bool {
        return DocumentFormatter::validate_phone( $phone );
    }

    /** @deprecated Use DocumentFormatter::PHONE_REGEX */
    public const PHONE_REGEX = '^\(?\d{2}\)?\s?\d{4,5}-?\d{4}$';

    /** @deprecated Use DocumentFormatter::format_cpf() */
    public static function format_cpf( string $cpf ): string {
        return DocumentFormatter::format_cpf( $cpf );
    }

    /** @deprecated Use DocumentFormatter::validate_rf() */
    public static function validate_rf( string $rf ): bool {
        return DocumentFormatter::validate_rf( $rf );
    }

    /** @deprecated Use DocumentFormatter::format_rf() */
    public static function format_rf( string $rf ): string {
        return DocumentFormatter::format_rf( $rf );
    }

    /** @deprecated Use DocumentFormatter::mask_cpf() */
    public static function mask_cpf( string $value ): string {
        return DocumentFormatter::mask_cpf( $value );
    }

    /** @deprecated Use DocumentFormatter::mask_email() */
    public static function mask_email( string $email ): string {
        return DocumentFormatter::mask_email( $email );
    }

    /** @deprecated Use DocumentFormatter::format_auth_code() */
    public static function format_auth_code( string $code ): string {
        return DocumentFormatter::format_auth_code( $code );
    }

    /** @deprecated Use DocumentFormatter::format_document() */
    public static function format_document( string $value, string $type = 'auto' ): string {
        return DocumentFormatter::format_document( $value, $type );
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
    
    // ── Auth code methods delegated to AuthCodeService (Sprint 31) ──

    /** @deprecated Use AuthCodeService::generate_random_string() */
    public static function generate_random_string( int $length = 12, string $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789' ): string {
        return AuthCodeService::generate_random_string( $length, $chars );
    }

    /** @deprecated Use AuthCodeService::generate_auth_code() */
    public static function generate_auth_code(): string {
        return AuthCodeService::generate_auth_code();
    }

    /** @deprecated Use AuthCodeService::generate_globally_unique_auth_code() */
    public static function generate_globally_unique_auth_code(): string {
        return AuthCodeService::generate_globally_unique_auth_code();
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
    
    /** @deprecated Use DocumentFormatter::clean_auth_code() */
    public static function clean_auth_code( string $code ): string {
        return DocumentFormatter::clean_auth_code( $code );
    }

    /** @deprecated Use DocumentFormatter::clean_identifier() */
    public static function clean_identifier( string $value ): string {
        return DocumentFormatter::clean_identifier( $value );
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
    
    // ── Security methods delegated to SecurityService (Sprint 31) ──

    /**
     * @deprecated Use SecurityService::generate_simple_captcha()
     * @return array<string, mixed>
     */
    public static function generate_simple_captcha(): array {
        return SecurityService::generate_simple_captcha();
    }

    /** @deprecated Use SecurityService::verify_simple_captcha() */
    public static function verify_simple_captcha( string $answer, string $hash ): bool {
        return SecurityService::verify_simple_captcha( $answer, $hash );
    }

    /**
     * @deprecated Use SecurityService::validate_security_fields()
     * @param array<string, mixed> $data POST data to validate
     * @return true|string True on success, error message string on failure
     */
    public static function validate_security_fields( array $data ) {
        return SecurityService::validate_security_fields( $data );
    }

    // ── Data sanitization methods delegated to DataSanitizer (Sprint 31) ──

    /**
     * @deprecated Use DataSanitizer::recursive_sanitize()
     * @param mixed $data Data to sanitize
     * @return mixed Sanitized data
     */
    public static function recursive_sanitize( $data ) {
        return DataSanitizer::recursive_sanitize( $data );
    }

    /** @deprecated Use DataSanitizer::normalize_brazilian_name() */
    public static function normalize_brazilian_name( string $name ): string {
        return DataSanitizer::normalize_brazilian_name( $name );
    }

    /**
     * Generate success HTML response for frontend form submission
     *
     * @since 2.9.16
     * @param array<string, mixed> $submission_data Submission data
     * @param int                  $form_id Form ID
     * @param string               $submission_date Submission date
     * @param string               $success_message Success message
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

        // Auth code (formatted for display)
        $auth_code = isset( $submission_data['auth_code'] ) ? self::format_auth_code( $submission_data['auth_code'] ) : '';

        // Load template
        ob_start();
        include FFC_PLUGIN_DIR . 'templates/submission-success.php';
        return ob_get_clean();
    }
}