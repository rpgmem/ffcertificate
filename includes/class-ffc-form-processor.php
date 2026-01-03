<?php
/**
 * FFC_Form_Processor
 * Handles form submission processing, validation, and restriction checks.
 * 
 * v2.9.2: Unified PDF generation with FFC_PDF_Generator
 * v2.9.11: Using FFC_Utils for validation and sanitization
 * v2.9.13: Optimized detect_reprint() to use cpf_rf column with fallback
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
    }

    /**
     * Check if submission passes restriction rules (whitelist/denylist/tickets)
     * Returns array with 'allowed' (bool) and 'message' (string) and 'is_ticket' (bool)
     */
    private function check_restrictions( $form_config, $val_cpf, $val_ticket ) {
        $restriction_enabled = isset( $form_config['enable_restriction'] ) && $form_config['enable_restriction'] == 1;
        $is_ticket_usage = false;

        // Denylist check
        if ( ! empty( $val_cpf ) || ! empty( $val_ticket ) ) {
            $denied_raw = isset( $form_config['denied_users_list'] ) ? $form_config['denied_users_list'] : '';
            $denied_list = array_filter( array_map( 'trim', explode( "\n", $denied_raw ) ) );
            
            if ( (!empty($val_cpf) && in_array( $val_cpf, $denied_list )) || (!empty($val_ticket) && in_array( $val_ticket, $denied_list )) ) {
                return array(
                    'allowed' => false,
                    'message' => __( 'Warning: Certificate issuance is blocked for this ID.', 'ffc' ),
                    'is_ticket' => false
                );
            }
        }

        // If restriction is disabled, allow everyone
        if ( ! $restriction_enabled ) {
            return array(
                'allowed' => true,
                'message' => '',
                'is_ticket' => false
            );
        }

        // Restriction is enabled - check ticket first, then whitelist
        if ( ! empty( $val_ticket ) ) {
            $generated_raw = isset( $form_config['generated_codes_list'] ) ? $form_config['generated_codes_list'] : '';
            $generated_list = array_filter( array_map( 'trim', explode( "\n", $generated_raw ) ) );
            
            if ( in_array( $val_ticket, $generated_list ) ) {
                return array(
                    'allowed' => true,
                    'message' => '',
                    'is_ticket' => true
                );
            } else {
                return array(
                    'allowed' => false,
                    'message' => __( 'Invalid or already used ticket.', 'ffc' ),
                    'is_ticket' => false
                );
            }
        } elseif ( ! empty( $val_cpf ) ) {
            $allowed_raw = isset( $form_config['allowed_users_list'] ) ? $form_config['allowed_users_list'] : '';
            $allowed_list = array_filter( array_map( 'trim', explode( "\n", $allowed_raw ) ) );
            
            if ( in_array( $val_cpf, $allowed_list ) ) {
                return array(
                    'allowed' => true,
                    'message' => '',
                    'is_ticket' => false
                );
            } else {
                return array(
                    'allowed' => false,
                    'message' => __( 'Access Denied: Your data was not found in the authorization list.', 'ffc' ),
                    'is_ticket' => false
                );
            }
        } else {
            return array(
                'allowed' => false,
                'message' => __( 'Error: A validation field (Ticket or CPF) is required.', 'ffc' ),
                'is_ticket' => false
            );
        }
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
            
            // ✅ v2.9.13: Try optimized query first (uses index)
            $existing_submission = $wpdb->get_row( $wpdb->prepare( 
                "SELECT * FROM {$table_name} 
                 WHERE form_id = %d 
                 AND cpf_rf = %s 
                 ORDER BY id DESC 
                 LIMIT 1", 
                $form_id, 
                $clean_cpf
            ) );
            
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
        // Debug: Log nonce value
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[FFC] handle_submission_ajax called' );
            error_log( '[FFC] $_POST[nonce]: ' . ( isset($_POST['nonce']) ? $_POST['nonce'] : 'NOT SET' ) );
            error_log( '[FFC] $_REQUEST[nonce]: ' . ( isset($_REQUEST['nonce']) ? $_REQUEST['nonce'] : 'NOT SET' ) );
        }
        
        check_ajax_referer( 'ffc_frontend_nonce', 'nonce' );
        
        // Validate security fields using FFC_Utils
        $security_check = FFC_Utils::validate_security_fields( $_POST );
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
                    if ( strlen($value) !== 7 && strlen($value) !== 11 ) {
                        wp_send_json_error( array( 'message' => __( 'Error: Identification ID must be exactly 7 or 11 digits.', 'ffc' ) ) );
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

        $val_cpf = isset($submission_data['cpf_rf']) ? trim($submission_data['cpf_rf']) : '';
        $val_ticket = isset($submission_data['ticket']) ? trim($submission_data['ticket']) : '';

        // Check restrictions (whitelist/denylist/tickets)
        $restriction_result = $this->check_restrictions( $form_config, $val_cpf, $val_ticket );
        
        if ( ! $restriction_result['allowed'] ) {
            wp_send_json_error( array( 'message' => $restriction_result['message'] ) );
        }

        // Detect reprint (OPTIMIZED v2.9.13)
        $reprint_result = $this->detect_reprint( $form_id, $val_cpf, $val_ticket );
        
        $form_post = get_post( $form_id );
        $is_reprint = $reprint_result['is_reprint'];
        
        if ( $is_reprint ) {
            // Use existing data
            $submission_data = $reprint_result['data'];
            $result = $reprint_result['id'];
            $user_email = $reprint_result['email'];
            $real_submission_date = $reprint_result['date'];
        } else {
            // New submission - save to database
            $result = $this->submission_handler->process_submission( 
                $form_id, 
                $form_post->post_title, 
                $submission_data, 
                $user_email, 
                $fields_config, 
                $form_config 
            );
            
            if ( is_wp_error( $result ) ) {
                wp_send_json_error( array( 'message' => $result->get_error_message() ) );
            }
            
            // Remove used ticket if applicable
            if ( $restriction_result['is_ticket'] && ! empty( $val_ticket ) ) {
                $this->consume_ticket( $form_id, $val_ticket );
            }
            
            // Retrieve saved data from database
            global $wpdb;
            $table_name = FFC_Utils::get_submissions_table();
            $saved_record = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_name} WHERE id = %d", $result ) );
            
            if ( $saved_record ) {
                $decoded_new = json_decode( $saved_record->data, true );
                if ( !is_array( $decoded_new ) ) {
                    $decoded_new = json_decode( stripslashes( $saved_record->data ), true );
                }
                
                // ✅ v2.9.16: RECONSTRUIR dados completos (colunas + JSON)
                if ( ! isset( $decoded_new['email'] ) && ! empty( $saved_record->email ) ) {
                    $decoded_new['email'] = $saved_record->email;
                }
                if ( ! isset( $decoded_new['cpf_rf'] ) && ! empty( $saved_record->cpf_rf ) ) {
                    $decoded_new['cpf_rf'] = $saved_record->cpf_rf;
                }
                if ( ! isset( $decoded_new['auth_code'] ) && ! empty( $saved_record->auth_code ) ) {
                    $decoded_new['auth_code'] = $saved_record->auth_code;
                }
                
                $submission_data = $decoded_new;
                $real_submission_date = $saved_record->submission_date;
            } else {
                $real_submission_date = current_time( 'mysql' );
            }
        }

        // ✅ v2.9.2: Use centralized PDF generator
        $pdf_generator = new FFC_PDF_Generator( $this->email_handler );
        $pdf_data = $pdf_generator->generate_pdf_data_from_form( 
            $submission_data, 
            $form_id, 
            $real_submission_date 
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