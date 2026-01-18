<?php
/**
 * Free_Form_Certificate_Loader v3.0.0
 * Fixed textdomain loading + REST API integration
 */

if (!defined('ABSPATH')) exit;

class Free_Form_Certificate_Loader {

    protected $submission_handler;
    protected $email_handler;
    protected $csv_exporter;
    protected $cpt;
    protected $admin;
    protected $frontend;
    protected $admin_ajax;

    public function __construct() {
        // Let WordPress load textdomain automatically (just-in-time in WP 6.7+)
        // No manual loading needed
        
        add_action('plugins_loaded', [$this, 'init_plugin'], 10);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_rate_limit_assets']);
        $this->define_activation_hooks();
    }

    public function init_plugin() {
        $this->load_dependencies();
        $this->submission_handler = new FFC_Submission_Handler();
        $this->email_handler      = new FFC_Email_Handler();
        $this->csv_exporter       = new FFC_CSV_Exporter();
        $this->cpt                = new FFC_CPT();
        $this->admin              = new FFC_Admin($this->submission_handler, $this->csv_exporter, $this->email_handler);
        $this->frontend           = new FFC_Frontend($this->submission_handler, $this->email_handler);
        $this->admin_ajax         = new FFC_Admin_Ajax();
        $this->define_admin_hooks();
        $this->init_rest_api(); // Initialize REST API
    }

    private function load_dependencies() {
        // Core utilities
        require_once FFC_PLUGIN_DIR . 'includes/core/class-ffc-utils.php';
        require_once FFC_PLUGIN_DIR . 'includes/core/class-ffc-encryption.php';
        require_once FFC_PLUGIN_DIR . 'includes/class-ffc-activator.php';
        require_once FFC_PLUGIN_DIR . 'includes/class-ffc-deactivator.php';

        // Repositories (v3.0.0)
        require_once FFC_PLUGIN_DIR . 'includes/repositories/abstract-repository.php';
        require_once FFC_PLUGIN_DIR . 'includes/repositories/submission-repository.php';
        require_once FFC_PLUGIN_DIR . 'includes/repositories/form-repository.php';

        // Migrations
        require_once FFC_PLUGIN_DIR . 'includes/migrations/class-ffc-migration-manager.php';
        require_once FFC_PLUGIN_DIR . 'includes/migrations/class-ffc-migration-user-link.php';

        // Integrations
        require_once FFC_PLUGIN_DIR . 'includes/integrations/class-ffc-ip-geolocation.php';
        require_once FFC_PLUGIN_DIR . 'includes/integrations/class-ffc-email-handler.php';

        // Security
        require_once FFC_PLUGIN_DIR . 'includes/security/class-ffc-geofence.php';
        require_once FFC_PLUGIN_DIR . 'includes/security/class-ffc-rate-limiter.php';

        // Generators
        require_once FFC_PLUGIN_DIR . 'includes/generators/class-ffc-magic-link-helper.php';
        require_once FFC_PLUGIN_DIR . 'includes/generators/class-ffc-qrcode-generator.php';
        require_once FFC_PLUGIN_DIR . 'includes/generators/class-ffc-pdf-generator.php';

        // User Dashboard (v3.1.0)
        require_once FFC_PLUGIN_DIR . 'includes/user-dashboard/class-ffc-user-manager.php';
        require_once FFC_PLUGIN_DIR . 'includes/user-dashboard/class-ffc-access-control.php';
        require_once FFC_PLUGIN_DIR . 'includes/shortcodes/class-ffc-dashboard-shortcode.php';
        require_once FFC_PLUGIN_DIR . 'includes/admin/class-ffc-admin-user-columns.php';

        // Settings system (load BEFORE admin classes)
        if (file_exists(FFC_PLUGIN_DIR . 'includes/settings/abstract-ffc-settings-tab.php')) {
            require_once FFC_PLUGIN_DIR . 'includes/settings/abstract-ffc-settings-tab.php';
        }

        // Submissions
        require_once FFC_PLUGIN_DIR . 'includes/submissions/class-ffc-submission-handler.php';

        // Admin
        require_once FFC_PLUGIN_DIR . 'includes/admin/class-ffc-submissions-list-table.php';
        require_once FFC_PLUGIN_DIR . 'includes/admin/class-ffc-admin-ajax.php';
        require_once FFC_PLUGIN_DIR . 'includes/admin/class-ffc-csv-exporter.php';
        require_once FFC_PLUGIN_DIR . 'includes/admin/class-ffc-cpt.php';
        require_once FFC_PLUGIN_DIR . 'includes/admin/class-ffc-form-editor.php';
        require_once FFC_PLUGIN_DIR . 'includes/admin/class-ffc-settings.php';
        require_once FFC_PLUGIN_DIR . 'includes/admin/class-ffc-admin.php';

        // Frontend
        require_once FFC_PLUGIN_DIR . 'includes/frontend/class-ffc-form-processor.php';
        require_once FFC_PLUGIN_DIR . 'includes/frontend/class-ffc-frontend.php';

        // REST API Controller (v3.0.0)
        if (file_exists(FFC_PLUGIN_DIR . 'includes/api/class-ffc-rest-controller.php')) {
            require_once FFC_PLUGIN_DIR . 'includes/api/class-ffc-rest-controller.php';
        }
    }

    /**
     * Initialize REST API
     * 
     * @since 3.0.0
     */
    private function init_rest_api() {
        if (class_exists('FFC_REST_Controller')) {
            new FFC_REST_Controller();
        }
    }

    private function define_activation_hooks() {
        require_once FFC_PLUGIN_DIR . 'includes/class-ffc-activator.php';
        require_once FFC_PLUGIN_DIR . 'includes/class-ffc-deactivator.php';
        register_activation_hook(FFC_PLUGIN_DIR . 'wp-ffcertificate.php', ['FFC_Activator', 'activate']);
        register_deactivation_hook(FFC_PLUGIN_DIR . 'wp-ffcertificate.php', ['FFC_Deactivator', 'deactivate']);
    }

    private function define_admin_hooks() {
        add_action('ffc_daily_cleanup_hook', [$this->submission_handler, 'run_data_cleanup']);
    }
    
    public function enqueue_rate_limit_assets() {
        wp_enqueue_script('ffc-rate-limit', FFC_PLUGIN_URL . 'assets/js/ffc-frontend-helpers.js', ['jquery'], FFC_VERSION, true);
        wp_enqueue_style('ffc-rate-limit', FFC_PLUGIN_URL . 'assets/css/admin-rate-limit.css', [], FFC_VERSION);
    }
    
    // For future use
    public function run() {}
}