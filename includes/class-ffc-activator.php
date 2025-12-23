<?php
/**
 * FFC_Activator
 * Gerencia a instalação do plugin: Tabelas, Páginas, Formulários Iniciais e Configurações.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FFC_Activator {

    public static function activate() {
        // 1. Criação das Tabelas
        self::create_tables();

        // 2. Criação da Página de Validação
        self::create_validation_page();

        // 3. Criação do Formulário Padrão
        self::create_default_form();

        // 4. Configurações Iniciais
        self::set_default_options();

        // 5. Atualização de Permalinks (Garante que o /valid funcione na hora)
        flush_rewrite_rules();
    }

    /**
     * Gerencia a criação da tabela de submissões
     */
    private static function create_tables() {
        global $wpdb;
        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        
        $table_name = $wpdb->prefix . 'ffc_submissions';

        $sql_submissions = "CREATE TABLE {$table_name} (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            form_id mediumint(9) NOT NULL,
            submission_date datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            data longtext NOT NULL,
            user_ip varchar(100) NOT NULL,
            email varchar(255) NOT NULL,
            status varchar(20) DEFAULT 'publish' NOT NULL,
            PRIMARY KEY  (id),
            KEY form_id (form_id),
            KEY email (email),
            KEY submission_date (submission_date),
            KEY status (status)
        ) {$wpdb->get_charset_collate()};";

        dbDelta( $sql_submissions );
        
        // Verificação manual da coluna status (segurança extra)
        $column = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'status'", 
            DB_NAME, $table_name
        ));

        if ( empty( $column ) ) {
            $wpdb->query("ALTER TABLE $table_name ADD status varchar(20) DEFAULT 'publish' NOT NULL");
        }

        update_option( 'ffc_db_version', '1.2' );
    }

    /**
     * Cria a página /valid com o shortcode necessário
     */
    private static function create_validation_page() {
        $slug = 'valid';
        $shortcode = '[ffc_verification]';
        
        $page_check = get_page_by_path($slug);

        if ( ! isset( $page_check->ID ) ) {
            // Se a página não existe, cria do zero
            wp_insert_post( array(
                'post_title'    => 'Validação de Certificado',
                'post_content'  => $shortcode,
                'post_status'   => 'publish',
                'post_type'     => 'page',
                'post_name'     => $slug
            ) );
        } else {
            // Se a página existe, garante que o shortcode esteja lá
            if ( strpos( $page_check->post_content, $shortcode ) === false ) {
                $page_check->post_content .= "\n" . $shortcode;
                wp_update_post( $page_check );
            }
        }
    }

    /**
     * Cria um formulário inicial para o usuário não começar do zero
     */
    private static function create_default_form() {
        $forms_query = new WP_Query( array( 
            'post_type'      => 'ffc_form', 
            'posts_per_page' => 1,
            'post_status'    => 'any' 
        ) );

        if ( ! $forms_query->have_posts() ) {
            $form_id = wp_insert_post( array(
                'post_title'   => 'Certificado de Exemplo',
                'post_status'  => 'publish',
                'post_type'    => 'ffc_form',
                'post_content' => 'Este é um formulário criado automaticamente pelo plugin.'
            ) );

            if ( $form_id ) {
                // Layout padrão com as variáveis dinâmicas
                $layout = '
                <div style="border:10px solid #2c3e50; padding:40px; text-align:center; font-family: Arial, sans-serif;">
                    <h1 style="color:#2c3e50; font-size:42px;">CERTIFICADO</h1>
                    <p style="font-size:20px;">Este certificado confirma que</p>
                    <h2 style="font-size:32px; color:#e67e22;">{{nome}}</h2>
                    <p style="font-size:18px;">concluiu o processo com sucesso em {{submission_date}}.</p>
                    <div style="margin-top:60px; padding-top:20px; border-top:1px solid #eee;">
                        <p style="margin:0;">Código de Autenticidade: <strong>{{auth_code}}</strong></p>
                        <p style="font-size:12px; color:#7f8c8d;">Valide este documento em: {{validation_url}}</p>
                    </div>
                </div>';

                update_post_meta( $form_id, 'ffc_form_config', array(
                    'pdf_layout'      => $layout,
                    'email_subject'   => 'Seu Certificado de Conclusão',
                    'send_user_email' => 1
                ) );
            }
        }
    }

    /**
     * Define as configurações globais iniciais se não existirem
     */
    private static function set_default_options() {
        $settings = get_option( 'ffc_settings' );
        
        if ( ! $settings ) {
            update_option( 'ffc_settings', array( 
                'smtp_mode'    => 'wp', // WP Default por padrão
                'cleanup_days' => 30 
            ) );
        }
    }
}