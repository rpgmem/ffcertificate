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
        add_action('wp_ajax_ffc_audience_add_user_permission', array($this, 'ajax_add_user_permission'));
        add_action('wp_ajax_ffc_audience_update_user_permission', array($this, 'ajax_update_user_permission'));
        add_action('wp_ajax_ffc_audience_remove_user_permission', array($this, 'ajax_remove_user_permission'));
    }

    /**
     * Register capabilities
     *
     * @return void
     */
    public function register_capabilities(): void {
        // Capabilities are added per-user via schedule permissions
        // This hook is for future global capability registration if needed
        do_action('ffcertificate_audience_register_capabilities');
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

        $s = \FreeFormCertificate\Core\Utils::asset_suffix();

        // Admin CSS
        wp_enqueue_style(
            'ffc-audience-admin',
            FFC_PLUGIN_URL . "assets/css/ffc-audience-admin{$s}.css",
            array(),
            FFC_VERSION
        );

        // Admin JS
        wp_enqueue_script(
            'ffc-audience-admin',
            FFC_PLUGIN_URL . "assets/js/ffc-audience-admin{$s}.js",
            array('jquery', 'wp-util'),
            FFC_VERSION,
            true
        );

        // Localize script
        wp_localize_script('ffc-audience-admin', 'ffcAudienceAdmin', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'restUrl' => rest_url('ffc/v1/audience/'),
            'nonce' => wp_create_nonce('wp_rest'),
            'adminNonce' => wp_create_nonce('ffc_admin_nonce'),
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

        $s = \FreeFormCertificate\Core\Utils::asset_suffix();

        // Frontend CSS
        wp_enqueue_style(
            'ffc-common',
            FFC_PLUGIN_URL . "assets/css/ffc-common{$s}.css",
            array(),
            FFC_VERSION
        );
        wp_enqueue_style(
            'ffc-audience',
            FFC_PLUGIN_URL . "assets/css/ffc-audience{$s}.css",
            array('ffc-common'),
            FFC_VERSION
        );

        // Frontend JS
        wp_enqueue_script(
            'ffc-audience',
            FFC_PLUGIN_URL . "assets/js/ffc-audience{$s}.js",
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
            'confirmDelete' => __('Are you sure you want to delete this item?', 'ffcertificate'),
            'confirmCancel' => __('Are you sure you want to cancel this booking?', 'ffcertificate'),
            'saving' => __('Saving...', 'ffcertificate'),
            'saved' => __('Saved!', 'ffcertificate'),
            'error' => __('An error occurred. Please try again.', 'ffcertificate'),
            'loading' => __('Loading...', 'ffcertificate'),
            'noResults' => __('No results found.', 'ffcertificate'),
            'selectAudience' => __('Select audience groups', 'ffcertificate'),
            'selectUsers' => __('Select users', 'ffcertificate'),
            'requiredField' => __('This field is required.', 'ffcertificate'),
            'invalidTime' => __('End time must be after start time.', 'ffcertificate'),
            'allEnvironments' => __('All Environments', 'ffcertificate'),
            'environmentLabel' => __('Environment', 'ffcertificate'),
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
            wp_send_json_error(array('message' => __('Permission denied.', 'ffcertificate')));
        }

        // Get parameters
        $environment_id = isset($_POST['environment_id']) ? absint($_POST['environment_id']) : 0;
        $booking_date = isset($_POST['booking_date']) ? sanitize_text_field(wp_unslash($_POST['booking_date'])) : '';
        $start_time = isset($_POST['start_time']) ? sanitize_text_field(wp_unslash($_POST['start_time'])) : '';
        $end_time = isset($_POST['end_time']) ? sanitize_text_field(wp_unslash($_POST['end_time'])) : '';
        $audience_ids = isset($_POST['audience_ids']) ? array_map('absint', (array) $_POST['audience_ids']) : array();
        $user_ids = isset($_POST['user_ids']) ? array_map('absint', (array) $_POST['user_ids']) : array();

        if (!$environment_id || !$booking_date || !$start_time || !$end_time) {
            wp_send_json_error(array('message' => __('Missing required parameters.', 'ffcertificate')));
        }

        // Check conflicts using service
        if (class_exists('\FreeFormCertificate\Audience\AudienceConflictService')) {
            $service = new AudienceConflictService();
            $conflicts = $service->check_conflicts($environment_id, $booking_date, $start_time, $end_time, $audience_ids, $user_ids);
            wp_send_json_success(array('conflicts' => $conflicts));
        }

        wp_send_json_error(array('message' => __('Service not available.', 'ffcertificate')));
    }

    /**
     * AJAX: Create booking
     *
     * @return void
     */
    public function ajax_create_booking(): void {
        check_ajax_referer('wp_rest', 'nonce');

        if (!current_user_can('read')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'ffcertificate')));
        }

        // Booking creation is handled by AudienceBookingService
        // This is a placeholder - actual implementation in Phase 6
        wp_send_json_error(array('message' => __('Not implemented yet.', 'ffcertificate')));
    }

    /**
     * AJAX: Cancel booking
     *
     * @return void
     */
    public function ajax_cancel_booking(): void {
        check_ajax_referer('wp_rest', 'nonce');

        if (!current_user_can('read')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'ffcertificate')));
        }

        // Booking cancellation is handled by AudienceBookingService
        // This is a placeholder - actual implementation in Phase 6
        wp_send_json_error(array('message' => __('Not implemented yet.', 'ffcertificate')));
    }

    /**
     * AJAX: Get schedule slots for a date range
     *
     * @return void
     */
    public function ajax_get_schedule_slots(): void {
        check_ajax_referer('wp_rest', 'nonce');

        if (!current_user_can('read')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'ffcertificate')));
        }

        // Slot retrieval is handled by AudienceScheduleService
        // This is a placeholder - actual implementation in Phase 5
        wp_send_json_error(array('message' => __('Not implemented yet.', 'ffcertificate')));
    }

    /**
     * AJAX: Search users for member selection
     *
     * @return void
     */
    public function ajax_search_users(): void {
        check_ajax_referer('ffc_search_users', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'ffcertificate')));
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
            wp_send_json_error(array('message' => __('Permission denied.', 'ffcertificate')));
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

    /**
     * AJAX: Add user permission to a schedule
     *
     * @return void
     */
    public function ajax_add_user_permission(): void {
        check_ajax_referer('ffc_schedule_permissions', '_wpnonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'ffcertificate')));
        }

        $schedule_id = isset($_POST['schedule_id']) ? absint($_POST['schedule_id']) : 0;
        $user_id = isset($_POST['user_id']) ? absint($_POST['user_id']) : 0;

        if (!$schedule_id || !$user_id) {
            wp_send_json_error(array('message' => __('Missing required parameters.', 'ffcertificate')));
        }

        $schedule = AudienceScheduleRepository::get_by_id($schedule_id);
        if (!$schedule) {
            wp_send_json_error(array('message' => __('Calendar not found.', 'ffcertificate')));
        }

        $user = get_userdata($user_id);
        if (!$user) {
            wp_send_json_error(array('message' => __('User not found.', 'ffcertificate')));
        }

        $existing = AudienceScheduleRepository::get_user_permissions($schedule_id, $user_id);
        if ($existing) {
            wp_send_json_error(array('message' => __('User already has access to this calendar.', 'ffcertificate')));
        }

        $result = AudienceScheduleRepository::set_user_permissions($schedule_id, $user_id, array(
            'can_book' => 1,
            'can_cancel_others' => 0,
            'can_override_conflicts' => 0,
        ));

        if (!$result) {
            wp_send_json_error(array('message' => __('Error adding user permissions.', 'ffcertificate')));
        }

        ob_start();
        ?>
        <tr data-user-id="<?php echo esc_attr($user_id); ?>">
            <td>
                <strong><?php echo esc_html($user->display_name); ?></strong>
                <br><span class="description"><?php echo esc_html($user->user_email); ?></span>
            </td>
            <td>
                <input type="checkbox" class="ffc-perm-toggle" data-perm="can_book" checked>
            </td>
            <td>
                <input type="checkbox" class="ffc-perm-toggle" data-perm="can_cancel_others">
            </td>
            <td>
                <input type="checkbox" class="ffc-perm-toggle" data-perm="can_override_conflicts">
            </td>
            <td>
                <button type="button" class="button button-small button-link-delete ffc-remove-user-btn"><?php esc_html_e('Remove', 'ffcertificate'); ?></button>
            </td>
        </tr>
        <?php
        $html = ob_get_clean();

        wp_send_json_success(array('html' => $html));
    }

    /**
     * AJAX: Update a single user permission on a schedule
     *
     * @return void
     */
    public function ajax_update_user_permission(): void {
        check_ajax_referer('ffc_schedule_permissions', '_wpnonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'ffcertificate')));
        }

        $schedule_id = isset($_POST['schedule_id']) ? absint($_POST['schedule_id']) : 0;
        $user_id = isset($_POST['user_id']) ? absint($_POST['user_id']) : 0;
        $permission = isset($_POST['permission']) ? sanitize_text_field(wp_unslash($_POST['permission'])) : '';
        $value = isset($_POST['value']) ? absint($_POST['value']) : 0;

        if (!$schedule_id || !$user_id || !$permission) {
            wp_send_json_error(array('message' => __('Missing required parameters.', 'ffcertificate')));
        }

        $allowed_permissions = array('can_book', 'can_cancel_others', 'can_override_conflicts');
        if (!in_array($permission, $allowed_permissions, true)) {
            wp_send_json_error(array('message' => __('Invalid permission.', 'ffcertificate')));
        }

        $existing = AudienceScheduleRepository::get_user_permissions($schedule_id, $user_id);
        if (!$existing) {
            wp_send_json_error(array('message' => __('User does not have access to this calendar.', 'ffcertificate')));
        }

        $perms = array(
            'can_book' => (int) $existing->can_book,
            'can_cancel_others' => (int) $existing->can_cancel_others,
            'can_override_conflicts' => (int) $existing->can_override_conflicts,
        );
        $perms[$permission] = $value ? 1 : 0;

        $result = AudienceScheduleRepository::set_user_permissions($schedule_id, $user_id, $perms);

        if (!$result) {
            wp_send_json_error(array('message' => __('Error updating permission.', 'ffcertificate')));
        }

        wp_send_json_success();
    }

    /**
     * AJAX: Remove user permission from a schedule
     *
     * @return void
     */
    public function ajax_remove_user_permission(): void {
        check_ajax_referer('ffc_schedule_permissions', '_wpnonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'ffcertificate')));
        }

        $schedule_id = isset($_POST['schedule_id']) ? absint($_POST['schedule_id']) : 0;
        $user_id = isset($_POST['user_id']) ? absint($_POST['user_id']) : 0;

        if (!$schedule_id || !$user_id) {
            wp_send_json_error(array('message' => __('Missing required parameters.', 'ffcertificate')));
        }

        $result = AudienceScheduleRepository::remove_user_permissions($schedule_id, $user_id);

        if (!$result) {
            wp_send_json_error(array('message' => __('Error removing user access.', 'ffcertificate')));
        }

        wp_send_json_success();
    }
}
