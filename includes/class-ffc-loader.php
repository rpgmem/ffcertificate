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
use FreeFormCertificate\UserDashboard\UserCleanup;
use FreeFormCertificate\SelfScheduling\SelfSchedulingCPT;
use FreeFormCertificate\SelfScheduling\SelfSchedulingAdmin;
use FreeFormCertificate\SelfScheduling\SelfSchedulingEditor;
use FreeFormCertificate\SelfScheduling\AppointmentHandler;
use FreeFormCertificate\SelfScheduling\AppointmentAjaxHandler;
use FreeFormCertificate\SelfScheduling\AppointmentEmailHandler;
use FreeFormCertificate\SelfScheduling\AppointmentReceiptHandler;
use FreeFormCertificate\SelfScheduling\AppointmentCsvExporter;
use FreeFormCertificate\SelfScheduling\SelfSchedulingShortcode;
use FreeFormCertificate\Audience\AudienceLoader;
use FreeFormCertificate\Core\ActivityLogSubscriber;

if (!defined('ABSPATH')) exit;

class Loader {

    protected $submission_handler;
    protected $email_handler;
    protected $csv_exporter;
    protected $cpt;
    protected $admin;
    protected $frontend;
    protected $admin_ajax;
    protected $self_scheduling_cpt;
    protected $self_scheduling_admin;
    protected $self_scheduling_editor;
    protected $self_scheduling_appointment_handler;
    protected $self_scheduling_email_handler;
    protected $self_scheduling_receipt_handler;
    protected $self_scheduling_csv_exporter;
    protected $self_scheduling_shortcode;
    protected $audience_loader;

    public function __construct() {
        add_action('plugins_loaded', [$this, 'init_plugin'], 10);
        add_action('wp_enqueue_scripts', [$this, 'register_frontend_assets']);
        $this->define_activation_hooks();
    }

    public function init_plugin(): void {
        if (class_exists('\FreeFormCertificate\SelfScheduling\SelfSchedulingActivator')) {
            \FreeFormCertificate\SelfScheduling\SelfSchedulingActivator::maybe_migrate();
        }
        if (class_exists('\FreeFormCertificate\Audience\AudienceActivator')) {
            \FreeFormCertificate\Audience\AudienceActivator::maybe_migrate();
        }

        // Shared classes (needed in both admin and frontend contexts)
        $this->submission_handler = new SubmissionHandler();
        $this->email_handler      = new EmailHandler();
        $this->cpt                = new CPT();

        // Admin-only classes skipped on frontend
        if ( is_admin() ) {
            $this->csv_exporter   = new CsvExporter();
            $this->admin          = new Admin($this->submission_handler, $this->csv_exporter, $this->email_handler);
            $this->admin_ajax     = new AdminAjax();
            AdminUserColumns::init();
            AdminUserCapabilities::init();
            $this->self_scheduling_admin    = new SelfSchedulingAdmin();
            $this->self_scheduling_editor   = new SelfSchedulingEditor();
            $this->self_scheduling_csv_exporter = new AppointmentCsvExporter();
        }

        // Frontend + AJAX classes
        $this->frontend           = new Frontend($this->submission_handler, $this->email_handler);

        DashboardShortcode::init();
        AccessControl::init();
        UserCleanup::init();

        $this->self_scheduling_cpt              = new SelfSchedulingCPT();
        $this->self_scheduling_appointment_handler = new AppointmentHandler();
        new AppointmentAjaxHandler( $this->self_scheduling_appointment_handler );
        $this->self_scheduling_email_handler    = new AppointmentEmailHandler();
        $this->self_scheduling_receipt_handler  = new AppointmentReceiptHandler();
        $this->self_scheduling_shortcode        = new SelfSchedulingShortcode();

        $this->audience_loader = AudienceLoader::get_instance();
        $this->audience_loader->init();

        new ActivityLogSubscriber();

        // Ensure daily cleanup cron is scheduled
        if ( ! wp_next_scheduled( 'ffcertificate_daily_cleanup_hook' ) ) {
            wp_schedule_event( time(), 'daily', 'ffcertificate_daily_cleanup_hook' );
        }

        $this->define_admin_hooks();
        $this->init_rest_api();
    }

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
        register_activation_hook(FFC_PLUGIN_DIR . 'ffcertificate.php', ['\\FreeFormCertificate\Activator', 'activate']);
        register_deactivation_hook(FFC_PLUGIN_DIR . 'ffcertificate.php', ['\\FreeFormCertificate\Deactivator', 'deactivate']);
    }

    private function define_admin_hooks(): void {
        add_action('ffcertificate_daily_cleanup_hook', [$this->submission_handler, 'run_data_cleanup']);
    }
    
    /**
     * Register frontend assets (scripts used as dependencies by shortcodes).
     * Only registers -- actual enqueue happens when shortcodes load their dependencies.
     */
    public function register_frontend_assets(): void {
        $s = \FreeFormCertificate\Core\Utils::asset_suffix();
        wp_register_script('ffc-rate-limit', FFC_PLUGIN_URL . "assets/js/ffc-frontend-helpers{$s}.js", ['jquery'], FFC_VERSION, true);
    }
}