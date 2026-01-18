<?php
/**
 * FFC_Magic_Link_Helper
 * 
 * Centralizes magic link generation and validation logic
 * 
 * @since 2.9.16
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FFC_Magic_Link_Helper {
    
    /**
     * Get verification page URL
     * 
     * @return string URL of verification page
     */
    public static function get_verification_page_url() {
        // Try to get stored page ID
        $page_id = get_option( 'ffc_verification_page_id', 0 );
        
        if ( $page_id ) {
            $url = get_permalink( $page_id );
            if ( $url ) {
                return trailingslashit( $url );
            }
        }
        
        // Fallback to /valid/
        return home_url( '/valid/' );
    }
    
    /**
     * Generate magic link from token
     * 
     * Centralizes the logic of building magic links throughout the plugin
     * 
     * @param string $token Magic token (32 hex characters)
     * @return string Complete magic link URL
     */
    public static function generate_magic_link( $token ) {
        if ( empty( $token ) ) {
            return '';
        }
        
        // Get base URL
        $base_url = self::get_verification_page_url();
        
        // Build magic link with hash format
        // Format: /valid/#token=abc123
        return $base_url . '#token=' . $token;
    }
    
    /**
     * Ensure submission has magic token
     * 
     * Generates token if missing and returns it
     * 
     * @param int $submission_id Submission ID
     * @param FFC_Submission_Handler $handler Submission handler instance
     * @return string Magic token (32 hex characters)
     */
    public static function ensure_token( $submission_id, $handler ) {
    if ( ! $handler || ! method_exists( $handler, 'ensure_magic_token' ) ) {
        return '';
    }
    
    $token = $handler->ensure_magic_token( $submission_id );
    
    // Se o token não é válido, gerar um novo
    if ( ! self::is_valid_token( $token ) ) {
        $token = bin2hex( random_bytes( 16 ) );  // Gera 32 caracteres hex
        // Assumindo que o handler salva o token; se não, adicione: $handler->save_magic_token( $submission_id, $token );
    }
    
    return $token;
    }
    
    /**
     * Get magic link for submission
     * 
     * Combines ensure_token + generate_magic_link
     * 
     * @param int $submission_id Submission ID
     * @param FFC_Submission_Handler $handler Submission handler instance
     * @return string Complete magic link URL
     */
    public static function get_submission_magic_link( $submission_id, $handler ) {
        $token = self::ensure_token( $submission_id, $handler );
        
        if ( empty( $token ) ) {
            return '';
        }
        
        return self::generate_magic_link( $token );
    }
    
    /**
     * Get magic link from submission array
     * 
     * Useful when you already have the submission data
     * 
     * @param array $submission Submission array with 'magic_token' key
     * @param FFC_Submission_Handler $handler Submission handler (optional, for generating if missing)
     * @return string Complete magic link URL
     */
    public static function get_magic_link_from_submission( $submission, $handler = null ) {
        $token = isset( $submission['magic_token'] ) ? $submission['magic_token'] : '';
        
        // If no token and handler provided, try to generate
        if ( empty( $token ) && $handler && ! empty( $submission['id'] ) ) {
            $token = self::ensure_token( $submission['id'], $handler );
        }
        
        return self::generate_magic_link( $token );
    }
    
    /**
     * Validate magic token format
     * 
     * @param string $token Token to validate
     * @return bool True if valid format
     */
    public static function is_valid_token( $token ) {
        // Magic token should be 32 hex characters
        return ! empty( $token ) && preg_match( '/^[a-f0-9]{32}$/i', $token );
    }
    
    /**
     * Extract token from URL
     * 
     * Supports both formats:
     * - /?ffc_magic=token
     * - /valid#token=token
     * - /valid?token=token
     * 
     * @param string $url URL to parse
     * @return string Token or empty string
     */
    public static function extract_token_from_url( $url ) {
        // Try query string parameters
        $query = parse_url( $url, PHP_URL_QUERY );
        if ( $query ) {
            parse_str( $query, $params );
            
            if ( isset( $params['ffc_magic'] ) ) {
                return $params['ffc_magic'];
            }
            
            if ( isset( $params['token'] ) ) {
                return $params['token'];
            }
        }
        
        // Try hash fragment
        $fragment = parse_url( $url, PHP_URL_FRAGMENT );
        if ( $fragment ) {
            parse_str( $fragment, $params );
            
            if ( isset( $params['token'] ) ) {
                return $params['token'];
            }
        }
        
        return '';
    }
    
    /**
     * Get magic link HTML (for display in admin)
     * 
     * @param string $token Magic token
     * @param bool $with_copy_button Include copy button
     * @return string HTML output
     */
    public static function get_magic_link_html( $token, $with_copy_button = true ) {
        if ( empty( $token ) ) {
            return '<em>' . __( 'No magic token', 'ffc' ) . '</em>';
        }
        
        $magic_link = self::generate_magic_link( $token );
        
        $html = '<a href="' . esc_url( $magic_link ) . '" target="_blank" class="ffc-magic-link">';
        $html .= esc_html( $magic_link );
        $html .= '</a>';
        
        if ( $with_copy_button ) {
            $html .= ' <button type="button" class="button button-small ffc-copy-magic-link" ';
            $html .= 'data-url="' . esc_attr( $magic_link ) . '" ';
            $html .= 'title="' . esc_attr__( 'Copy to clipboard', 'ffc' ) . '">';
            $html .= '📋 ' . __( 'Copy', 'ffc' );
            $html .= '</button>';
        }
        
        return $html;
    }
    
    /**
     * Generate QR code URL for magic link
     * 
     * @param string $token Magic token
     * @param int $size QR code size (default: 200)
     * @return string QR code image URL
     */
    public static function get_magic_link_qr_code( $token, $size = 200 ) {
        if ( empty( $token ) ) {
            return '';
        }
        
        $magic_link = self::generate_magic_link( $token );
        
        // Use Google Charts API (or your preferred QR service)
        return 'https://chart.googleapis.com/chart?chs=' . $size . 'x' . $size . '&cht=qr&chl=' . urlencode( $magic_link );
    }
    
    /**
     * Debug: Get info about a magic link
     * 
     * @param string $token Magic token
     * @return array Debug information
     */
    public static function debug_info( $token ) {
        return array(
            'token' => $token,
            'token_valid' => self::is_valid_token( $token ),
            'token_length' => strlen( $token ),
            'verification_url' => self::get_verification_page_url(),
            'magic_link' => self::generate_magic_link( $token ),
            'qr_code_url' => self::get_magic_link_qr_code( $token )
        );
    }
}