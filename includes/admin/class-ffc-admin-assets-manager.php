<?php
declare(strict_types=1);

/**
 * AdminAssetsManager
 *
 * Manages CSS and JavaScript asset loading for admin pages.
 * Extracted from FFC_Admin class to follow Single Responsibility Principle.
 *
 * @since 3.1.1 (Extracted from FFC_Admin)
 * @version 3.3.0 - Added strict types and type hints
 * @version 3.2.0 - Migrated to namespace (Phase 2)
 */

namespace FreeFormCertificate\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AdminAssetsManager {

    /**
     * Hook suffix for current page
     *
     * @var string
     */
    private $hook;

    /**
     * Current post type
     *
     * @var string
     */
    private $post_type;

    /**
     * Register assets hooks
     */
    public function register(): void {
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
    }

    /**
     * Main enqueue method
     * Delegates to specialized methods based on context.
     *
     * @param string $hook Hook suffix for the current admin page
     */
    public function enqueue_admin_assets( string $hook ): void {
        global $post_type;

        $this->hook = $hook;
        $this->post_type = $post_type;

        // Only load on FFC pages
        if ( ! $this->is_ffc_page() ) {
            return;
        }

        // Load WordPress media library
        wp_enqueue_media();

        // Enqueue assets in proper order
        $this->enqueue_core_module();
        $this->enqueue_css_assets();
        $this->enqueue_javascript_modules();
        $this->enqueue_conditional_assets();
    }

    /**
     * Check if current page is an FFC page
     *
     * @return bool True if FFC page, false otherwise
     */
    private function is_ffc_page(): bool {
        $is_ffc_post_type = ( $this->post_type === 'ffc_form' );
        $is_ffc_menu = ( isset( $_GET['page'] ) && strpos( $_GET['page'], 'ffc-' ) !== false );

        return $is_ffc_post_type || $is_ffc_menu;
    }

    /**
     * Enqueue FFC Core module (required by all other modules)
     *
     * @since 3.1.0
     */
    private function enqueue_core_module(): void {
        wp_enqueue_script(
            'ffc-core',
            FFC_PLUGIN_URL . 'assets/js/ffc-core.js',
            array( 'jquery' ),
            FFC_VERSION,
            true
        );
    }

    /**
     * Enqueue all CSS assets with proper dependency chain
     *
     * Dependency hierarchy:
     * 1. ffc-pdf-core (base)
     * 2. ffc-common (shared utilities)
     * 3. ffc-admin-utilities (admin utilities, depends on common)
     * 4. ffc-admin-css (general admin, depends on pdf-core, common, utilities)
     * 5. ffc-admin-submissions-css (submissions page, depends on admin)
     * 6. Conditional: ffc-admin-settings (only on settings page)
     * 7. Conditional: ffc-admin-submission-edit (only on edit page)
     */
    private function enqueue_css_assets(): void {
        // 1. Base styles (PDF core)
        wp_enqueue_style(
            'ffc-pdf-core',
            FFC_PLUGIN_URL . 'assets/css/ffc-pdf-core.css',
            array(),
            FFC_VERSION
        );

        // 2. Common utilities (shared between admin and frontend)
        wp_enqueue_style(
            'ffc-common',
            FFC_PLUGIN_URL . 'assets/css/ffc-common.css',
            array(),
            FFC_VERSION
        );

        // 3. Admin-specific utilities (v3.1.0)
        wp_enqueue_style(
            'ffc-admin-utilities',
            FFC_PLUGIN_URL . 'assets/css/ffc-admin-utilities.css',
            array( 'ffc-common' ),
            FFC_VERSION
        );

        // 4. Admin general styles (depends on pdf-core, common, and admin-utilities)
        wp_enqueue_style(
            'ffc-admin-css',
            FFC_PLUGIN_URL . 'assets/css/ffc-admin.css',
            array( 'ffc-pdf-core', 'ffc-common', 'ffc-admin-utilities' ),
            FFC_VERSION
        );

        // 5. Submissions page styles (depends on ffc-admin.css)
        wp_enqueue_style(
            'ffc-admin-submissions-css',
            FFC_PLUGIN_URL . 'assets/css/ffc-admin-submissions.css',
            array( 'ffc-admin-css' ),
            FFC_VERSION
        );
    }

    /**
     * Enqueue JavaScript modules with proper dependencies
     *
     * Module hierarchy:
     * 1. ffc-core (loaded in enqueue_core_module)
     * 2. ffc-admin-field-builder (depends on core, sortable)
     * 3. ffc-admin-pdf (depends on core)
     * 4. ffc-admin-js (main script, depends on modules)
     *
     * @since 3.1.0 - Modular architecture
     */
    private function enqueue_javascript_modules(): void {
        // 1. Field Builder module
        wp_enqueue_script(
            'ffc-admin-field-builder',
            FFC_PLUGIN_URL . 'assets/js/ffc-admin-field-builder.js',
            array( 'jquery', 'jquery-ui-sortable', 'ffc-core' ),
            FFC_VERSION,
            true
        );

        // 2. PDF Management module
        wp_enqueue_script(
            'ffc-admin-pdf',
            FFC_PLUGIN_URL . 'assets/js/ffc-admin-pdf.js',
            array( 'jquery', 'ffc-core' ),
            FFC_VERSION,
            true
        );

        // 3. Main admin script (depends on modules)
        wp_enqueue_script(
            'ffc-admin-js',
            FFC_PLUGIN_URL . 'assets/js/ffc-admin.js',
            array( 'jquery', 'ffc-admin-field-builder', 'ffc-admin-pdf' ),
            FFC_VERSION,
            true
        );

        // 4. Localize main admin script
        wp_localize_script( 'ffc-admin-js', 'ffc_ajax', $this->get_localization_data() );
    }

    /**
     * Enqueue conditional assets based on current page
     *
     * - Settings CSS (only on settings page)
     * - Submission Edit CSS + JS (only on edit page)
     */
    private function enqueue_conditional_assets(): void {
        // Settings page styles
        if ( $this->is_settings_page() ) {
            wp_enqueue_style(
                'ffc-admin-settings',
                FFC_PLUGIN_URL . 'assets/css/ffc-admin-settings.css',
                array( 'ffc-admin-css' ),
                FFC_VERSION
            );
        }

        // Submission edit page assets
        if ( $this->is_submission_edit_page() ) {
            $this->enqueue_submission_edit_assets();
        }
    }

    /**
     * Enqueue submission edit page specific assets
     */
    private function enqueue_submission_edit_assets(): void {
        // Edit page CSS
        wp_enqueue_style(
            'ffc-admin-submission-edit',
            FFC_PLUGIN_URL . 'assets/css/ffc-admin-submission-edit.css',
            array( 'ffc-admin-css' ),
            FFC_VERSION
        );

        // Edit page JS
        wp_enqueue_script(
            'ffc-admin-submission-edit',
            FFC_PLUGIN_URL . 'assets/js/ffc-admin-submission-edit.js',
            array( 'jquery' ),
            FFC_VERSION,
            true
        );

        // Localize edit script
        wp_localize_script(
            'ffc-admin-submission-edit',
            'ffc_submission_edit',
            array(
                'copied_text' => '✅ ' . __( 'Copied!', 'ffc' )
            )
        );
    }

    /**
     * Check if current page is settings page
     *
     * @return bool
     */
    private function is_settings_page(): bool {
        return isset( $_GET['page'] ) && $_GET['page'] === 'ffc-settings';
    }

    /**
     * Check if current page is submission edit page
     *
     * @return bool
     */
    private function is_submission_edit_page(): bool {
        return isset( $_GET['page'] )
            && $_GET['page'] === 'ffc-submissions'
            && isset( $_GET['action'] )
            && $_GET['action'] === 'edit';
    }

    /**
     * Get localization data for JavaScript
     *
     * @return array Localization data
     */
    private function get_localization_data(): array {
        return array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'ffc_admin_pdf_nonce' ),
            'strings'  => array(
                // General
                'generating'              => __( 'Generating...', 'ffc' ),
                'error'                   => __( 'Error: ', 'ffc' ),
                'connectionError'         => __( 'Connection error.', 'ffc' ),
                'fileImported'            => __( 'File imported successfully!', 'ffc' ),
                'errorReadingFile'        => __( 'Error reading file.', 'ffc' ),
                'selectTemplate'          => __( 'Please select a template.', 'ffc' ),
                'confirmReplaceContent'   => __( 'This will replace current content. Continue?', 'ffc' ),
                'loading'                 => __( 'Loading...', 'ffc' ),
                'templateLoaded'          => __( 'Template loaded successfully!', 'ffc' ),
                'selectBackgroundImage'   => __( 'Select Background Image', 'ffc' ),
                'useImage'                => __( 'Use this image', 'ffc' ),
                'codesGenerated'          => __( 'codes generated', 'ffc' ),
                'errorGeneratingCodes'    => __( 'Error generating codes.', 'ffc' ),
                'confirmDeleteField'      => __( 'Remove this field?', 'ffc' ),
                'pdfLibrariesFailed'      => __( 'PDF libraries failed to load. Please refresh the page.', 'ffc' ),
                'pdfGenerationError'      => __( 'Error generating PDF. Please try again.', 'ffc' ),
                'pleaseWait'              => __( 'Please wait, this may take a few seconds...', 'ffc' ),
                'downloadAgain'           => __( 'Download Again', 'ffc' ),
                'verifying'               => __( 'Verifying...', 'ffc' ),
                'processing'              => __( 'Processing...', 'ffc' ),
                'generatingPdf'           => __( 'Generating PDF...', 'ffc' ),
                'pdfContainerNotFound'    => __( 'Error: PDF container not found', 'ffc' ),
                'errorGeneratingPdf'      => __( 'Error generating PDF', 'ffc' ),
                'html2canvasFailed'       => __( 'Error: html2canvas failed', 'ffc' ),
                'confirmLoadTemplate'     => __( 'Load "%s"? This will replace your current certificate HTML.', 'ffc' ),
                'dismiss'                 => __( 'Dismiss', 'ffc' ),

                // Ticket Generation
                'enterValidNumber'        => __( 'Please enter a valid number.', 'ffc' ),
                'generatingTickets'       => __( 'Generating tickets...', 'ffc' ),
                'ticketsGeneratedSuccess' => __( 'tickets generated successfully!', 'ffc' ),
                'codesFieldNotFound'      => __( 'Error: codes field not found', 'ffc' ),
                'permissionDenied'        => __( 'Permission denied. Please reload the page.', 'ffc' ),
                'badRequest'              => __( 'Bad request. Check console.', 'ffc' ),
                'serverError'             => __( 'Server error (Status: %d)', 'ffc' ),

                // Field Builder
                'chooseFieldType'         => __( 'Choose Field Type:', 'ffc' ),
                'remove'                  => __( 'Remove', 'ffc' ),
                'fieldType'               => __( 'Field Type:', 'ffc' ),
                'label'                   => __( 'Label:', 'ffc' ),
                'fieldLabel'              => __( 'Field Label', 'ffc' ),
                'nameVariable'            => __( 'Name (variable):', 'ffc' ),
                'fieldName'               => __( 'field_name', 'ffc' ),
                'required'                => __( 'Required:', 'ffc' ),
                'options'                 => __( 'Options:', 'ffc' ),
                'separateWithCommas'      => __( 'Separate with commas', 'ffc' ),

                // Field Types
                'textField'               => __( 'Text Field', 'ffc' ),
                'email'                   => __( 'Email', 'ffc' ),
                'number'                  => __( 'Number', 'ffc' ),
                'textarea'                => __( 'Textarea', 'ffc' ),
                'dropdownSelect'          => __( 'Dropdown Select', 'ffc' ),
                'checkbox'                => __( 'Checkbox', 'ffc' ),
                'radioButtons'            => __( 'Radio Buttons', 'ffc' ),
                'date'                    => __( 'Date', 'ffc' ),

                // Template Manager
                'selectTemplate'          => __( 'Select a Template', 'ffc' ),
                'cancel'                  => __( 'Cancel', 'ffc' ),
                'loadingTemplate'         => __( 'Loading template...', 'ffc' ),
                'templateLoadedSuccess'   => __( 'Template "%s" loaded successfully!', 'ffc' ),
                'templateFileNotFound'    => __( 'Template file not found. Check if file exists in html/ folder.', 'ffc' ),
                'accessDenied'            => __( 'Access denied. Check file permissions.', 'ffc' ),
                'networkError'            => __( 'Network error. Check your connection.', 'ffc' ),
                'errorLoadingTemplate'    => __( 'Error loading template: %s', 'ffc' ),
                'chooseBackgroundImage'   => __( 'Choose Background Image', 'ffc' ),
                'useThisImage'            => __( 'Use this image', 'ffc' ),
                'htmlFieldNotFound'       => __( 'HTML field not found.', 'ffc' ),
                'selectHtmlFile'          => __( 'Please select an HTML file.', 'ffc' ),
                'htmlImportedSuccess'     => __( 'HTML imported successfully!', 'ffc' ),
                'htmlTextareaNotFound'    => __( 'Error: HTML textarea not found', 'ffc' ),
                'wpMediaNotAvailable'     => __( 'WordPress Media Library is not available. Please reload the page.', 'ffc' ),
                'backgroundImageSelected' => __( 'Background image selected!', 'ffc' ),
            )
        );
    }
}
