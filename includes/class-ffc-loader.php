<?php
/**
 * Free_Form_Certificate_Loader
 * 
 * Loads all plugin dependencies in the correct order
 * 
 * ✅ v2.9.16: Added Migration Manager to load order
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Free_Form_Certificate_Loader {

    protected $submission_handler;
    protected $email_handler;
    protected $csv_exporter;
    protected $cpt;
    protected $admin;
    protected $frontend;
    protected $admin_ajax;

    public function __construct() {
        add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
        $this->load_dependencies();
        $this->define_activation_hooks();
        $this->define_admin_hooks();
    }

    public function run() {
        // Reserved
    }

    private function load_dependencies() {
        // ✅ Base - Load in correct order
        require_once FFC_PLUGIN_DIR . 'includes/class-ffc-utils.php';              // 1. Utils first (used by others)
        require_once FFC_PLUGIN_DIR . 'includes/class-ffc-migration-manager.php';  // 2. Migration Manager (used by Activator)
        require_once FFC_PLUGIN_DIR . 'includes/class-ffc-activator.php';          // 3. Activator (uses Migration Manager)
        require_once FFC_PLUGIN_DIR . 'includes/class-ffc-deactivator.php';        // 4. Deactivator
        require_once FFC_PLUGIN_DIR . 'includes/class-ffc-rate-limiter.php';       // 5. Rate Limiter (v2.9.16)
        require_once FFC_PLUGIN_DIR . 'includes/class-ffc-magic-link-helper.php';  // 6. Magic Link Helper (v2.9.16)
        
        // Data
        require_once FFC_PLUGIN_DIR . 'includes/class-ffc-submission-handler.php';
        require_once FFC_PLUGIN_DIR . 'includes/class-ffc-submissions-list-table.php';
        
        // Service
        require_once FFC_PLUGIN_DIR . 'includes/class-ffc-admin-ajax.php';
        require_once FFC_PLUGIN_DIR . 'includes/class-ffc-email-handler.php';
        require_once FFC_PLUGIN_DIR . 'includes/class-ffc-csv-exporter.php';
        require_once FFC_PLUGIN_DIR . 'includes/class-ffc-qrcode-generator.php';
        require_once FFC_PLUGIN_DIR . 'includes/class-ffc-pdf-generator.php';  // v2.9.2: Centralized PDF generation
        
        // Presentation
        require_once FFC_PLUGIN_DIR . 'includes/class-ffc-cpt.php';
        require_once FFC_PLUGIN_DIR . 'includes/class-ffc-form-editor.php';
        require_once FFC_PLUGIN_DIR . 'includes/class-ffc-settings.php';
        require_once FFC_PLUGIN_DIR . 'includes/class-ffc-admin.php';
        require_once FFC_PLUGIN_DIR . 'includes/class-ffc-frontend.php';

        // Instantiate
        $this->submission_handler = new FFC_Submission_Handler();
        $this->email_handler = new FFC_Email_Handler();
        $this->csv_exporter = new FFC_CSV_Exporter();
        $this->cpt = new FFC_CPT();
        $this->admin = new FFC_Admin( $this->submission_handler, $this->csv_exporter, $this->email_handler );
        $this->frontend = new FFC_Frontend( $this->submission_handler, $this->email_handler );
        $this->admin_ajax = new FFC_Admin_Ajax();
    }

    private function define_activation_hooks() {
        register_activation_hook( FFC_PLUGIN_DIR . 'wp-ffcertificate.php', array( 'FFC_Activator', 'activate' ) );
        register_deactivation_hook( FFC_PLUGIN_DIR . 'wp-ffcertificate.php', array( 'FFC_Deactivator', 'deactivate' ) );
    }

    private function define_admin_hooks() {
        add_action( 'ffc_daily_cleanup_hook', array( $this->submission_handler, 'run_data_cleanup' ) );
    }

    public function load_textdomain() {
        load_plugin_textdomain( 'ffc', false, dirname( plugin_basename( FFC_PLUGIN_DIR . 'wp-ffcertificate.php' ) ) . '/languages' );
    }
}