<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Responsável pelo processamento de dados submetidos (AJAX).
 * Lida com validação, inserção no banco e delega a geração de HTML para o Template Engine.
 */
class FFC_Process {

    /**
     * @var FFC_Submission_Handler
     */
    private $submission_handler;

    /**
     * @var FFC_Template_Engine
     */
    private $template_engine;

    public function __construct( $submission_handler ) {
        $this->submission_handler = $submission_handler;

        // Carrega e Instancia o Motor de Template
        if ( file_exists( FFC_PLUGIN_DIR . 'includes/core/class-ffc-template-engine.php' ) ) {
            require_once FFC_PLUGIN_DIR . 'includes/core/class-ffc-template-engine.php';
            $this->template_engine = new FFC_Template_Engine();
        }

        // AJAX para Submissão do Formulário
        add_action( 'wp_ajax_ffc_submit_form', array( $this, 'handle_submission_ajax' ) );
        add_action( 'wp_ajax_nopriv_ffc_submit_form', array( $this, 'handle_submission_ajax' ) );

        // AJAX para Verificação de Autenticidade
        add_action( 'wp_ajax_ffc_verify_certificate', array( $this, 'handle_verification_ajax' ) );
        add_action( 'wp_ajax_nopriv_ffc_verify_certificate', array( $this, 'handle_verification_ajax' ) );
    }

    /**
     * Processa o envio do formulário de certificado.
     */
    public function handle_submission_ajax() {
        // 1. Verifica Nonce
        if ( ! check_ajax_referer( 'ffc_frontend_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Security error (Nonce). Reload the page.', 'ffc' ) ) );
        }

        $form_id = isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0;
        if ( ! $form_id ) {
            wp_send_json_error( array( 'message' => __( 'Form ID not found.', 'ffc' ) ) );
        }

        // 2. Validação de Segurança (Captcha/Honeypot)
        if ( class_exists( 'FFC_Security' ) ) {
            $security = new FFC_Security();
            $sec_check = $security->validate_security_fields( $_POST );
            
            if ( is_wp_error( $sec_check ) ) {
                $new_captcha = $security->get_new_captcha_data();
                wp_send_json_error( array(
                    'message'         => $sec_check->get_error_message(),
                    'refresh_captcha' => true,
                    'new_hash'        => $new_captcha['hash'],
                    'new_label'       => $new_captcha['label']
                ) );
            }
        }

        // 3. Sanitização e Captura dos Dados do Formulário
        // NOTA: O shortcode envia os campos dentro do array 'ffc_data'
        $raw_data = isset( $_POST['ffc_data'] ) ? $_POST['ffc_data'] : array();
        
        if ( empty( $raw_data ) ) {
            wp_send_json_error( array( 'message' => __( 'No data received.', 'ffc' ) ) );
        }

        // Usa o sanitizador recursivo da classe Security
        $submission_data = class_exists( 'FFC_Security' ) 
            ? FFC_Security::recursive_sanitize( $raw_data ) 
            : array_map( 'sanitize_text_field', $raw_data );

        // Adiciona dados do sistema
        $submission_data['submission_date'] = current_time( 'Y-m-d H:i:s' ); // Banco
        $submission_data['fill_date']       = current_time( 'd/m/Y' );       // Exibição
        
        // Gera Código de Autenticidade
        if ( ! isset( $submission_data['auth_code'] ) ) {
            $submission_data['auth_code'] = strtoupper( wp_generate_password( 12, false ) );
        }

        // 4. Verificação de Restrições (CPF, Códigos VIP, etc)
        $restriction_error = $this->check_restrictions( $form_id, $submission_data );
        if ( $restriction_error ) {
            wp_send_json_error( array( 'message' => $restriction_error ) );
        }

        // 5. Salva no Banco de Dados
        global $wpdb;
        $table_name = $wpdb->prefix . 'ffc_submissions';
        $user_email = isset( $submission_data['email'] ) ? sanitize_email( $submission_data['email'] ) : '';

        $inserted = $wpdb->insert(
            $table_name,
            array(
                'form_id'         => $form_id,
                'submission_date' => $submission_data['submission_date'],
                'data'            => json_encode( $submission_data, JSON_UNESCAPED_UNICODE ),
                'user_ip'         => $this->get_user_ip(),
                'email'           => $user_email
            ),
            array( '%d', '%s', '%s', '%s', '%s' )
        );

        if ( ! $inserted ) {
            wp_send_json_error( array( 'message' => __( 'Database error. Could not save submission.', 'ffc' ) ) );
        }

        // 6. PREPARA O HTML FINAL DO PDF
        $config = get_post_meta( $form_id, '_ffc_form_config', true );
        $raw_template = isset( $config['pdf_layout'] ) ? $config['pdf_layout'] : '';
        
        $final_html = '';
        if ( $this->template_engine ) {
            // O engine substitui {{nome}}, {{data}}, etc pelos valores de $submission_data
            $final_html = $this->template_engine->render( $raw_template, $submission_data );
        } else {
            $final_html = 'Error: Template Engine not loaded.';
        }

        $pdf_response = array(
            'final_html' => $final_html,
            'bg_image'   => isset( $config['background_image'] ) ? $config['background_image'] : '',
            'form_title' => get_the_title( $form_id )
        );

        $success_msg = isset( $config['success_message'] ) && ! empty( $config['success_message'] ) 
            ? $config['success_message'] 
            : __( 'Certificate generated successfully!', 'ffc' );

        // 7. Envio de E-mail (Opcional)
        if ( ! empty( $config['send_user_email'] ) && ! empty( $user_email ) ) {
            $this->send_email_notification( $user_email, $form_id, $submission_data );
        }

        wp_send_json_success( array(
            'message'  => $success_msg,
            'pdf_data' => $pdf_response
        ) );
    }

    /**
     * Processa a verificação de autenticidade (Validador).
     */
    public function handle_verification_ajax() {
        // Validação de Segurança
        if ( class_exists( 'FFC_Security' ) ) {
            $security = new FFC_Security();
            $sec_check = $security->validate_security_fields( $_POST );
            
            if ( is_wp_error( $sec_check ) ) {
                $new_captcha = $security->get_new_captcha_data();
                wp_send_json_error( array(
                    'message'         => $sec_check->get_error_message(),
                    'refresh_captcha' => true,
                    'new_hash'        => $new_captcha['hash'],
                    'new_label'       => $new_captcha['label']
                ) );
            }
        }

        // Nome do campo ajustado para bater com o Shortcode (verification_code)
        $raw_code = isset( $_POST['verification_code'] ) ? sanitize_text_field( $_POST['verification_code'] ) : '';
        $clean_code = preg_replace( '/[^a-zA-Z0-9]/', '', $raw_code );
        
        if ( empty( $clean_code ) ) {
            wp_send_json_error( array( 'message' => __( 'Please enter a code.', 'ffc' ) ) );
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'ffc_submissions';
        
        // Busca usando LIKE no JSON para performance razoável
        $result = $wpdb->get_row( $wpdb->prepare( 
            "SELECT * FROM $table_name WHERE data LIKE %s LIMIT 1", 
            '%' . $wpdb->esc_like( $clean_code ) . '%' 
        ) );

        if ( ! $result ) {
            wp_send_json_error( array( 'message' => __( 'Certificate not found.', 'ffc' ) ) );
        }
        
        // Verifica se o código bate exatamente (evitar falsos positivos parciais no JSON)
        $data = json_decode( $result->data, true );
        if ( ! is_array( $data ) ) $data = json_decode( stripslashes( $result->data ), true );
        
        $stored_code = isset( $data['auth_code'] ) ? preg_replace( '/[^a-zA-Z0-9]/', '', $data['auth_code'] ) : '';
        
        if ( strtoupper( $stored_code ) !== strtoupper( $clean_code ) ) {
            wp_send_json_error( array( 'message' => __( 'Certificate not found.', 'ffc' ) ) );
        }

        // Retorno de sucesso com HTML formatado
        $name = isset($data['name']) ? $data['name'] : (isset($data['nome']) ? $data['nome'] : 'N/A');
        $form_title = get_the_title( $result->form_id );
        
        $html = '<div class="ffc-verify-success" style="border:1px solid #46b450; background:#edfaef; padding:15px; border-radius:4px; margin-top:15px;">';
        $html .= '<h4 style="color:#46b450; margin-top:0;">✅ ' . esc_html__( 'Valid Certificate', 'ffc' ) . '</h4>';
        $html .= '<p><strong>' . esc_html__( 'Name:', 'ffc' ) . '</strong> ' . esc_html( $name ) . '</p>';
        $html .= '<p><strong>' . esc_html__( 'Event:', 'ffc' ) . '</strong> ' . esc_html( $form_title ) . '</p>';
        $html .= '<p><strong>' . esc_html__( 'Date:', 'ffc' ) . '</strong> ' . esc_html( isset($data['fill_date']) ? $data['fill_date'] : '' ) . '</p>';
        $html .= '</div>';
        
        wp_send_json_success( array( 'html' => $html ) );
    }

    // --- MÉTODOS AUXILIARES ---

    private function check_restrictions( $form_id, $data ) {
        $config = get_post_meta( $form_id, '_ffc_form_config', true );
        
        if ( empty( $config['enable_restriction'] ) ) return false;

        // Lista Negra
        if ( ! empty( $config['denied_users_list'] ) && ! empty( $data['cpf_rf'] ) ) {
            $blocked = array_map( 'trim', explode( "\n", $config['denied_users_list'] ) );
            if ( in_array( $data['cpf_rf'], $blocked ) ) return __( 'Access denied for this ID.', 'ffc' );
        }

        // Código Único Geral (Senha do Formulário)
        if ( ! empty( $config['validation_code'] ) ) {
            // Verifica se o usuário enviou o campo 'access_code'
            if ( empty( $data['access_code'] ) ) return __( 'Access Code is required.', 'ffc' );
            if ( trim( $data['access_code'] ) !== trim( $config['validation_code'] ) ) return __( 'Invalid Access Code.', 'ffc' );
        }

        // Lista de Tickets (Uso único)
        if ( ! empty( $config['generated_codes_list'] ) && ! empty( $data['access_code'] ) ) {
            $tickets = array_map( 'trim', explode( "\n", $config['generated_codes_list'] ) );
            $input_code = trim( $data['access_code'] );
            $key = array_search( $input_code, $tickets );
            
            if ( $key !== false ) {
                // Remove o código usado e atualiza o post_meta
                unset( $tickets[$key] );
                $config['generated_codes_list'] = implode( "\n", $tickets );
                update_post_meta( $form_id, '_ffc_form_config', $config );
                return false; // Sucesso, código consumido
            } else {
                return __( 'Invalid or already used Access Code.', 'ffc' );
            }
        }

        // Lista Branca (Apenas estes IDs)
        if ( ! empty( $config['allowed_users_list'] ) ) {
            if ( empty( $data['cpf_rf'] ) ) return __( 'ID (CPF/RF) is required for verification.', 'ffc' );
            $allowed = array_map( 'trim', explode( "\n", $config['allowed_users_list'] ) );
            if ( ! in_array( $data['cpf_rf'], $allowed ) ) return __( 'Your ID is not on the allowed list.', 'ffc' );
        }

        return false;
    }

    private function send_email_notification( $to, $form_id, $data ) {
        $subject = sprintf( __( 'Your Certificate: %s', 'ffc' ), get_the_title( $form_id ) );
        $message = __( 'Your certificate has been generated. Use this code to verify authenticity:', 'ffc' ) . ' ' . $data['auth_code'];
        $headers = array('Content-Type: text/html; charset=UTF-8');
        wp_mail( $to, $subject, $message, $headers );
    }

    private function get_user_ip() {
        return isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : '0.0.0.0';
    }
}