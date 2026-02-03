<?php
declare(strict_types=1);

/**
 * CPT
 * Manages the Custom Post Type for forms, including registration and duplication logic.
 *
 * v2.9.2: OPTIMIZED to use FFC_Utils functions
 * @version 3.3.0 - Added strict types and type hints
 * @version 3.2.0 - Migrated to namespace (Phase 2)
 */

namespace FreeFormCertificate\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CPT {

    public function __construct() {
        add_action( 'init', array( $this, 'register_form_cpt' ) );
        add_filter( 'post_row_actions', array( $this, 'add_duplicate_link' ), 10, 2 );
        add_action( 'admin_action_ffc_duplicate_form', array( $this, 'handle_form_duplication' ) );
    }

    /**
     * Registers the 'ffc_form' Custom Post Type
     */
    public function register_form_cpt(): void {
        $labels = array(
            'name'                  => _x( 'Forms', 'Post Type General Name', 'wp-ffcertificate' ),
            'singular_name'         => _x( 'Form', 'Post Type Singular Name', 'wp-ffcertificate' ),
            'menu_name'             => __( 'Free Form Certificate', 'wp-ffcertificate' ),
            'name_admin_bar'        => __( 'FFC Form', 'wp-ffcertificate' ),
            'add_new'               => __( 'Add New Form', 'wp-ffcertificate' ),
            'add_new_item'          => __( 'Add New Form', 'wp-ffcertificate' ),
            'new_item'              => __( 'New Form', 'wp-ffcertificate' ),
            'edit_item'             => __( 'Edit Form', 'wp-ffcertificate' ),
            'view_item'             => __( 'View Form', 'wp-ffcertificate' ),
            'all_items'             => __( 'All Forms', 'wp-ffcertificate' ),
            'search_items'          => __( 'Search Forms', 'wp-ffcertificate' ),
            'not_found'             => __( 'No forms found.', 'wp-ffcertificate' ),
            'not_found_in_trash'    => __( 'No forms found in Trash.', 'wp-ffcertificate' ),
        );

        $args = array(
            'labels'             => $labels,
            'public'             => false,
            'show_ui'            => true,
            'show_in_menu'       => true,
            'query_var'          => true,
            'capability_type'    => 'post',
            'has_archive'        => false,
            'hierarchical'       => false,
            'menu_icon'          => 'dashicons-feedback',
            'supports'           => array( 'title' ),
            'rewrite'            => array( 'slug' => 'ffc-form' ),
        );

        register_post_type( 'ffc_form', $args );
    }

    /**
     * Adds a "Duplicate" link to the post row actions
     */
    public function add_duplicate_link( array $actions, object $post ): array {
        if ( $post->post_type !== 'ffc_form' ) {
            return $actions;
        }

        // ✅ OPTIMIZED v2.9.2: Check permissions before adding link
        if ( ! \FreeFormCertificate\Core\Utils::current_user_can_manage() ) {
            return $actions;
        }

        $url = wp_nonce_url(
            admin_url( 'admin.php?action=ffc_duplicate_form&post=' . $post->ID ),
            'ffc_duplicate_form_nonce'
        );

        $actions['duplicate'] = '<a href="' . esc_url( $url ) . '" title="' . esc_attr__( 'Duplicate this form', 'wp-ffcertificate' ) . '">' . esc_html__( 'Duplicate', 'wp-ffcertificate' ) . '</a>';

        return $actions;
    }

    /**
     * Handles the duplication process when the action is triggered
     */
    public function handle_form_duplication(): void {
        // ✅ OPTIMIZED v2.9.2: Use FFC_Utils for permission check
        if ( ! \FreeFormCertificate\Core\Utils::current_user_can_manage() ) {
            \FreeFormCertificate\Core\Utils::debug_log( 'Unauthorized form duplication attempt', array(
                'user_id' => get_current_user_id(),
                'ip' => \FreeFormCertificate\Core\Utils::get_user_ip()
            ) );
            wp_die( esc_html__( 'You do not have permission to duplicate this post.', 'wp-ffcertificate' ) );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified immediately below via check_admin_referer.
        $post_id = ( isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0 );

        check_admin_referer( 'ffc_duplicate_form_nonce' );

        $post = get_post( $post_id );

        if ( ! $post || $post->post_type !== 'ffc_form' ) {
            \FreeFormCertificate\Core\Utils::debug_log( 'Invalid form duplication request', array(
                'post_id' => $post_id,
                'user_id' => get_current_user_id()
            ) );
            wp_die( esc_html__( 'Invalid post.', 'wp-ffcertificate' ) );
        }

        // ✅ OPTIMIZED v2.9.2: Use \FreeFormCertificate\Core\Utils::sanitize_filename() for title
        $original_title = $post->post_title;
        /* translators: %s: original post title */
        $new_title = sprintf( __( '%s (Copy)', 'wp-ffcertificate' ), $original_title );

        // Create new post
        $new_post_args = array(
            'post_title'  => $new_title,
            'post_status' => 'draft',
            'post_type'   => $post->post_type,
            'post_author' => get_current_user_id(),
        );

        $new_post_id = wp_insert_post( $new_post_args );

        if ( is_wp_error( $new_post_id ) ) {
            \FreeFormCertificate\Core\Utils::debug_log( 'Form duplication failed', array(
                'error' => $new_post_id->get_error_message(),
                'original_post_id' => $post_id
            ) );
            wp_die( esc_html( $new_post_id->get_error_message() ) );
        }

        // Copy all metadata
        $fields = get_post_meta( $post_id, '_ffc_form_fields', true );
        $config = get_post_meta( $post_id, '_ffc_form_config', true );
        $bg_image = get_post_meta( $post_id, '_ffc_form_bg', true );
        $geofence_config = get_post_meta( $post_id, '_ffc_geofence_config', true );

        $metadata_copied = array();

        if ( $fields ) {
            update_post_meta( $new_post_id, '_ffc_form_fields', $fields );
            $metadata_copied[] = 'fields';
        }

        if ( $config ) {
            update_post_meta( $new_post_id, '_ffc_form_config', $config );
            $metadata_copied[] = 'config';
        }

        if ( $bg_image ) {
            update_post_meta( $new_post_id, '_ffc_form_bg', $bg_image );
            $metadata_copied[] = 'bg_image';
        }

        if ( $geofence_config ) {
            update_post_meta( $new_post_id, '_ffc_geofence_config', $geofence_config );
            $metadata_copied[] = 'geofence_config';
        }

        // ✅ OPTIMIZED v2.9.2: Log successful duplication
        \FreeFormCertificate\Core\Utils::debug_log( 'Form duplicated successfully', array(
            'original_post_id' => $post_id,
            'new_post_id' => $new_post_id,
            'original_title' => \FreeFormCertificate\Core\Utils::truncate( $original_title, 50 ),
            'new_title' => \FreeFormCertificate\Core\Utils::truncate( $new_title, 50 ),
            'metadata_copied' => implode( ', ', $metadata_copied ),
            'user_id' => get_current_user_id()
        ) );

        // Redirect to forms list
        wp_safe_redirect( admin_url( 'edit.php?post_type=ffc_form' ) );
        exit;
    }
}
