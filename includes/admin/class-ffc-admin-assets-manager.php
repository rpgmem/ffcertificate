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
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Page routing check, no nonce needed.
        $is_ffc_menu = ( isset( $_GET['page'] ) && strpos( sanitize_text_field( wp_unslash( $_GET['page'] ) ), 'ffc-' ) !== false );

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
        // Settings page styles + scripts
        if ( $this->is_settings_page() ) {
            wp_enqueue_style(
                'ffc-admin-settings',
                FFC_PLUGIN_URL . 'assets/css/ffc-admin-settings.css',
                array( 'ffc-admin-css' ),
                FFC_VERSION
            );

            wp_enqueue_script(
                'ffc-admin-migrations',
                FFC_PLUGIN_URL . 'assets/js/ffc-admin-migrations.js',
                array( 'jquery' ),
                FFC_VERSION,
                true
            );

            wp_localize_script( 'ffc-admin-migrations', 'ffcMigrations', array(
                'strings' => array(
                    'processing'        => __( 'Processing...', 'ffcertificate' ),
                    'complete'          => __( 'Complete', 'ffcertificate' ),
                    'processed'         => __( 'Processed ', 'ffcertificate' ),
                    'records'           => __( 'records...', 'ffcertificate' ),
                    'migrationComplete' => __( 'Migration Complete', 'ffcertificate' ),
                    'allRecordsMigrated' => __( 'All records have been successfully migrated.', 'ffcertificate' ),
                    'errorOccurred'     => __( 'Error occurred. Please try again.', 'ffcertificate' ),
                ),
            ) );
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
                'copied_text' => __( 'Copied!', 'ffcertificate' ),
                'search_min_chars' => __( 'Please enter at least 2 characters.', 'ffcertificate' ),
                'no_users_found' => __( 'No users found.', 'ffcertificate' ),
                'search_error' => __( 'Error searching for users. Please try again.', 'ffcertificate' ),
                'clear_selection' => __( 'Clear', 'ffcertificate' ),
            )
        );
    }

    /**
     * Check if current page is settings page
     *
     * @return bool
     */
    private function is_settings_page(): bool {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Page routing check for asset loading, no data processing.
        if ( ! isset( $_GET['page'] ) ) {
            return false;
        }
        $page = sanitize_key( wp_unslash( $_GET['page'] ) );
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        return $page === 'ffc-settings' || $page === 'ffc-scheduling-settings';
    }

    /**
     * Check if current page is submission edit page
     *
     * @return bool
     */
    private function is_submission_edit_page(): bool {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Page routing check for asset loading.
        return isset( $_GET['page'] )
            && sanitize_key( wp_unslash( $_GET['page'] ) ) === 'ffc-submissions'
            && isset( $_GET['action'] )
            && sanitize_key( wp_unslash( $_GET['action'] ) ) === 'edit';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
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
                'generating'              => __( 'Generating...', 'ffcertificate' ),
                'error'                   => __( 'Error: ', 'ffcertificate' ),
                'connectionError'         => __( 'Connection error.', 'ffcertificate' ),
                'fileImported'            => __( 'File imported successfully!', 'ffcertificate' ),
                'errorReadingFile'        => __( 'Error reading file.', 'ffcertificate' ),
                'selectTemplate'          => __( 'Please select a template.', 'ffcertificate' ),
                'confirmReplaceContent'   => __( 'This will replace current content. Continue?', 'ffcertificate' ),
                'loading'                 => __( 'Loading...', 'ffcertificate' ),
                'templateLoaded'          => __( 'Template loaded successfully!', 'ffcertificate' ),
                'selectBackgroundImage'   => __( 'Select Background Image', 'ffcertificate' ),
                'useImage'                => __( 'Use this image', 'ffcertificate' ),
                'codesGenerated'          => __( 'codes generated', 'ffcertificate' ),
                'errorGeneratingCodes'    => __( 'Error generating codes.', 'ffcertificate' ),
                'confirmDeleteField'      => __( 'Remove this field?', 'ffcertificate' ),
                'pdfLibrariesFailed'      => __( 'PDF libraries failed to load. Please refresh the page.', 'ffcertificate' ),
                'pdfGenerationError'      => __( 'Error generating PDF. Please try again.', 'ffcertificate' ),
                'pleaseWait'              => __( 'Please wait, this may take a few seconds...', 'ffcertificate' ),
                'downloadAgain'           => __( 'Download Again', 'ffcertificate' ),
                'verifying'               => __( 'Verifying...', 'ffcertificate' ),
                'processing'              => __( 'Processing...', 'ffcertificate' ),
                'generatingPdf'           => __( 'Generating PDF...', 'ffcertificate' ),
                'pdfContainerNotFound'    => __( 'Error: PDF container not found', 'ffcertificate' ),
                'errorGeneratingPdf'      => __( 'Error generating PDF', 'ffcertificate' ),
                'html2canvasFailed'       => __( 'Error: html2canvas failed', 'ffcertificate' ),
                /* translators: %s: value */
                'confirmLoadTemplate'     => __( 'Load "%s"? This will replace your current certificate HTML.', 'ffcertificate' ),
                'dismiss'                 => __( 'Dismiss', 'ffcertificate' ),

                // Ticket Generation
                'enterValidNumber'        => __( 'Please enter a valid number.', 'ffcertificate' ),
                'generatingTickets'       => __( 'Generating tickets...', 'ffcertificate' ),
                'ticketsGeneratedSuccess' => __( 'tickets generated successfully!', 'ffcertificate' ),
                'codesFieldNotFound'      => __( 'Error: codes field not found', 'ffcertificate' ),
                'permissionDenied'        => __( 'Permission denied. Please reload the page.', 'ffcertificate' ),
                'badRequest'              => __( 'Bad request. Check console.', 'ffcertificate' ),
                /* translators: %d: number */
                'serverError'             => __( 'Server error (Status: %d)', 'ffcertificate' ),

                // Field Builder
                'chooseFieldType'         => __( 'Choose Field Type:', 'ffcertificate' ),
                'remove'                  => __( 'Remove', 'ffcertificate' ),
                'fieldType'               => __( 'Field Type:', 'ffcertificate' ),
                'label'                   => __( 'Label:', 'ffcertificate' ),
                'fieldLabel'              => __( 'Field Label', 'ffcertificate' ),
                'nameVariable'            => __( 'Name (variable):', 'ffcertificate' ),
                'fieldName'               => __( 'field_name', 'ffcertificate' ),
                'required'                => __( 'Required:', 'ffcertificate' ),
                'options'                 => __( 'Options:', 'ffcertificate' ),
                'separateWithCommas'      => __( 'Separate with commas', 'ffcertificate' ),

                // Field Types
                'textField'               => __( 'Text Field', 'ffcertificate' ),
                'email'                   => __( 'Email', 'ffcertificate' ),
                'number'                  => __( 'Number', 'ffcertificate' ),
                'textarea'                => __( 'Textarea', 'ffcertificate' ),
                'dropdownSelect'          => __( 'Dropdown Select', 'ffcertificate' ),
                'checkbox'                => __( 'Checkbox', 'ffcertificate' ),
                'radioButtons'            => __( 'Radio Buttons', 'ffcertificate' ),
                'date'                    => __( 'Date', 'ffcertificate' ),

                // Template Manager
                'selectTemplate'          => __( 'Select a Template', 'ffcertificate' ),
                'cancel'                  => __( 'Cancel', 'ffcertificate' ),
                'loadingTemplate'         => __( 'Loading template...', 'ffcertificate' ),
                /* translators: %s: error message, %s: error message */
                'templateLoadedSuccess'   => __( 'Template "%s" loaded successfully!', 'ffcertificate' ),
                'templateFileNotFound'    => __( 'Template file not found. Check if file exists in html/ folder.', 'ffcertificate' ),
                'accessDenied'            => __( 'Access denied. Check file permissions.', 'ffcertificate' ),
                'networkError'            => __( 'Network error. Check your connection.', 'ffcertificate' ),
                /* translators: %s: error message */
                'errorLoadingTemplate'    => __( 'Error loading template: %s', 'ffcertificate' ),
                'chooseBackgroundImage'   => __( 'Choose Background Image', 'ffcertificate' ),
                'useThisImage'            => __( 'Use this image', 'ffcertificate' ),
                'htmlFieldNotFound'       => __( 'HTML field not found.', 'ffcertificate' ),
                'selectHtmlFile'          => __( 'Please select an HTML file.', 'ffcertificate' ),
                'htmlImportedSuccess'     => __( 'HTML imported successfully!', 'ffcertificate' ),
                'htmlTextareaNotFound'    => __( 'Error: HTML textarea not found', 'ffcertificate' ),
                'wpMediaNotAvailable'     => __( 'WordPress Media Library is not available. Please reload the page.', 'ffcertificate' ),
                'backgroundImageSelected' => __( 'Background image selected!', 'ffcertificate' ),

                // Certificate Preview
                'previewTitle'            => __( 'Certificate Preview', 'ffcertificate' ),
                'previewEmpty'            => __( 'The HTML editor is empty. Add a template first.', 'ffcertificate' ),
                'previewSampleNote'       => __( 'Placeholders replaced with sample data. QR code shown as placeholder.', 'ffcertificate' ),
                'close'                   => __( 'Close', 'ffcertificate' ),
            )
        );
    }
}
