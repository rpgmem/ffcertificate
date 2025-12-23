<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Main Loader class responsible for orchestrating dependencies and hooks.
 */
class Free_Form_Certificate_Loader {

    protected $submission_handler;
    protected $cpt;
    protected $admin;
    protected $frontend;

    /**
     * Initialize the class and set up core hooks.
     */
    public function __construct() {
        $this->load_dependencies();
        $this->define_activation_hooks();
        $this->define_admin_hooks();
        
        // Load plugin localization
        add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
    }

    /**
     * Entry point for future execution, if needed.
     */
    public function run() {
        // Reserved for future initialization logic.
    }

    /**
     * Load required files and instantiate classes.
     */
    private function load_dependencies() {
        require_once FFC_PLUGIN_DIR . 'includes/class-ffc-activator.php';
        require_once FFC_PLUGIN_DIR . 'includes/class-ffc-deactivator.php';
        require_once FFC_PLUGIN_DIR . 'includes/class-ffc-submission-handler.php';
        require_once FFC_PLUGIN_DIR . 'includes/class-ffc-submissions-list-table.php';
        require_once FFC_PLUGIN_DIR . 'includes/class-ffc-cpt.php';
        require_once FFC_PLUGIN_DIR . 'includes/class-ffc-admin.php';
        require_once FFC_PLUGIN_DIR . 'includes/class-ffc-frontend.php';

        $this->submission_handler = new FFC_Submission_Handler();
        $this->cpt                = new FFC_CPT();
        $this->admin              = new FFC_Admin( $this->submission_handler );
        $this->frontend           = new FFC_Frontend( $this->submission_handler );
    }

    /**
     * Register activation and deactivation hooks.
     */
    private function define_activation_hooks() {
        // Adjust the main file path if necessary to point to the root plugin file
        register_activation_hook( FFC_PLUGIN_DIR . 'wp-ffcertificate.php', array( 'FFC_Activator', 'activate' ) );
        register_uninstall_hook( FFC_PLUGIN_DIR . 'wp-ffcertificate.php', array( 'FFC_Deactivator', 'uninstall_cleanup' ) );
    }

    /**
     * Define background tasks and internal hooks.
     */
    private function define_admin_hooks() {
        add_action( 'ffc_daily_cleanup_hook', array( $this->submission_handler, 'run_data_cleanup' ) );
        add_action( 'ffc_process_submission_hook', array( $this->submission_handler, 'async_process_submission' ), 10, 7 );
    }

    /**
     * Load the plugin text domain for translations.
     */
    public function load_textdomain() {
        load_plugin_textdomain( 
            'ffc', 
            false, 
            dirname( plugin_basename( FFC_PLUGIN_DIR . 'wp-ffcertificate.php' ) ) . '/languages' 
        );
    }
}