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
        add_action( 'wp_ajax_ffc_search_user', array( $this, 'search_user' ) );
    }

    /**
     * Load template HTML
     */
    public function load_template(): void {
        // Verify nonce - try different nonce names
        $nonce_verified = false;

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- isset() existence check only; nonce verified immediately inside.
        if ( isset( $_POST['nonce'] ) ) {
            $nonce_value = sanitize_text_field( wp_unslash( $_POST['nonce'] ) );
            // Try ffc_form_nonce first
            if ( wp_verify_nonce( $nonce_value, 'ffc_form_nonce' ) ) {
                $nonce_verified = true;
            }
            // Try ffc_admin_nonce
            elseif ( wp_verify_nonce( $nonce_value, 'ffc_admin_nonce' ) ) {
                $nonce_verified = true;
            }
        }

        if ( ! $nonce_verified ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed. Please reload the page.', 'ffcertificate' ) ) );
        }

        // Check permissions
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ffcertificate' ) ) );
        }

        $template_id = isset( $_POST['template_id'] ) ? absint( wp_unslash( $_POST['template_id'] ) ) : 0;

        if ( ! $template_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid template ID.', 'ffcertificate' ) ) );
        }

        // Get template post
        $template = get_post( $template_id );

        if ( ! $template || $template->post_type !== 'ffc_template' ) {
            wp_send_json_error( array( 'message' => __( 'Template not found.', 'ffcertificate' ) ) );
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

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- isset() existence check only; nonce verified immediately inside.
        if ( isset( $_POST['nonce'] ) ) {
            $nonce_value = sanitize_text_field( wp_unslash( $_POST['nonce'] ) );
            // Try ffc_form_nonce first
            if ( wp_verify_nonce( $nonce_value, 'ffc_form_nonce' ) ) {
                $nonce_verified = true;
            }
            // Try ffc_admin_nonce
            elseif ( wp_verify_nonce( $nonce_value, 'ffc_admin_nonce' ) ) {
                $nonce_verified = true;
            }
        }

        if ( ! $nonce_verified ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed. Please reload the page.', 'ffcertificate' ) ) );
        }

        // Check permissions
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ffcertificate' ) ) );
        }

        $quantity = isset( $_POST['quantity'] ) ? absint( wp_unslash( $_POST['quantity'] ) ) : 0;
        $form_id = isset( $_POST['form_id'] ) ? absint( wp_unslash( $_POST['form_id'] ) ) : 0;

        if ( $quantity < 1 || $quantity > 1000 ) {
            wp_send_json_error( array( 'message' => __( 'Quantity must be between 1 and 1000.', 'ffcertificate' ) ) );
        }

        if ( ! $form_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid form ID.', 'ffcertificate' ) ) );
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
                    wp_send_json_error( array( 'message' => __( 'Error generating unique codes. Please try a smaller quantity.', 'ffcertificate' ) ) );
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
            $result .= $letters[ wp_rand( 0, strlen( $letters ) - 1 ) ];
        }

        return $result;
    }

    /**
     * Generate random numbers
     */
    private function random_numbers( int $length ): string {
        $result = '';

        for ( $i = 0; $i < $length; $i++ ) {
            $result .= wp_rand( 0, 9 );
        }

        return $result;
    }

    /**
     * Search for WordPress users
     *
     * Searches by name, email, ID, or CPF/RF (via submission lookup).
     *
     * @since 4.3.0
     */
    public function search_user(): void {
        // Verify nonce
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- isset() existence check only; nonce verified immediately inside.
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'ffc_user_search_nonce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed. Please reload the page.', 'ffcertificate' ) ) );
        }

        // Check permissions
        if ( ! \FreeFormCertificate\Core\Utils::current_user_can_manage() ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ffcertificate' ) ) );
        }

        $search_term = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';

        if ( strlen( $search_term ) < 2 ) {
            wp_send_json_error( array( 'message' => __( 'Please enter at least 2 characters.', 'ffcertificate' ) ) );
        }

        $users = array();

        // Check if search term is a numeric ID
        if ( is_numeric( $search_term ) ) {
            $user = get_userdata( (int) $search_term );
            if ( $user ) {
                $users[] = $this->format_user_result( $user );
            }
        }

        // Search by email or name
        $user_query_args = array(
            'search' => '*' . $search_term . '*',
            'search_columns' => array( 'user_login', 'user_email', 'display_name', 'user_nicename' ),
            'number' => 10,
            'orderby' => 'display_name',
            'order' => 'ASC',
        );

        $user_query = new \WP_User_Query( $user_query_args );
        $found_users = $user_query->get_results();

        foreach ( $found_users as $user ) {
            // Avoid duplicates if ID search already found this user
            $exists = false;
            foreach ( $users as $existing ) {
                if ( $existing['id'] === $user->ID ) {
                    $exists = true;
                    break;
                }
            }
            if ( ! $exists ) {
                $users[] = $this->format_user_result( $user );
            }
        }

        // Search by CPF/RF in submissions (if no users found by standard search)
        if ( empty( $users ) ) {
            $users = $this->search_user_by_cpf( $search_term );
        }

        if ( empty( $users ) ) {
            wp_send_json_error( array( 'message' => __( 'No users found.', 'ffcertificate' ) ) );
        }

        wp_send_json_success( array( 'users' => $users ) );
    }

    /**
     * Format user data for AJAX response
     *
     * @param \WP_User $user WordPress user object
     * @return array Formatted user data
     */
    private function format_user_result( \WP_User $user ): array {
        return array(
            'id' => $user->ID,
            'display_name' => $user->display_name,
            'email' => $user->user_email,
            'avatar' => get_avatar_url( $user->ID, array( 'size' => 32 ) ),
        );
    }

    /**
     * Search for user by CPF/RF in submissions
     *
     * @param string $cpf_rf CPF/RF to search for
     * @return array Array of user results
     */
    private function search_user_by_cpf( string $cpf_rf ): array {
        global $wpdb;

        // Clean CPF/RF (remove formatting)
        $cpf_rf_clean = preg_replace( '/[^0-9]/', '', $cpf_rf );

        if ( strlen( $cpf_rf_clean ) < 6 ) {
            return array();
        }

        // Generate hash to search
        $cpf_rf_hash = hash( 'sha256', $cpf_rf_clean );

        $table = \FreeFormCertificate\Core\Utils::get_submissions_table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $user_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT user_id FROM {$table} WHERE cpf_rf_hash = %s AND user_id IS NOT NULL LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $cpf_rf_hash
        ) );

        if ( ! $user_id ) {
            return array();
        }

        $user = get_userdata( (int) $user_id );

        if ( ! $user ) {
            return array();
        }

        return array( $this->format_user_result( $user ) );
    }
}

// Initialize
new AdminAjax();
