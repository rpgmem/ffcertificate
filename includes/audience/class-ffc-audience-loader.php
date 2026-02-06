<?php
declare(strict_types=1);

/**
 * Audience Loader
 *
 * Initializes and loads all components of the audience booking system.
 *
 * @since 4.5.0
 * @package FreeFormCertificate\Audience
 */

namespace FreeFormCertificate\Audience;

if (!defined('ABSPATH')) {
    exit;
}

class AudienceLoader {

    /**
     * Singleton instance
     *
     * @var AudienceLoader|null
     */
    private static ?AudienceLoader $instance = null;

    /**
     * Admin page handler
     *
     * @var AudienceAdminPage|null
     */
    private ?AudienceAdminPage $admin_page = null;

    /**
     * Get singleton instance
     *
     * @return AudienceLoader
     */
    public static function get_instance(): AudienceLoader {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor for singleton
     */
    private function __construct() {
        // Empty
    }

    /**
     * Initialize the audience system
     *
     * @return void
     */
    public function init(): void {
        // Register hooks
        $this->register_hooks();

        // Initialize admin components if in admin
        if (is_admin()) {
            $this->init_admin();
        }

        // Initialize frontend components
        $this->init_frontend();

        // Initialize REST API
        $this->init_api();

        // Initialize notifications (email + ICS)
        $this->init_notifications();
    }

    /**
     * Register WordPress hooks
     *
     * @return void
     */
    private function register_hooks(): void {
        // Register custom capabilities
        add_action('init', array($this, 'register_capabilities'));

        // AJAX handlers
        add_action('wp_ajax_ffc_audience_check_conflicts', array($this, 'ajax_check_conflicts'));
        add_action('wp_ajax_ffc_audience_create_booking', array($this, 'ajax_create_booking'));
        add_action('wp_ajax_ffc_audience_cancel_booking', array($this, 'ajax_cancel_booking'));
        add_action('wp_ajax_ffc_audience_get_schedule_slots', array($this, 'ajax_get_schedule_slots'));
        add_action('wp_ajax_ffc_search_users', array($this, 'ajax_search_users'));
        add_action('wp_ajax_ffc_audience_get_environments', array($this, 'ajax_get_environments'));
    }

    /**
     * Register capabilities
     *
     * @return void
     */
    public function register_capabilities(): void {
        // Capabilities are added per-user via schedule permissions
        // This hook is for future global capability registration if needed
        do_action('ffc_audience_register_capabilities');
    }

    /**
     * Initialize admin components
     *
     * @return void
     */
    private function init_admin(): void {
        // Load admin page handler
        if (class_exists('\FreeFormCertificate\Audience\AudienceAdminPage')) {
            $this->admin_page = new AudienceAdminPage();
            $this->admin_page->init();
        }

        // Load admin assets
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
    }

    /**
     * Initialize frontend components
     *
     * @return void
     */
    private function init_frontend(): void {
        // Register shortcode
        if (class_exists('\FreeFormCertificate\Audience\AudienceShortcode')) {
            AudienceShortcode::init();
        }

        // Enqueue frontend assets
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
    }

    /**
     * Initialize REST API
     *
     * @return void
     */
    private function init_api(): void {
        add_action('rest_api_init', array($this, 'register_rest_routes'));
    }

    /**
     * Initialize notifications (email + ICS)
     *
     * @return void
     */
    private function init_notifications(): void {
        if (class_exists('\FreeFormCertificate\Audience\AudienceNotificationHandler')) {
            AudienceNotificationHandler::init();
        }
    }

    /**
     * Register REST API routes
     *
     * @return void
     */
    public function register_rest_routes(): void {
        if (class_exists('\FreeFormCertificate\Audience\AudienceRestController')) {
            $controller = new AudienceRestController();
            $controller->register_routes();
        }
    }

    /**
     * Enqueue admin assets
     *
     * @param string $hook Current admin page hook
     * @return void
     */
    public function enqueue_admin_assets(string $hook): void {
        // Only load on our admin pages
        if (strpos($hook, 'ffc-audience') === false && strpos($hook, 'ffc-scheduling') === false) {
            return;
        }

        // Admin CSS
        wp_enqueue_style(
            'ffc-audience-admin',
            FFC_PLUGIN_URL . 'assets/css/ffc-audience-admin.css',
            array(),
            FFC_VERSION
        );

        // Admin JS
        wp_enqueue_script(
            'ffc-audience-admin',
            FFC_PLUGIN_URL . 'assets/js/ffc-audience-admin.js',
            array('jquery', 'wp-util'),
            FFC_VERSION,
            true
        );

        // Localize script
        wp_localize_script('ffc-audience-admin', 'ffcAudienceAdmin', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'restUrl' => rest_url('ffc/v1/audience/'),
            'nonce' => wp_create_nonce('wp_rest'),
            'strings' => $this->get_admin_strings(),
        ));
    }

    /**
     * Enqueue frontend assets
     *
     * @return void
     */
    public function enqueue_frontend_assets(): void {
        // Only load when shortcode is present
        global $post;
        if (!$post || !has_shortcode($post->post_content, 'ffc_audience')) {
            return;
        }

        // Frontend CSS
        wp_enqueue_style(
            'ffc-common',
            FFC_PLUGIN_URL . 'assets/css/ffc-common.css',
            array(),
            FFC_VERSION
        );
        wp_enqueue_style(
            'ffc-audience',
            FFC_PLUGIN_URL . 'assets/css/ffc-audience.css',
            array('ffc-common'),
            FFC_VERSION
        );

        // Frontend JS
        wp_enqueue_script(
            'ffc-audience',
            FFC_PLUGIN_URL . 'assets/js/ffc-audience.js',
            array('jquery'),
            FFC_VERSION,
            true
        );
    }

    /**
     * Get admin translation strings
     *
     * @return array<string, string>
     */
    private function get_admin_strings(): array {
        return array(
            'confirmDelete' => __('Are you sure you want to delete this item?', 'wp-ffcertificate'),
            'confirmCancel' => __('Are you sure you want to cancel this booking?', 'wp-ffcertificate'),
            'saving' => __('Saving...', 'wp-ffcertificate'),
            'saved' => __('Saved!', 'wp-ffcertificate'),
            'error' => __('An error occurred. Please try again.', 'wp-ffcertificate'),
            'loading' => __('Loading...', 'wp-ffcertificate'),
            'noResults' => __('No results found.', 'wp-ffcertificate'),
            'selectAudience' => __('Select audience groups', 'wp-ffcertificate'),
            'selectUsers' => __('Select users', 'wp-ffcertificate'),
            'requiredField' => __('This field is required.', 'wp-ffcertificate'),
            'invalidTime' => __('End time must be after start time.', 'wp-ffcertificate'),
        );
    }


    /**
     * AJAX: Check for conflicts
     *
     * @return void
     */
    public function ajax_check_conflicts(): void {
        check_ajax_referer('wp_rest', 'nonce');

        if (!current_user_can('read')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'wp-ffcertificate')));
        }

        // Get parameters
        $environment_id = isset($_POST['environment_id']) ? absint($_POST['environment_id']) : 0;
        $booking_date = isset($_POST['booking_date']) ? sanitize_text_field(wp_unslash($_POST['booking_date'])) : '';
        $start_time = isset($_POST['start_time']) ? sanitize_text_field(wp_unslash($_POST['start_time'])) : '';
        $end_time = isset($_POST['end_time']) ? sanitize_text_field(wp_unslash($_POST['end_time'])) : '';
        $audience_ids = isset($_POST['audience_ids']) ? array_map('absint', (array) $_POST['audience_ids']) : array();
        $user_ids = isset($_POST['user_ids']) ? array_map('absint', (array) $_POST['user_ids']) : array();

        if (!$environment_id || !$booking_date || !$start_time || !$end_time) {
            wp_send_json_error(array('message' => __('Missing required parameters.', 'wp-ffcertificate')));
        }

        // Check conflicts using service
        if (class_exists('\FreeFormCertificate\Audience\AudienceConflictService')) {
            $service = new AudienceConflictService();
            $conflicts = $service->check_conflicts($environment_id, $booking_date, $start_time, $end_time, $audience_ids, $user_ids);
            wp_send_json_success(array('conflicts' => $conflicts));
        }

        wp_send_json_error(array('message' => __('Service not available.', 'wp-ffcertificate')));
    }

    /**
     * AJAX: Create booking
     *
     * @return void
     */
    public function ajax_create_booking(): void {
        check_ajax_referer('wp_rest', 'nonce');

        if (!current_user_can('read')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'wp-ffcertificate')));
        }

        // Booking creation is handled by AudienceBookingService
        // This is a placeholder - actual implementation in Phase 6
        wp_send_json_error(array('message' => __('Not implemented yet.', 'wp-ffcertificate')));
    }

    /**
     * AJAX: Cancel booking
     *
     * @return void
     */
    public function ajax_cancel_booking(): void {
        check_ajax_referer('wp_rest', 'nonce');

        if (!current_user_can('read')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'wp-ffcertificate')));
        }

        // Booking cancellation is handled by AudienceBookingService
        // This is a placeholder - actual implementation in Phase 6
        wp_send_json_error(array('message' => __('Not implemented yet.', 'wp-ffcertificate')));
    }

    /**
     * AJAX: Get schedule slots for a date range
     *
     * @return void
     */
    public function ajax_get_schedule_slots(): void {
        check_ajax_referer('wp_rest', 'nonce');

        if (!current_user_can('read')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'wp-ffcertificate')));
        }

        // Slot retrieval is handled by AudienceScheduleService
        // This is a placeholder - actual implementation in Phase 5
        wp_send_json_error(array('message' => __('Not implemented yet.', 'wp-ffcertificate')));
    }

    /**
     * AJAX: Search users for member selection
     *
     * @return void
     */
    public function ajax_search_users(): void {
        check_ajax_referer('ffc_search_users', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'wp-ffcertificate')));
        }

        $query = isset($_GET['query']) ? sanitize_text_field(wp_unslash($_GET['query'])) : '';

        if (strlen($query) < 2) {
            wp_send_json_success(array());
        }

        $users = get_users(array(
            'search' => '*' . $query . '*',
            'search_columns' => array('user_login', 'user_email', 'display_name'),
            'number' => 20,
            'orderby' => 'display_name',
        ));

        $results = array();
        foreach ($users as $user) {
            $results[] = array(
                'id' => $user->ID,
                'name' => $user->display_name,
                'email' => $user->user_email,
            );
        }

        wp_send_json_success($results);
    }

    /**
     * AJAX: Get environments by schedule ID
     *
     * @return void
     */
    public function ajax_get_environments(): void {
        check_ajax_referer('ffc_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'wp-ffcertificate')));
        }

        $schedule_id = isset($_GET['schedule_id']) ? absint($_GET['schedule_id']) : 0;

        if ($schedule_id <= 0) {
            wp_send_json_success(array());
        }

        $environments = AudienceEnvironmentRepository::get_by_schedule($schedule_id);

        $results = array();
        foreach ($environments as $env) {
            $results[] = array(
                'id' => $env->id,
                'name' => $env->name,
            );
        }

        wp_send_json_success($results);
    }
}
