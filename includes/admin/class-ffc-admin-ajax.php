<?php
declare(strict_types=1);

/**
 * AdminAjax Handlers
 * Handles AJAX requests from admin interface
 *
 * @version 3.3.0 - Added strict types and type hints
 * @version 3.2.0 - Migrated to namespace (Phase 2)
 */

namespace FreeFormCertificate\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AdminAjax {

    public function __construct() {
        // Register AJAX handlers
        add_action( 'wp_ajax_ffc_load_template', array( $this, 'load_template' ) );
        add_action( 'wp_ajax_ffc_generate_tickets', array( $this, 'generate_tickets' ) );
    }

    /**
     * Load template HTML
     */
    public function load_template(): void {
        // Verify nonce - try different nonce names
        $nonce_verified = false;

        if ( isset( $_POST['nonce'] ) ) {
            // Try ffc_form_nonce first
            if ( wp_verify_nonce( $_POST['nonce'], 'ffc_form_nonce' ) ) {
                $nonce_verified = true;
            }
            // Try ffc_admin_nonce
            elseif ( wp_verify_nonce( $_POST['nonce'], 'ffc_admin_nonce' ) ) {
                $nonce_verified = true;
            }
        }

        if ( ! $nonce_verified ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed. Please reload the page.', 'ffc' ) ) );
        }

        // Check permissions
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ffc' ) ) );
        }

        $template_id = isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : 0;

        if ( ! $template_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid template ID.', 'ffc' ) ) );
        }

        // Get template post
        $template = get_post( $template_id );

        if ( ! $template || $template->post_type !== 'ffc_template' ) {
            wp_send_json_error( array( 'message' => __( 'Template not found.', 'ffc' ) ) );
        }

        // Get template HTML from post meta
        $template_html = get_post_meta( $template_id, '_ffc_template_html', true );

        if ( empty( $template_html ) ) {
            // Try getting from post content as fallback
            $template_html = $template->post_content;
        }

        wp_send_json_success( array(
            'html' => $template_html,
            'template_name' => $template->post_title
        ) );
    }

    /**
     * Generate tickets/codes
     */
    public function generate_tickets(): void {
        // Verify nonce - try different nonce names
        $nonce_verified = false;

        if ( isset( $_POST['nonce'] ) ) {
            // Try ffc_form_nonce first
            if ( wp_verify_nonce( $_POST['nonce'], 'ffc_form_nonce' ) ) {
                $nonce_verified = true;
            }
            // Try ffc_admin_nonce
            elseif ( wp_verify_nonce( $_POST['nonce'], 'ffc_admin_nonce' ) ) {
                $nonce_verified = true;
            }
        }

        if ( ! $nonce_verified ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed. Please reload the page.', 'ffc' ) ) );
        }

        // Check permissions
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ffc' ) ) );
        }

        $quantity = isset( $_POST['quantity'] ) ? absint( $_POST['quantity'] ) : 0;
        $form_id = isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0;

        if ( $quantity < 1 || $quantity > 1000 ) {
            wp_send_json_error( array( 'message' => __( 'Quantity must be between 1 and 1000.', 'ffc' ) ) );
        }

        if ( ! $form_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid form ID.', 'ffc' ) ) );
        }

        // Generate unique codes
        $codes = array();
        $existing_codes = $this->get_existing_codes( $form_id );

        for ( $i = 0; $i < $quantity; $i++ ) {
            $attempts = 0;
            do {
                $code = $this->generate_unique_code();
                $attempts++;

                // Prevent infinite loop
                if ( $attempts > 100 ) {
                    wp_send_json_error( array( 'message' => __( 'Error generating unique codes. Please try a smaller quantity.', 'ffc' ) ) );
                }
            } while ( in_array( $code, $existing_codes ) || in_array( $code, $codes ) );

            $codes[] = $code;
        }

        wp_send_json_success( array(
            'codes' => implode( "\n", $codes ),
            'quantity' => $quantity
        ) );
    }

    /**
     * Get existing codes for a form
     */
    private function get_existing_codes( int $form_id ): array {
        $form_config = get_post_meta( $form_id, '_ffc_form_config', true );

        if ( ! is_array( $form_config ) || empty( $form_config['generated_codes_list'] ) ) {
            return array();
        }

        $codes_raw = $form_config['generated_codes_list'];
        $codes = array_filter( array_map( 'trim', explode( "\n", $codes_raw ) ) );

        return $codes;
    }

    /**
     * Generate a unique code
     * Format: ABC-DEF-123 (3 letters - 3 letters - 3 numbers)
     */
    private function generate_unique_code(): string {
        $part1 = $this->random_letters( 3 );
        $part2 = $this->random_letters( 3 );
        $part3 = $this->random_numbers( 3 );

        return strtoupper( $part1 . '-' . $part2 . '-' . $part3 );
    }

    /**
     * Generate random letters
     */
    private function random_letters( int $length ): string {
        $letters = 'ABCDEFGHJKLMNPQRSTUVWXYZ'; // Removed I and O to avoid confusion
        $result = '';

        for ( $i = 0; $i < $length; $i++ ) {
            $result .= $letters[ rand( 0, strlen( $letters ) - 1 ) ];
        }

        return $result;
    }

    /**
     * Generate random numbers
     */
    private function random_numbers( int $length ): string {
        $result = '';

        for ( $i = 0; $i < $length; $i++ ) {
            $result .= rand( 0, 9 );
        }

        return $result;
    }
}

// Initialize
new \FFC_Admin_Ajax();
