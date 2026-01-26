<?php
declare(strict_types=1);

/**
 * FormEditor
 * Handles the advanced UI for the Form Builder, including AJAX and layout management.
 *
 * @version 3.3.0 - Added strict types and type hints
 * @version 3.2.0 - Migrated to namespace (Phase 2)
 */

namespace FreeFormCertificate\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FormEditor {

    private $metabox_renderer; // ✅ v3.1.1: Metabox Renderer
    private $save_handler;     // ✅ v3.1.1: Save Handler

    public function __construct() {
        // ✅ Autoloader handles class loading
        $this->metabox_renderer = new \FFC_Form_Editor_Metabox_Renderer();
        $this->save_handler = new \FFC_Form_Editor_Save_Handler();

        add_action( 'add_meta_boxes', array( $this, 'add_custom_metaboxes' ), 20 );
        add_action( 'save_post', array( $this->save_handler, 'save_form_data' ) );
        add_action( 'admin_notices', array( $this->save_handler, 'display_save_errors' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

        // AJAX handlers for the editor
        add_action( 'wp_ajax_ffc_generate_codes', array( $this, 'ajax_generate_random_codes' ) );
        add_action( 'wp_ajax_ffc_load_template', array( $this, 'ajax_load_template' ) );
    }

    /**
     * Enqueue scripts and styles for form editor
     */
    public function enqueue_scripts( string $hook ): void {
        // Only load on form edit page
        if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ) ) ) {
            return;
        }

        $screen = get_current_screen();
        if ( ! $screen || $screen->post_type !== 'ffc_form' ) {
            return;
        }

        wp_enqueue_script(
            'ffc-geofence-admin',
            FFC_PLUGIN_URL . 'assets/js/ffc-geofence-admin.js',
            array( 'jquery' ),
            FFC_VERSION,
            true
        );

        wp_localize_script(
            'ffc-geofence-admin',
            'ffc_geofence_admin',
            array(
                'alert_message' => __( 'At least one geolocation method (GPS or IP) must be enabled when geolocation is active.', 'ffc' )
            )
        );
    }

    /**
     * Registers all metaboxes for the Form CPT
     *
     * ✅ v3.1.1: Delegates rendering to FFC_Form_Editor_Metabox_Renderer
     */
    public function add_custom_metaboxes(): void {
        // Remove any potential duplicates
        remove_meta_box( 'ffc_form_builder', 'ffc_form', 'normal' );
        remove_meta_box( 'ffc_form_config', 'ffc_form', 'normal' );
        remove_meta_box( 'ffc_builder_box', 'ffc_form', 'normal' );

        // Main metaboxes (content area) - Delegated to Metabox Renderer
        add_meta_box(
            'ffc_box_layout',
            __( '1. Certificate Layout', 'ffc' ),
            array( $this->metabox_renderer, 'render_box_layout' ),
            'ffc_form',
            'normal',
            'high'
        );

        add_meta_box(
            'ffc_box_builder',
            __( '2. Form Builder (Fields)', 'ffc' ),
            array( $this->metabox_renderer, 'render_box_builder' ),
            'ffc_form',
            'normal',
            'high'
        );

        add_meta_box(
            'ffc_box_restriction',
            __( '3. Restriction & Security', 'ffc' ),
            array( $this->metabox_renderer, 'render_box_restriction' ),
            'ffc_form',
            'normal',
            'high'
        );

        add_meta_box(
            'ffc_box_email',
            __( '4. Email Configuration', 'ffc' ),
            array( $this->metabox_renderer, 'render_box_email' ),
            'ffc_form',
            'normal',
            'high'
        );

        add_meta_box(
            'ffc_box_geofence',
            __( '5. Geolocation & Date/Time Restrictions', 'ffc' ),
            array( $this->metabox_renderer, 'render_box_geofence' ),
            'ffc_form',
            'normal',
            'high'
        );

        // Sidebar metabox (shortcode + instructions) - Delegated to Metabox Renderer
        add_meta_box(
            'ffc_form_shortcode',
            __( 'How to Use / Shortcode', 'ffc' ),
            array( $this->metabox_renderer, 'render_shortcode_metabox' ),
            'ffc_form',
            'side',
            'high'
        );
    }

    /**
     * AJAX: Generates a list of unique ticket codes
     */
    public function ajax_generate_random_codes(): void {
        check_ajax_referer( 'ffc_admin_pdf_nonce', 'nonce' );
        
        if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error();

        $qty = isset($_POST['qty']) ? absint($_POST['qty']) : 10;
        $codes = array();
        for($i = 0; $i < $qty; $i++) {
            $rnd = strtoupper(bin2hex(random_bytes(4))); 
            $codes[] = substr($rnd, 0, 4) . '-' . substr($rnd, 4, 4);
        }
        wp_send_json_success( array( 'codes' => implode("\n", $codes) ) );
    }

    /**
     * AJAX: Loads a local HTML template from the plugin directory
     */
    public function ajax_load_template(): void {
        check_ajax_referer( 'ffc_admin_pdf_nonce', 'nonce' );
        
        if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error();

        $filename = isset($_POST['filename']) ? sanitize_file_name($_POST['filename']) : '';
        if ( empty($filename) ) wp_send_json_error();

        $filepath = FFC_PLUGIN_DIR . 'html/' . $filename;
        if ( ! file_exists( $filepath ) ) wp_send_json_error();

        $content = file_get_contents( $filepath );
        wp_send_json_success( $content );
    }
}
