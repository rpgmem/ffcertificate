<?php
declare(strict_types=1);

/**
 * PdfGenerator
 *
 * Centralized PDF generation for all contexts:
 * - Form submission (frontend)
 * - Manual verification
 * - Magic link verification
 * - Admin PDF download
 * - Certificate reprint
 *
 * v3.3.0: Added strict types and type hints
 * v3.2.0: Migrated to namespace (Phase 2)
 * v2.9.2: Single source of truth for PDF generation
 * v2.9.14: REFACTORED - Moved generate_html logic from FFC_Email_Handler
 */

namespace FreeFormCertificate\Generators;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


class PdfGenerator {
    
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
     * @return array<string, mixed>|\WP_Error PDF data array or error
     */
    public function generate_pdf_data( int $submission_id, object $submission_handler ) {
        // Get submission
        $submission = $submission_handler->get_submission( $submission_id );
        
        if ( ! $submission ) {
            return new WP_Error( 'submission_not_found', __( 'Submission not found.', 'ffcertificate' ) );
        }
        
        // Convert to array
        $sub_array = (array) $submission;
        
        // Rebuild complete data (columns + JSON)
        $data = array(
            'email' => $sub_array['email'],
        );

        if ( ! empty( $sub_array['auth_code'] ) ) {
            $data['auth_code'] = $sub_array['auth_code'];
        }

        if ( ! empty( $sub_array['cpf_rf'] ) ) {
            $data['cpf_rf'] = $sub_array['cpf_rf'];
        }

        // Extra fields from JSON
        $extra_data = json_decode( $sub_array['data'], true );
        if ( ! is_array( $extra_data ) ) {
            $extra_data = json_decode( wp_unslash( $sub_array['data'] ), true );
        }

        // Merge — columns have priority over JSON fields
        if ( is_array( $extra_data ) && ! empty( $extra_data ) ) {
            $data = array_merge( $extra_data, $data );
        }
        
        // Enrich data with submission metadata
        $data = $this->enrich_submission_data( $data, $sub_array );

        /**
         * Filters certificate template data before HTML generation.
         *
         * @since 4.6.4
         * @param array $data          Enriched submission data used as template variables.
         * @param int   $submission_id Submission ID.
         * @param array $sub_array     Raw submission database row.
         */
        $data = apply_filters( 'ffcertificate_certificate_data', $data, $submission_id, $sub_array );

        // Get form data (convert form_id to int - wpdb returns strings)
        $form_id = (int) $sub_array['form_id'];
        $form_title = get_the_title( $form_id );
        $form_config = get_post_meta( $form_id, '_ffc_form_config', true );
        $bg_image_url = get_post_meta( $form_id, '_ffc_form_bg', true );

        $html = $this->generate_html( $data, $form_title, $form_config, $sub_array['submission_date'] );

        /**
         * Filters the generated certificate HTML before it is returned.
         *
         * @since 4.6.4
         * @param string $html          Generated certificate HTML.
         * @param array  $data          Template data.
         * @param int    $submission_id Submission ID.
         * @param int    $form_id       Form ID.
         */
        $html = apply_filters( 'ffcertificate_certificate_html', $html, $data, $submission_id, $form_id );

        // Get verification code from submission data
        $auth_code = isset( $data['auth_code'] ) ? $data['auth_code'] : '';

        // Generate safe filename with verification code
        $filename = $this->generate_filename( $form_title, $auth_code );

        /**
         * Filters the certificate PDF filename.
         *
         * @since 4.6.4
         * @param string $filename      Generated filename.
         * @param string $form_title    Form title.
         * @param string $auth_code     Authentication code.
         * @param int    $submission_id Submission ID.
         */
        $filename = apply_filters( 'ffcertificate_certificate_filename', $filename, $form_title, $auth_code, $submission_id );

        // Log generation
        \FreeFormCertificate\Core\Utils::debug_log( 'PDF data generated', array(
            'submission_id' => $submission_id,
            'form_id' => $form_id,
            'form_title' => \FreeFormCertificate\Core\Utils::truncate( $form_title, 50 ),
            'auth_code' => $auth_code,
            'filename' => $filename,
            'html_length' => strlen( $html ),
            'has_bg_image' => ! empty( $bg_image_url )
        ) );
        
        $pdf_data = array(
            'html'          => $html,
            'filename'      => $filename,
            'form_title'    => $form_title,
            'auth_code'     => $auth_code,
            'submission_id' => $submission_id,
            'submission'    => $data,
            'bg_image'      => $bg_image_url
        );

        /**
         * Fires after PDF data is fully generated.
         *
         * @since 4.6.4
         * @param array $pdf_data     Complete PDF data array.
         * @param int   $submission_id Submission ID.
         */
        do_action( 'ffcertificate_after_pdf_generation', $pdf_data, $submission_id );

        return $pdf_data;
    }
    
    /**
     * Enrich submission data with metadata
     *
     * @param array<string, mixed> $data Original submission data
     * @param array<string, mixed> $submission Submission database row
     * @return array<string, mixed> Enriched data
     */
    private function enrich_submission_data( array $data, array $submission ): array {
        // Add email if missing
        if ( ! isset( $data['email'] ) && ! empty( $submission['email'] ) ) {
            $data['email'] = $submission['email'];
        }
        
        // Add formatted date if missing
        if ( ! isset( $data['fill_date'] ) ) {
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
        
        // Add submission ID (convert to int - wpdb returns strings)
        if ( ! isset( $data['submission_id'] ) ) {
            $data['submission_id'] = (int) $submission['id'];
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
     * - {{main_address}} - Institutional address from Settings > General
     * - {{site_name}} - WordPress site name
     * - {{qr_code}} - QR Code with default settings
     * - {{qr_code:size=150}} - Custom size
     * - {{qr_code:size=200:margin=0}} - Custom size and margin
     * - {{validation_url}} - Validation link with magic token
     * - {{validation_url link:m>v}} - Custom link format
     *
     * @param array<string, mixed> $data Submission data
     * @param string $form_title Form title
     * @param array<string, mixed> $form_config Form configuration
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
        $layout = str_replace( '{{print_date}}', wp_date( $date_format ), $layout );

        $layout = str_replace( '{{form_title}}', $form_title, $layout );

        // Inject settings-based placeholders into data (v4.6.10)
        if ( ! isset( $data['main_address'] ) && ! empty( $settings['main_address'] ) ) {
            $data['main_address'] = $settings['main_address'];
        }
        if ( ! isset( $data['site_name'] ) ) {
            $data['site_name'] = get_bloginfo( 'name' );
        }

        // Ensure email field exists
        if ( ! isset( $data['email'] ) && isset( $data['user_email'] ) ) {
            $data['email'] = $data['user_email'];
        }

        // Quiz score aliases: map {{score}}, {{max_score}}, {{score_percent}} to internal keys
        if ( isset( $data['_quiz_score'] ) ) {
            $data['score'] = $data['_quiz_score'];
        }
        if ( isset( $data['_quiz_max_score'] ) ) {
            $data['max_score'] = $data['_quiz_max_score'];
        }
        if ( isset( $data['_quiz_percent'] ) ) {
            $data['score_percent'] = $data['_quiz_percent'];
        }
        
        // Replace field placeholders with formatted values
        foreach ( $data as $key => $value ) {
            if ( is_array( $value ) ) {
                $value = implode( ', ', $value );
            }
            
            // Format documents (CPF, RF, RG)
            if ( in_array( $key, array( 'cpf', 'cpf_rf', 'rg' ) ) ) {
                $value = \FreeFormCertificate\Core\Utils::format_document( $value );
            }
            
            // Format auth code with certificate prefix
            if ( $key === 'auth_code' ) {
                $value = \FreeFormCertificate\Core\Utils::format_auth_code( $value, \FreeFormCertificate\Core\DocumentFormatter::PREFIX_CERTIFICATE );
            }
            
            // Apply allowed HTML filtering
            $safe_value = wp_kses( $value, \FreeFormCertificate\Core\Utils::get_allowed_html_tags() );
            $layout = str_replace( '{{' . $key . '}}', $safe_value, $layout );
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
     * @param array<string, mixed> $data Submission data
     * @param array<string, mixed> $form_config Form configuration
     * @return string Processed HTML
     */
    private function process_qrcode_placeholders( string $layout, array $data, array $form_config ): string {
        // Autoloader handles class loading
        $qr_generator = new \FreeFormCertificate\Generators\QRCodeGenerator();
        
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
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $submission = $wpdb->get_row(
        $wpdb->prepare( "SELECT magic_token FROM %i WHERE id = %d", $table, $submission_id ),
        ARRAY_A
    );
    
    if ( ! $submission || empty( $submission['magic_token'] ) ) {
        return '';
    }
    
    // Use helper to generate magic link
    $magic_link = \FreeFormCertificate\Generators\MagicLinkHelper::generate_magic_link( $submission['magic_token'] );
    
    if ( empty( $magic_link ) ) {
        return '';
    }
    
    // Generate QR code
    $qr_generator = new \FreeFormCertificate\Generators\QRCodeGenerator();
    return $qr_generator->generate( $magic_link, array( 'size' => $size ) );
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
     * @param array<string, mixed> $data Submission data
     * @return string URL
     */
    private function get_qr_code_target_url( array $data ): string {
        $verification_url = untrailingslashit( site_url( 'valid' ) );
        
        // Priority 1: Magic link (if exists)
        $magic_token = isset( $data['magic_token'] ) ? $data['magic_token'] : '';
        $magic_url = \FreeFormCertificate\Generators\MagicLinkHelper::generate_magic_link( $magic_token );

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
     * @param array<string, mixed> $data Submission data
     * @return string Processed HTML
     */
    private function process_validation_url_placeholders( string $layout, array $data ): string {
        // Get base URLs
        $valid_url = untrailingslashit( site_url( 'valid' ) );
        
        // Get magic link URL (with fallback to /valid)
        $magic_token = isset( $data['magic_token'] ) ? $data['magic_token'] : '';
        $magic_url = \FreeFormCertificate\Generators\MagicLinkHelper::generate_magic_link( $magic_token );

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
     * @return array{to: string, text: string, target: string, color: string} Parsed parameters
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
     * @param array<string, mixed> $data Submission data
     * @param string $form_title Form title
     * @return string Default HTML
     */
    private function generate_default_html( array $data, string $form_title ): string {
        $layout = '<div style="text-align:center; padding: 50px;">';
        $layout .= '<h1>' . esc_html( $form_title ) . '</h1>';
        $layout .= '<p>' . esc_html__( 'We certify that the holder of the data below has completed the event.', 'ffcertificate' ) . '</p>';
        
        // Show name if exists
        if ( isset( $data['name'] ) ) {
            $layout .= '<h2>' . esc_html( $data['name'] ) . '</h2>';
        }
        
        // Show auth code if exists
        if ( isset( $data['auth_code'] ) ) {
            $layout .= '<p>' . esc_html__( 'Authenticity:', 'ffcertificate' ) . ' ' . esc_html( \FreeFormCertificate\Core\Utils::format_auth_code( $data['auth_code'], \FreeFormCertificate\Core\DocumentFormatter::PREFIX_CERTIFICATE ) ) . '</p>';
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
        $safe_name = \FreeFormCertificate\Core\Utils::sanitize_filename( $form_title );
        
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
     * @param array<string, mixed> $submission_data Posted form data
     * @param int $form_id Form ID
     * @param string $submission_date Submission date
     * @return array<string, mixed>|\WP_Error PDF data array
     */
    public function generate_pdf_data_from_form( array $submission_data, int $form_id, ?string $submission_date = null ) {
        // Get form data
        $form_post = get_post( $form_id );
        if ( ! $form_post ) {
            return new WP_Error( 'form_not_found', __( 'Form not found.', 'ffcertificate' ) );
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
        
        $html = $this->generate_html( $submission_data, $form_title, $form_config, $submission_date );
        
        // Get verification code
        $auth_code = isset( $submission_data['auth_code'] ) ? $submission_data['auth_code'] : '';
        
        // Generate filename with verification code
        $filename = $this->generate_filename( $form_title, $auth_code );
        
        // Log generation
        \FreeFormCertificate\Core\Utils::debug_log( 'PDF data generated from form', array(
            'form_id' => $form_id,
            'form_title' => \FreeFormCertificate\Core\Utils::truncate( $form_title, 50 ),
            'html_length' => strlen( $html ),
            'has_bg_image' => ! empty( $bg_image_url )
        ) );
        
        return array(
            'html'       => $html,
            'filename'   => $filename,
            'form_title' => $form_title,
            'submission' => $submission_data,
            'bg_image'   => $bg_image_url
        );
    }

    /**
     * Generate PDF data for appointment receipt
     *
     * Uses the same HTML→canvas→PDF pipeline as certificates.
     * Template loaded from plugin default or overridden via filter.
     *
     * @since 4.2.0
     * @param array<string, mixed> $appointment Appointment data array from database
     * @param array<string, mixed> $calendar Calendar data array from database
     * @return array{html: string, filename: string, form_title: string, bg_image: mixed, type: string} PDF data array (html, template, filename, bg_image)
     */
    public function generate_appointment_pdf_data( array $appointment, array $calendar ): array {
        // Get date/time format settings
        $date_format = get_option( 'date_format' );
        $time_format = get_option( 'time_format' );

        // Format appointment date
        $formatted_date = __( 'N/A', 'ffcertificate' );
        if ( ! empty( $appointment['appointment_date'] ) ) {
            $ts = strtotime( $appointment['appointment_date'] );
            if ( $ts !== false ) {
                $formatted_date = date_i18n( $date_format, $ts );
            }
        }

        // Format time range
        $formatted_time = __( 'N/A', 'ffcertificate' );
        if ( ! empty( $appointment['start_time'] ) ) {
            $ts = strtotime( $appointment['start_time'] );
            if ( $ts !== false ) {
                $formatted_time = date_i18n( $time_format, $ts );
            }
            if ( ! empty( $appointment['end_time'] ) ) {
                $ts2 = strtotime( $appointment['end_time'] );
                if ( $ts2 !== false ) {
                    $formatted_time .= ' - ' . date_i18n( $time_format, $ts2 );
                }
            }
        }

        // Format created_at
        $formatted_created = __( 'N/A', 'ffcertificate' );
        if ( ! empty( $appointment['created_at'] ) ) {
            $ts = strtotime( $appointment['created_at'] );
            if ( $ts !== false ) {
                $formatted_created = date_i18n( $date_format . ' ' . $time_format, $ts );
            }
        }

        // Decrypt sensitive data if needed
        $email = \FreeFormCertificate\Core\Encryption::decrypt_field( $appointment, 'email' );

        // CPF/RF: after the split migration, data lives in cpf_encrypted or rf_encrypted.
        // decrypt_field('cpf_rf') only checks cpf_rf_encrypted (legacy), so try the split columns.
        $cpf_rf = \FreeFormCertificate\Core\Encryption::decrypt_field( $appointment, 'cpf', 'cpf_encrypted' );
        if ( $cpf_rf === '' ) {
            $cpf_rf = \FreeFormCertificate\Core\Encryption::decrypt_field( $appointment, 'rf', 'rf_encrypted' );
        }
        if ( $cpf_rf === '' ) {
            // Legacy fallback (pre-migration data)
            $cpf_rf = \FreeFormCertificate\Core\Encryption::decrypt_field( $appointment, 'cpf_rf' );
        }

        // Status labels
        $status_labels = array(
            'pending'   => __( 'Pending Approval', 'ffcertificate' ),
            'confirmed' => __( 'Confirmed', 'ffcertificate' ),
            'cancelled' => __( 'Cancelled', 'ffcertificate' ),
            'completed' => __( 'Completed', 'ffcertificate' ),
            'no_show'   => __( 'No Show', 'ffcertificate' ),
        );
        $status = $appointment['status'] ?? 'pending';
        $status_label = $status_labels[ $status ] ?? $status;

        // Build data array for placeholder replacement
        $data = array(
            'name'              => $appointment['name'] ?? '',
            'email'             => $email,
            'cpf_rf'            => $cpf_rf,
            'calendar_title'    => $calendar['title'] ?? __( 'N/A', 'ffcertificate' ),
            'appointment_date'  => $formatted_date,
            'appointment_time'  => $formatted_time,
            'created_at'        => $formatted_created,
            'status'            => $status_label,
            'validation_code'   => ! empty( $appointment['validation_code'] )
                ? \FreeFormCertificate\Core\Utils::format_auth_code( $appointment['validation_code'], \FreeFormCertificate\Core\DocumentFormatter::PREFIX_APPOINTMENT )
                : '',
            'auth_code'         => ! empty( $appointment['validation_code'] )
                ? $appointment['validation_code']
                : '',
            'site_name'         => get_bloginfo( 'name' ),
        );

        // Add magic_token (confirmation_token) for QR code / validation URL
        if ( ! empty( $appointment['confirmation_token'] ) ) {
            $data['magic_token'] = $appointment['confirmation_token'];
        }

        // Load receipt template
        $template = $this->get_appointment_receipt_template();

        // Build form_config-like structure for generate_html
        $form_config = array(
            'pdf_layout' => $template,
        );

        // Generate HTML using the existing generate_html() method
        $calendar_title = $calendar['title'] ?? __( 'Appointment Receipt', 'ffcertificate' );
        $html = $this->generate_html( $data, $calendar_title, $form_config, $appointment['created_at'] ?? null );

        // Generate filename
        $validation_code = $appointment['validation_code'] ?? '';
        $safe_title = __( 'Appointment_Receipt', 'ffcertificate' );
        $filename = $this->generate_filename( $safe_title, $validation_code );

        // Allow background image customization via filter
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- ffc_ is the plugin prefix
        $bg_image = apply_filters( 'ffcertificate_appointment_receipt_bg_image', '', $appointment, $calendar );

        return array(
            'html'       => $html,
            'filename'   => $filename,
            'form_title' => $calendar_title,
            'bg_image'   => $bg_image,
            'type'       => 'appointment_receipt',
        );
    }

    /**
     * Get appointment receipt HTML template
     *
     * Loads from plugin default file. Can be overridden via filter.
     *
     * @since 4.2.0
     * @return string HTML template with placeholders
     */
    private function get_appointment_receipt_template(): string {
        $template_file = FFC_PLUGIN_DIR . 'html/default_appointment_receipt_1.html';

        // Allow override via filter
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- ffc_ is the plugin prefix
        $template_file = apply_filters( 'ffcertificate_appointment_receipt_template_file', $template_file );

        if ( file_exists( $template_file ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local template file.
            $template = file_get_contents( $template_file );
            if ( ! empty( $template ) ) {
                return $template;
            }
        }

        // Fallback: minimal receipt template
        return '<div style="width:1123px;height:794px;padding:60px;box-sizing:border-box;font-family:Arial,sans-serif">'
            . '<h1 style="text-align:center;color:#0073aa">' . esc_html__( 'Appointment Receipt', 'ffcertificate' ) . '</h1>'
            . '<p><strong>' . esc_html__( 'Name:', 'ffcertificate' ) . '</strong> {{name}}</p>'
            . '<p><strong>' . esc_html__( 'Event:', 'ffcertificate' ) . '</strong> {{calendar_title}}</p>'
            . '<p><strong>' . esc_html__( 'Date:', 'ffcertificate' ) . '</strong> {{appointment_date}}</p>'
            . '<p><strong>' . esc_html__( 'Time:', 'ffcertificate' ) . '</strong> {{appointment_time}}</p>'
            . '<p><strong>' . esc_html__( 'Validation Code:', 'ffcertificate' ) . '</strong> {{validation_code}}</p>'
            . '</div>';
    }
}