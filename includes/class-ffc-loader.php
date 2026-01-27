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
use FreeFormCertificate\Frontend\Frontend;
use FreeFormCertificate\Admin\AdminAjax;
use FreeFormCertificate\API\RestController;
use FreeFormCertificate\Shortcodes\DashboardShortcode;
use FreeFormCertificate\UserDashboard\AccessControl;
use FreeFormCertificate\Calendars\CalendarCPT;
use FreeFormCertificate\Calendars\CalendarAdmin;
use FreeFormCertificate\Calendars\CalendarEditor;
use FreeFormCertificate\Calendars\AppointmentHandler;
use FreeFormCertificate\Calendars\AppointmentEmailHandler;
use FreeFormCertificate\Calendars\AppointmentCsvExporter;
use FreeFormCertificate\Calendars\CalendarShortcode;

if (!defined('ABSPATH')) exit;

class Loader {

    protected $submission_handler = null;
    protected $email_handler = null;
    protected $csv_exporter = null;
    protected $cpt = null;
    protected $admin = null;
    protected $frontend = null;
    protected $admin_ajax = null;
    protected $calendar_cpt = null;
    protected $calendar_admin = null;
    protected $calendar_editor = null;
    protected $appointment_handler = null;
    protected $appointment_email_handler = null;
    protected $appointment_csv_exporter = null;
    protected $calendar_shortcode = null;

    public function __construct() {
        // Let WordPress load textdomain automatically (just-in-time in WP 6.7+)
        // No manual loading needed
        
        add_action('plugins_loaded', [$this, 'init_plugin'], 10);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_rate_limit_assets']);
        $this->define_activation_hooks();
    }

    public function init_plugin(): void {
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

        // ✅ v3.1.0: Initialize Dashboard Shortcode ([user_dashboard_personal])
        DashboardShortcode::init();

        // ✅ v3.1.0: Initialize Access Control (blocks wp-admin for configured roles)
        AccessControl::init();

        // ✅ v4.1.0: Initialize Calendar System
        $this->calendar_cpt              = new CalendarCPT();
        $this->calendar_admin            = new CalendarAdmin();
        $this->calendar_editor           = new CalendarEditor();
        $this->appointment_handler       = new AppointmentHandler();
        $this->appointment_email_handler = new AppointmentEmailHandler();
        $this->appointment_csv_exporter  = new AppointmentCsvExporter();
        $this->calendar_shortcode        = new CalendarShortcode();

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
    
    public function enqueue_rate_limit_assets(): void {
        wp_enqueue_script('ffc-rate-limit', FFC_PLUGIN_URL . 'assets/js/ffc-frontend-helpers.js', ['jquery'], FFC_VERSION, true);
        // ✅ v3.1.0: Rate limit styles consolidated into ffc-admin-settings.css
        wp_enqueue_style('ffc-admin-settings', FFC_PLUGIN_URL . 'assets/css/ffc-admin-settings.css', [], FFC_VERSION);
    }
    
    // For future use
    public function run(): void {}
}