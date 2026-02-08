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
            $s = \FreeFormCertificate\Core\Utils::asset_suffix();

            // Dark mode script (loaded early to prevent flash)
            $ffc_settings = get_option( 'ffc_settings', array() );
            $ffc_dark_mode = isset( $ffc_settings['dark_mode'] ) ? $ffc_settings['dark_mode'] : 'off';
            if ( $ffc_dark_mode !== 'off' ) {
                wp_enqueue_script( 'ffc-dark-mode', FFC_PLUGIN_URL . "assets/js/ffc-dark-mode{$s}.js", array(), FFC_VERSION, false );
                wp_localize_script( 'ffc-dark-mode', 'ffcDarkMode', array( 'mode' => $ffc_dark_mode ) );
            }

            // CSS - Using centralized version constant
            wp_enqueue_style( 'ffc-pdf-core', FFC_PLUGIN_URL . "assets/css/ffc-pdf-core{$s}.css", array(), FFC_VERSION );
            wp_enqueue_style( 'ffc-common', FFC_PLUGIN_URL . "assets/css/ffc-common{$s}.css", array(), FFC_VERSION );
            wp_enqueue_style( 'ffc-frontend-css', FFC_PLUGIN_URL . "assets/css/ffc-frontend{$s}.css", array('ffc-pdf-core', 'ffc-common'), FFC_VERSION );
            
            // PDF Libraries - Using centralized version constants
            wp_enqueue_script( 'html2canvas', FFC_PLUGIN_URL . 'libs/js/html2canvas.min.js', array(), FFC_HTML2CANVAS_VERSION, true );
            wp_enqueue_script( 'jspdf', FFC_PLUGIN_URL . 'libs/js/jspdf.umd.min.js', array(), FFC_JSPDF_VERSION, true );
            
            // PDF Generator (shared module)
            wp_enqueue_script( 'ffc-pdf-generator', FFC_PLUGIN_URL . "assets/js/ffc-pdf-generator{$s}.js", array( 'jquery', 'html2canvas', 'jspdf' ), FFC_VERSION, true );

            // ✅ v3.1.0: ffc-frontend.js depends on PDF generator and ffc-rate-limit (which loads ffc-frontend-helpers.js)
            wp_enqueue_script( 'ffc-frontend-js', FFC_PLUGIN_URL . "assets/js/ffc-frontend{$s}.js", array( 'jquery', 'ffc-pdf-generator', 'ffc-rate-limit' ), FFC_VERSION, true );

            // ✅ v3.0.0: Geofence frontend validation - NEW!
            wp_enqueue_script( 'ffc-geofence-frontend', FFC_PLUGIN_URL . "assets/js/ffc-geofence-frontend{$s}.js", array( 'jquery' ), FFC_VERSION, true );

            // Pass geofence configurations to frontend
            $this->localize_geofence_config();

            wp_localize_script( 'ffc-frontend-js', 'ffc_ajax', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'ffc_frontend_nonce' ),
            'strings'  => array(
            // Verification
            'verifying'             => __( 'Verifying...', 'ffcertificate' ),
            'verify'                => __( 'Verify', 'ffcertificate' ),
            'processing'            => __( 'Processing...', 'ffcertificate' ),
            'submit'                => __( 'Submit', 'ffcertificate' ),
            'connectionError'       => __( 'Connection error.', 'ffcertificate' ),
            'enterCode'             => __( 'Please enter the code.', 'ffcertificate' ),
            'generatingCertificate' => __( 'Generating certificate in the background, please wait 10 seconds and check your downloads folder...', 'ffcertificate' ),
            'idMustHaveDigits'      => __( 'The ID must have exactly 7 digits (RF) or 11 digits (CPF).', 'ffcertificate' ),
            'invalidToken'          => __( 'Invalid token.', 'ffcertificate' ),
            'generating'            => __( 'Generating...', 'ffcertificate' ),
            'downloadAgain'         => __( 'Download Again', 'ffcertificate' ),

            // Document Verification Display
            'certificateValid'      => __( 'Document Valid!', 'ffcertificate' ),
            'certificateInvalid'    => __( 'Document Invalid', 'ffcertificate' ),
            'formTitle'             => __( 'Form', 'ffcertificate' ),
            'authCode'              => __( 'Auth Code', 'ffcertificate' ),
            'issueDate'             => __( 'Issue Date', 'ffcertificate' ),
            'downloadPDF'           => __( 'Download PDF', 'ffcertificate' ),
            'tryManually'           => __( 'Or try manual verification', 'ffcertificate' ),
            'enterAuthCode'         => __( 'Enter auth code', 'ffcertificate' ),

            // Validation (CPF/RF)
            'rfInvalid'             => __( 'Invalid RF', 'ffcertificate' ),
            'cpfInvalid'            => __( 'Invalid CPF', 'ffcertificate' ),
            'enterValidCpfRf'       => __( 'Enter a valid CPF (11 digits) or RF (7 digits)', 'ffcertificate' ),

            // Success/Error Messages
            'success'               => __( 'Success!', 'ffcertificate' ),
            'submissionSuccessful'  => __( 'Your submission was successful.', 'ffcertificate' ),
            'error'                 => __( 'Error occurred', 'ffcertificate' ),
            'fillRequired'          => __( 'Please fill all required fields', 'ffcertificate' ),

            // Rate Limiting
            'wait'                  => __( 'Wait...', 'ffcertificate' ),
            'send'                  => __( 'Send', 'ffcertificate' ),

            // PDF Generation
            'pdfLibrariesFailed'    => __( 'PDF libraries failed to load. Please refresh the page.', 'ffcertificate' ),
            'pdfGenerationError'    => __( 'Error generating PDF (html2canvas). Please try again.', 'ffcertificate' ),
            'pleaseWait'            => __( 'Please wait, this may take a few seconds...', 'ffcertificate' ),
            'generatingPdf'         => __( 'Generating PDF...', 'ffcertificate' ),
            'pdfContainerNotFound'  => __( 'Error: PDF container not found', 'ffcertificate' ),
            'errorGeneratingPdf'    => __( 'Error generating PDF', 'ffcertificate' ),
            'html2canvasFailed'     => __( 'Error: html2canvas failed', 'ffcertificate' ),
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
            $config = \FreeFormCertificate\Security\Geofence::get_frontend_config($form_id_int);

            if ($config !== null) {
                $geofence_configs[$form_id_int] = $config;
            }
        }

        // Add global settings without re-indexing form IDs
        $geofence_configs['_global'] = array(
            'debug' => !empty($global_settings['debug_enabled']),
            'strings' => array(
                // Admin bypass messages
                'bypassGeneric' => __('Admin Bypass Mode Active - Geofence restrictions are disabled for administrators', 'ffcertificate'),
                'bypassDatetime' => __('Admin Bypass: Date/Time restrictions are disabled for administrators', 'ffcertificate'),
                'bypassGeo' => __('Admin Bypass: Geolocation restrictions are disabled for administrators', 'ffcertificate'),
                'bypassActive' => __('Admin Bypass Mode Active', 'ffcertificate'),

                // Geolocation messages
                'detectingLocation' => __('Detecting your location...', 'ffcertificate'),
                'browserNoSupport' => __('Your browser does not support geolocation.', 'ffcertificate'),
                'httpsRequired' => __('This form requires a secure connection (HTTPS) to access your location. Please contact the site administrator.', 'ffcertificate'),
                'locationError' => __('Unable to determine your location.', 'ffcertificate'),
                'permissionDenied' => __('Location permission denied. Please enable location services.', 'ffcertificate'),
                'positionUnavailable' => __('Location information is unavailable.', 'ffcertificate'),
                'timeout' => __('Location request timed out.', 'ffcertificate'),
                'outsideArea' => __('You are outside the allowed area for this form.', 'ffcertificate'),
                // Safari/iOS specific messages
                'safariPermissionDenied' => __('Location access was denied. On Safari/iOS, go to Settings > Privacy & Security > Location Services and ensure it is enabled for your browser.', 'ffcertificate'),
                'safariPositionUnavailable' => __('Unable to determine your location. On Safari/iOS, ensure Location Services is enabled in Settings > Privacy & Security > Location Services.', 'ffcertificate'),
                'safariTimeout' => __('Location request timed out. On Safari/iOS, ensure Location Services is enabled in Settings > Privacy & Security > Location Services.', 'ffcertificate'),

                // DateTime messages
                'formNotYetAvailable' => __('This form is not yet available.', 'ffcertificate'),
                'formNoLongerAvailable' => __('This form is no longer available.', 'ffcertificate'),
                'formOnlyDuringHours' => __('This form is only available during specific hours.', 'ffcertificate'),
            ),
        );

        // Localize script with preserved array keys
        wp_localize_script('ffc-geofence-frontend', 'ffcGeofenceConfig', $geofence_configs);
    }
}