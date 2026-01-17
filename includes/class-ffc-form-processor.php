<?php
/**
 * FFC_Form_Processor
 * Handles form submission processing, validation, and restriction checks.
 * 
 * v2.9.2: Unified PDF generation with FFC_PDF_Generator
 * v2.9.11: Using FFC_Utils for validation and sanitization
 * v2.9.13: Optimized detect_reprint() to use cpf_rf column with fallback
 * v2.10.0: LGPD - Validates consent checkbox (mandatory)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FFC_Form_Processor {
    
    private $submission_handler;
    private $email_handler;

    /**
     * Constructor
     */
    public function __construct( $submission_handler, $email_handler ) {
        $this->submission_handler = $submission_handler;
        $this->email_handler = $email_handler;
        
        // Register AJAX hooks
        add_action('wp_ajax_ffc_submit_form', [$this, 'handle_submission_ajax']);
        add_action('wp_ajax_nopriv_ffc_submit_form', [$this, 'handle_submission_ajax']);
    }

    /**
     * ✅ v2.10.0: Check if submission passes restriction rules
     * 
     * NEW: Modular checkbox-based restrictions (password, allowlist, denylist, ticket)
     * Validation order: Password → Denylist (priority) → Allowlist → Ticket (consumed)
     * 
     * @param array $form_config Form configuration
     * @param string $val_cpf CPF/RF from form (already cleaned)
     * @param string $val_ticket Ticket from form
     * @param int $form_id Form ID (needed for ticket consumption)
     * @return array ['allowed' => bool, 'message' => string, 'is_ticket' => bool]
     */
    private function check_restrictions( $form_config, $val_cpf, $val_ticket, $form_id ) {
        $restrictions = isset($form_config['restrictions']) ? $form_config['restrictions'] : array();
        
        // Clean CPF/RF (remove any mask)
        $clean_cpf = preg_replace('/\D/', '', $val_cpf);
        
        // ========================================
        // 1. PASSWORD CHECK (if active)
        // ========================================
        if (!empty($restrictions['password']) && $restrictions['password'] == '1') {
            $password = isset($_POST['ffc_password']) ? trim($_POST['ffc_password']) : '';
            $valid_password = isset($form_config['validation_code']) ? $form_config['validation_code'] : '';
            
            if (empty($password)) {
                return array(
                    'allowed' => false, 
                    'message' => __('Password is required.', 'ffc'), 
                    'is_ticket' => false
                );
            }
            
            if ($password !== $valid_password) {
                return array(
                    'allowed' => false, 
                    'message' => __('Incorrect password.', 'ffc'), 
                    'is_ticket' => false
                );
            }
        }
        
        // ========================================
        // 2. DENYLIST CHECK (if active - HAS PRIORITY)
        // ========================================
        if (!empty($restrictions['denylist']) && $restrictions['denylist'] == '1') {
            $denied_raw = isset($form_config['denied_users_list']) ? $form_config['denied_users_list'] : '';
            $denied_list = array_filter(array_map('trim', explode("\n", $denied_raw)));
            
            // Clean masks from denylist before comparing
            $denied_clean = array_map(function($d) { 
                return preg_replace('/\D/', '', $d); 
            }, $denied_list);
            
            if (in_array($clean_cpf, $denied_clean)) {
                return array(
                    'allowed' => false, 
                    'message' => __('Your CPF/RF is blocked.', 'ffc'), 
                    'is_ticket' => false
                );
            }
        }
        
        // ========================================
        // 3. ALLOWLIST CHECK (if active)
        // ========================================
        if (!empty($restrictions['allowlist']) && $restrictions['allowlist'] == '1') {
            $allowed_raw = isset($form_config['allowed_users_list']) ? $form_config['allowed_users_list'] : '';
            $allowed_list = array_filter(array_map('trim', explode("\n", $allowed_raw)));
            
            // Clean masks from allowlist before comparing
            $allowed_clean = array_map(function($a) { 
                return preg_replace('/\D/', '', $a); 
            }, $allowed_list);
            
            if (!in_array($clean_cpf, $allowed_clean)) {
                return array(
                    'allowed' => false, 
                    'message' => __('Your CPF/RF is not authorized.', 'ffc'), 
                    'is_ticket' => false
                );
            }
        }
        
        // ========================================
        // 4. TICKET CHECK (if active - CONSUMED)
        // ========================================
        if (!empty($restrictions['ticket']) && $restrictions['ticket'] == '1') {
            $ticket = strtoupper(trim($val_ticket));
            
            if (empty($ticket)) {
                return array(
                    'allowed' => false, 
                    'message' => __('Ticket code is required.', 'ffc'), 
                    'is_ticket' => false
                );
            }
            
            $tickets_raw = isset($form_config['generated_codes_list']) ? $form_config['generated_codes_list'] : '';
            $tickets = array_filter(array_map(function($t) { 
                return strtoupper(trim($t)); 
            }, explode("\n", $tickets_raw)));
            
            if (!in_array($ticket, $tickets)) {
                return array(
                    'allowed' => false, 
                    'message' => __('Invalid or already used ticket.', 'ffc'), 
                    'is_ticket' => false
                );
            }
            
            // ✅ CONSUME TICKET (remove from list)
            $tickets = array_diff($tickets, array($ticket));
            $form_config['generated_codes_list'] = implode("\n", $tickets);
            update_post_meta($form_id, '_ffc_form_config', $form_config);
            
            return array(
                'allowed' => true, 
                'message' => '', 
                'is_ticket' => true
            );
        }
        
        // ========================================
        // NO RESTRICTIONS ACTIVE - ALLOW
        // ========================================
        return array(
            'allowed' => true, 
            'message' => '', 
            'is_ticket' => false
        );
    }

    /**
     * Check for existing submission (reprint detection)
     * 
     * v2.9.13: OPTIMIZED - Uses dedicated cpf_rf column with fallback to JSON
     * 
     * Returns array with 'is_reprint' (bool), 'data' (array), 'id' (int), 'email' (string), 'date' (string)
     */
    private function detect_reprint( $form_id, $val_cpf, $val_ticket ) {
        global $wpdb;
        $table_name = FFC_Utils::get_submissions_table();
        $existing_submission = null;

        // ✅ PRIORITY 1: Check by ticket (if provided)
        if ( ! empty( $val_ticket ) ) {
            $like_query = '%' . $wpdb->esc_like( '"ticket":"' . $val_ticket . '"' ) . '%';
            $existing_submission = $wpdb->get_row( $wpdb->prepare( 
                "SELECT * FROM {$table_name} WHERE form_id = %d AND data LIKE %s ORDER BY id DESC LIMIT 1", 
                $form_id, 
                $like_query 
            ) );
        } 
        
        // ✅ PRIORITY 2: Check by CPF/RF (OPTIMIZED - if ticket not provided)
        elseif ( ! empty( $val_cpf ) ) {
            // Remove formatting for comparison
            $clean_cpf = preg_replace( '/[^0-9]/', '', $val_cpf );
            
            // Check if encryption is enabled
            if (class_exists('FFC_Encryption') && FFC_Encryption::is_configured()) {
                // Use HASH for encrypted data
                $cpf_hash = FFC_Encryption::hash($clean_cpf);
                
                $existing_submission = $wpdb->get_row( $wpdb->prepare( 
                    "SELECT * FROM {$table_name} 
                     WHERE form_id = %d 
                     AND cpf_rf_hash = %s 
                     ORDER BY id DESC 
                     LIMIT 1", 
                    $form_id, 
                    $cpf_hash
                ) );
            } else {
                // Use plain CPF for non-encrypted data
                $existing_submission = $wpdb->get_row( $wpdb->prepare( 
                    "SELECT * FROM {$table_name} 
                     WHERE form_id = %d 
                     AND cpf_rf = %s 
                     ORDER BY id DESC 
                     LIMIT 1", 
                    $form_id, 
                    $clean_cpf
                ) );
            }
            
            // ⚠️ Fallback: If column doesn't exist or is NULL, search in JSON
            if ( ! $existing_submission ) {
                $like_query = '%' . $wpdb->esc_like( '"cpf_rf":"' . $val_cpf . '"' ) . '%';
                $existing_submission = $wpdb->get_row( $wpdb->prepare( 
                    "SELECT * FROM {$table_name} 
                     WHERE form_id = %d 
                     AND data LIKE %s 
                     ORDER BY id DESC 
                     LIMIT 1", 
                    $form_id, 
                    $like_query 
                ) );
            }
        }

        if ( $existing_submission ) {
            $decoded_data = json_decode( $existing_submission->data, true );
            if( !is_array($decoded_data) ) {
                $decoded_data = json_decode( stripslashes( $existing_submission->data ), true );
            }
            
            // ✅ v2.9.16: RECONSTRUIR dados completos (colunas + JSON)
            // Garantir que campos obrigatórios das colunas sejam incluídos
            if ( ! isset( $decoded_data['email'] ) && ! empty( $existing_submission->email ) ) {
                $decoded_data['email'] = $existing_submission->email;
            }
            if ( ! isset( $decoded_data['cpf_rf'] ) && ! empty( $existing_submission->cpf_rf ) ) {
                $decoded_data['cpf_rf'] = $existing_submission->cpf_rf;
            }
            if ( ! isset( $decoded_data['auth_code'] ) && ! empty( $existing_submission->auth_code ) ) {
                $decoded_data['auth_code'] = $existing_submission->auth_code;
            }
            
            return array(
                'is_reprint' => true,
                'data' => $decoded_data,  // ✅ Agora tem dados completos!
                'id' => $existing_submission->id,
                'email' => $existing_submission->email,
                'date' => $existing_submission->submission_date
            );
        }

        return array(
            'is_reprint' => false,
            'data' => array(),
            'id' => 0,
            'email' => '',
            'date' => ''
        );
    }

    /**
     * Remove used ticket from form configuration
     */
    private function consume_ticket( $form_id, $ticket ) {
        $current_config = get_post_meta( $form_id, '_ffc_form_config', true );
        $current_raw_codes = isset( $current_config['generated_codes_list'] ) ? $current_config['generated_codes_list'] : '';
        $current_list = array_filter( array_map( 'trim', explode( "\n", $current_raw_codes ) ) );
        $updated_list = array_diff( $current_list, array( $ticket ) );
        $current_config['generated_codes_list'] = implode( "\n", $updated_list );
        update_post_meta( $form_id, '_ffc_form_config', $current_config );
    }

    /**
     * Handle form submission via AJAX
     */
    public function handle_submission_ajax() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ffc_frontend_nonce')) {
            wp_send_json_error(['message' => __('Security check failed. Please refresh the page.', 'ffc')]);
            return;
        }
        
        // ===== DEBUG CAPTCHA =====
        error_log('===== FFC CAPTCHA DEBUG =====');
        error_log('Answer received: ' . (isset($_POST['ffc_captcha_ans']) ? $_POST['ffc_captcha_ans'] : 'NOT SET'));
        error_log('Hash received: ' . (isset($_POST['ffc_captcha_hash']) ? $_POST['ffc_captcha_hash'] : 'NOT SET'));
        
        if (isset($_POST['ffc_captcha_ans']) && isset($_POST['ffc_captcha_hash'])) {
            $test_answer = trim($_POST['ffc_captcha_ans']);
            $received_hash = $_POST['ffc_captcha_hash'];
            $generated_hash = wp_hash($test_answer . 'ffc_math_salt');
            
            error_log('Trimmed answer: ' . $test_answer);
            error_log('Generated hash from answer: ' . $generated_hash);
            error_log('Hashes match: ' . ($generated_hash === $received_hash ? 'YES' : 'NO'));
            
            // Test with different variations
            error_log('Test with (int): ' . wp_hash((int)$test_answer . 'ffc_math_salt'));
            error_log('Test with (string): ' . wp_hash((string)$test_answer . 'ffc_math_salt'));
        }
        error_log('=============================');
        // ===== END DEBUG =====
        
        // Validate security fields using FFC_Utils
        $security_check = FFC_Utils::validate_security_fields($_POST);
        if ( $security_check !== true ) {
            // Generate new captcha for retry
            $n1 = rand( 1, 9 );
            $n2 = rand( 1, 9 );
            wp_send_json_error( array( 
                'message' => $security_check, 
                'refresh_captcha' => true, 
                'new_label' => sprintf( esc_html__( 'Security: How much is %d + %d?', 'ffc' ), $n1, $n2 ) . ' <span class="required">*</span>',
                'new_hash' => wp_hash( ($n1 + $n2) . 'ffc_math_salt' )
            ) );
        }

        $form_id = isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0;
        if ( ! $form_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid Form ID.', 'ffc' ) ) );
        }

        $form_config = get_post_meta( $form_id, '_ffc_form_config', true );
        if ( ! is_array( $form_config ) ) $form_config = array();
        
        $fields_config = get_post_meta( $form_id, '_ffc_form_fields', true );
        if ( ! $fields_config ) {
            wp_send_json_error( array( 'message' => __( 'Form configuration not found.', 'ffc' ) ) );
        }

        // Process and sanitize form fields using FFC_Utils
        $submission_data = array();
        $user_email = '';

        foreach ( $fields_config as $field ) {
            $name = $field['name'];
            if ( isset( $_POST[ $name ] ) ) {
                $value = FFC_Utils::recursive_sanitize( $_POST[ $name ] );
                
                // Special validation for CPF/RF
                if ( $name === 'cpf_rf' ) {
                    $value = preg_replace( '/\D/', '', $value );
                    
                    // Validate length
                    if ( strlen($value) !== 7 && strlen($value) !== 11 ) {
                        wp_send_json_error( array( 'message' => __( 'CPF/RF must be exactly 7 or 11 digits.', 'ffc' ) ) );
                    }
                    
                    // Validate CPF (11 digits) using official algorithm
                    if ( strlen($value) === 11 ) {
                        if ( ! FFC_Utils::validate_cpf( $value ) ) {
                            wp_send_json_error( array( 'message' => __( 'Invalid CPF. Please check the number and try again.', 'ffc' ) ) );
                        }
                    }
                    
                    // Validate RF (7 digits) - must be numeric
                    if ( strlen($value) === 7 ) {
                        if ( ! FFC_Utils::validate_rf( $value ) ) {
                            wp_send_json_error( array( 'message' => __( 'Invalid RF. Must contain only numbers.', 'ffc' ) ) );
                        }
                    }
                }
                
                $submission_data[ $name ] = $value;
                
                if ( isset($field['type']) && $field['type'] === 'email' ) {
                    $user_email = sanitize_email( $value );
                }
            }
        }

        if ( empty( $user_email ) ) {
            wp_send_json_error( array( 'message' => __( 'Email address is required.', 'ffc' ) ) );
        }
        // ✅ v2.10.0: Validate LGPD consent (mandatory)
        if ( empty( $_POST['ffc_lgpd_consent'] ) || $_POST['ffc_lgpd_consent'] !== '1' ) {
            wp_send_json_error( array( 
                'message' => __( 'You must agree to the Privacy Policy to continue.', 'ffc' ) 
            ) );
        }
        
        // ✅ v2.10.0: Add consent to submission data
        $submission_data['ffc_lgpd_consent'] = '1';

        // ✅ v2.10.0: Capture restriction fields (password/ticket) from POST
        // These are NOT in $fields_config, so capture separately
        $val_password = isset($_POST['ffc_password']) ? trim($_POST['ffc_password']) : '';
        $val_ticket = isset($_POST['ffc_ticket']) ? strtoupper(trim($_POST['ffc_ticket'])) : '';
        
        $val_cpf = isset($submission_data['cpf_rf']) ? trim($submission_data['cpf_rf']) : '';

        // ✅ v2.10.0: Rate Limit Check
        if (class_exists('FFC_Rate_Limiter')) {
            $ip = FFC_Utils::get_user_ip();
            $email = $user_email;
            $cpf = $val_cpf;
            
            $rate_check = FFC_Rate_Limiter::check_all($ip, $email, $cpf, $form_id);
            
            if (!$rate_check['allowed']) {
                wp_send_json_error(array(
                    'message' => $rate_check['message'] ?? 'Rate limit exceeded.',
                    'rate_limit' => true,
                    'wait_seconds' => $rate_check['wait_seconds'] ?? 0
                ));
            }
            
            // Record attempt
            FFC_Rate_Limiter::record_attempt('ip', $ip, $form_id);
            if ($email) FFC_Rate_Limiter::record_attempt('email', $email, $form_id);
            if ($cpf) FFC_Rate_Limiter::record_attempt('cpf', preg_replace('/[^0-9]/', '', $cpf), $form_id);
        }

        // ✅ v3.0.0: Geofence validation (date/time + IP geolocation)
        if (class_exists('FFC_Geofence')) {
            $geofence_check = FFC_Geofence::can_access_form($form_id, array(
                'check_datetime' => true, // Always validate date/time server-side
                'check_geo' => true,      // Validate IP geolocation if enabled
            ));

            if (!$geofence_check['allowed']) {
                wp_send_json_error(array(
                    'message' => $geofence_check['message'],
                    'geofence_blocked' => true,
                    'reason' => $geofence_check['reason']
                ));
            }
        }

        // Check restrictions (whitelist/denylist/tickets)
        $restriction_result = $this->check_restrictions( $form_config, $val_cpf, $val_ticket, $form_id );
        
        if ( ! $restriction_result['allowed'] ) {
            wp_send_json_error( array( 'message' => $restriction_result['message'] ) );
        }

        // Detect reprint (OPTIMIZED v2.9.13)
        $reprint_result = $this->detect_reprint( $form_id, $val_cpf, $val_ticket );
        
        $form_post = get_post( $form_id );
        $is_reprint = $reprint_result['is_reprint'];
        
        if ( $is_reprint ) {
            // Reprint - use existing submission ID
            $submission_id = $reprint_result['id'];
        } else {
            // New submission - save to database
            $submission_id = $this->submission_handler->process_submission( 
                $form_id, 
                $form_post->post_title, 
                $submission_data, 
                $user_email, 
                $fields_config, 
                $form_config 
            );
            
            if ( is_wp_error( $submission_id ) ) {
                wp_send_json_error( array( 'message' => $submission_id->get_error_message() ) );
            }
            
            // Remove used ticket if applicable
            if ( $restriction_result['is_ticket'] && ! empty( $val_ticket ) ) {
                $this->consume_ticket( $form_id, $val_ticket );
            }
        }

        // ✅ v2.10.0: Use unified PDF generation method
        // All we need is the submission_id - everything else is retrieved automatically
        $pdf_generator = new FFC_PDF_Generator( $this->submission_handler );
        $pdf_data = $pdf_generator->generate_pdf_data( 
            $submission_id, 
            $this->submission_handler 
        );
        
        if ( is_wp_error( $pdf_data ) ) {
            wp_send_json_error( array( 'message' => $pdf_data->get_error_message() ) );
        }

        // Success message with HTML response (v2.9.7+)
        $custom_message = isset( $form_config['success_message'] ) ? trim( $form_config['success_message'] ) : '';
        $msg = $is_reprint 
            ? __( 'Certificate previously issued (Reprint).', 'ffc' ) 
            : ( ! empty( $custom_message ) ? $custom_message : __( 'Success!', 'ffc' ) );

        wp_send_json_success( array( 
            'message' => $msg, 
            'pdf_data' => $pdf_data,
            'html' => FFC_Utils::generate_success_html(  // ✅ v2.9.13: Centralized in FFC_Utils
                $submission_data, 
                $form_id, 
                $real_submission_date,
                $msg
            )
        ) );
    }
}