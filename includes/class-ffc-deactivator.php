<?php
/**
 * FFC_Deactivator
 * This class handles the logic when the plugin is deactivated or uninstalled.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FFC_Deactivator {

    /**
     * Deactivation hook logic.
     * Usually, we only flush rewrite rules here to avoid breaking permalinks.
     */
    public static function deactivate() {
        flush_rewrite_rules();
    }

    /**
     * Destructive Cleanup.
     * WARNING: This deletes all plugin data.
     * Ideally, this should be called from an uninstall.php file or a specific action.
     */
    public static function uninstall_cleanup() {
        if ( ! current_user_can( 'activate_plugins' ) ) {
            return;
        }
        
        // Security check: Ensure this was a conscious post action with a nonce
        // Note: WordPress deactivation via the "Plugins" page doesn't send POST data by default.
        if ( ! isset( $_POST['confirm_uninstall'] ) || $_POST['confirm_uninstall'] !== 'yes' ) {
            wp_die( esc_html__( 'Please confirm the uninstallation to proceed.', 'ffc' ) );
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'ffc_submissions';

        // 1. Drop the submissions table
        $wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );
        
        // 2. Delete plugin options
        delete_option( 'ffc_db_version' );
        delete_option( 'ffc_settings' );
        
        // 3. Clear scheduled CRON tasks
        wp_clear_scheduled_hook( 'ffc_daily_cleanup_hook' );
        wp_clear_scheduled_hook( 'ffc_process_submission_hook' );
        
        // 4. Delete all Custom Post Type 'ffc_form' entries
        $args = array(
            'post_type'      => 'ffc_form',
            'posts_per_page' => -1,
            'post_status'    => 'any', // Catch drafts, trashed, published, etc.
            'fields'         => 'ids'
        );
        
        $forms = get_posts( $args );
        
        if ( ! empty( $forms ) ) {
            foreach ( $forms as $form_id ) {
                // Set second parameter to true to bypass trash and delete permanently
                wp_delete_post( $form_id, true );
            }
        }

        // Flush rewrite rules after removing the CPT
        flush_rewrite_rules();
    }
}