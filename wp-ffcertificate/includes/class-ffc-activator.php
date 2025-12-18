<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FFC_Activator {

    public static function activate() {
        global $wpdb;
        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        
        $db_version            = '1.1';
        $submission_table_name = $wpdb->prefix . 'ffc_submissions';

        $sql_submissions = "CREATE TABLE {$submission_table_name} (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            form_id mediumint(9) NOT NULL,
            submission_date datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            data longtext NOT NULL,
            user_ip varchar(100) NOT NULL,
            email varchar(255) NOT NULL, 
            UNIQUE KEY id (id),
            KEY form_id (form_id)
        ) {$wpdb->get_charset_collate()};";

        dbDelta( $sql_submissions );

        update_option( 'ffc_db_version', $db_version );
        
        if ( ! get_option( 'ffc_settings' ) ) {
            add_option( 'ffc_settings', array( 'cleanup_days' => 30, 'pdf_default_layout' => '' ) );
        }
    }
}