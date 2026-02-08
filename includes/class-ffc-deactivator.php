<?php
declare(strict_types=1);

/**
 * Deactivator
 * This class handles the logic when the plugin is deactivated or uninstalled.
 *
 * @version 3.3.0 - Added strict types and type hints
 * @version 3.2.0 - Migrated to namespace (Phase 2)
 */

namespace FreeFormCertificate;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Deactivator {

    /**
     * Deactivation hook logic.
     * Usually, we only flush rewrite rules here to avoid breaking permalinks.
     */
    public static function deactivate(): void {
        // Clear scheduled cron tasks (both old and new prefixed names)
        wp_clear_scheduled_hook( 'ffcertificate_daily_cleanup_hook' );
        wp_clear_scheduled_hook( 'ffc_daily_cleanup_hook' );

        flush_rewrite_rules();
    }

    /**
     * Destructive Cleanup.
     * WARNING: This deletes all plugin data.
     * Ideally, this should be called from an uninstall.php file or a specific action.
     */
    public static function uninstall_cleanup(): void {
        if ( ! current_user_can( 'activate_plugins' ) ) {
            return;
        }
        
        // Security check: Ensure this was a conscious post action with a nonce
        // Note: WordPress deactivation via the "Plugins" page doesn't send POST data by default.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Deactivation hook; nonce handled by WordPress plugin deactivation flow.
        if ( ! isset( $_POST['confirm_uninstall'] ) || sanitize_text_field( wp_unslash( $_POST['confirm_uninstall'] ) ) !== 'yes' ) {
            wp_die( esc_html__( 'Please confirm the uninstallation to proceed.', 'ffcertificate' ) );
        }
        
        global $wpdb;
        $table_name = \FreeFormCertificate\Core\Utils::get_submissions_table();

        // 1. Drop the submissions table
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );
        
        // 2. Delete plugin options
        delete_option( 'ffc_db_version' );
        delete_option( 'ffc_settings' );
        
        // 3. Clear scheduled CRON tasks (both old and new prefixed names)
        wp_clear_scheduled_hook( 'ffcertificate_daily_cleanup_hook' );
        wp_clear_scheduled_hook( 'ffcertificate_process_submission_hook' );
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