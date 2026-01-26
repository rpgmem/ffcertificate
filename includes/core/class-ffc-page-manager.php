<?php
declare(strict_types=1);

/**
 * PageManager
 *
 * Manages plugin pages (verification page, etc)
 *
 * @version 3.3.0 - Added strict types and type hints
 * @version 3.2.0 - Migrated to namespace (Phase 2)
 * @since 2.9.16
 */

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PageManager {
    
    /**
     * Get verification page ID
     * 
     * @return int Page ID or 0 if not exists
     */
    public static function get_verification_page_id(): int {
        return absint( get_option( 'ffc_verification_page_id', 0 ) );
    }
    
    /**
     * Get verification page URL
     * 
     * @return string Page URL
     */
    public static function get_verification_page_url(): string {
        $page_id = self::get_verification_page_id();
        
        if ( $page_id ) {
            $url = get_permalink( $page_id );
            if ( $url ) {
                return $url;
            }
        }
        
        // Fallback to /valid
        return home_url( '/valid/' );
    }
    
    /**
     * Check if verification page exists and is published
     * 
     * @return bool
     */
    public static function verification_page_exists(): bool {
        $page_id = self::get_verification_page_id();
        
        if ( ! $page_id ) {
            return false;
        }
        
        $page = get_post( $page_id );
        
        if ( ! $page || $page->post_status !== 'publish' || $page->post_type !== 'page' ) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Create or recreate verification page
     * 
     * Safe to call multiple times - won't create duplicates
     * 
     * @return int|WP_Error Page ID or error
     */
    public static function ensure_verification_page(): int {
        // Check if page exists and is OK
        if ( self::verification_page_exists() ) {
            return self::get_verification_page_id();
        }
        
        // Try to find existing page by slug
        $existing = get_page_by_path( 'valid' );
        
        if ( $existing ) {
            // Update stored ID
            update_option( 'ffc_verification_page_id', $existing->ID );
            
            // Ensure has correct shortcode
            if ( has_shortcode( $existing->post_content, 'ffc_verification' ) ) {
                return $existing->ID;
            }
            
            // Update content to include shortcode
            wp_update_post( array(
                'ID' => $existing->ID,
                'post_content' => '[ffc_verification]'
            ) );
            
            return $existing->ID;
        }
        
        // Create new page
        $page_id = wp_insert_post( array(
            'post_title'     => __( 'Certificate Verification', 'ffc' ),
            'post_content'   => '[ffc_verification]',
            'post_status'    => 'publish',
            'post_type'      => 'page',
            'post_name'      => 'valid',
            'post_author'    => 1,
            'comment_status' => 'closed',
            'ping_status'    => 'closed'
        ) );
        
        if ( is_wp_error( $page_id ) ) {
            return $page_id;
        }
        
        // Store ID
        update_option( 'ffc_verification_page_id', $page_id );
        
        // Mark as managed
        update_post_meta( $page_id, '_ffc_managed_page', '1' );
        
        return $page_id;
    }
    
    /**
     * Add admin notice if verification page is missing
     * 
     * Call this in admin_notices hook
     */
    public static function maybe_show_missing_page_notice(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        
        if ( self::verification_page_exists() ) {
            return;
        }
        
        $fix_url = wp_nonce_url(
            add_query_arg( 'ffc_recreate_page', '1' ),
            'ffc_recreate_page'
        );
        
        ?>
        <div class="notice notice-warning is-dismissible">
            <p>
                <strong><?php esc_html_e( 'Free Form Certificate:', 'ffc' ); ?></strong>
                <?php esc_html_e( 'The verification page is missing. Magic links and PDF downloads won\'t work!', 'ffc' ); ?>
            </p>
            <p>
                <a href="<?php echo esc_url( $fix_url ); ?>" class="button button-primary">
                    <?php esc_html_e( 'Create Verification Page', 'ffc' ); ?>
                </a>
            </p>
        </div>
        <?php
    }
    
    /**
     * Handle page recreation request
     * 
     * Call this in admin_init hook
     */
    public static function handle_page_recreation_request(): void {
        if ( ! isset( $_GET['ffc_recreate_page'] ) ) {
            return;
        }
        
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        
        check_admin_referer( 'ffc_recreate_page' );
        
        $result = self::ensure_verification_page();
        
        if ( is_wp_error( $result ) ) {
            wp_die( $result->get_error_message() );
        }
        
        wp_safe_redirect( add_query_arg(
            'ffc_page_created',
            '1',
            admin_url( 'edit.php?post_type=ffc_form' )
        ) );
        exit;
    }
}