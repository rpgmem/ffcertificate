<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Gerencia as requisições Ajax vindas do FRONTEND (Site público)
 */
class FFC_Frontend_Ajax {

    public function __construct() {
        // Ação para salvar o formulário (Logged in & Visitors)
        add_action( 'wp_ajax_ffc_handle_submission', array( $this, 'handle_submission' ) );
        add_action( 'wp_ajax_nopriv_ffc_handle_submission', array( $this, 'handle_submission' ) );

        // Ação para verificar certificado (Logged in & Visitors)
        add_action( 'wp_ajax_ffc_verify_certificate', array( $this, 'verify_certificate' ) );
        add_action( 'wp_ajax_nopriv_ffc_verify_certificate', array( $this, 'verify_certificate' ) );
    }

    /**
     * ==============================================================
     * 1. PROCESSAR SUBMISSÃO DO FORMULÁRIO
     * ==============================================================
     */
    public function handle_submission() {
        // 1. Verificação de Segurança (Nonce)
        check_ajax_referer( 'ffc_frontend_ajax_nonce', 'nonce' );

        // 2. Verificação de Captcha
        $captcha_error = $this->validate_captcha_request();
        if ( $captcha_error ) {
            wp_send_json_error( $captcha_error ); // Retorna erro e novos dados de captcha
        }

        // 3. Sanitização e Validação dos Dados Básicos
        $form_id = isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0;
        if ( ! $form_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid Form ID.', 'ffc' ) ) );
        }

        // Coleta todos os campos enviados (exceto os de controle)
        $ignore_keys = array( 'action', 'nonce', 'form_id', 'ffc_captcha_ans', 'ffc_captcha_hash' );
        $submission_data = array();

        foreach ( $_POST as $key => $value ) {
            if ( ! in_array( $key, $ignore_keys ) ) {
                if ( is_array( $value ) ) {
                    $submission_data[ $key ] = array_map( 'sanitize_text_field', $value );
                } else {
                    $submission_data[ $key ] = sanitize_textarea_field( $value ); // Textarea permite quebras de linha
                }
            }
        }

        // 4. Gera ID Único (Código de Autenticidade)
        // Ex: CERT-A1B2-C3D4
        $unique_id = 'CERT-' . strtoupper( substr( md5( uniqid( wp_rand(), true ) ), 0, 8 ) );
        $unique_id = implode( '-', str_split( str_replace('CERT-', '', $unique_id), 4 ) ); // Formata visualmente

        // Adiciona dados meta à submissão
        $submission_data['submission_date'] = current_time( 'mysql' );
        $submission_data['unique_id']       = $unique_id;

        // 5. Salva no Banco de Dados
        global $wpdb;
        $table_name = $wpdb->prefix . 'ffc_submissions';
        
        $inserted = $wpdb->insert(
            $table_name,
            array(
                'form_id'         => $form_id,
                'data'            => wp_json_encode( $submission_data, JSON_UNESCAPED_UNICODE ),
                'unique_id'       => $unique_id,
                'submission_date' => current_time( 'mysql' ),
                'status'          => 'approved' // Padrão
            ),
            array( '%d', '%s', '%s', '%s', '%s' )
        );

        if ( ! $inserted ) {
            wp_send_json_error( array( 'message' => __( 'Error saving submission to database.', 'ffc' ) ) );
        }

        $submission_db_id = $wpdb->insert_id;

        // 6. Prepara Dados para o PDF (Substituição de Variáveis)
        $pdf_data = $this->prepare_pdf_data( $form_id, $submission_data, $unique_id );

        wp_send_json_success( array(
            'message'  => __( 'Form submitted successfully!', 'ffc' ),
            'pdf_data' => $pdf_data // O JS usará isso para gerar o PDF
        ));
    }

    /**
     * ==============================================================
     * 2. VERIFICAR CERTIFICADO
     * ==============================================================
     */
    public function verify_certificate() {
        check_ajax_referer( 'ffc_frontend_ajax_nonce', 'nonce' );

        // Valida Captcha
        $captcha_error = $this->validate_captcha_request();
        if ( $captcha_error ) {
            wp_send_json_error( $captcha_error );
        }

        $code = isset( $_POST['verification_code'] ) ? sanitize_text_field( $_POST['verification_code'] ) : ''; // Shortcode name
        if( empty( $code ) && isset($_POST['ffc_auth_code']) ) {
             $code = sanitize_text_field( $_POST['ffc_auth_code'] ); // Fallback
        }

        if ( empty( $code ) ) {
            wp_send_json_error( array( 'message' => __( 'Please enter a code.', 'ffc' ) ) );
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'ffc_submissions';

        // Busca pelo unique_id
        $result = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_name} WHERE unique_id = %s", $code ) );

        if ( $result ) {
            $data = json_decode( $result->data, true );
            $form_title = get_the_title( $result->form_id );
            $date = date_i18n( get_option( 'date_format' ), strtotime( $result->submission_date ) );

            // Constrói HTML de Sucesso
            $html  = '<div class="ffc-verify-success">';
            $html .= '<h3>' . __( 'Certificate Verified', 'ffc' ) . ' ✅</h3>';
            $html .= '<p>' . sprintf( __( 'The certificate <strong>%s</strong> is valid.', 'ffc' ), $code ) . '</p>';
            
            $html .= '<div class="ffc-verify-details"><ul>';
            $html .= '<li><strong>' . __( 'Event/Form:', 'ffc' ) . '</strong> ' . esc_html( $form_title ) . '</li>';
            $html .= '<li><strong>' . __( 'Date:', 'ffc' ) . '</strong> ' . $date . '</li>';
            
            // Tenta mostrar o nome se existir campo 'nome' ou 'name'
            if ( ! empty( $data['nome'] ) ) $html .= '<li><strong>' . __( 'Issued to:', 'ffc' ) . '</strong> ' . esc_html( $data['nome'] ) . '</li>';
            elseif ( ! empty( $data['name'] ) ) $html .= '<li><strong>' . __( 'Issued to:', 'ffc' ) . '</strong> ' . esc_html( $data['name'] ) . '</li>';
            
            $html .= '</ul></div>';
            $html .= '</div>';

            wp_send_json_success( array( 'html' => $html ) );
        } else {
            wp_send_json_error( array( 'message' => __( 'Certificate not found or invalid code.', 'ffc' ) ) );
        }
    }

    /**
     * Helper: Valida Captcha e retorna array de erro ou false se OK
     */
    private function validate_captcha_request() {
        if ( class_exists( 'FFC_Security' ) ) {
            $security = new FFC_Security();
            if ( ! $security->validate_captcha( $_POST ) ) {
                // Se falhar, gera novos dados para atualizar o frontend
                return array(
                    'message'         => __( 'Incorrect math answer. Please try again.', 'ffc' ),
                    'refresh_captcha' => true,
                    'new_label'       => $security->get_math_question(),
                    'new_hash'        => $security->get_hash()
                );
            }
        }
        return false; // Sem erro
    }

    /**
     * Helper: Prepara o HTML final para o PDF
     */
    private function prepare_pdf_data( $form_id, $submission_data, $unique_id ) {
        // 1. Busca Configurações do Layout
        $form_config = get_post_meta( $form_id, '_ffc_form_config', true );
        $bg_image    = isset( $form_config['background_image'] ) ? $form_config['background_image'] : '';
        $html_layout = isset( $form_config['pdf_layout'] ) ? $form_config['pdf_layout'] : '';

        // Se não tiver layout salvo, tenta um fallback simples (opcional)
        if ( empty( $html_layout ) ) {
            $html_layout = '<div style="text-align:center; padding-top:100px;"><h1>Certificate</h1><p>Awarded to {nome}</p></div>';
        }

        // 2. Substituição de Variáveis ({nome}, {email}, etc)
        // Ordena chaves pelo tamanho (maior para menor) para evitar sub-replaces errados
        uksort( $submission_data, function($a, $b) {
            return strlen($b) - strlen($a);
        });

        foreach ( $submission_data as $key => $value ) {
            if ( is_string( $value ) ) {
                $html_layout = str_replace( '{' . $key . '}', $value, $html_layout );
            }
        }

        // 3. Substituições Especiais
        $html_layout = str_replace( '{unique_id}', $unique_id, $html_layout );
        
        // Data Formatada
        $date_format = get_option( 'date_format' );
        $html_layout = str_replace( '{submission_date}', date_i18n( $date_format ), $html_layout );
        $html_layout = str_replace( '{date}', date_i18n( $date_format ), $html_layout ); // Alias

        // 4. Geração de QR Code
        // Usamos uma API pública confiável (goqr.me ou qrserver) para gerar a imagem na hora
        // URL de verificação (ajuste conforme sua página de validação)
        // Supondo que exista uma página /verificar/ ou você use a home para testar
        $verify_url = home_url( '/?verify_code=' . $unique_id ); 
        
        // Tag de Imagem do QR Code
        $qr_img_url = 'https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=' . urlencode( $verify_url );
        $qr_html    = '<img src="' . $qr_img_url . '" class="ffc-qr-code" alt="QR Code" />';

        $html_layout = str_replace( '{qr_code}', $qr_html, $html_layout );

        return array(
            'final_html' => $html_layout,
            'bg_image'   => $bg_image,
            'form_title' => get_the_title( $form_id )
        );
    }
}