<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FFC_Frontend {
    
    private $shortcodes;
    private $form_processor;
    private $verification_handler;

    public function __construct( $submission_handler, $email_handler ) {
        // Load classes only if not already loaded
        if (!class_exists('FFC_Verification_Handler')) {
            require_once FFC_PLUGIN_DIR . 'includes/class-ffc-verification-handler.php';
        }
        if (!class_exists('FFC_Form_Processor')) {
            require_once FFC_PLUGIN_DIR . 'includes/class-ffc-form-processor.php';
        }
        if (!class_exists('FFC_Shortcodes')) {
            require_once FFC_PLUGIN_DIR . 'includes/class-ffc-shortcodes.php';
        }

        $this->verification_handler = new FFC_Verification_Handler( $submission_handler, $email_handler );
        $this->form_processor = new FFC_Form_Processor( $submission_handler, $email_handler );
        $this->shortcodes = new FFC_Shortcodes( 
            $this->form_processor, 
            $this->verification_handler,
            $submission_handler
        );
        
        $this->register_hooks();
    }

    private function register_hooks() {
        add_action( 'wp_enqueue_scripts', array( $this, 'frontend_assets' ) );
        
        add_shortcode( 'ffc_form', array( $this->shortcodes, 'render_form' ) );
        add_shortcode( 'ffc_verification', array( $this->shortcodes, 'render_verification_page' ) );
        
        add_action( 'wp_ajax_ffc_submit_form', array( $this->form_processor, 'handle_submission_ajax' ) );
        add_action( 'wp_ajax_nopriv_ffc_submit_form', array( $this->form_processor, 'handle_submission_ajax' ) );

        add_action( 'wp_ajax_ffc_verify_certificate', array( $this->verification_handler, 'handle_verification_ajax' ) );
        add_action( 'wp_ajax_nopriv_ffc_verify_certificate', array( $this->verification_handler, 'handle_verification_ajax' ) );

        add_action( 'wp_ajax_ffc_verify_magic_token', array( $this->verification_handler, 'handle_magic_verification_ajax' ) );
        add_action( 'wp_ajax_nopriv_ffc_verify_magic_token', array( $this->verification_handler, 'handle_magic_verification_ajax' ) );
    }

    public function frontend_assets() {
        global $post;
        
        if ( ! is_a( $post, 'WP_Post' ) ) {
            return;
        }

        if ( has_shortcode( $post->post_content, 'ffc_form' ) || has_shortcode( $post->post_content, 'ffc_verification' ) ) {
            
            // CSS - Using centralized version constant
            wp_enqueue_style( 'ffc-pdf-core', FFC_PLUGIN_URL . 'assets/css/ffc-pdf-core.css', array(), FFC_VERSION );
            wp_enqueue_style( 'ffc-frontend-css', FFC_PLUGIN_URL . 'assets/css/frontend.css', array('ffc-pdf-core'), FFC_VERSION );
            
            // PDF Libraries - Using centralized version constants
            wp_enqueue_script( 'html2canvas', FFC_PLUGIN_URL . 'assets/js/html2canvas.min.js', array(), FFC_HTML2CANVAS_VERSION, true );
            wp_enqueue_script( 'jspdf', FFC_PLUGIN_URL . 'assets/js/jspdf.umd.min.js', array(), FFC_JSPDF_VERSION, true );
            
            // PDF Generator (shared module)
            wp_enqueue_script( 'ffc-pdf-generator', FFC_PLUGIN_URL . 'assets/js/ffc-pdf-generator.js', array( 'jquery', 'html2canvas', 'jspdf' ), FFC_VERSION, true );
            
            // ✅ v2.9.12: Frontend Utilities (shared module) - NEW!
            wp_enqueue_script( 'ffc-frontend-utils', FFC_PLUGIN_URL . 'assets/js/ffc-frontend-utils.js', array( 'jquery' ), FFC_VERSION, true );
            
            // ✅ v2.9.12: ffc-frontend.js (depends on PDF generator AND utils) - UPDATED!
            wp_enqueue_script( 'ffc-frontend-js', FFC_PLUGIN_URL . 'assets/js/ffc-frontend.js', array( 'jquery', 'ffc-pdf-generator', 'ffc-frontend-utils' ), FFC_VERSION, true );

            // ✅ v3.0.0: Geofence frontend validation - NEW!
            wp_enqueue_script( 'ffc-geofence-frontend', FFC_PLUGIN_URL . 'assets/js/ffc-geofence-frontend.js', array( 'jquery' ), FFC_VERSION, true );

            // Pass geofence configurations to frontend
            $this->localize_geofence_config();

            wp_localize_script( 'ffc-frontend-js', 'ffc_ajax', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'ffc_frontend_nonce' ),
            'strings'  => array(
            'verifying'             => __( 'Verifying...', 'ffc' ),
            'verify'                => __( 'Verify', 'ffc' ),
            'processing'            => __( 'Processing...', 'ffc' ),
            'submit'                => __( 'Submit', 'ffc' ),
            'connectionError'       => __( 'Connection error.', 'ffc' ),
            'enterCode'             => __( 'Please enter the code.', 'ffc' ),
            'generatingCertificate' => __( 'Generating certificate in the background, please wait 10 seconds and check your downloads folder...', 'ffc' ),
            'idMustHaveDigits'      => __( 'The ID must have exactly 7 digits (RF) or 11 digits (CPF).', 'ffc' ),
            'pdfLibrariesFailed'    => __( 'Error: PDF libraries (html2canvas/jspdf) failed to load.', 'ffc' ),
            'pdfGenerationError'    => __( 'Error generating PDF (html2canvas). Please try again.', 'ffc' ),
            'invalidToken'          => __( 'Invalid token.', 'ffc' ),
            'generating'            => __( 'Generating...', 'ffc' ),
            'downloadAgain'         => __( 'Download Again', 'ffc' ),
            'pleaseWait'            => __( 'Please wait, this may take a few seconds...', 'ffc' ),
            )
        ) );
        }
    }

    /**
     * Localize geofence configuration for frontend
     * @since 3.0.0
     */
    private function localize_geofence_config() {
        global $post;

        if (!is_a($post, 'WP_Post')) {
            return;
        }

        // Find all form IDs in post content
        preg_match_all('/\[ffc_form\s+id=[\'"](\d+)[\'"]\]/', $post->post_content, $matches);

        if (empty($matches[1])) {
            return;
        }

        $geofence_configs = array();
        $global_settings = get_option('ffc_geolocation_settings', array());

        foreach ($matches[1] as $form_id) {
            $config = FFC_Geofence::get_frontend_config($form_id);

            if ($config !== null) {
                $geofence_configs[$form_id] = $config;
            }
        }

        // Always localize (even if empty) to avoid JS errors
        wp_localize_script('ffc-geofence-frontend', 'ffcGeofenceConfig', array_merge(
            $geofence_configs,
            array(
                '_global' => array(
                    'debug' => !empty($global_settings['debug_enabled'])
                )
            )
        ));
    }
}