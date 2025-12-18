<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FFC_Deactivator {

    public static function uninstall_cleanup() {
        if ( ! current_user_can( 'activate_plugins' ) ) {
            return;
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'ffc_submissions';
        $wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );
        
        delete_option( 'ffc_db_version' );
        delete_option( 'ffc_settings' );
        
        wp_clear_scheduled_hook( 'ffc_daily_cleanup_hook' );
        wp_clear_scheduled_hook( 'ffc_process_submission_hook' );
        
        $args = array(
            'post_type'      => 'ffc_form',
            'posts_per_page' => -1,
            'post_status'    => array( 'publish', 'pending', 'draft', 'auto-draft', 'future', 'private', 'inherit', 'trash' ),
            'fields'         => 'ids'
        );
        
        $forms = get_posts( $args );
        
        foreach ( $forms as $form_id ) {
            wp_delete_post( $form_id, true );
        }
    }
}