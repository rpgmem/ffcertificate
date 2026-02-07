<?php
declare(strict_types=1);

/**
 * Loader v3.0.0
 * Fixed textdomain loading + REST API integration
 *
 * @version 4.0.0 - Removed alias usage (Phase 4)
 * @version 3.3.0 - Added strict types and type hints
 * @version 3.2.0 - Migrated to namespace (Phase 2) - Removed require_once (autoloader handles)
 */

namespace FreeFormCertificate;

use FreeFormCertificate\Submissions\SubmissionHandler;
use FreeFormCertificate\Integrations\EmailHandler;
use FreeFormCertificate\Admin\CsvExporter;
use FreeFormCertificate\Admin\CPT;
use FreeFormCertificate\Admin\Admin;
use FreeFormCertificate\Admin\AdminUserColumns;
use FreeFormCertificate\Admin\AdminUserCapabilities;
use FreeFormCertificate\Frontend\Frontend;
use FreeFormCertificate\Admin\AdminAjax;
use FreeFormCertificate\API\RestController;
use FreeFormCertificate\Shortcodes\DashboardShortcode;
use FreeFormCertificate\UserDashboard\AccessControl;
// v4.5.0: Self-Scheduling System (renamed from Calendars)
use FreeFormCertificate\SelfScheduling\SelfSchedulingCPT;
use FreeFormCertificate\SelfScheduling\SelfSchedulingAdmin;
use FreeFormCertificate\SelfScheduling\SelfSchedulingEditor;
use FreeFormCertificate\SelfScheduling\AppointmentHandler;
use FreeFormCertificate\SelfScheduling\AppointmentEmailHandler;
use FreeFormCertificate\SelfScheduling\AppointmentReceiptHandler;
use FreeFormCertificate\SelfScheduling\AppointmentCsvExporter;
use FreeFormCertificate\SelfScheduling\SelfSchedulingShortcode;
// v4.5.0: Audience Scheduling System (new)
use FreeFormCertificate\Audience\AudienceLoader;

if (!defined('ABSPATH')) exit;

class Loader {

    protected $submission_handler = null;
    protected $email_handler = null;
    protected $csv_exporter = null;
    protected $cpt = null;
    protected $admin = null;
    protected $frontend = null;
    protected $admin_ajax = null;
    // v4.5.0: Self-Scheduling System (renamed from calendar_*)
    protected $self_scheduling_cpt = null;
    protected $self_scheduling_admin = null;
    protected $self_scheduling_editor = null;
    protected $self_scheduling_appointment_handler = null;
    protected $self_scheduling_email_handler = null;
    protected $self_scheduling_receipt_handler = null;
    protected $self_scheduling_csv_exporter = null;
    protected $self_scheduling_shortcode = null;
    // v4.5.0: Audience Scheduling System (new)
    protected $audience_loader = null;

    public function __construct() {
        add_action('plugins_loaded', [$this, 'init_plugin'], 10);
        add_action('wp_enqueue_scripts', [$this, 'register_frontend_assets']);
        $this->define_activation_hooks();
    }

    public function init_plugin(): void {
        // ✅ v4.5.0: Run self-scheduling migrations if needed
        if (class_exists('\FreeFormCertificate\SelfScheduling\SelfSchedulingActivator')) {
            \FreeFormCertificate\SelfScheduling\SelfSchedulingActivator::maybe_migrate();
        }

        // Autoloader handles all class loading now
        $this->submission_handler = new SubmissionHandler();
        $this->email_handler      = new EmailHandler();
        $this->csv_exporter       = new CsvExporter();
        $this->cpt                = new CPT();
        $this->admin              = new Admin($this->submission_handler, $this->csv_exporter, $this->email_handler);
        $this->frontend           = new Frontend($this->submission_handler, $this->email_handler);
        $this->admin_ajax         = new AdminAjax();

        // ✅ v3.1.0: Initialize Admin User Columns (adds "View Dashboard" link to users list)
        AdminUserColumns::init();

        // ✅ v4.4.0: Initialize Admin User Capabilities (FFC capability management on user profile)
        AdminUserCapabilities::init();

        // ✅ v3.1.0: Initialize Dashboard Shortcode ([user_dashboard_personal])
        DashboardShortcode::init();

        // ✅ v3.1.0: Initialize Access Control (blocks wp-admin for configured roles)
        AccessControl::init();

        // ✅ v4.5.0: Initialize Self-Scheduling System (renamed from Calendar System)
        $this->self_scheduling_cpt              = new SelfSchedulingCPT();
        $this->self_scheduling_admin            = new SelfSchedulingAdmin();
        $this->self_scheduling_editor           = new SelfSchedulingEditor();
        $this->self_scheduling_appointment_handler = new AppointmentHandler();
        $this->self_scheduling_email_handler    = new AppointmentEmailHandler();
        $this->self_scheduling_receipt_handler  = new AppointmentReceiptHandler();
        $this->self_scheduling_csv_exporter     = new AppointmentCsvExporter();
        $this->self_scheduling_shortcode        = new SelfSchedulingShortcode();

        // ✅ v4.5.0: Initialize Audience Scheduling System (new)
        $this->audience_loader = AudienceLoader::get_instance();
        $this->audience_loader->init();

        $this->define_admin_hooks();
        $this->init_rest_api(); // Initialize REST API
    }

    // Removed load_dependencies() - PSR-4 autoloader handles all class loading now

    /**
     * Initialize REST API
     *
     * @since 3.0.0
     */
    private function init_rest_api(): void {
        if (class_exists(RestController::class)) {
            new RestController();
        }
    }

    private function define_activation_hooks(): void {
        // Autoloader handles class loading
        register_activation_hook(FFC_PLUGIN_DIR . 'wp-ffcertificate.php', ['\\FreeFormCertificate\Activator', 'activate']);
        register_deactivation_hook(FFC_PLUGIN_DIR . 'wp-ffcertificate.php', ['\\FreeFormCertificate\Deactivator', 'deactivate']);
    }

    private function define_admin_hooks(): void {
        add_action('ffc_daily_cleanup_hook', [$this->submission_handler, 'run_data_cleanup']);
    }
    
    /**
     * Register frontend assets (scripts used as dependencies by shortcodes).
     * Only registers -- actual enqueue happens when shortcodes load their dependencies.
     */
    public function register_frontend_assets(): void {
        wp_register_script('ffc-rate-limit', FFC_PLUGIN_URL . 'assets/js/ffc-frontend-helpers.js', ['jquery'], FFC_VERSION, true);
    }

    /**
     * Run the plugin
     *
     * Kept for backwards compatibility — initialization happens via
     * plugins_loaded hook in constructor.
     */
    public function run(): void {}
}