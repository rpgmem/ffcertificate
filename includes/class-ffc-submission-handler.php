<?php
/**
 * FFC_Submission_Handler
 * Gerencia o processamento, salvamento, edição e exportação das submissões.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FFC_Submission_Handler {
    
    protected $submission_table_name;
    
    public function __construct() {
        global $wpdb;
        $this->submission_table_name = $wpdb->prefix . 'ffc_submissions';

        // Hook para processamento em background (E-mail/Log)
        add_action( 'ffc_process_submission_hook', array( $this, 'async_process_submission' ), 10, 7 );
        
        // Configuração de SMTP Customizado
        add_action( 'phpmailer_init', array( $this, 'configure_custom_smtp' ) );
    }

    /**
     * Configura o PHPMailer para usar SMTP se definido nas configurações
     */
    public function configure_custom_smtp( $phpmailer ) {
        $settings = get_option( 'ffc_settings', array() );
        if ( isset($settings['smtp_mode']) && $settings['smtp_mode'] === 'custom' ) {
            $phpmailer->isSMTP();
            $phpmailer->Host       = isset($settings['smtp_host']) ? $settings['smtp_host'] : '';
            $phpmailer->SMTPAuth   = true;
            $phpmailer->Port       = isset($settings['smtp_port']) ? (int) $settings['smtp_port'] : 587;
            $phpmailer->Username   = isset($settings['smtp_user']) ? $settings['smtp_user'] : '';
            $phpmailer->Password   = isset($settings['smtp_pass']) ? $settings['smtp_pass'] : '';
            $phpmailer->SMTPSecure = isset($settings['smtp_secure']) ? $settings['smtp_secure'] : 'tls';
            
            if ( ! empty( $settings['smtp_from_email'] ) ) {
                $phpmailer->From     = $settings['smtp_from_email'];
                $phpmailer->FromName = isset($settings['smtp_from_name']) ? $settings['smtp_from_name'] : get_bloginfo( 'name' );
            }
        }
    }

    /**
     * Busca uma submissão pelo ID
     */
    public function get_submission( $id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->submission_table_name} WHERE id = %d", $id ), ARRAY_A );
    }

    /**
     * Processa a submissão inicial e salva no banco de dados
     */
    public function process_submission( $form_id, $form_title, &$submission_data, $user_email, $fields_config, $form_config ) {
        global $wpdb;

        // 1. Gera código de autenticação se não existir
        if ( empty( $submission_data['auth_code'] ) ) {
            $submission_data['auth_code'] = strtoupper( wp_generate_password( 12, false ) );
        }

        // 2. Limpeza e padronização de campos de identificação
        $keys_to_clean = array( 'auth_code', 'codigo', 'verification_code', 'cpf', 'cpf_rf', 'rg', 'ticket' );
        foreach ( $submission_data as $key => $value ) {
            if ( in_array( $key, $keys_to_clean ) ) {
                // Remove qualquer coisa que não seja letra ou número e coloca em maiúsculo
                $submission_data[$key] = strtoupper( preg_replace( '/[^a-zA-Z0-9]/', '', $value ) );
            }
        }

        // 3. Prepara dados para o JSON (Removendo e-mails duplicados para salvar espaço)
        $data_to_save = $submission_data;
        $email_keys = array( 'email', 'user_email', 'your-email', 'ffc_email' );
        foreach ( $email_keys as $key ) {
            if ( isset( $data_to_save[$key] ) ) unset( $data_to_save[$key] );
        }
        
        $inserted = $wpdb->insert(
            $this->submission_table_name,
            array(
                'form_id'         => $form_id,
                'submission_date' => current_time( 'mysql' ),
                'data'            => wp_json_encode( $data_to_save ), 
                'user_ip'         => $this->get_user_ip(),
                'email'           => $user_email,
                'status'          => 'publish' 
            ),
            array( '%d', '%s', '%s', '%s', '%s', '%s' )
        );
        
        if ( ! $inserted ) {
            return new WP_Error( 'db_error', __( 'Erro ao salvar submissão no banco de dados.', 'ffc' ) );
        }
        
        $submission_id = $wpdb->insert_id;
        
        // 4. Agenda o envio de e-mail assíncrono
        wp_schedule_single_event( time() + 2, 'ffc_process_submission_hook', array( $submission_id, $form_id, $form_title, $submission_data, $user_email, $fields_config, $form_config ) );

        return $submission_id;
    }

    /**
     * Processamento Assíncrono (E-mails)
     */
    public function async_process_submission( $submission_id, $form_id, $form_title, $submission_data, $user_email, $fields_config, $form_config ) {
        // Garante que o e-mail esteja nos dados para a geração do PDF
        if ( ! isset( $submission_data['email'] ) ) {
            $submission_data['email'] = $user_email;
        }
        
        // Gera o HTML final
        $pdf_content = $this->generate_pdf_html( $submission_data, $form_title, $form_config );
        
        // Envio para o Usuário
        if ( isset( $form_config['send_user_email'] ) && $form_config['send_user_email'] == 1 ) {
            $this->send_user_email( $user_email, $form_title, $pdf_content, $form_config );
        }

        // Notificação para o Admin
        $this->send_admin_notification( $form_title, $submission_data, $form_config );
    }

    /**
     * GERA O HTML DO CERTIFICADO
     */
    public function generate_pdf_html( $submission_data, $form_title, $form_config ) {
        $layout = isset( $form_config['pdf_layout'] ) ? $form_config['pdf_layout'] : '';
        
        if ( empty( $layout ) ) {
            $layout = '<div style="text-align:center; padding: 50px;">
                        <h1>' . esc_html( $form_title ) . '</h1>
                        <p>Certificamos que o portador dos dados abaixo concluiu o evento.</p>
                        <h2>{{name}}</h2>
                        <p>Autenticidade: {{auth_code}}</p>
                      </div>';
        }

        // Tags de Sistema (Datas e Títulos)
        $layout = str_replace( '{{validation_url}}', esc_url( site_url( '/validar' ) ), $layout );
        $layout = str_replace( '{{submission_date}}', date_i18n( get_option( 'date_format' ), current_time( 'timestamp' ) ), $layout );
        $layout = str_replace( '{{form_title}}', $form_title, $layout );

        // Se o e-mail foi removido do array para salvar no DB, adicionamos aqui para o PDF
        if ( !isset($submission_data['email']) && isset($submission_data['user_email']) ) {
             $submission_data['email'] = $submission_data['user_email'];
        }

        // Tags Dinâmicas do Formulário (Funciona para text, select, radio, hidden)
        foreach ( $submission_data as $key => $value ) {
            if ( is_array( $value ) ) {
                $value = implode( ', ', $value );
            }

            // Formatação de Documentos e Código de Autenticação
            if ( in_array( $key, array( 'cpf', 'cpf_rf', 'rg' ) ) ) {
                $value = $this->format_document( $value );
            }
            if ( $key === 'auth_code' ) {
                $value = $this->format_auth_code( $value );
            }
            
            $safe_value = wp_kses( $value, FFC_Utils::get_allowed_html_tags() );
            $layout = str_replace( '{{' . $key . '}}', $safe_value, $layout );
        }

        // Garante caminhos absolutos para imagens (evita quebra em e-mails)
        $site_url = untrailingslashit( get_home_url() );
        $layout = preg_replace('/(src|href|background)=["\']\/([^"\']+)["\']/i', '$1="' . $site_url . '/$2"', $layout);

        return $layout;
    }

    private function send_user_email( $to, $form_title, $html_content, $form_config ) {
        $subject = ! empty( $form_config['email_subject'] ) ? $form_config['email_subject'] : sprintf( __( 'Seu Certificado: %s', 'ffc' ), $form_title );
        
        // Prepara o corpo do e-mail mesclando o texto definido com o layout do certificado
        $body_text = isset( $form_config['email_body'] ) ? wpautop( $form_config['email_body'] ) : '';
        $body  = '<div style="font-family: sans-serif; line-height: 1.6; color: #333;">';
        $body .= $body_text;
        $body .= '<div style="margin-top:30px; border:1px solid #eee; border-radius: 8px; overflow: hidden;">';
        $body .= $html_content;
        $body .= '</div></div>'; 
        
        wp_mail( $to, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
    }

    private function send_admin_notification( $form_title, $data, $form_config ) {
        $admins = isset( $form_config['email_admin'] ) ? array_filter(array_map('trim', explode( ',', $form_config['email_admin'] ))) : array( get_option( 'admin_email' ) );
        
        $subject = sprintf( __( 'Nova Emissão: %s', 'ffc' ), $form_title );
        $body    = '<h3>' . __( 'Detalhes da Submissão:', 'ffc' ) . '</h3>';
        $body   .= '<table border="1" cellpadding="10" style="border-collapse:collapse; width:100%; font-family: sans-serif;">';
        
        foreach ( $data as $k => $v ) {
            $display_v = is_array($v) ? implode(', ', $v) : $v;

            if ( in_array( $k, array( 'cpf', 'cpf_rf', 'rg' ) ) ) { $display_v = $this->format_document( $display_v ); }
            if ( $k === 'auth_code' ) { $display_v = $this->format_auth_code( $display_v ); }

            $label = ucfirst( str_replace('_', ' ', $k) );
            $body .= '<tr><td style="background:#f9f9f9; width:30%;"><strong>' . esc_html( $label ) . '</strong></td><td>' . wp_kses( $display_v, FFC_Utils::get_allowed_html_tags() ) . '</td></tr>';
        }
        $body .= '</table>';

        foreach ( $admins as $email ) {
            if ( is_email( $email ) ) {
                wp_mail( $email, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
            }
        }
    }

    /* Helpers de Formatação */
    private function format_document( $value ) {
        $value = preg_replace( '/[^0-9]/', '', $value );
        $len = strlen( $value );
        if ( $len === 7 ) { // RF
            return substr( $value, 0, 3 ) . '.' . substr( $value, 3, 3 ) . '-' . substr( $value, 6, 1 );
        } 
        if ( $len === 11 ) { // CPF
            return substr( $value, 0, 3 ) . '.' . substr( $value, 3, 3 ) . '.' . substr( $value, 6, 3 ) . '-' . substr( $value, 9, 2 );
        }
        return $value;
    }

    private function format_auth_code( $value ) {
        $value = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $value));
        if ( strlen( $value ) === 12 ) {
            return substr( $value, 0, 4 ) . '-' . substr( $value, 4, 4 ) . '-' . substr( $value, 8, 4 );
        }
        return $value;
    }

    /* Gerenciamento de Status */
    public function trash_submission( $id ) { global $wpdb; return $wpdb->update($this->submission_table_name, array('status'=>'trash'), array('id'=>absint($id))); }
    public function restore_submission( $id ) { global $wpdb; return $wpdb->update($this->submission_table_name, array('status'=>'publish'), array('id'=>absint($id))); }
    public function delete_submission( $id ) { global $wpdb; return $wpdb->delete($this->submission_table_name, array('id'=>absint($id))); }

    /**
     * Exportação CSV robusta com suporte a campos dinâmicos
     */
    public function export_csv() {
        global $wpdb;
        $rows = $wpdb->get_results( "SELECT * FROM {$this->submission_table_name} WHERE status = 'publish' ORDER BY id DESC", ARRAY_A );
        if ( empty( $rows ) ) wp_die( 'Nenhuma submissão disponível para exportação.' );
        
        $filename = 'certificados-emitidos-' . date( 'Y-m-d' ) . '.csv';
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( "Content-Disposition: attachment; filename={$filename}" );
        
        $output = fopen( 'php://output', 'w' );
        fprintf( $output, chr(0xEF).chr(0xBB).chr(0xBF) ); // UTF-8 BOM para Excel
        
        // Identifica todas as chaves únicas presentes nos JSONs para criar as colunas do CSV
        $all_keys = array();
        foreach($rows as $r){ 
            $d = json_decode($r['data'], true); 
            if(is_array($d)) $all_keys = array_merge($all_keys, array_keys($d)); 
        }
        $dynamic_headers = array_unique($all_keys);
        
        // Cabeçalho fixo + dinâmico
        fputcsv($output, array_merge(array('ID_Sistema', 'Data_Emissao', 'Email_Principal'), $dynamic_headers));
        
        foreach($rows as $row){
            $d = json_decode($row['data'], true) ?: array();
            $line = array($row['id'], $row['submission_date'], $row['email']);
            foreach($dynamic_headers as $h) { 
                $val = isset($d[$h]) ? $d[$h] : ''; 
                $line[] = is_array($val) ? implode(' | ',$val) : $val; 
            }
            fputcsv($output, $line);
        }
        fclose($output); 
        exit;
    }

    private function get_user_ip() {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) return sanitize_text_field($_SERVER['HTTP_CLIENT_IP']);
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) return sanitize_text_field(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        return sanitize_text_field($_SERVER['REMOTE_ADDR']);
    }
}