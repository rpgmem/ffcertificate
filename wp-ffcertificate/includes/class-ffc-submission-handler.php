<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FFC_Submission_Handler {
    
    protected $submission_table_name;
    
    public function __construct() {
        global $wpdb;
        $this->submission_table_name = $wpdb->prefix . 'ffc_submissions';

        // Hook para injetar SMTP personalizado (se configurado nas opções)
        add_action( 'phpmailer_init', array( $this, 'configure_custom_smtp' ) );
    }

    /**
     * Configura o PHPMailer com as definições do plugin, se o modo SMTP estiver ativo.
     */
    public function configure_custom_smtp( $phpmailer ) {
        $settings = get_option( 'ffc_settings', array() );
        
        // Verifica se o modo Custom está ativo
        if ( isset($settings['smtp_mode']) && $settings['smtp_mode'] === 'custom' ) {
            $phpmailer->isSMTP();
            $phpmailer->Host       = isset($settings['smtp_host']) ? $settings['smtp_host'] : '';
            $phpmailer->SMTPAuth   = true;
            $phpmailer->Port       = isset($settings['smtp_port']) ? $settings['smtp_port'] : 587;
            $phpmailer->Username   = isset($settings['smtp_user']) ? $settings['smtp_user'] : '';
            $phpmailer->Password   = isset($settings['smtp_pass']) ? $settings['smtp_pass'] : '';
            $phpmailer->SMTPSecure = isset($settings['smtp_secure']) ? $settings['smtp_secure'] : 'tls';
            
            // Configura remetente
            $phpmailer->From       = isset($settings['smtp_from_email']) ? $settings['smtp_from_email'] : $settings['smtp_user'];
            $phpmailer->FromName   = isset($settings['smtp_from_name']) ? $settings['smtp_from_name'] : get_bloginfo( 'name' );
        }
    }

    /**
     * Processa a submissão: Sanitiza, Salva no DB e Agenda tarefas.
     * * @param int    $form_id
     * @param string $form_title
     * @param array  $submission_data  PASSADO POR REFERÊNCIA (&) para atualizar o auth_code no frontend
     * @param string $user_email
     * @param array  $fields_config
     * @param array  $form_config
     */
    public function process_submission( $form_id, $form_title, &$submission_data, $user_email, $fields_config, $form_config ) {
        global $wpdb;

        // 1. GERAÇÃO DO AUTH_CODE (Se não vier do formulário)
        // Gera um código único de 12 caracteres (ex: A1B2C3D4E5F6) e adiciona ao array
        if ( empty( $submission_data['auth_code'] ) ) {
            $submission_data['auth_code'] = strtoupper( substr( md5( uniqid( rand(), true ) ), 0, 12 ) );
        }

        // 2. LIMPEZA DE DADOS (Sanitização de Máscaras)
        // Garante que CPF, RG e Códigos sejam salvos apenas com letras e números
        if ( is_array( $submission_data ) ) {
            foreach ( $submission_data as $key => $value ) {
                // Lista de campos que devem ser limpos
                if ( in_array( $key, array( 'auth_code', 'codigo', 'verification_code', 'cpf', 'cpf_rf', 'rg' ) ) ) {
                    $submission_data[$key] = preg_replace( '/[^a-zA-Z0-9]/', '', $value );
                }
            }
        }
        
        // 3. SALVAR NO BANCO DE DADOS
        $inserted = $wpdb->insert(
            $this->submission_table_name,
            array(
                'form_id'         => $form_id,
                'submission_date' => current_time( 'mysql' ),
                'data'            => wp_json_encode( $submission_data ), // Salva o JSON já sanitizado e com auth_code
                'user_ip'         => $_SERVER['REMOTE_ADDR'],
                'email'           => $user_email
            ),
            array( '%d', '%s', '%s', '%s', '%s' )
        );
        
        if ( ! $inserted ) {
            return new WP_Error( 'db_error', __( 'Error saving to database.', 'ffc' ) );
        }
        
        $submission_id = $wpdb->insert_id;

        // 4. Agendar processamento assíncrono (envio de emails)
        // Pequeno delay para evitar travar a requisição do usuário
        wp_schedule_single_event( 
            time() + 2, 
            'ffc_process_submission_hook', 
            array( $submission_id, $form_id, $form_title, $submission_data, $user_email, $fields_config, $form_config ) 
        );

        return $submission_id;
    }

    /**
     * Tarefa Assíncrona: Gera PDF e envia e-mails
     */
    public function async_process_submission( $submission_id, $form_id, $form_title, $submission_data, $user_email, $fields_config, $form_config ) {
        $pdf_content = $this->generate_pdf_html( $submission_data, $form_title, $form_config );
        
        // Verifica se a opção de enviar e-mail para o usuário está ativa
        if ( isset( $form_config['send_user_email'] ) && $form_config['send_user_email'] == 1 ) {
            $this->send_user_email( $user_email, $form_title, $pdf_content, $form_config );
        }

        // Envia notificação para o administrador (sempre)
        $this->send_admin_notification( $form_title, $submission_data, $form_config );
    }

    /**
     * Gera o HTML do Certificado substituindo os placeholders
     */
    public function generate_pdf_html( $submission_data, $form_title, $form_config ) {
        $layout = isset( $form_config['pdf_layout'] ) ? $form_config['pdf_layout'] : '';
        if ( empty( $layout ) ) {
            $layout = '<h1>Certificate: ' . esc_html( $form_title ) . '</h1><p>{{submission_date}}</p>';
        }

        // Substitui data
        $layout = str_replace( '{{submission_date}}', date_i18n( get_option( 'date_format' ), current_time( 'timestamp' ) ), $layout );

        // Substitui campos do formulário (incluindo auth_code gerado)
        foreach ( $submission_data as $key => $value ) {
            $layout = str_replace( '{{' . $key . '}}', esc_html( $value ), $layout );
        }

        return $layout;
    }

    private function send_user_email( $to, $form_title, $html_content, $form_config ) {
        $subject = isset( $form_config['email_subject'] ) && ! empty( $form_config['email_subject'] ) ? $form_config['email_subject'] : "Certificate: $form_title";
        $body    = isset( $form_config['email_body'] ) ? wpautop( $form_config['email_body'] ) : '';
        
        $body .= '<hr>';
        $body .= $html_content; 

        $headers = array( 'Content-Type: text/html; charset=UTF-8' );
        wp_mail( $to, $subject, $body, $headers );
    }

    private function send_admin_notification( $form_title, $data, $form_config ) {
        $admins = isset( $form_config['email_admin'] ) ? explode( ',', $form_config['email_admin'] ) : array();
        
        // Se não houver e-mail configurado, usa o do admin do WP
        if ( empty( array_filter($admins) ) ) {
             $admins[] = get_option( 'admin_email' );
        }
        
        $subject = "New Submission: $form_title";
        $body    = "New submission received:<br><ul>";
        foreach ( $data as $k => $v ) {
            $body .= "<li><strong>" . esc_html( $k ) . ":</strong> " . esc_html( $v ) . "</li>";
        }
        $body .= "</ul>";

        $headers = array( 'Content-Type: text/html; charset=UTF-8' );
        foreach ( $admins as $email ) {
            if ( is_email( trim( $email ) ) ) {
                wp_mail( trim( $email ), $subject, $body, $headers );
            }
        }
    }

    public function delete_submission( $id ) {
        global $wpdb;
        $wpdb->delete( $this->submission_table_name, array( 'id' => $id ), array( '%d' ) );
    }

    public function delete_all_submissions() {
        global $wpdb;
        $wpdb->query( "TRUNCATE TABLE {$this->submission_table_name}" );
    }

    /**
     * Exporta dados para CSV
     */
    public function export_csv() {
        global $wpdb;
        $rows = $wpdb->get_results( "SELECT * FROM {$this->submission_table_name}", ARRAY_A );

        if ( empty( $rows ) ) {
            wp_die( __( 'No data to export.', 'ffc' ) );
        }

        $filename = 'submissions-' . date( 'Y-m-d' ) . '.csv';
        
        // Headers corretos para forçar download
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( "Content-Disposition: attachment; filename={$filename}" );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        $output = fopen( 'php://output', 'w' );
        
        // Coleta todos os campos dinâmicos (JSON) de todas as linhas para criar o cabeçalho
        $dynamic_headers = array();
        foreach ( $rows as $row ) {
            $data = json_decode( $row['data'], true );
            if ( is_array( $data ) ) {
                $dynamic_headers = array_merge( $dynamic_headers, array_keys( $data ) );
            }
        }
        $dynamic_headers = array_unique( $dynamic_headers );
        
        $headers = array_merge( array( 'ID', 'Form ID', 'Date', 'IP', 'Email' ), $dynamic_headers );
        
        // Adiciona BOM para compatibilidade com Excel (UTF-8)
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        fputcsv( $output, $headers );

        foreach ( $rows as $row ) {
            $data       = json_decode( $row['data'], true );
            $csv_row    = array( $row['id'], $row['form_id'], $row['submission_date'], $row['user_ip'], $row['email'] );
            
            foreach ( $dynamic_headers as $header ) {
                $csv_row[] = isset( $data[ $header ] ) ? $data[ $header ] : '';
            }
            fputcsv( $output, $csv_row );
        }
        
        fclose( $output );
        exit;
    }

    /**
     * Limpeza automática de dados antigos
     */
    public function run_data_cleanup() {
        $settings = get_option( 'ffc_settings', array() );
        $cleanup_days = isset( $settings['cleanup_days'] ) ? intval( $settings['cleanup_days'] ) : 30;

        if ( $cleanup_days <= 0 ) {
            return;
        }

        global $wpdb;
        $wpdb->query( $wpdb->prepare( 
            "DELETE FROM {$this->submission_table_name} WHERE submission_date < DATE_SUB(NOW(), INTERVAL %d DAY)", 
            $cleanup_days 
        ) );
    }
}