<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Classe responsável pelo Frontend e Shortcodes do Plugin
 */
class FFC_Frontend {
    
    private $submission_handler;

    public function __construct( FFC_Submission_Handler $handler ) {
        $this->submission_handler = $handler;
        
        // Ativos do Frontend
        add_action( 'wp_enqueue_scripts', array( $this, 'frontend_assets' ) );
        
        // Shortcodes
        add_shortcode( 'ffc_form', array( $this, 'shortcode_form' ) );
        add_shortcode( 'ffc_verification', array( $this, 'shortcode_verification_page' ) );
        
        // AJAX Handles - Submissão de Formulário
        add_action( 'wp_ajax_ffc_submit_form', array( $this, 'handle_submission_ajax' ) );
        add_action( 'wp_ajax_nopriv_ffc_submit_form', array( $this, 'handle_submission_ajax' ) );

        // AJAX Handles - Verificação de Certificado
        add_action( 'wp_ajax_ffc_verify_certificate', array( $this, 'handle_verification_ajax' ) );
        add_action( 'wp_ajax_nopriv_ffc_verify_certificate', array( $this, 'handle_verification_ajax' ) );
    }

    /**
     * Carrega Scripts e Estilos condicionalmente
     */
    public function frontend_assets() {
        global $post;
        
        if ( ! is_a( $post, 'WP_Post' ) ) {
            return;
        }

        if ( has_shortcode( $post->post_content, 'ffc_form' ) || has_shortcode( $post->post_content, 'ffc_verification' ) ) {
            
            wp_enqueue_style( 'ffc-pdf-core', FFC_PLUGIN_URL . 'assets/css/ffc-pdf-core.css', array(), '1.0.0' );
            wp_enqueue_style( 'ffc-frontend-css', FFC_PLUGIN_URL . 'assets/css/frontend.css', array('ffc-pdf-core'), '1.0.0' );
            
            // Bibliotecas de geração de PDF (Frontend)
            wp_enqueue_script( 'html2canvas', FFC_PLUGIN_URL . 'assets/js/html2canvas.min.js', array(), '1.4.1', true );
            wp_enqueue_script( 'jspdf', FFC_PLUGIN_URL . 'assets/js/jspdf.umd.min.js', array(), '2.5.1', true );
            
            wp_enqueue_script( 
                'ffc-frontend-js', 
                FFC_PLUGIN_URL . 'assets/js/frontend.js', 
                array( 'jquery', 'html2canvas', 'jspdf' ), 
                time(), // Cache busting para desenvolvimento
                true 
            );

            wp_localize_script( 'ffc-frontend-js', 'ffc_ajax', array(
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( 'ffc_frontend_nonce' ),
                'strings'  => array(
                    'verifying'             => __( 'Verifying...', 'ffc' ),
                    'verify'                => __( 'Verify', 'ffc' ),
                    'processing'            => __( 'Processing...', 'ffc' ),
                    'submit'                => __( 'Submit', 'ffc' ),
                    'connectionError'       => __( 'Connection error.', 'ffc' ),
                    'enterCode'             => __( 'Please enter the code.', 'ffc' ),
                    'generatingCertificate' => __( 'Generating certificate in the background, please wait 10 seconds and check your downloads folder...', 'ffc' ),
                    'idMustHaveDigits'      => __( 'The ID must have exactly 7 digits (RF) or 11 digits (CPF).', 'ffc' ),
                    'pdfLibrariesFailed'    => __( 'Error: PDF libraries (html2canvas/jspdf) failed to load.', 'ffc' ),
                    'pdfGenerationError'    => __( 'Error generating PDF (html2canvas). Please try again.', 'ffc' ),
                )
            ) );
        }
    }

    // =========================================================================
    // HELPER FUNCTIONS
    // =========================================================================

    private function recursive_sanitize( $data ) {
        if ( is_array( $data ) ) {
            $sanitized = array();
            foreach ( $data as $key => $value ) {
                $sanitized[ sanitize_key( $key ) ] = $this->recursive_sanitize( $value );
            }
            return $sanitized;
        }
        return wp_kses( $data, FFC_Utils::get_allowed_html_tags() );
    }

    private function get_new_captcha_data() {
        $n1 = rand( 1, 9 );
        $n2 = rand( 1, 9 );
        return array(
            'label' => sprintf( esc_html__( 'Security: How much is %d + %d?', 'ffc' ), $n1, $n2 ) . ' <span class="required">*</span>',
            'hash'  => wp_hash( ($n1 + $n2) . 'ffc_math_salt' )
        );
    }

    private function generate_security_fields() {
        $captcha = $this->get_new_captcha_data();
        ob_start();
        ?>
        <div class="ffc-security-container">
            <div style="position: absolute; left: -9999px; display: none;">
                <label><?php esc_html_e('Do not fill this field if you are human:', 'ffc'); ?></label>
                <input type="text" name="ffc_honeypot_trap" value="" tabindex="-1" autocomplete="off">
            </div>

            <div class="ffc-captcha-row">
                <label for="ffc_captcha_ans">
                    <?php echo $captcha['label']; ?>
                </label>
                <input type="number" name="ffc_captcha_ans" id="ffc_captcha_ans" class="ffc-input" required>
                <input type="hidden" name="ffc_captcha_hash" value="<?php echo esc_attr( $captcha['hash'] ); ?>">
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    private function validate_security_fields( $data ) {
        if ( ! empty( $data['ffc_honeypot_trap'] ) ) {
            return __( 'Security Error: Request blocked (Honeypot).', 'ffc' );
        }
        if ( ! isset( $data['ffc_captcha_ans'] ) || ! isset( $data['ffc_captcha_hash'] ) ) {
            return __( 'Error: Please answer the security question.', 'ffc' );
        }
        $user_ans = trim( $data['ffc_captcha_ans'] );
        $hash_sent = $data['ffc_captcha_hash'];
        $check_hash = wp_hash( $user_ans . 'ffc_math_salt' );

        if ( $check_hash !== $hash_sent ) {
            return __( 'Error: The math answer is incorrect.', 'ffc' );
        }
        return true; 
    }

    // =========================================================================
    // 1. VERIFICATION PAGE (SHORTCODE)
    // =========================================================================
    public function shortcode_verification_page( $atts ) {
        ob_start();
        $input_raw = isset( $_POST['ffc_auth_code'] ) ? sanitize_text_field( $_POST['ffc_auth_code'] ) : '';
        $result_html = '';
        $error_msg = '';

        if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ffc_auth_code']) ) {
            $security_check = $this->validate_security_fields( $_POST );
            
            if ( $security_check !== true ) {
                $error_msg = $security_check;
            } elseif ( empty( $input_raw ) ) {
                $error_msg = __( 'Please enter the code.', 'ffc' );
            } else {
                global $wpdb;
                $table_name = $wpdb->prefix . 'ffc_submissions';
                $clean_code = preg_replace( '/[^A-Z0-9]/', '', strtoupper( $input_raw ) );
                $like_query = '%' . $wpdb->esc_like( '"auth_code":"' . $clean_code . '"' ) . '%';
                $submission = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_name} WHERE data LIKE %s LIMIT 1", $like_query ) );

                if ( $submission ) {
                    $data = json_decode( $submission->data, true );
                    if ( is_null( $data ) ) $data = json_decode( stripslashes( $submission->data ), true );
                    $form = get_post( $submission->form_id );
                    $form_title = $form ? $form->post_title : 'N/A';
                    $date_generated = date_i18n( get_option('date_format') . ' ' . get_option('time_format'), strtotime( $submission->submission_date ) );
                    $display_code = isset($data['auth_code']) ? $data['auth_code'] : $clean_code;

                    $result_html .= '<div class="ffc-verify-success">';
                    $result_html .= '<h3>✅ ' . esc_html__( 'Authentic Certificate', 'ffc' ) . '</h3>';
                    $result_html .= '<p><strong>' . esc_html__( 'Code:', 'ffc' ) . '</strong> ' . esc_html( $display_code ) . '</p>';
                    $result_html .= '<p><strong>' . esc_html__( 'Event:', 'ffc' ) . '</strong> ' . esc_html( $form_title ) . '</p>';
                    $result_html .= '<p><strong>' . esc_html__( 'Issued on:', 'ffc' ) . '</strong> ' . esc_html( $date_generated ) . '</p>';
                    $result_html .= '<hr>';
                    $result_html .= '<h4>' . esc_html__( 'Participant Data:', 'ffc' ) . '</h4><ul style="list-style:none; padding:0;">';
                    if ( is_array( $data ) ) {
                        foreach ( $data as $key => $value ) {
                            if ( in_array( $key, array('auth_code', 'cpf_rf', 'ticket', 'fill_date', 'date', 'submission_date', 'fill_time'), true ) ) continue;
                            $label = ucfirst( str_replace( '_', ' ', $key ) );
                            $result_html .= '<li><strong>' . esc_html( $label ) . ':</strong> ' . esc_html( $value ) . '</li>';
                        }
                    }
                    $result_html .= '</ul></div>';
                } else {
                    $error_msg = __( 'Certificate not found or invalid code.', 'ffc' );
                }
            }
            if ( ! empty( $error_msg ) ) {
                $result_html .= '<div class="ffc-verify-error">❌ ' . esc_html( $error_msg ) . '</div>';
            }
        }
        ?>
        <div class="ffc-verification-container">
            <form method="POST" class="ffc-verification-form">
                <div style="display:flex; gap:10px; flex-wrap:wrap; justify-content:center; align-items: flex-start;">
                    <input type="text" name="ffc_auth_code" class="ffc-input ffc-verify-input" value="<?php echo esc_attr( $input_raw ); ?>" placeholder="<?php esc_attr_e( 'Enter code...', 'ffc' ); ?>" required maxlength="14">
                    <button type="submit" class="ffc-submit-btn"><?php esc_html_e( 'Verify', 'ffc' ); ?></button>
                </div>
                <div class="ffc-no-js-security"><?php echo $this->generate_security_fields(); ?></div>
            </form>
            <div class="ffc-verify-result"><?php echo $result_html; ?></div>
        </div>
        <?php
        return ob_get_clean();
    }

    // =========================================================================
    // 2. ISSUANCE FORM (SHORTCODE)
    // =========================================================================
    public function shortcode_form( $atts ) {
        $atts = shortcode_atts( array( 'id' => 0 ), $atts, 'ffc_form' );
        $form_id = absint( $atts['id'] );
        if ( ! $form_id || get_post_type( $form_id ) !== 'ffc_form' ) return '<p>' . esc_html__( 'Form not found.', 'ffc' ) . '</p>';

        $form_post  = get_post( $form_id );
        $form_title = $form_post ? $form_post->post_title : '';
        $fields = get_post_meta( $form_id, '_ffc_form_fields', true );
        if ( empty( $fields ) ) return '<p>' . esc_html__( 'Form has no fields.', 'ffc' ) . '</p>';

        ob_start();
        ?>
        <div class="ffc-form-wrapper" id="ffc-form-<?php echo esc_attr( $form_id ); ?>">
            <h2 class="ffc-form-title"><?php echo esc_html( $form_title ); ?></h2>
            <form class="ffc-submission-form" id="ffc-form-element-<?php echo esc_attr( $form_id ); ?>">
                <input type="hidden" name="form_id" value="<?php echo esc_attr( $form_id ); ?>">
                
                <?php foreach ( $fields as $field ) : 
                    $type = isset($field['type']) ? $field['type'] : 'text';
                    $name = isset($field['name']) ? $field['name'] : '';
                    $label = isset($field['label']) ? $field['label'] : '';
                    $default = isset($field['default_value']) ? $field['default_value'] : '';
                    $is_req = ! empty( $field['required'] );
                    $required_attr = $is_req ? 'required' : '';
                    $options = ! empty( $field['options'] ) ? explode( ',', $field['options'] ) : array();

                    if ( empty( $name ) ) continue;
                    
                    // Tratamento especial para CPF/RF
                    if ( $name === 'cpf_rf' ) $type = 'tel'; 

                    // CORREÇÃO: Renderiza campo hidden fora da estrutura visual
                    if ( $type === 'hidden' ) : ?>
                        <input type="hidden" name="<?php echo esc_attr( $name ); ?>" id="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $default ); ?>">
                        <?php continue; ?>
                    <?php endif; ?>
                    
                    <div class="ffc-form-field">
                        <label for="<?php echo esc_attr( $name ); ?>">
                            <?php echo esc_html( $label ); ?> 
                            <?php if ( $is_req ) echo '<span class="required">*</span>'; ?>
                        </label>
                        
                        <?php if ( $type === 'textarea' ) : ?>
                            <textarea class="ffc-input" name="<?php echo esc_attr( $name ); ?>" id="<?php echo esc_attr( $name ); ?>" <?php echo $required_attr; ?>><?php echo esc_textarea($default); ?></textarea>

                        <?php elseif ( $type === 'select' ) : ?>
                            <select class="ffc-input" name="<?php echo esc_attr( $name ); ?>" id="<?php echo esc_attr( $name ); ?>" <?php echo $required_attr; ?>>
                                <option value=""><?php esc_html_e( 'Select...', 'ffc' ); ?></option>
                                <?php foreach ( $options as $opt ) : $opt_val = trim($opt); ?>
                                    <option value="<?php echo esc_attr( $opt_val ); ?>" <?php selected($default, $opt_val); ?>><?php echo esc_html( $opt_val ); ?></option>
                                <?php endforeach; ?>
                            </select>

                        <?php elseif ( $type === 'radio' ) : ?>
                            <div class="ffc-radio-group">
                                <?php foreach ( $options as $opt ) : $opt_val = trim( $opt ); ?>
                                    <label><input type="radio" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $opt_val ); ?>" <?php echo $required_attr; ?> <?php checked($default, $opt_val); ?>> <?php echo esc_html( $opt_val ); ?></label>
                                <?php endforeach; ?>
                            </div>

                        <?php else : ?>
                            <input class="ffc-input" type="<?php echo esc_attr( $type ); ?>" name="<?php echo esc_attr( $name ); ?>" id="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $default ); ?>" <?php echo $required_attr; ?>>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>

                <?php echo $this->generate_security_fields(); ?>

                <button type="submit" class="ffc-submit-btn"><?php esc_html_e( 'Submit', 'ffc' ); ?></button>
                <div class="ffc-message"></div>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    // =========================================================================
    // 3. AJAX PROCESSING (FORM SUBMISSION)
    // =========================================================================
    public function handle_submission_ajax() {
        check_ajax_referer( 'ffc_frontend_nonce', 'nonce' );
        
        $security_check = $this->validate_security_fields( $_POST );
        if ( $security_check !== true ) {
            $new_captcha = $this->get_new_captcha_data();
            wp_send_json_error( array( 'message' => $security_check, 'refresh_captcha' => true, 'new_label' => $new_captcha['label'], 'new_hash' => $new_captcha['hash'] ) );
        }

        $form_id = isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0;
        if ( ! $form_id ) wp_send_json_error( array( 'message' => __( 'Invalid Form ID.', 'ffc' ) ) );

        $form_config = get_post_meta( $form_id, '_ffc_form_config', true );
        if ( ! is_array( $form_config ) ) $form_config = array();
        $fields_config = get_post_meta( $form_id, '_ffc_form_fields', true );
        if ( ! $fields_config ) wp_send_json_error( array( 'message' => __( 'Form configuration not found.', 'ffc' ) ) );

        $submission_data = array();
        $user_email = '';

        foreach ( $fields_config as $field ) {
            $name = $field['name'];
            if ( isset( $_POST[ $name ] ) ) {
                $value = $this->recursive_sanitize( $_POST[ $name ] );
                if ( $name === 'cpf_rf' ) {
                    $value = preg_replace( '/\D/', '', $value );
                    if ( strlen($value) !== 7 && strlen($value) !== 11 ) {
                        wp_send_json_error( array( 'message' => __( 'Error: Identification ID must be exactly 7 or 11 digits.', 'ffc' ) ) );
                    }
                }
                $submission_data[ $name ] = $value;
                if ( isset($field['type']) && $field['type'] === 'email' ) $user_email = sanitize_email( $value );
            }
        }

        if ( empty( $user_email ) ) wp_send_json_error( array( 'message' => __( 'Email address is required.', 'ffc' ) ) );

        $val_cpf = isset($submission_data['cpf_rf']) ? trim($submission_data['cpf_rf']) : '';
        $val_ticket = isset($submission_data['ticket']) ? trim($submission_data['ticket']) : '';
        $restriction_enabled = isset( $form_config['enable_restriction'] ) && $form_config['enable_restriction'] == 1;
        $is_ticket_usage = false;

        // Denylist check
        if ( ! empty( $val_cpf ) || ! empty( $val_ticket ) ) {
            $denied_raw = isset( $form_config['denied_users_list'] ) ? $form_config['denied_users_list'] : '';
            $denied_list = array_filter( array_map( 'trim', explode( "\n", $denied_raw ) ) );
            if ( (!empty($val_cpf) && in_array( $val_cpf, $denied_list )) || (!empty($val_ticket) && in_array( $val_ticket, $denied_list )) ) {
                wp_send_json_error( array( 'message' => __( 'Warning: Certificate issuance is blocked for this ID.', 'ffc' ) ) );
            }
        }

        if ( $restriction_enabled ) {
            $is_authorized = false;
            if ( ! empty( $val_ticket ) ) {
                $generated_raw = isset( $form_config['generated_codes_list'] ) ? $form_config['generated_codes_list'] : '';
                $generated_list = array_filter( array_map( 'trim', explode( "\n", $generated_raw ) ) );
                if ( in_array( $val_ticket, $generated_list ) ) { $is_authorized = true; $is_ticket_usage = true; }
                else { wp_send_json_error( array( 'message' => __( 'Invalid or already used ticket.', 'ffc' ) ) ); }
            } 
            elseif ( ! empty( $val_cpf ) ) {
                $allowed_raw = isset( $form_config['allowed_users_list'] ) ? $form_config['allowed_users_list'] : '';
                $allowed_list = array_filter( array_map( 'trim', explode( "\n", $allowed_raw ) ) );
                if ( in_array( $val_cpf, $allowed_list ) ) $is_authorized = true;
            } else {
                wp_send_json_error( array( 'message' => __( 'Error: A validation field (Ticket or CPF) is required.', 'ffc' ) ) );
            }
            if ( ! $is_authorized ) wp_send_json_error( array( 'message' => __( 'Access Denied: Your data was not found in the authorization list.', 'ffc' ) ) );
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'ffc_submissions';
        $form_post = get_post( $form_id );
        $is_reprint = false;
        $real_submission_date = current_time( 'mysql' ); 
        $existing_submission = null;

        if ( ! empty( $val_ticket ) ) {
            $like_query = '%' . $wpdb->esc_like( '"ticket":"' . $val_ticket . '"' ) . '%';
            $existing_submission = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_name} WHERE form_id = %d AND data LIKE %s ORDER BY id DESC LIMIT 1", $form_id, $like_query ) );
        } elseif ( ! empty( $val_cpf ) ) {
            $like_query = '%' . $wpdb->esc_like( '"cpf_rf":"' . $val_cpf . '"' ) . '%';
            $existing_submission = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_name} WHERE form_id = %d AND data LIKE %s ORDER BY id DESC LIMIT 1", $form_id, $like_query ) );
        }

        if ( $existing_submission ) {
            $decoded_data = json_decode( $existing_submission->data, true );
            if( !is_array($decoded_data) ) $decoded_data = json_decode( stripslashes( $existing_submission->data ), true );
            $submission_data = $decoded_data;
            $result = $existing_submission->id;
            $user_email = $existing_submission->email;
            $is_reprint = true;
            $real_submission_date = $existing_submission->submission_date;
        }

        if ( ! $is_reprint ) {
            $result = $this->submission_handler->process_submission( $form_id, $form_post->post_title, $submission_data, $user_email, $fields_config, $form_config );
            if ( is_wp_error( $result ) ) wp_send_json_error( array( 'message' => $result->get_error_message() ) );
            
            if ( $is_ticket_usage && ! empty( $val_ticket ) ) {
                $current_config = get_post_meta( $form_id, '_ffc_form_config', true );
                $current_raw_codes = isset( $current_config['generated_codes_list'] ) ? $current_config['generated_codes_list'] : '';
                $current_list = array_filter( array_map( 'trim', explode( "\n", $current_raw_codes ) ) );
                $updated_list = array_diff( $current_list, array( $val_ticket ) );
                $current_config['generated_codes_list'] = implode( "\n", $updated_list );
                update_post_meta( $form_id, '_ffc_form_config', $current_config );
            }
            
            $saved_record = $wpdb->get_row( $wpdb->prepare( "SELECT data FROM {$table_name} WHERE id = %d", $result ) );
            if ( $saved_record ) {
                $decoded_new = json_decode( $saved_record->data, true );
                if ( !is_array( $decoded_new ) ) $decoded_new = json_decode( stripslashes( $saved_record->data ), true );
                $submission_data = $decoded_new;
            }
        }

        $formatted_date = date_i18n( get_option('date_format') . ' ' . get_option('time_format'), strtotime( $real_submission_date ) );
        $submission_data['fill_date'] = $formatted_date;
        $submission_data['date'] = $formatted_date;

        $pdf_html = $this->submission_handler->generate_pdf_html( $submission_data, $form_post->post_title, $form_config );
        $bg_image_url = get_post_meta( $form_id, '_ffc_form_bg', true );

        $custom_message = isset( $form_config['success_message'] ) ? trim( $form_config['success_message'] ) : '';
        $msg = $is_reprint ? __( 'Certificate previously issued (Reprint).', 'ffc' ) : ( ! empty( $custom_message ) ? $custom_message : __( 'Success!', 'ffc' ) );

        wp_send_json_success( array( 
            'message' => $msg, 
            'pdf_data' => array( 
                'template'      => $pdf_html, 
                'form_title'    => $form_post->post_title, 
                'submission_id' => $result, 
                'submission'    => $submission_data,
                'bg_image'      => $bg_image_url
            ) 
        ) );
    }

    public function handle_verification_ajax() {
        check_ajax_referer( 'ffc_frontend_nonce', 'nonce' );
        $security_check = $this->validate_security_fields( $_POST );
        if ( $security_check !== true ) {
            $new_captcha = $this->get_new_captcha_data();
            wp_send_json_error( array( 'message' => $security_check, 'refresh_captcha' => true, 'new_label' => $new_captcha['label'], 'new_hash' => $new_captcha['hash'] ) );
        }
        $raw_code = isset( $_POST['ffc_auth_code'] ) ? sanitize_text_field( $_POST['ffc_auth_code'] ) : '';
        $clean_code = strtoupper( preg_replace( '/[^a-zA-Z0-9]/', '', $raw_code ) );
        if ( empty( $clean_code ) ) wp_send_json_error( array( 'message' => __( 'Please enter the code.', 'ffc' ) ) );

        global $wpdb;
        $table_name = $wpdb->prefix . 'ffc_submissions';
        $like_query = '%' . $wpdb->esc_like( '"auth_code":"' . $clean_code . '"' ) . '%';
        $result = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_name} WHERE data LIKE %s LIMIT 1", $like_query ) );

        if ( $result ) {
            $data = json_decode( $result->data, true );
            if ( !is_array($data) ) $data = json_decode( stripslashes( $result->data ), true );
            $form_title = get_the_title( $result->form_id );
            $student_name = isset($data['name']) ? $data['name'] : (isset($data['nome']) ? $data['nome'] : 'N/A');
            
            $response_html = '<div class="ffc-verify-success">';
            $response_html .= '<h4>✅ ' . esc_html__( 'Authentic Certificate', 'ffc' ) . '</h4>';
            $response_html .= '<p><strong>' . esc_html__( 'Name:', 'ffc' ) . '</strong> ' . esc_html( $student_name ) . '</p>';
            $response_html .= '<p><strong>' . esc_html__( 'Course/Event:', 'ffc' ) . '</strong> ' . esc_html( $form_title ) . '</p>';
            $response_html .= '<p><strong>' . esc_html__( 'Issued Date:', 'ffc' ) . '</strong> ' . date_i18n( get_option('date_format'), strtotime($result->submission_date) ) . '</p></div>';
            wp_send_json_success( array( 'html' => $response_html ) );
        } else {
            wp_send_json_error( array( 'message' => '❌ ' . __( 'Certificate not found.', 'ffc' ) ) );
        }
    }
}