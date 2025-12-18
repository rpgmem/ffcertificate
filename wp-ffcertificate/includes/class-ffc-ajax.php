<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * FFC_Ajax
 * Responsável por processar todas as requisições assíncronas (AJAX) do plugin.
 */
class FFC_Ajax {

    public function __construct() {
        // Registra os hooks para usuários logados (admin)
        add_action( 'wp_ajax_ffc_load_template', array( $this, 'load_html_template' ) );
        add_action( 'wp_ajax_ffc_generate_codes', array( $this, 'generate_random_codes' ) );
        add_action( 'wp_ajax_ffc_admin_get_pdf_data', array( $this, 'get_pdf_submission_data' ) );
        
        // Se precisar que algo funcione no frontend para visitantes, use também:
        // add_action( 'wp_ajax_nopriv_ffc_action_name', ... );
    }

    /**
     * 1. Carrega o conteúdo HTML de um template para o editor
     */
    public function load_html_template() {
        // Verifica o nonce de segurança vindo do admin.js
        check_ajax_referer( 'ffc_admin_ajax_nonce', 'nonce' );

        $filename = isset( $_POST['filename'] ) ? sanitize_text_field( $_POST['filename'] ) : '';

        if ( empty( $filename ) ) {
            wp_send_json_error( array( 'message' => __( 'No template selected.', 'ffc' ) ) );
        }

        // Tenta buscar na pasta de layouts primeiro
        $paths_to_check = array(
            FFC_PLUGIN_DIR . 'templates/layouts/' . $filename . '.html',
            FFC_PLUGIN_DIR . 'templates/' . $filename . '.html'
        );

        foreach ( $paths_to_check as $path ) {
            if ( file_exists( $path ) ) {
                $content = file_get_contents( $path );
                wp_send_json_success( $content ); // Retorna o HTML puro
            }
        }

        wp_send_json_error( array( 'message' => __( 'Template file not found on server.', 'ffc' ) ) );
    }

    /**
     * 2. Gera lista de códigos aleatórios (Tickets)
     */
    public function generate_random_codes() {
        check_ajax_referer( 'ffc_admin_ajax_nonce', 'nonce' );

        $qty = isset( $_POST['qty'] ) ? absint( $_POST['qty'] ) : 10;
        $prefix = 'TICKET-';
        
        $codes = array();
        for ( $i = 0; $i < $qty; $i++ ) {
            // Gera algo como TICKET-7F3A9
            $random_part = strtoupper( substr( md5( uniqid( wp_rand(), true ) ), 0, 5 ) );
            $codes[] = $prefix . $random_part;
        }

        // Retorna string separada por quebras de linha para o textarea
        wp_send_json_success( array( 
            'codes' => implode( "\n", $codes ),
            'count' => count( $codes )
        ));
    }

    /**
     * 3. Busca os dados de uma submissão para gerar o PDF (Preview e Download)
     */
    public function get_pdf_submission_data() {
        check_ajax_referer( 'ffc_admin_ajax_nonce', 'nonce' );

        $submission_id = isset( $_POST['submission_id'] ) ? absint( $_POST['submission_id'] ) : 0;
        
        if ( ! $submission_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid Submission ID.', 'ffc' ) ) );
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'ffc_submissions';
        
        // Busca a submissão no banco
        $submission = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_name} WHERE id = %d", $submission_id ) );

        if ( ! $submission ) {
            wp_send_json_error( array( 'message' => __( 'Submission not found.', 'ffc' ) ) );
        }

        // Decodifica o JSON dos dados salvos
        $form_data = json_decode( $submission->data, true );
        if ( ! is_array( $form_data ) ) {
            // Tenta limpar barras se o JSON estiver "sujo"
            $form_data = json_decode( stripslashes( $submission->data ), true );
        }

        // Formata data de envio
        $date_format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
        $formatted_date = date_i18n( $date_format, strtotime( $submission->submission_date ) );
        
        // Garante que a data exista no array
        if ( is_array( $form_data ) ) {
            $form_data['submission_date'] = $formatted_date;
        }

        // Busca configurações do formulário pai (Layout PDF e Imagem Fundo)
        $form_config = get_post_meta( $submission->form_id, '_ffc_form_config', true );
        
        $response = array(
            'submission' => $form_data,
            'form_title' => get_the_title( $submission->form_id ),
            'template'   => '',
            'bg_image'   => ''
        );

        if ( is_array( $form_config ) ) {
            if ( ! empty( $form_config['pdf_layout'] ) ) {
                $response['template'] = $form_config['pdf_layout'];
            }
            if ( ! empty( $form_config['background_image'] ) ) {
                $response['bg_image'] = $form_config['background_image'];
            }
        }

        wp_send_json_success( $response );
    }
}
?>