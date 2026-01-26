<?php
declare(strict_types=1);

/**
 * Frontend
 *
 * @version 3.3.0 - Added strict types and type hints
 * @version 3.2.0 - Migrated to namespace (Phase 2)
 */

namespace FreeFormCertificate\Frontend;

use FreeFormCertificate\Submissions\SubmissionHandler;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Frontend {

    private $shortcodes;
    private $form_processor;
    private $verification_handler;

    public function __construct( SubmissionHandler $submission_handler, $email_handler ) {
        $this->verification_handler = new VerificationHandler( $submission_handler, $email_handler );
        $this->form_processor = new FormProcessor( $submission_handler, $email_handler );
        $this->shortcodes = new Shortcodes(
            $this->form_processor,
            $this->verification_handler,
            $submission_handler
        );
        
        $this->register_hooks();
    }

    private function register_hooks(): void {
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

    public function frontend_assets(): void {
        global $post;
        
        if ( ! is_a( $post, 'WP_Post' ) ) {
            return;
        }

        if ( has_shortcode( $post->post_content, 'ffc_form' ) || has_shortcode( $post->post_content, 'ffc_verification' ) ) {
            
            // CSS - Using centralized version constant
            wp_enqueue_style( 'ffc-pdf-core', FFC_PLUGIN_URL . 'assets/css/ffc-pdf-core.css', array(), FFC_VERSION );
            wp_enqueue_style( 'ffc-common', FFC_PLUGIN_URL . 'assets/css/ffc-common.css', array(), FFC_VERSION );
            wp_enqueue_style( 'ffc-frontend-css', FFC_PLUGIN_URL . 'assets/css/ffc-frontend.css', array('ffc-pdf-core', 'ffc-common'), FFC_VERSION );
            
            // PDF Libraries - Using centralized version constants
            wp_enqueue_script( 'html2canvas', FFC_PLUGIN_URL . 'libs/js/html2canvas.min.js', array(), FFC_HTML2CANVAS_VERSION, true );
            wp_enqueue_script( 'jspdf', FFC_PLUGIN_URL . 'libs/js/jspdf.umd.min.js', array(), FFC_JSPDF_VERSION, true );
            
            // PDF Generator (shared module)
            wp_enqueue_script( 'ffc-pdf-generator', FFC_PLUGIN_URL . 'assets/js/ffc-pdf-generator.js', array( 'jquery', 'html2canvas', 'jspdf' ), FFC_VERSION, true );

            // ✅ v3.1.0: ffc-frontend.js depends on PDF generator and ffc-rate-limit (which loads ffc-frontend-helpers.js)
            wp_enqueue_script( 'ffc-frontend-js', FFC_PLUGIN_URL . 'assets/js/ffc-frontend.js', array( 'jquery', 'ffc-pdf-generator', 'ffc-rate-limit' ), FFC_VERSION, true );

            // ✅ v3.0.0: Geofence frontend validation - NEW!
            wp_enqueue_script( 'ffc-geofence-frontend', FFC_PLUGIN_URL . 'assets/js/ffc-geofence-frontend.js', array( 'jquery' ), FFC_VERSION, true );

            // Pass geofence configurations to frontend
            $this->localize_geofence_config();

            wp_localize_script( 'ffc-frontend-js', 'ffc_ajax', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'ffc_frontend_nonce' ),
            'strings'  => array(
            // Verification
            'verifying'             => __( 'Verifying...', 'ffc' ),
            'verify'                => __( 'Verify', 'ffc' ),
            'processing'            => __( 'Processing...', 'ffc' ),
            'submit'                => __( 'Submit', 'ffc' ),
            'connectionError'       => __( 'Connection error.', 'ffc' ),
            'enterCode'             => __( 'Please enter the code.', 'ffc' ),
            'generatingCertificate' => __( 'Generating certificate in the background, please wait 10 seconds and check your downloads folder...', 'ffc' ),
            'idMustHaveDigits'      => __( 'The ID must have exactly 7 digits (RF) or 11 digits (CPF).', 'ffc' ),
            'invalidToken'          => __( 'Invalid token.', 'ffc' ),
            'generating'            => __( 'Generating...', 'ffc' ),
            'downloadAgain'         => __( 'Download Again', 'ffc' ),

            // Certificate Display
            'certificateValid'      => __( 'Certificate Valid!', 'ffc' ),
            'certificateInvalid'    => __( 'Certificate Invalid', 'ffc' ),
            'formTitle'             => __( 'Form', 'ffc' ),
            'authCode'              => __( 'Auth Code', 'ffc' ),
            'issueDate'             => __( 'Issue Date', 'ffc' ),
            'downloadPDF'           => __( 'Download PDF', 'ffc' ),
            'tryManually'           => __( 'Or try manual verification', 'ffc' ),
            'enterAuthCode'         => __( 'Enter auth code', 'ffc' ),

            // Validation (CPF/RF)
            'rfInvalid'             => __( 'Invalid RF', 'ffc' ),
            'cpfInvalid'            => __( 'Invalid CPF', 'ffc' ),
            'enterValidCpfRf'       => __( 'Enter a valid CPF (11 digits) or RF (7 digits)', 'ffc' ),

            // Success/Error Messages
            'success'               => __( 'Success!', 'ffc' ),
            'submissionSuccessful'  => __( 'Your submission was successful.', 'ffc' ),
            'error'                 => __( 'Error occurred', 'ffc' ),
            'fillRequired'          => __( 'Please fill all required fields', 'ffc' ),

            // Rate Limiting
            'wait'                  => __( 'Wait...', 'ffc' ),
            'send'                  => __( 'Send', 'ffc' ),

            // PDF Generation
            'pdfLibrariesFailed'    => __( 'PDF libraries failed to load. Please refresh the page.', 'ffc' ),
            'pdfGenerationError'    => __( 'Error generating PDF (html2canvas). Please try again.', 'ffc' ),
            'pleaseWait'            => __( 'Please wait, this may take a few seconds...', 'ffc' ),
            'generatingPdf'         => __( 'Generating PDF...', 'ffc' ),
            'pdfContainerNotFound'  => __( 'Error: PDF container not found', 'ffc' ),
            'errorGeneratingPdf'    => __( 'Error generating PDF', 'ffc' ),
            'html2canvasFailed'     => __( 'Error: html2canvas failed', 'ffc' ),
            )
        ) );
        }
    }

    /**
     * Localize geofence configuration for frontend
     * @since 3.0.0
     */
    private function localize_geofence_config(): void {
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
            $form_id_int = (int) $form_id;
            $config = \FFC_Geofence::get_frontend_config($form_id_int);

            if ($config !== null) {
                $geofence_configs[$form_id_int] = $config;
            }
        }

        // Add global settings without re-indexing form IDs
        $geofence_configs['_global'] = array(
            'debug' => !empty($global_settings['debug_enabled']),
            'strings' => array(
                // Admin bypass messages
                'bypassGeneric' => __('Admin Bypass Mode Active - Geofence restrictions are disabled for administrators', 'ffc'),
                'bypassDatetime' => __('Admin Bypass: Date/Time restrictions are disabled for administrators', 'ffc'),
                'bypassGeo' => __('Admin Bypass: Geolocation restrictions are disabled for administrators', 'ffc'),
                'bypassActive' => __('Admin Bypass Mode Active', 'ffc'),

                // Geolocation messages
                'detectingLocation' => __('Detecting your location...', 'ffc'),
                'browserNoSupport' => __('Your browser does not support geolocation.', 'ffc'),
                'locationError' => __('Unable to determine your location.', 'ffc'),
                'permissionDenied' => __('Location permission denied. Please enable location services.', 'ffc'),
                'positionUnavailable' => __('Location information is unavailable.', 'ffc'),
                'timeout' => __('Location request timed out.', 'ffc'),
                'outsideArea' => __('You are outside the allowed area for this form.', 'ffc'),

                // DateTime messages
                'formNotYetAvailable' => __('This form is not yet available.', 'ffc'),
                'formNoLongerAvailable' => __('This form is no longer available.', 'ffc'),
                'formOnlyDuringHours' => __('This form is only available during specific hours.', 'ffc'),
            ),
        );

        // Localize script with preserved array keys
        wp_localize_script('ffc-geofence-frontend', 'ffcGeofenceConfig', $geofence_configs);
    }
}