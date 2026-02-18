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
use FreeFormCertificate\Privacy\PrivacyHandler;
use FreeFormCertificate\Admin\AdminUserCustomFields;
use FreeFormCertificate\Core\ActivityLogSubscriber;
use FreeFormCertificate\Reregistration\ReregistrationAdmin;
use FreeFormCertificate\Reregistration\ReregistrationFrontend;
use FreeFormCertificate\Reregistration\ReregistrationRepository;
use FreeFormCertificate\Reregistration\ReregistrationEmailHandler;

if (!defined('ABSPATH')) exit;

class Loader {

    /** @var \FreeFormCertificate\Submissions\SubmissionHandler */
    protected $submission_handler;
    /** @var \FreeFormCertificate\Integrations\EmailHandler */
    protected $email_handler;
    /** @var \FreeFormCertificate\Admin\CsvExporter|null */
    protected $csv_exporter;
    /** @var \FreeFormCertificate\Admin\CPT */
    protected $cpt;
    /** @var \FreeFormCertificate\Admin\Admin|null */
    protected $admin;
    /** @var \FreeFormCertificate\Frontend\Frontend */
    protected $frontend;
    /** @var \FreeFormCertificate\Admin\AdminAjax|null */
    protected $admin_ajax;
    /** @var \FreeFormCertificate\SelfScheduling\SelfSchedulingCPT */
    protected $self_scheduling_cpt;
    /** @var \FreeFormCertificate\SelfScheduling\SelfSchedulingAdmin|null */
    protected $self_scheduling_admin;
    /** @var \FreeFormCertificate\SelfScheduling\SelfSchedulingEditor|null */
    protected $self_scheduling_editor;
    /** @var \FreeFormCertificate\SelfScheduling\AppointmentHandler */
    protected $self_scheduling_appointment_handler;
    /** @var \FreeFormCertificate\SelfScheduling\AppointmentEmailHandler */
    protected $self_scheduling_email_handler;
    /** @var \FreeFormCertificate\SelfScheduling\AppointmentReceiptHandler */
    protected $self_scheduling_receipt_handler;
    /** @var \FreeFormCertificate\SelfScheduling\AppointmentCsvExporter|null */
    protected $self_scheduling_csv_exporter;
    /** @var \FreeFormCertificate\SelfScheduling\SelfSchedulingShortcode */
    protected $self_scheduling_shortcode;
    /** @var \FreeFormCertificate\Audience\AudienceLoader */
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
            AdminUserCustomFields::init();
            $reregistration_admin = new ReregistrationAdmin();
            $reregistration_admin->init();
            $this->self_scheduling_admin    = new SelfSchedulingAdmin();
            $this->self_scheduling_editor   = new SelfSchedulingEditor();
            $this->self_scheduling_csv_exporter = new AppointmentCsvExporter();
        }

        // Frontend + AJAX classes
        $this->frontend           = new Frontend($this->submission_handler, $this->email_handler);

        DashboardShortcode::init();
        ReregistrationFrontend::init();
        AccessControl::init();
        UserCleanup::init();
        PrivacyHandler::init();

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

        // Ensure reregistration expiry cron is scheduled
        if ( ! wp_next_scheduled( 'ffcertificate_reregistration_expire_hook' ) ) {
            wp_schedule_event( time(), 'daily', 'ffcertificate_reregistration_expire_hook' );
        }

        $this->ensure_admin_capabilities();
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

    /**
     * Ensure admin-level FFC capabilities are granted to the administrator role.
     *
     * Capabilities added in updates (e.g. ffc_manage_reregistration) are only
     * granted during plugin activation.  For sites that update without
     * reactivating, this one-time check fills the gap.
     *
     * @since 4.11.1
     */
    private function ensure_admin_capabilities(): void {
        // v2: added cleanup of user-level false overrides for admin users.
        $version_key = 'ffc_admin_caps_version_v2';
        $current     = get_option( $version_key, '' );

        if ( $current === FFC_VERSION ) {
            return;
        }

        $admin_role = get_role( 'administrator' );
        if ( $admin_role && class_exists( '\FreeFormCertificate\UserDashboard\UserManager' ) ) {
            $all_ffc_caps = \FreeFormCertificate\UserDashboard\UserManager::get_all_capabilities();

            // 1. Grant admin-level capabilities to the administrator role.
            foreach ( \FreeFormCertificate\UserDashboard\UserManager::ADMIN_CAPABILITIES as $cap ) {
                if ( ! $admin_role->has_cap( $cap ) ) {
                    $admin_role->add_cap( $cap, true );
                }
            }

            // 2. Clean up user-level overrides for admin users.
            //    A previous bug in save_capability_fields() used add_cap(false)
            //    which stored explicit denials in user_meta, overriding the role.
            $admins = get_users( array( 'role' => 'administrator', 'fields' => 'ID' ) );
            foreach ( $admins as $admin_id ) {
                $user = get_userdata( (int) $admin_id );
                if ( ! $user ) {
                    continue;
                }
                foreach ( $all_ffc_caps as $cap ) {
                    // Remove only explicit false values (user-level denials).
                    if ( isset( $user->caps[ $cap ] ) && ! $user->caps[ $cap ] ) {
                        $user->remove_cap( $cap );
                    }
                }
            }
        }

        update_option( $version_key, FFC_VERSION );
    }

    private function define_admin_hooks(): void {
        add_action('ffcertificate_daily_cleanup_hook', function() { $this->submission_handler->run_data_cleanup(); });
        add_action('ffcertificate_reregistration_expire_hook', array(ReregistrationRepository::class, 'expire_overdue'));
        add_action('ffcertificate_reregistration_expire_hook', array(ReregistrationEmailHandler::class, 'run_automated_reminders'));
    }
    
    /**
     * Register frontend assets (scripts used as dependencies by shortcodes).
     * Only registers -- actual enqueue happens when shortcodes load their dependencies.
     */
    public function register_frontend_assets(): void {
        $s = \FreeFormCertificate\Core\Utils::asset_suffix();
        wp_register_script('ffc-rate-limit', FFC_PLUGIN_URL . "assets/js/ffc-frontend-helpers{$s}.js", ['jquery'], FFC_VERSION, true);

        // Dynamic fragments: refresh captcha + nonces on cached pages (v4.12.0)
        wp_register_script( 'ffc-dynamic-fragments', FFC_PLUGIN_URL . "assets/js/ffc-dynamic-fragments{$s}.js", array(), FFC_VERSION, true );
        wp_localize_script( 'ffc-dynamic-fragments', 'ffcDynamic', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
        ) );
    }
}