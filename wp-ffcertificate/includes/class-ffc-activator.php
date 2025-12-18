<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FFC_Activator {

    /**
     * Executado na ativação do plugin.
     */
    public static function activate() {
        self::create_tables();
        
        // Configurações padrão se não existirem
        if ( ! get_option( 'ffc_settings' ) ) {
            add_option( 'ffc_settings', array( 'cleanup_days' => 30, 'pdf_default_layout' => '' ) );
        }

        // Limpa as regras de rewrite para garantir que o Custom Post Type (ffc_form) funcione
        flush_rewrite_rules();
    }

    /**
     * Cria ou atualiza a tabela do banco de dados.
     */
    private static function create_tables() {
        global $wpdb;
        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

        $submission_table_name = $wpdb->prefix . 'ffc_submissions';
        $charset_collate       = $wpdb->get_charset_collate();
        $db_version            = '1.2'; // Versão incrementada devido à nova coluna

        // SQL Estrito para o dbDelta
        // 1. Adicionada coluna 'status' para suportar Trash/Restore
        // 2. Removidos comentários // que quebram o SQL
        // 3. Ajustada PRIMARY KEY
        
        $sql_submissions = "CREATE TABLE $submission_table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            form_id mediumint(9) NOT NULL,
            submission_date datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            data longtext NOT NULL,
            user_ip varchar(100) DEFAULT '' NOT NULL,
            email varchar(255) NOT NULL,
            status varchar(20) DEFAULT 'publish' NOT NULL,
            PRIMARY KEY  (id),
            KEY form_id (form_id),
            KEY email (email),
            KEY status (status),
            KEY submission_date (submission_date)
        ) $charset_collate;";

        dbDelta( $sql_submissions );

        update_option( 'ffc_db_version', $db_version );
    }
}