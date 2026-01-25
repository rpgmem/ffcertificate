<?php
declare(strict_types=1);

/**
 * FFC_PDF_Generator
 *
 * Centralized PDF generation for all contexts:
 * - Form submission (frontend)
 * - Manual verification
 * - Magic link verification
 * - Admin PDF download
 * - Certificate reprint
 *
 * v3.3.0: Added strict types and type hints
 * v2.9.2: Single source of truth for PDF generation
 * v2.9.14: REFACTORED - Moved generate_html logic from FFC_Email_Handler
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FFC_PDF_Generator {
    
    /**
     * Constructor
     */
    public function __construct() {
        // Reserved for future hooks
    }
    
    /**
     * Generate PDF data for any context
     *
     * @param int $submission_id Submission ID
     * @param object $submission_handler Submission handler instance
     * @return array|WP_Error PDF data array or error
     */
    public function generate_pdf_data( int $submission_id, object $submission_handler ) {
        // Get submission
        $submission = $submission_handler->get_submission( $submission_id );
        
        if ( ! $submission ) {
            return new WP_Error( 'submission_not_found', __( 'Submission not found.', 'ffc' ) );
        }
        
        // Convert to array
        $sub_array = (array) $submission;
        
        // ✅ v2.9.15: REBUILD complete data (columns + JSON)

        // Step 1: Required fields from columns
        $data = array(
            'email' => $sub_array['email'],  // ✅ Da coluna
        );
        
        // Adicionar auth_code se existir na coluna
        if ( ! empty( $sub_array['auth_code'] ) ) {
            $data['auth_code'] = $sub_array['auth_code'];  // ✅ Da coluna
        }
        
        // Adicionar cpf_rf se existir na coluna
        if ( ! empty( $sub_array['cpf_rf'] ) ) {
            $data['cpf_rf'] = $sub_array['cpf_rf'];  // ✅ Da coluna
        }
        
        // Step 2: Extra fields from JSON
        $extra_data = json_decode( $sub_array['data'], true );
        if ( ! is_array( $extra_data ) ) {
            $extra_data = json_decode( stripslashes( $sub_array['data'] ), true );
        }

        FFC_Debug::log_pdf( 'JSON: data', $sub_array['data'] );
        FFC_Debug::log_pdf( 'JSON: extra_data', $extra_data );
        FFC_Debug::log_pdf( 'JSON: is_array', is_array( $extra_data ) ? 'YES' : 'NO' );

        // Step 3: Merge (extras do NOT overwrite required fields)
        if ( is_array( $extra_data ) && ! empty( $extra_data ) ) {
            $data = array_merge( $extra_data, $data );  // ✅ Important order: columns have priority
        }

        // ✅ Now $data has EVERYTHING: columns + JSON
        FFC_Debug::log_pdf( 'MERGE: count', count( $extra_data ) );
        FFC_Debug::log_pdf( 'MERGE: AFTER', $data );
        
        // Enrich data with submission metadata
        $data = $this->enrich_submission_data( $data, $sub_array );
        
        // Get form data
        $form_id = $sub_array['form_id'];
        $form_title = get_the_title( $form_id );
        $form_config = get_post_meta( $form_id, '_ffc_form_config', true );
        $bg_image_url = get_post_meta( $form_id, '_ffc_form_bg', true );
        
        // ✅ Generate HTML using internal method (not email handler)
        $html = $this->generate_html( $data, $form_title, $form_config, $sub_array['submission_date'] );
        
        // Get verification code from submission data
        $auth_code = isset( $data['auth_code'] ) ? $data['auth_code'] : '';
        
        // Generate safe filename with verification code
        $filename = $this->generate_filename( $form_title, $auth_code );
        
        // Log generation
        if ( class_exists( 'FFC_Utils' ) && method_exists( 'FFC_Utils', 'debug_log' ) ) {
            FFC_Utils::debug_log( 'PDF data generated', array(
                'submission_id' => $submission_id,
                'form_id' => $form_id,
                'form_title' => FFC_Utils::truncate( $form_title, 50 ),
                'auth_code' => $auth_code,
                'filename' => $filename,
                'html_length' => strlen( $html ),
                'has_bg_image' => ! empty( $bg_image_url )
            ) );
        }
        
        return array(
            'html'          => $html,
            'template'      => $html,  // Alias for backward compatibility
            'filename'      => $filename,
            'form_title'    => $form_title,
            'auth_code'     => $auth_code,
            'submission_id' => $submission_id,
            'submission'    => $data,
            'bg_image'      => $bg_image_url
        );
    }
    
    /**
     * Enrich submission data with metadata
     * 
     * @param array $data Original submission data
     * @param array $submission Submission database row
     * @return array Enriched data
     */
    private function enrich_submission_data( array $data, array $submission ): array {
        // Add email if missing
        if ( ! isset( $data['email'] ) && ! empty( $submission['email'] ) ) {
            $data['email'] = $submission['email'];
        }
        
        // Add formatted date if missing
        if ( ! isset( $data['fill_date'] ) ) {
            // ✅ v2.10.0: Use plugin date format setting
            $settings = get_option( 'ffc_settings', array() );
            $date_format = isset( $settings['date_format'] ) ? $settings['date_format'] : 'F j, Y';
            
            // If custom format selected, use it
            if ( $date_format === 'custom' && ! empty( $settings['date_format_custom'] ) ) {
                $date_format = $settings['date_format_custom'];
            }
            
            $data['fill_date'] = date_i18n(
                $date_format,
                strtotime( $submission['submission_date'] )
            );
        }
        
        // Add date alias
        if ( ! isset( $data['date'] ) ) {
            $data['date'] = $data['fill_date'];
        }
        
        // Add submission ID
        if ( ! isset( $data['submission_id'] ) ) {
            $data['submission_id'] = $submission['id'];
        }
        
        // Add magic token if exists
        if ( ! isset( $data['magic_token'] ) && ! empty( $submission['magic_token'] ) ) {
            $data['magic_token'] = $submission['magic_token'];
        }
        
        return $data;
    }
    
    /**
     * Generate HTML from template
     *
     * ✅ MOVED FROM FFC_Email_Handler (v2.9.14)
     * This is now the single source of truth for HTML generation
     *
     * Supported placeholders:
     * - {{name}}, {{email}}, {{auth_code}}, etc.
     * - {{submission_date}} - Date when submission was created (from database)
     * - {{print_date}} - Current date/time when PDF is being generated
     * - {{qr_code}} - QR Code with default settings
     * - {{qr_code:size=150}} - Custom size
     * - {{qr_code:size=200:margin=0}} - Custom size and margin
     * - {{validation_url}} - Validation link with magic token
     * - {{validation_url link:m>v}} - Custom link format
     *
     * @param array $data Submission data
     * @param string $form_title Form title
     * @param array $form_config Form configuration
     * @param string $submission_date Submission creation date from database
     * @return string Generated HTML
     */
    public function generate_html( array $data, string $form_title, array $form_config, ?string $submission_date = null ): string {
        $layout = isset( $form_config['pdf_layout'] ) ? $form_config['pdf_layout'] : '';
        
        // Use default template if none configured
        if ( empty( $layout ) ) {
            return $this->generate_default_html( $data, $form_title );
        }
        
        // Replace standard placeholders
        // ✅ v2.10.0: Use plugin date format setting
        $settings = get_option( 'ffc_settings', array() );
        $date_format = isset( $settings['date_format'] ) ? $settings['date_format'] : 'F j, Y';

        // If custom format selected, use it
        if ( $date_format === 'custom' && ! empty( $settings['date_format_custom'] ) ) {
            $date_format = $settings['date_format_custom'];
        }

        // {{submission_date}} - Submission creation date in DB (to avoid issues with reprinting)
        if ( ! empty( $submission_date ) ) {
            $layout = str_replace( '{{submission_date}}', date_i18n( $date_format, strtotime( $submission_date ) ), $layout );
        }

        // {{print_date}} - Current date/time of PDF generation/printing
        $layout = str_replace( '{{print_date}}', date_i18n( $date_format, current_time( 'timestamp' ) ), $layout );

        $layout = str_replace( '{{form_title}}', $form_title, $layout );
        
        // Ensure email field exists
        if ( ! isset( $data['email'] ) && isset( $data['user_email'] ) ) {
            $data['email'] = $data['user_email'];
        }
        
        // Replace field placeholders with formatted values
        foreach ( $data as $key => $value ) {
            if ( is_array( $value ) ) {
                $value = implode( ', ', $value );
            }
            
            // Format documents (CPF, RF, RG)
            if ( in_array( $key, array( 'cpf', 'cpf_rf', 'rg' ) ) ) {
                $value = FFC_Utils::format_document( $value );
            }
            
            // Format auth code
            if ( $key === 'auth_code' ) {
                $value = FFC_Utils::format_auth_code( $value );
            }
            
            // Apply allowed HTML filtering
            $safe_value = wp_kses( $value, FFC_Utils::get_allowed_html_tags() );
            $layout = str_replace( '{{' . $key . '}}', $safe_value, $layout );
            FFC_Debug::log_pdf( 'REPLACED: {{' . $key . '}}', substr( $safe_value, 0, 30 ) );
        }
        
        // Fix relative URLs to absolute
        $site_url = untrailingslashit( get_home_url() );
        $layout = preg_replace('/(src|href|background)=["\']\/([^"\']+)["\']/i', '$1="' . $site_url . '/$2"', $layout);
        
        // Process QR Code placeholders
        if ( strpos( $layout, '{{qr_code' ) !== false ) {
            $layout = $this->process_qrcode_placeholders( $layout, $data, $form_config );
        }
        
        // Process Validation URL placeholders
        if ( strpos( $layout, '{{validation_url' ) !== false ) {
            $layout = $this->process_validation_url_placeholders( $layout, $data );
        }
        
        return $layout;
    }
    
    /**
     * Process QR Code placeholders in template
     * 
     * ✅ MOVED FROM FFC_Email_Handler (v2.9.14)
     * 
     * Replaces {{qr_code}} and variants with actual QR Code images
     * 
     * Supports formats:
     * - {{qr_code}} - Default size
     * - {{qr_code:size=150}} - Custom size
     * - {{qr_code:size=200:margin=0}} - Size + margin
     * - {{qr_code:size=250:margin=3:error=H}} - All params
     * 
     * @param string $layout Template HTML
     * @param array $data Submission data
     * @param array $form_config Form configuration
     * @return string Processed HTML
     */
    private function process_qrcode_placeholders( string $layout, array $data, array $form_config ): string {
        // Initialize QR Code generator
        if ( ! class_exists( 'FFC_QRCode_Generator' ) ) {
            require_once FFC_PLUGIN_DIR . 'includes/generators/class-ffc-qrcode-generator.php';
        }
        
        $qr_generator = new FFC_QRCode_Generator();
        
        // Determine target URL (magic link or verification page)
        $target_url = $this->get_qr_code_target_url( $data );
        
        // Get submission ID for caching
        $submission_id = isset( $data['submission_id'] ) ? absint( $data['submission_id'] ) : 0;
        
        // Replace all QR Code placeholders
        $layout = preg_replace_callback(
            '/\{\{qr_code(?::([^}]+))?\}\}/',
            function( $matches ) use ( $qr_generator, $target_url, $submission_id ) {
                $placeholder = $matches[0];
                return $qr_generator->parse_and_generate( $placeholder, $target_url, $submission_id );
            },
            $layout
        );
        
        return $layout;
    }
    
    /**
     * Generate QR code for submission magic link
     * 
     * @param int $submission_id Submission ID
     * @param int $size QR code size (default: 200)
     * @return string QR code image URL or data URI
     */
    public static function generate_magic_link_qr( int $submission_id, int $size = 200 ): string {
    global $wpdb;
    
    // Get submission
    $table = $wpdb->prefix . 'ffc_submissions';
    $submission = $wpdb->get_row(
        $wpdb->prepare( "SELECT magic_token FROM $table WHERE id = %d", $submission_id ),
        ARRAY_A
    );
    
    if ( ! $submission || empty( $submission['magic_token'] ) ) {
        return '';
    }
    
    // Use helper to generate magic link
    $magic_link = FFC_Magic_Link_Helper::generate_magic_link( $submission['magic_token'] );
    
    if ( empty( $magic_link ) ) {
        return '';
    }
    
    // Generate QR code
    return self::generate_qr_code( $magic_link, $size );
    }

    /**
     * Get target URL for QR Code
     * 
     * ✅ MOVED FROM FFC_Email_Handler (v2.9.14)
     * 
     * Priority order:
     * 1. Magic link with hash format (if token exists)
     * 2. Verification page without parameters (fallback)
     * 
     * Format: /valid#token=xxx (hash prevents WordPress redirects)
     * 
     * @param array $data Submission data
     * @return string URL
     */
    private function get_qr_code_target_url( array $data ): string {
        $verification_url = untrailingslashit( site_url( 'valid' ) );
        
        // Priority 1: Magic link (if exists)
        $magic_token = isset( $data['magic_token'] ) ? $data['magic_token'] : '';
        $magic_url = FFC_Magic_Link_Helper::generate_magic_link( $magic_token );

        return ! empty( $magic_url ) ? $magic_url : $verification_url;
    }
    /**
     * Process Validation URL placeholders in template
     * 
     * ✅ MOVED FROM FFC_Email_Handler (v2.9.14)
     * 
     * Supports multiple formats:
     * - {{validation_url}} → Default: link to magic, text shows /valid
     * - {{validation_url link:m>v}} → Link to magic, text /valid
     * - {{validation_url link:v>v}} → Link to /valid, text /valid
     * - {{validation_url link:m>m}} → Link to magic, text magic
     * - {{validation_url link:v>m}} → Link to /valid, text magic
     * - {{validation_url link:m>"Custom Text"}} → Link to magic, custom text
     * - {{validation_url link:m>v target:_blank}} → With target
     * - {{validation_url link:m>v color:blue}} → With color
     * 
     * @param string $layout Template HTML
     * @param array $data Submission data
     * @return string Processed HTML
     */
    private function process_validation_url_placeholders( string $layout, array $data ): string {
        // Get base URLs
        $valid_url = untrailingslashit( site_url( 'valid' ) );
        
        // Get magic link URL (with fallback to /valid)
        $magic_token = isset( $data['magic_token'] ) ? $data['magic_token'] : '';
        $magic_url = FFC_Magic_Link_Helper::generate_magic_link( $magic_token );

        if ( empty( $magic_url ) ) {
            $magic_url = $valid_url; // Fallback
        }
        
        // Find all {{validation_url ...}} placeholders
        preg_match_all( '/{{validation_url(?:\s+([^}]+))?}}/', $layout, $matches, PREG_SET_ORDER );
        
        foreach ( $matches as $match ) {
            $full_placeholder = $match[0];
            $params_string = isset( $match[1] ) ? trim( $match[1] ) : '';
            
            // Parse parameters
            $params = $this->parse_validation_url_params( $params_string );
            
            // Determine href URL
            $href = ( $params['to'] === 'm' ) ? $magic_url : $valid_url;
            
            // Determine text
            $text = '';
            if ( is_string( $params['text'] ) && ! in_array( $params['text'], array( 'm', 'v' ) ) ) {
                // Custom text literal
                $text = $params['text'];
            } elseif ( $params['text'] === 'm' ) {
                $text = $magic_url;
            } else {
                // Default to /valid
                $text = $valid_url;
            }
            
            // Build <a> tag
            $link = '<a href="' . esc_url( $href ) . '" class="ffc-validation-link"';
            
            // Add target if specified
            if ( ! empty( $params['target'] ) ) {
                $link .= ' target="' . esc_attr( $params['target'] ) . '"';
            }
            
            // Add color style if specified
            if ( ! empty( $params['color'] ) ) {
                $link .= ' style="color: ' . esc_attr( $params['color'] ) . ';"';
            }
            
            $link .= '>' . esc_html( $text ) . '</a>';
            
            // Replace placeholder with generated link
            $layout = str_replace( $full_placeholder, $link, $layout );
        }
        
        return $layout;
    }
    
    /**
     * Parse validation URL parameters
     * 
     * ✅ MOVED FROM FFC_Email_Handler (v2.9.14)
     * 
     * @param string $params_string Parameter string (e.g., "link:m>v target:_blank color:blue")
     * @return array Parsed parameters
     */
    private function parse_validation_url_params( string $params_string ): array {
        $defaults = array(
            'to' => 'm',      // Default destination: magic link
            'text' => 'v',    // Default text: /valid URL
            'target' => '',
            'color' => ''
        );
        
        // Empty params = default (link:m>v)
        if ( empty( $params_string ) ) {
            return $defaults;
        }
        
        $params = $defaults;
        
        // Split by spaces to get individual parameters
        $parts = preg_split( '/\s+/', $params_string );
        
        foreach ( $parts as $part ) {
            // Parse link:X>Y or link:X>"Custom Text"
            if ( preg_match( '/^link:(.+)$/', $part, $link_match ) ) {
                $link_value = $link_match[1];
                
                // Check for custom text: m>"Text" or v>"Text"
                if ( preg_match( '/^([mv])>"([^"]+)"$/', $link_value, $custom_match ) ) {
                    $params['to'] = $custom_match[1];
                    $params['text'] = $custom_match[2]; // Literal text
                }
                // Check for standard format: m>v, v>m, m>m, v>v
                elseif ( preg_match( '/^([mv])>([mv])$/', $link_value, $standard_match ) ) {
                    $params['to'] = $standard_match[1];
                    $params['text'] = $standard_match[2];
                }
            }
            // Parse target:_blank
            elseif ( preg_match( '/^target:(.+)$/', $part, $target_match ) ) {
                $params['target'] = $target_match[1];
            }
            // Parse color:blue or color:#2271b1
            elseif ( preg_match( '/^color:(.+)$/', $part, $color_match ) ) {
                $params['color'] = $color_match[1];
            }
        }
        
        return $params;
    }
    
    /**
     * Generate default HTML template when none configured
     * 
     * @param array $data Submission data
     * @param string $form_title Form title
     * @return string Default HTML
     */
    private function generate_default_html( array $data, string $form_title ): string {
        $layout = '<div style="text-align:center; padding: 50px;">';
        $layout .= '<h1>' . esc_html( $form_title ) . '</h1>';
        $layout .= '<p>' . esc_html__( 'We certify that the holder of the data below has completed the event.', 'ffc' ) . '</p>';
        
        // Show name if exists
        if ( isset( $data['name'] ) ) {
            $layout .= '<h2>' . esc_html( $data['name'] ) . '</h2>';
        }
        
        // Show auth code if exists
        if ( isset( $data['auth_code'] ) ) {
            $layout .= '<p>' . esc_html__( 'Authenticity:', 'ffc' ) . ' ' . esc_html( FFC_Utils::format_auth_code( $data['auth_code'] ) ) . '</p>';
        }
        
        $layout .= '</div>';
        
        return $layout;
    }
    
    /**
     * Generate safe filename for PDF
     * 
     * @param string $form_title Form title
     * @param string $auth_code Verification code (optional)
     * @return string Safe filename with .pdf extension
     */
    private function generate_filename( string $form_title, string $auth_code = '' ): string {
        // Sanitize form title
        if ( class_exists( 'FFC_Utils' ) && method_exists( 'FFC_Utils', 'sanitize_filename' ) ) {
            $safe_name = FFC_Utils::sanitize_filename( $form_title );
        } else {
            $safe_name = sanitize_file_name( $form_title );
        }
        
        if ( empty( $safe_name ) ) {
            $safe_name = 'certificate';
        }
        
        // Add verification code if available
        if ( ! empty( $auth_code ) ) {
            // Sanitize code (usually already alphanumeric, but just in case)
            $safe_code = preg_replace( '/[^A-Za-z0-9]/', '', $auth_code );
            if ( ! empty( $safe_code ) ) {
                $safe_name .= '_' . strtoupper( $safe_code );
            }
        }
        
        return $safe_name . '.pdf';
    }
    
    /**
     * Generate PDF data from form submission (for frontend)
     * 
     * @param array $submission_data Posted form data
     * @param int $form_id Form ID
     * @param string $submission_date Submission date
     * @return array PDF data array
     */
    public function generate_pdf_data_from_form( array $submission_data, int $form_id, ?string $submission_date = null ) {
        // Get form data
        $form_post = get_post( $form_id );
        if ( ! $form_post ) {
            return new WP_Error( 'form_not_found', __( 'Form not found.', 'ffc' ) );
        }
        
        $form_title = $form_post->post_title;
        $form_config = get_post_meta( $form_id, '_ffc_form_config', true );
        $bg_image_url = get_post_meta( $form_id, '_ffc_form_bg', true );
        
        // Add formatted date
        if ( $submission_date ) {
            $formatted_date = date_i18n(
                get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
                strtotime( $submission_date )
            );
            $submission_data['fill_date'] = $formatted_date;
            $submission_data['date'] = $formatted_date;
        }
        
        // ✅ Generate HTML using internal method
        $html = $this->generate_html( $submission_data, $form_title, $form_config, $submission_date );
        
        // Get verification code
        $auth_code = isset( $submission_data['auth_code'] ) ? $submission_data['auth_code'] : '';
        
        // Generate filename with verification code
        $filename = $this->generate_filename( $form_title, $auth_code );
        
        // Log generation
        if ( class_exists( 'FFC_Utils' ) && method_exists( 'FFC_Utils', 'debug_log' ) ) {
            FFC_Utils::debug_log( 'PDF data generated from form', array(
                'form_id' => $form_id,
                'form_title' => FFC_Utils::truncate( $form_title, 50 ),
                'html_length' => strlen( $html ),
                'has_bg_image' => ! empty( $bg_image_url )
            ) );
        }
        
        return array(
            'html'       => $html,
            'template'   => $html,  // Alias for backward compatibility
            'filename'   => $filename,
            'form_title' => $form_title,
            'submission' => $submission_data,
            'bg_image'   => $bg_image_url
        );
    }
}