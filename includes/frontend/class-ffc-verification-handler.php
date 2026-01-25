<?php
declare(strict_types=1);

/**
 * FFC_Verification_Handler
 * Handles certificate verification and authenticity checks.
 *
 * v2.8.0: Added magic link verification with rate limiting
 * v2.9.0: Added QR Code support in verification
 * v2.9.2: Unified PDF generation with FFC_PDF_Generator, removed prepare_pdf_data()
 * v2.10.0: ENCRYPTION - Auto-decryption via Submission Handler + Access logging
 * v3.3.0: Added strict types and type hints
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FFC_Verification_Handler {

    private $submission_handler;
    private $email_handler;

    /**
     * Constructor
     *
     * @param FFC_Submission_Handler|null $submission_handler Submission handler dependency
     * @param FFC_Email_Handler|null $email_handler Email handler for PDF generation
     */
    public function __construct( ?FFC_Submission_Handler $submission_handler = null, ?FFC_Email_Handler $email_handler = null ) {
        $this->submission_handler = $submission_handler;
        $this->email_handler = $email_handler;
    }

    /**
     * Search for certificate by authentication code
     *
     * v2.9.15: RECONSTRUÇÃO - Combina colunas + JSON
     */
    /**
     * Search for certificate - uses Repository
     */
    private function search_certificate( string $auth_code ): array {
        $repository = new FFC_Submission_Repository();
        $clean_code = FFC_Utils::clean_auth_code($auth_code);
        
        if (empty($clean_code)) {
            return [
                'found' => false,
                'submission' => null,
                'data' => []
            ];
        }
        
        $submission = $repository->findByAuthCode($clean_code);
        
        if (!$submission) {
            return [
                'found' => false,
                'submission' => null,
                'data' => []
            ];
        }
        
        // Decrypt
        $submission = $this->submission_handler->decrypt_submission_data($submission);
        
        // Rebuild data
        $data = [
            'email' => $submission['email'],
        ];
        
        if (!empty($submission['auth_code'])) {
            $data['auth_code'] = $submission['auth_code'];
        }
        
        if (!empty($submission['cpf_rf'])) {
            $data['cpf_rf'] = $submission['cpf_rf'];
        }
        
        $extra_data = json_decode($submission['data'], true);
        if (!is_array($extra_data)) {
            $extra_data = json_decode(stripslashes($submission['data']), true);
        }
        
        if (is_array($extra_data) && !empty($extra_data)) {
            $data = array_merge($extra_data, $data);
        }
        
        return [
            'found' => true,
            'submission' => (object) $submission,
            'data' => $data
        ];
    }

    /**
     * Verify certificate by magic token
     *
     * This method bypasses captcha/honeypot validation and is used
     * when accessing certificates via magic links.
     *
     * @since 2.8.0 Magic Links feature
     * @param string $token Magic token (32 hex characters)
     * @return array Result array with 'found', 'submission', 'data', 'magic_token'
     */
    public function verify_by_magic_token( string $token ): array {
        if ( ! FFC_Magic_Link_Helper::is_valid_token( $token ) ) {
            return array(
                'found' => false,
                'submission' => null,
                'data' => array(),
                'error' => 'invalid_token_format',
                'magic_token' => ''
            );
        }

        // Rate limiting check
        $user_ip = FFC_Utils::get_user_ip();
        $rate_check = FFC_Rate_Limiter::check_verification( $user_ip );
        if ( ! $rate_check['allowed'] ) {
            return array(
                'found' => false,
                'submission' => null,
                'data' => array(),
                'error' => 'rate_limited',
                'magic_token' => ''
            );
        }

        // Get submission by token
        $submission = $this->submission_handler->get_submission_by_token( $token );

        if ( ! $submission ) {
            return array(
                'found' => false,
                'submission' => null,
                'data' => array(),
                'magic_token' => ''
            );
        }

        // v2.10.0: Log access for LGPD compliance
        if ( class_exists( 'FFC_Activity_Log' ) ) {
            FFC_Activity_Log::log_data_accessed(
                (int) $submission['id'],  // Convert to int (wpdb returns strings)
                array(
                    'method' => 'magic_link',
                    'token' => substr( $token, 0, 8 ) . '...',
                    'ip' => FFC_Utils::get_user_ip()
                )
            );
        }

        // v2.9.16: REBUILD complete data (columns + JSON)
        // v2.10.0: NOTE - Automatic decryption
        // Data already comes decrypted from Submission Handler.
        // The get_submission_by_token() method calls decrypt_submission_data()
        // internally, so the fields email, cpf_rf, user_ip, data are already
        // in plain text here. No need to decrypt again.

        // Step 1: Required fields from columns
        $data = array();
        
        if ( ! empty( $submission['email'] ) ) {
            $data['email'] = $submission['email'];
        }
        
        if ( ! empty( $submission['auth_code'] ) ) {
            $data['auth_code'] = $submission['auth_code'];
        }
        
        if ( ! empty( $submission['cpf_rf'] ) ) {
            $data['cpf_rf'] = $submission['cpf_rf'];
        }
        
        // Passo 2: Campos extras do JSON
        $extra_data = json_decode( $submission['data'], true );
        if ( ! is_array( $extra_data ) ) {
            $extra_data = json_decode( stripslashes( $submission['data'] ), true );
        }
        
        // Step 3: Merge (columns have priority over JSON)
        if ( is_array( $extra_data ) && ! empty( $extra_data ) ) {
            $data = array_merge( $extra_data, $data );
        }

        // Ensure magic_token exists (fallback for old submissions)
        $magic_token = $submission['magic_token'];
        if ( empty( $magic_token ) ) {
            $magic_token = $this->submission_handler->ensure_magic_token( (int) $submission['id'] );
        }

        return array(
            'found' => true,
            'submission' => (object) $submission,
            'data' => is_array( $data ) ? $data : array(),
            'magic_token' => $magic_token
        );
    }

    /**
     * Format verification response HTML
     *
     * ✅ v2.9.7 ENHANCED: Shows more fields with better formatting
     * ✅ v3.1.0: Refactored to use template file
     */
    private function format_verification_response( object $submission, array $data, bool $show_download_button = false ): string {
        $form = get_post( $submission->form_id );
        $form_title = $form ? $form->post_title : __( 'N/A', 'ffc' );
        $date_generated = date_i18n(
            get_option('date_format') . ' ' . get_option('time_format'),
            strtotime( $submission->submission_date )
        );
        $display_code = isset($data['auth_code']) ? $data['auth_code'] : '';

        // Format auth code (XXXX-XXXX-XXXX)
        if ( strlen( $display_code ) === 12 ) {
            $display_code = substr( $display_code, 0, 4 ) . '-' . substr( $display_code, 4, 4 ) . '-' . substr( $display_code, 8, 4 );
        }

        // ✅ v2.9.7: Show MORE fields with better formatting
        // Only skip internal/technical fields
        $skip_fields = array(
            'auth_code',        // Already shown above
            'ticket',           // Internal field
            'fill_date',        // Redundant with submission_date
            'fill_time',        // Redundant
            'is_edited',        // Internal
            'edited_at',        // Internal
            'submission_id',    // Internal
            'magic_token'       // Internal/security
        );

        // ✅ Priority fields to show first (in order)
        $priority_fields = array('name', 'cpf_rf', 'email', 'program', 'date');

        // Callbacks for template
        $get_field_label_callback = array( $this, 'get_field_label' );
        $format_field_value_callback = array( $this, 'format_field_value' );

        // Render template
        ob_start();
        include FFC_PLUGIN_DIR . 'templates/certificate-preview.php';
        return ob_get_clean();
    }

    /**
     * Get human-readable field label
     *
     * @param string $field_key Field key
     * @return string Formatted label
     */
    private function get_field_label( string $field_key ): string {
    // Custom labels for known fields
    $labels = array(
        'cpf_rf'   => __( 'CPF/RF', 'ffc' ),
        'cpf'      => __( 'CPF', 'ffc' ),
        'rf'       => __( 'RF', 'ffc' ),
        'name'     => __( 'Name', 'ffc' ),
        'email'    => __( 'Email', 'ffc' ),
        'program'  => __( 'Program', 'ffc' ),
        'date'     => __( 'Date', 'ffc' ),
        'rg'       => __( 'RG', 'ffc' ),
        'phone'    => __( 'Phone', 'ffc' ),
        'address'  => __( 'Address', 'ffc' ),
        'city'     => __( 'City', 'ffc' ),
        'state'    => __( 'State', 'ffc' ),
        'zip'      => __( 'ZIP Code', 'ffc' ),
        'course'   => __( 'Course', 'ffc' ),
        'duration' => __( 'Duration', 'ffc' ),
        'hours'    => __( 'Hours', 'ffc' ),
        'grade'    => __( 'Grade', 'ffc' ),
    );
    
    if ( isset( $labels[$field_key] ) ) {
        return $labels[$field_key];
    }
    
    // Auto-format unknown fields
    return ucwords( str_replace( array('_', '-'), ' ', $field_key ) );
}

    /**
     * Format field value for display
     *
     * @param string $field_key Field key
     * @param mixed $value Field value
     * @return string Formatted value
     */
    private function format_field_value( string $field_key, $value ): string {
    // Handle arrays
    if ( is_array( $value ) ) {
        return implode( ', ', $value );
    }
    
    // Format documents (CPF, RF, RG)
    if ( in_array( $field_key, array( 'cpf', 'cpf_rf', 'rg' ) ) && ! empty( $value ) ) {
        if ( class_exists( 'FFC_Utils' ) && method_exists( 'FFC_Utils', 'format_document' ) ) {
            return FFC_Utils::format_document( $value, 'auto' );
        }
    }
    
    return $value;
}

    /**
     * Verify certificate (used by shortcode fallback - non-AJAX)
     * Returns array with 'success' (bool), 'html' (string), 'message' (string)
     */
    public function verify_certificate( string $auth_code ): array {
        $result = $this->search_certificate( $auth_code );
        if ( $result['found'] && isset( $result['submission']['id'] ) ) {
        // ✅ v2.10.0: Log access for LGPD compliance
        if ( class_exists( 'FFC_Activity_Log' ) ) {
            FFC_Activity_Log::log_data_accessed(
                (int) $result['submission']['id'],  // Convert to int (wpdb returns strings)
                array(
                    'method' => 'manual_verification',
                    'auth_code' => substr( $auth_code, 0, 4 ) . '...',
                    'ip' => FFC_Utils::get_user_ip()
                )
            );
        }
        }
        
        if ( ! $result['found'] ) {
            return array(
                'success' => false,
                'html' => '',
                'message' => __( 'Certificate not found or invalid code.', 'ffc' )
            );
        }

        $html = $this->format_verification_response( $result['submission'], $result['data'], true );

        return array(
            'success' => true,
            'html' => $html,
            'message' => ''
        );
    }

    /**
     * Handle magic link verification via AJAX
     *
     * This endpoint bypasses security checks (captcha/honeypot) as the
     * magic token itself provides authentication.
     *
     * @since 2.8.0 Magic Links feature
     */
    public function handle_magic_verification_ajax(): void {
        // No nonce check - magic token is the authentication
        // No captcha - token proves legitimacy

        $token = isset( $_POST['token'] ) ? sanitize_text_field( $_POST['token'] ) : '';
        $user_ip = FFC_Utils::get_user_ip();

        $rate_check = FFC_Rate_Limiter::check_verification( $user_ip );
        if ( ! $rate_check['allowed'] ) {
            wp_send_json_error( array(
                'message' => __( 'Too many verification attempts. Please try again later.', 'ffc' )
            ) );
        }

        if ( empty( $token ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid token.', 'ffc' ) ) );
        }

        // Verify by magic token
        $result = $this->verify_by_magic_token( $token );
        
        if ( isset( $result['error'] ) && $result['error'] === 'rate_limited' ) {
            wp_send_json_error( array( 
                'message' => __( 'Too many attempts. Please try again in 1 minute.', 'ffc' ) 
            ) );
        }
        
        if ( ! $result['found'] ) {
            wp_send_json_error( array( 
                'message' => '❌ ' . __( 'Certificate not found or invalid link.', 'ffc' ) 
            ) );
        }

        // ✅ v2.9.2: Use centralized PDF generator
        $pdf_generator = new FFC_PDF_Generator( $this->email_handler );
        $pdf_data = $pdf_generator->generate_pdf_data(
            (int) $result['submission']->id,  // Convert to int (wpdb returns strings)
            $this->submission_handler
        );
        
        if ( is_wp_error( $pdf_data ) ) {
            wp_send_json_error( array( 'message' => $pdf_data->get_error_message() ) );
        }

        // Format response HTML with download button
        $html = $this->format_verification_response( 
            $result['submission'], 
            $result['data'], 
            true  // Show download button
        );
        
        wp_send_json_success( array( 
            'html' => $html,
            'submission_id' => $result['submission']->id,
            'pdf_data' => $pdf_data
        ) );
    }

    /**
     * Handle certificate verification via AJAX (manual - with captcha)
     */
    public function handle_verification_ajax(): void {
        check_ajax_referer( 'ffc_frontend_nonce', 'nonce' );
        
        // Validate security
        $captcha_ans = isset($_POST['ffc_captcha_ans']) ? sanitize_text_field($_POST['ffc_captcha_ans']) : '';
        $captcha_hash = isset($_POST['ffc_captcha_hash']) ? sanitize_text_field($_POST['ffc_captcha_hash']) : '';
        $honeypot = isset($_POST['ffc_honeypot_trap']) ? sanitize_text_field($_POST['ffc_honeypot_trap']) : '';
        $user_ip = FFC_Utils::get_user_ip();

        if ( ! empty( $honeypot ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid submission.', 'ffc' ) ) );
        }

        $rate_check = FFC_Rate_Limiter::check_verification( $user_ip );  
        if ( ! $rate_check['allowed'] ) {
            wp_send_json_error( array( 
                'message' => __( 'Too many verification attempts. Please try again later.', 'ffc' ) 
            ) );
        }
        
        if ( ! FFC_Utils::verify_simple_captcha( $captcha_ans, $captcha_hash ) ) {
            $new_captcha = FFC_Utils::generate_simple_captcha();
            wp_send_json_error( array( 
                'message' => __( 'Incorrect answer to math question.', 'ffc' ),
                'refresh_captcha' => true,
                'new_label' => $new_captcha['label'],
                'new_hash' => $new_captcha['hash']
            ) );
        }
        
        $auth_code = isset($_POST['ffc_auth_code']) ? sanitize_text_field($_POST['ffc_auth_code']) : '';
        $result = $this->search_certificate( $auth_code );
        
        if ( ! $result['found'] ) {
            $new_captcha = FFC_Utils::generate_simple_captcha();
            wp_send_json_error( array( 
                'message' => '❌ ' . __( 'Certificate not found or invalid code.', 'ffc' ),
                'refresh_captcha' => true,
                'new_label' => $new_captcha['label'],
                'new_hash' => $new_captcha['hash']
            ) );
        }
        
        // Get form data for PDF
        
        // ✅ v2.9.2: Use centralized PDF generator
        $pdf_generator = new FFC_PDF_Generator( $this->email_handler );
        $pdf_data = $pdf_generator->generate_pdf_data(
            (int) $result['submission']->id,  // Convert to int (wpdb returns strings)
            $this->submission_handler
        );
        
        if ( is_wp_error( $pdf_data ) ) {
            wp_send_json_error( array( 'message' => $pdf_data->get_error_message() ) );
        }
        
        $html = $this->format_verification_response( 
            $result['submission'], 
            $result['data'], 
            true 
        );
        
        wp_send_json_success( array( 
            'html' => $html,
            'submission_id' => $result['submission']->id,
            'pdf_data' => $pdf_data
        ) );
    }
}