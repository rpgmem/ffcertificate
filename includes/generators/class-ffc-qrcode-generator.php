<?php
declare(strict_types=1);

/**
 * QRCodeGenerator
 * Generates dynamic QR Codes for certificate verification
 *
 * Features:
 * - Customizable size, margin, error correction
 * - Base64 output for embedding in HTML/PDF
 * - Optional database caching
 * - Placeholder parsing ({{qr_code:param=value}})
 *
 * @version 3.3.0 - Added strict types and type hints
 * @version 3.2.0 - Migrated to namespace (Phase 2)
 * @since 2.9.0
 * @since 2.9.2 OPTIMIZED to use FFC_Utils functions
 */

namespace FreeFormCertificate\Generators;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange

class QRCodeGenerator {

    use \FreeFormCertificate\Core\DatabaseHelperTrait;
    
    /**
     * Default settings
     *
     * @var array<string, mixed>
     */
    private $defaults = array(
        'size'        => 200,
        'margin'      => 2,
        'error_level' => 'M'
    );
    
    /**
     * Constructor
     */
    public function __construct() {
        // Load phpqrcode library
        if ( ! class_exists( '\\QRcode' ) ) {
            require_once FFC_PLUGIN_DIR . 'libs/phpqrcode/qrlib.php';
        }
        
        // Load defaults from settings
        $this->load_defaults_from_settings();
    }
    
    /**
     * Load default values from plugin settings
     */
    private function load_defaults_from_settings(): void {
        $settings = get_option( 'ffc_settings', array() );
        
        if ( isset( $settings['qr_default_size'] ) ) {
            $this->defaults['size'] = absint( $settings['qr_default_size'] );
        }
        
        if ( isset( $settings['qr_default_margin'] ) ) {
            $this->defaults['margin'] = absint( $settings['qr_default_margin'] );
        }
        
        if ( isset( $settings['qr_default_error_level'] ) ) {
            $this->defaults['error_level'] = sanitize_text_field( $settings['qr_default_error_level'] );
        }
    }
    
    /**
     * Parse placeholder and generate QR Code
     * 
     * Supported formats:
     * - {{qr_code}}
     * - {{qr_code:size=150}}
     * - {{qr_code:size=200:margin=0}}
     * - {{qr_code:size=250:margin=3:error=H}}
     * 
     * @param string $placeholder Full placeholder string
     * @param string $url Target URL for QR Code
     * @param int $submission_id Optional submission ID for cache
     * @return string HTML img tag with base64 QR Code
     */
    public function parse_and_generate( string $placeholder, string $url, int $submission_id = 0 ): string {
        // Parse parameters from placeholder
        $params = $this->parse_placeholder_params( $placeholder );
        
        \FreeFormCertificate\Core\Utils::debug_log( 'QR Code generation requested', array(
            'submission_id' => $submission_id,
            'size' => $params['size'],
            'cache_enabled' => $this->is_cache_enabled()
        ) );
        
        // Check cache if enabled and submission_id provided
        if ( $submission_id > 0 && $this->is_cache_enabled() ) {
            $cached = $this->get_from_cache( $submission_id );
            if ( $cached ) {
                \FreeFormCertificate\Core\Utils::debug_log( 'QR Code served from cache', array(
                    'submission_id' => $submission_id
                ) );
                return $this->format_as_img_tag( $cached, $params['size'] );
            }
        }
        
        /**
         * Filters the URL encoded in the QR code.
         *
         * @since 4.6.4
         * @param string $url           Target URL for QR code.
         * @param int    $submission_id  Submission ID (0 if not provided).
         * @param array  $params        QR code parameters (size, margin, error_level).
         */
        $url = apply_filters( 'ffcertificate_qrcode_url', $url, $submission_id, $params );

        // Generate QR Code
        $qr_base64 = $this->generate( $url, $params );

        if ( empty( $qr_base64 ) ) {
            \FreeFormCertificate\Core\Utils::debug_log( 'QR Code generation failed', array(
                'url' => substr( $url, 0, 50 ) . '...',
                'submission_id' => $submission_id
            ) );
            return '';
        }
        
        // Cache if enabled
        if ( $submission_id > 0 && $this->is_cache_enabled() ) {
            $this->save_to_cache( $submission_id, $qr_base64 );
            \FreeFormCertificate\Core\Utils::debug_log( 'QR Code cached', array(
                'submission_id' => $submission_id
            ) );
        }
        
        $img_html = $this->format_as_img_tag( $qr_base64, $params['size'] );

        /**
         * Filters the QR code HTML output (img tag).
         *
         * @since 4.6.4
         * @param string $img_html      HTML img tag with base64 QR code.
         * @param string $url           Target URL.
         * @param int    $submission_id  Submission ID.
         */
        return apply_filters( 'ffcertificate_qrcode_html', $img_html, $url, $submission_id );
    }

    /**
     * Parse parameters from placeholder string
     * 
     * Examples:
     * - "{{qr_code}}" → defaults
     * - "{{qr_code:size=150}}" → size=150
     * - "{{qr_code:size=200:margin=0:error=H}}" → all custom
     * 
     * @param string $placeholder
     * @return array<string, mixed> Parameters with keys: size, margin, error_level
     */
    private function parse_placeholder_params( string $placeholder ): array {
        $params = $this->defaults;
        
        // Remove {{ and }}
        $content = trim( str_replace( array( '{{', '}}' ), '', $placeholder ) );
        
        // Split by colon
        $parts = explode( ':', $content );
        
        // Skip first part (qr_code)
        array_shift( $parts );
        
        // Parse each parameter
        foreach ( $parts as $part ) {
            if ( strpos( $part, '=' ) === false ) {
                continue;
            }
            
            list( $key, $value ) = explode( '=', $part, 2 );
            $key = trim( $key );
            $value = trim( $value );
            
            switch ( $key ) {
                case 'size':
                    $params['size'] = absint( $value );
                    break;
                case 'margin':
                    $params['margin'] = absint( $value );
                    break;
                case 'error':
                    $params['error_level'] = strtoupper( $value );
                    break;
            }
        }
        
        // Validate ranges
        $params['size'] = max( 50, min( 1000, $params['size'] ) );
        $params['margin'] = max( 0, min( 10, $params['margin'] ) );
        
        if ( ! in_array( $params['error_level'], array( 'L', 'M', 'Q', 'H' ) ) ) {
            $params['error_level'] = 'M';
        }
        
        return $params;
    }
    
    /**
     * Generate QR Code as base64 PNG
     * 
     * @param string $url Target URL
     * @param array<string, mixed> $params Generation parameters
     * @return string Base64 encoded PNG
     */
    public function generate( string $url, array $params = array() ): string {
        // Merge with defaults
        $params = array_merge( $this->defaults, $params );
        
        // Validate URL
        if ( empty( $url ) ) {
            return '';
        }
        
        try {
            // Create temporary file
            $temp_file = tempnam( sys_get_temp_dir(), 'ffc_qr_' );
            
            // Generate QR Code
            \QRcode::png(
                $url,
                $temp_file,
                $this->get_error_correction_constant( $params['error_level'] ),
                $params['size'] / 10, // Size parameter for phpqrcode
                $params['margin']
            );
            
            // Read file and encode
            if ( file_exists( $temp_file ) ) {
                $image_data = file_get_contents( $temp_file );
                $base64 = base64_encode( $image_data );
                
                // Clean up
                wp_delete_file( $temp_file );
                
                return $base64;
            }
            
            return '';
            
        } catch ( Exception $e ) {
            \FreeFormCertificate\Core\Utils::debug_log( 'QR Code generation exception', array(
                'error' => $e->getMessage(),
                'url' => substr( $url, 0, 50 ) . '...'
            ) );
            return '';
        }
    }
    
    /**
     * Get error correction constant for phpqrcode library
     * 
     * @param string $level L, M, Q, or H
     * @return int QR_ECLEVEL constant
     */
    private function get_error_correction_constant( string $level ): int {
        switch ( strtoupper( $level ) ) {
            case 'L':
                return \QR_ECLEVEL_L; // 7%
            case 'Q':
                return \QR_ECLEVEL_Q; // 25%
            case 'H':
                return \QR_ECLEVEL_H; // 30%
            case 'M':
            default:
                return \QR_ECLEVEL_M; // 15%
        }
    }
    
    /**
     * Format base64 QR Code as HTML img tag
     * 
     * @param string $base64 Base64 encoded PNG
     * @param int $size Display size in pixels
     * @return string HTML img tag
     */
    private function format_as_img_tag( string $base64, int $size ): string {
        if ( empty( $base64 ) ) {
            return '';
        }
        
        return sprintf(
            '<img src="data:image/png;base64,%s" alt="QR Code" style="width:%dpx; height:%dpx; display:block; margin:0 auto;" />',
            $base64,
            $size,
            $size
        );
    }
    
    /**
     * Check if cache is enabled
     * 
     * @return bool
     */
    private function is_cache_enabled(): bool {
        $settings = get_option( 'ffc_settings', array() );
        return isset( $settings['qr_cache_enabled'] ) && $settings['qr_cache_enabled'] == 1;
    }
    
    /**
     * Get QR Code from cache
     * 
     * @param int $submission_id
     * @return string|false Base64 QR Code or false if not found
     */
    private function get_from_cache( int $submission_id ) {
        global $wpdb;
        $table_name = \FreeFormCertificate\Core\Utils::get_submissions_table();
        
        // Check if column exists
        if ( ! $this->cache_column_exists() ) {
            return false;
        }
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $qr_code = $wpdb->get_var( $wpdb->prepare(
            "SELECT qr_code_cache FROM %i WHERE id = %d",
            $table_name,
            $submission_id
        ) );
        
        return ! empty( $qr_code ) ? $qr_code : false;
    }
    
    /**
     * Save QR Code to cache
     * 
     * @param int $submission_id
     * @param string $qr_base64 Base64 encoded QR Code
     * @return bool Success
     */
    private function save_to_cache( int $submission_id, string $qr_base64 ): bool {
        global $wpdb;
        $table_name = \FreeFormCertificate\Core\Utils::get_submissions_table();
        
        // Check if column exists, create if needed
        if ( ! $this->cache_column_exists() ) {
            $this->create_cache_column();
        }
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->update(
            $table_name,
            array( 'qr_code_cache' => $qr_base64 ),
            array( 'id' => $submission_id ),
            array( '%s' ),
            array( '%d' )
        );
        
        return $result !== false;
    }
    
    /**
     * Check if qr_code_cache column exists
     * 
     * @return bool
     */
    private function cache_column_exists(): bool {
        $table_name = \FreeFormCertificate\Core\Utils::get_submissions_table();
        return self::column_exists( $table_name, 'qr_code_cache' );
    }
    
    /**
     * Create qr_code_cache column if it doesn't exist
     * 
     * @return bool Success
     */
    private function create_cache_column(): bool {
        $table_name = \FreeFormCertificate\Core\Utils::get_submissions_table();
        return self::add_column_if_missing( $table_name, 'qr_code_cache', 'LONGTEXT NULL', 'magic_token' );
    }
    
    /**
     * Clear QR Code cache
     * 
     * @param int $submission_id Optional specific submission (0 = all)
     * @return int Number of cleared entries
     */
    public function clear_cache( int $submission_id = 0 ): bool {
        global $wpdb;
        $table_name = \FreeFormCertificate\Core\Utils::get_submissions_table();
        
        if ( ! $this->cache_column_exists() ) {
            return 0;
        }
        
        \FreeFormCertificate\Core\Utils::debug_log( 'Clearing QR cache', array(
            'submission_id' => $submission_id,
            'scope' => $submission_id ? 'single' : 'all'
        ) );
        
        if ( $submission_id ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $result = $wpdb->update(
                $table_name,
                array( 'qr_code_cache' => null ),
                array( 'id' => $submission_id ),
                array( '%s' ),
                array( '%d' )
            );
            $cleared = $result !== false ? 1 : 0;
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $result = $wpdb->query(
                $wpdb->prepare( "UPDATE %i SET qr_code_cache = NULL WHERE qr_code_cache IS NOT NULL", $table_name )
            );
            $cleared = (int) $result;
        }
        
        \FreeFormCertificate\Core\Utils::debug_log( 'QR cache cleared', array(
            'cleared_count' => $cleared
        ) );
        
        return $cleared;
    }
    
    /**
     * Generate QR code for submission magic link
     * 
     * Uses FFC_Magic_Link_Helper to generate the link
     * 
     * @since 2.9.16
     * @param int $submission_id Submission ID
     * @param int $size QR code size (default: 200)
     * @return string Base64 QR code or empty string
     */
    public function generate_magic_link_qr( int $submission_id, int $size = 200 ): string {
    global $wpdb;
    $table_name = \FreeFormCertificate\Core\Utils::get_submissions_table();
    
    // Get submission magic token
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $submission = $wpdb->get_row(
        $wpdb->prepare( "SELECT magic_token FROM %i WHERE id = %d", $table_name, $submission_id ),
        ARRAY_A
    );
    
    if ( ! $submission || empty( $submission['magic_token'] ) ) {
        \FreeFormCertificate\Core\Utils::debug_log( 'Magic QR: No token found', array(
            'submission_id' => $submission_id
        ) );
        return '';
    }
    
    $magic_link = \FreeFormCertificate\Generators\MagicLinkHelper::generate_magic_link( $submission['magic_token'] );
    
    if ( empty( $magic_link ) ) {
        \FreeFormCertificate\Core\Utils::debug_log( 'Magic QR: Link generation failed', array(
            'submission_id' => $submission_id,
            'token' => substr( $submission['magic_token'], 0, 8 ) . '...'
        ) );
        return '';
    }
    
    \FreeFormCertificate\Core\Utils::debug_log( 'Magic QR: Generating for submission', array(
        'submission_id' => $submission_id,
        'url_length' => strlen( $magic_link )
    ) );
    
    // Generate QR code with magic link
    $params = array(
        'size' => $size,
        'margin' => $this->defaults['margin'],
        'error_level' => $this->defaults['error_level']
    );
    
    return $this->generate( $magic_link, $params );
    }

    /**
     * Get cache statistics
     *
     * @return array<string, mixed> Statistics
     */
    public function get_cache_stats(): array {
        global $wpdb;
        $table_name = \FreeFormCertificate\Core\Utils::get_submissions_table();
        
        if ( ! $this->cache_column_exists() ) {
            return array(
                'enabled' => false,
                'total_submissions' => 0,
                'cached_qr_codes' => 0,
                'cache_size' => '0 KB',
                'cache_dir' => 'N/A'
            );
        }
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $total = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table_name ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $cached = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE qr_code_cache IS NOT NULL', $table_name ) );
        
        $avg_size_bytes = 4096; // 4 KB per QR Code (estimate)
        $total_bytes = $cached * $avg_size_bytes;
        
        return array(
            'enabled' => $this->is_cache_enabled(),
            'total_submissions' => (int) $total,
            'cached_qr_codes' => (int) $cached,
            'cache_size' => \FreeFormCertificate\Core\Utils::format_bytes( $total_bytes ),
            'cache_dir' => 'Database (qr_code_cache column)'
        );
    }
}