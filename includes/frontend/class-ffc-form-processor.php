<?php
declare(strict_types=1);

/**
 * FormProcessor
 * Handles form submission processing, validation, and restriction checks.
 *
 * v2.9.2: Unified PDF generation with FFC_PDF_Generator
 * v2.9.11: Using FFC_Utils for validation and sanitization
 * v2.9.13: Optimized detect_reprint() to use cpf_rf column with fallback
 * v2.10.0: LGPD - Validates consent checkbox (mandatory)
 * v3.3.0: Added strict types and type hints
 * v3.2.0: Migrated to namespace (Phase 2)
 */

namespace FreeFormCertificate\Frontend;

use FreeFormCertificate\Submissions\SubmissionHandler;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

class FormProcessor {

    private $submission_handler;
    private $email_handler;

    /**
     * Constructor
     */
    public function __construct( SubmissionHandler $submission_handler, $email_handler ) {
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
    private function check_restrictions( array $form_config, string $val_cpf, string $val_ticket, int $form_id ): array {
        $restrictions = isset($form_config['restrictions']) ? $form_config['restrictions'] : array();
        
        // Clean CPF/RF (remove any mask)
        $clean_cpf = preg_replace('/\D/', '', $val_cpf);
        
        // ========================================
        // 1. PASSWORD CHECK (if active)
        // ========================================
        if (!empty($restrictions['password']) && $restrictions['password'] == '1') {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handle_submission_ajax() caller.
            $password = isset($_POST['ffc_password']) ? trim(sanitize_text_field(wp_unslash($_POST['ffc_password']))) : '';
            $valid_password = isset($form_config['validation_code']) ? $form_config['validation_code'] : '';
            
            if (empty($password)) {
                return array(
                    'allowed' => false, 
                    'message' => __('Password is required.', 'wp-ffcertificate'), 
                    'is_ticket' => false
                );
            }
            
            if ($password !== $valid_password) {
                return array(
                    'allowed' => false, 
                    'message' => __('Incorrect password.', 'wp-ffcertificate'), 
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
                    'message' => __('Your CPF/RF is blocked.', 'wp-ffcertificate'), 
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
                    'message' => __('Your CPF/RF is not authorized.', 'wp-ffcertificate'), 
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
                    'message' => __('Ticket code is required.', 'wp-ffcertificate'), 
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
                    'message' => __('Invalid or already used ticket.', 'wp-ffcertificate'), 
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
    private function detect_reprint( int $form_id, string $val_cpf, string $val_ticket ): array {
        global $wpdb;
        $table_name = \FreeFormCertificate\Core\Utils::get_submissions_table();
        $existing_submission = null;

        // ✅ PRIORITY 1: Check by ticket (if provided)
        if ( ! empty( $val_ticket ) ) {
            $like_query = '%' . $wpdb->esc_like( '"ticket":"' . $val_ticket . '"' ) . '%';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
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
            if (class_exists('\FreeFormCertificate\Core\Encryption') && \FreeFormCertificate\Core\Encryption::is_configured()) {
                // Use HASH for encrypted data
                $cpf_hash = \FreeFormCertificate\Core\Encryption::hash($clean_cpf);
                
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
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
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
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
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
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
            // Ensure data is not null before json_decode (strict types requirement)
            $data_json = $existing_submission->data ?? '';

            // Only decode if we have actual data (not null, not empty string)
            if (!empty($data_json) && is_string($data_json)) {
                $decoded_data = json_decode( $data_json, true );
                if( !is_array($decoded_data) ) {
                    $decoded_data = json_decode( stripslashes( $data_json ), true );
                }
            } else {
                $decoded_data = null;
            }

            // If still not an array, initialize empty
            if ( !is_array($decoded_data) ) {
                $decoded_data = array();
            }

            // ✅ v2.9.16: REBUILD complete data (columns + JSON)
            // Ensure required column fields are included
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
    private function consume_ticket( int $form_id, string $ticket ): void {
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
    public function handle_submission_ajax(): void {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'ffc_frontend_nonce')) {
            wp_send_json_error(['message' => __('Security check failed. Please refresh the page.', 'wp-ffcertificate')]);
            return;
        }

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified above via wp_verify_nonce.

        // ===== DEBUG CAPTCHA =====
        \FreeFormCertificate\Core\Debug::log_form('===== CAPTCHA DEBUG =====');
        \FreeFormCertificate\Core\Debug::log_form('Answer received', isset($_POST['ffc_captcha_ans']) ? sanitize_text_field(wp_unslash($_POST['ffc_captcha_ans'])) : 'NOT SET');
        \FreeFormCertificate\Core\Debug::log_form('Hash received', isset($_POST['ffc_captcha_hash']) ? sanitize_text_field(wp_unslash($_POST['ffc_captcha_hash'])) : 'NOT SET');

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- isset() check only; values sanitized inside block.
        if (isset($_POST['ffc_captcha_ans']) && isset($_POST['ffc_captcha_hash'])) {
            $test_answer = trim(sanitize_text_field(wp_unslash($_POST['ffc_captcha_ans'])));
            $received_hash = sanitize_text_field(wp_unslash($_POST['ffc_captcha_hash']));
            $generated_hash = wp_hash($test_answer . 'ffc_math_salt');

            \FreeFormCertificate\Core\Debug::log_form('Trimmed answer', $test_answer);
            \FreeFormCertificate\Core\Debug::log_form('Generated hash from answer', $generated_hash);
            \FreeFormCertificate\Core\Debug::log_form('Hashes match', $generated_hash === $received_hash ? 'YES' : 'NO');

            // Test with different variations
            \FreeFormCertificate\Core\Debug::log_form('Test with (int)', wp_hash((int)$test_answer . 'ffc_math_salt'));
            \FreeFormCertificate\Core\Debug::log_form('Test with (string)', wp_hash((string)$test_answer . 'ffc_math_salt'));
        }
        \FreeFormCertificate\Core\Debug::log_form('===== END CAPTCHA DEBUG =====');
        // ===== END DEBUG =====
        
        // Validate security fields using FFC_Utils
        $security_check = \FreeFormCertificate\Core\Utils::validate_security_fields($_POST);
        if ( $security_check !== true ) {
            // Generate new captcha for retry
            $n1 = wp_rand( 1, 9 );
            $n2 = wp_rand( 1, 9 );
            wp_send_json_error( array( 
                'message' => $security_check, 
                'refresh_captcha' => true, 
                /* translators: 1: first number, 2: second number */
                'new_label' => sprintf( esc_html__( 'Security: How much is %1$d + %2$d?', 'wp-ffcertificate' ), $n1, $n2 ) . ' <span class="required">*</span>',
                'new_hash' => wp_hash( ($n1 + $n2) . 'ffc_math_salt' )
            ) );
        }

        $form_id = isset( $_POST['form_id'] ) ? absint( wp_unslash( $_POST['form_id'] ) ) : 0;
        if ( ! $form_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid Form ID.', 'wp-ffcertificate' ) ) );
        }

        $form_config = get_post_meta( $form_id, '_ffc_form_config', true );
        if ( ! is_array( $form_config ) ) $form_config = array();
        
        $fields_config = get_post_meta( $form_id, '_ffc_form_fields', true );
        if ( ! $fields_config ) {
            wp_send_json_error( array( 'message' => __( 'Form configuration not found.', 'wp-ffcertificate' ) ) );
        }

        // Process and sanitize form fields using FFC_Utils
        $submission_data = array();
        $user_email = '';

        // Name fields that should be normalized (capitalized with lowercase connectives)
        $name_fields = array( 'nome_completo', 'nome', 'name', 'full_name', 'ffc_nome', 'participante' );

        foreach ( $fields_config as $field ) {
            $name = $field['name'];
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- isset() check only; value unslashed and sanitized below.
            if ( isset( $_POST[ $name ] ) ) {
                $value = \FreeFormCertificate\Core\Utils::recursive_sanitize( wp_unslash( $_POST[ $name ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized via recursive_sanitize().

                // Normalize name fields (proper capitalization with lowercase connectives)
                if ( in_array( $name, $name_fields, true ) && is_string( $value ) && ! empty( $value ) ) {
                    $value = \FreeFormCertificate\Core\Utils::normalize_brazilian_name( $value );
                }

                // Special validation for CPF/RF
                if ( $name === 'cpf_rf' ) {
                    $value = preg_replace( '/\D/', '', $value );
                    
                    // Validate length
                    if ( strlen($value) !== 7 && strlen($value) !== 11 ) {
                        wp_send_json_error( array( 'message' => __( 'CPF/RF must be exactly 7 or 11 digits.', 'wp-ffcertificate' ) ) );
                    }
                    
                    // Validate CPF (11 digits) using official algorithm
                    if ( strlen($value) === 11 ) {
                        if ( ! \FreeFormCertificate\Core\Utils::validate_cpf( $value ) ) {
                            wp_send_json_error( array( 'message' => __( 'Invalid CPF. Please check the number and try again.', 'wp-ffcertificate' ) ) );
                        }
                    }
                    
                    // Validate RF (7 digits) - must be numeric
                    if ( strlen($value) === 7 ) {
                        if ( ! \FreeFormCertificate\Core\Utils::validate_rf( $value ) ) {
                            wp_send_json_error( array( 'message' => __( 'Invalid RF. Must contain only numbers.', 'wp-ffcertificate' ) ) );
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
            wp_send_json_error( array( 'message' => __( 'Email address is required.', 'wp-ffcertificate' ) ) );
        }
        // ✅ v2.10.0: Validate LGPD consent (mandatory)
        if ( empty( $_POST['ffc_lgpd_consent'] ) || sanitize_text_field( wp_unslash( $_POST['ffc_lgpd_consent'] ) ) !== '1' ) {
            wp_send_json_error( array( 
                'message' => __( 'You must agree to the Privacy Policy to continue.', 'wp-ffcertificate' ) 
            ) );
        }
        
        // ✅ v2.10.0: Add consent to submission data
        $submission_data['ffc_lgpd_consent'] = '1';

        // ✅ v2.10.0: Capture restriction fields (password/ticket) from POST
        // These are NOT in $fields_config, so capture separately
        $val_password = isset($_POST['ffc_password']) ? trim(sanitize_text_field(wp_unslash($_POST['ffc_password']))) : '';
        $val_ticket = isset($_POST['ffc_ticket']) ? strtoupper(trim(sanitize_text_field(wp_unslash($_POST['ffc_ticket'])))) : '';
        
        $val_cpf = isset($submission_data['cpf_rf']) ? trim($submission_data['cpf_rf']) : '';

        // ✅ v2.10.0: Rate Limit Check
        if (class_exists('\FreeFormCertificate\Security\RateLimiter')) {
            $ip = \FreeFormCertificate\Core\Utils::get_user_ip();
            $email = $user_email;
            $cpf = $val_cpf;
            
            $rate_check = \FreeFormCertificate\Security\RateLimiter::check_all($ip, $email, $cpf, $form_id);
            
            if (!$rate_check['allowed']) {
                wp_send_json_error(array(
                    'message' => $rate_check['message'] ?? 'Rate limit exceeded.',
                    'rate_limit' => true,
                    'wait_seconds' => $rate_check['wait_seconds'] ?? 0
                ));
            }
            
            // Record attempt
            \FreeFormCertificate\Security\RateLimiter::record_attempt('ip', $ip, $form_id);
            if ($email) \FreeFormCertificate\Security\RateLimiter::record_attempt('email', $email, $form_id);
            if ($cpf) \FreeFormCertificate\Security\RateLimiter::record_attempt('cpf', preg_replace('/[^0-9]/', '', $cpf), $form_id);
        }

        // ✅ v3.0.0: Geofence validation (date/time + geolocation)
        if (class_exists('\FreeFormCertificate\Security\Geofence')) {
            // Get form geofence config to check if IP validation is enabled
            $geofence_config = \FreeFormCertificate\Security\Geofence::get_form_config($form_id);
            $should_validate_ip = false;

            // Backend validation logic:
            // - Always validate datetime (server-side is authoritative)
            // - Only validate IP geolocation if explicitly enabled
            // - GPS validation happens on frontend (browser geolocation API)
            //   Note: GPS-only mode relies on frontend validation; backend cannot verify GPS
            if ($geofence_config && !empty($geofence_config['geo_enabled']) && !empty($geofence_config['geo_ip_enabled'])) {
                $should_validate_ip = true;
            }

            $geofence_check = \FreeFormCertificate\Security\Geofence::can_access_form($form_id, array(
                'check_datetime' => true,        // Always validate date/time server-side
                'check_geo' => $should_validate_ip, // Only validate IP if explicitly enabled
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
            // Reprint - use existing submission ID (convert to int from wpdb string)
            $submission_id = (int) $reprint_result['id'];
            $real_submission_date = $reprint_result['date'];
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

            // Get the submission date from the newly created submission
            $real_submission_date = current_time( 'mysql' );

            // Remove used ticket if applicable
            if ( $restriction_result['is_ticket'] && ! empty( $val_ticket ) ) {
                $this->consume_ticket( $form_id, $val_ticket );
            }
        }

        // ✅ v2.10.0: Use unified PDF generation method
        // All we need is the submission_id - everything else is retrieved automatically
        $pdf_generator = new \FreeFormCertificate\Generators\PdfGenerator( $this->submission_handler );
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
            ? __( 'Certificate previously issued (Reprint).', 'wp-ffcertificate' ) 
            : ( ! empty( $custom_message ) ? $custom_message : __( 'Success!', 'wp-ffcertificate' ) );

        // phpcs:enable WordPress.Security.NonceVerification.Missing

        wp_send_json_success( array(
            'message' => $msg,
            'pdf_data' => $pdf_data,
            'html' => \FreeFormCertificate\Core\Utils::generate_success_html(  // ✅ v2.9.13: Centralized in FFC_Utils
                $submission_data,
                $form_id,
                $real_submission_date,
                $msg
            )
        ) );
    }
}