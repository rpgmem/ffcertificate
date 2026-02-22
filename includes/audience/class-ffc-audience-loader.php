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

    use \FreeFormCertificate\Core\AjaxTrait;

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
        add_action('wp_ajax_ffc_audience_get_booking', array($this, 'ajax_get_booking'));
        add_action('wp_ajax_ffc_audience_get_schedule_slots', array($this, 'ajax_get_schedule_slots'));
        add_action('wp_ajax_ffc_search_users', array($this, 'ajax_search_users'));
        add_action('wp_ajax_ffc_audience_get_environments', array($this, 'ajax_get_environments'));
        add_action('wp_ajax_ffc_audience_add_user_permission', array($this, 'ajax_add_user_permission'));
        add_action('wp_ajax_ffc_audience_update_user_permission', array($this, 'ajax_update_user_permission'));
        add_action('wp_ajax_ffc_audience_remove_user_permission', array($this, 'ajax_remove_user_permission'));

        // Custom fields AJAX
        add_action('wp_ajax_ffc_save_custom_fields', array($this, 'ajax_save_custom_fields'));
        add_action('wp_ajax_ffc_delete_custom_field', array($this, 'ajax_delete_custom_field'));
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

        // Custom fields CSS + JS (on audiences page)
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';
        if ($page === 'ffc-scheduling-audiences') {
            wp_enqueue_script('jquery-ui-sortable');

            wp_enqueue_style(
                'ffc-custom-fields-admin',
                FFC_PLUGIN_URL . "assets/css/ffc-custom-fields-admin{$s}.css",
                array('ffc-audience-admin'),
                FFC_VERSION
            );

            wp_enqueue_script(
                'ffc-custom-fields-admin',
                FFC_PLUGIN_URL . "assets/js/ffc-custom-fields-admin{$s}.js",
                array('jquery', 'jquery-ui-sortable', 'wp-util', 'ffc-audience-admin'),
                FFC_VERSION,
                true
            );
        }

        // Localize script
        wp_localize_script('ffc-audience-admin', 'ffcAudienceAdmin', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'restUrl' => rest_url('ffc/v1/audience/'),
            'restNonce' => wp_create_nonce('wp_rest'),
            'searchUsersNonce' => wp_create_nonce('ffc_search_users'),
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
            'cancelReason' => __('Please provide a reason for cancellation:', 'ffcertificate'),
            'bookingCancelled' => __('Booking cancelled successfully.', 'ffcertificate'),
            'bookingDetails' => __('Booking Details', 'ffcertificate'),
            'date' => __('Date', 'ffcertificate'),
            'time' => __('Time', 'ffcertificate'),
            'description' => __('Description', 'ffcertificate'),
            'type' => __('Type', 'ffcertificate'),
            'status' => __('Status', 'ffcertificate'),
            'createdBy' => __('Created By', 'ffcertificate'),
            'audiences' => __('Audiences', 'ffcertificate'),
            'users' => __('Users', 'ffcertificate'),
            'cancelReasonLabel' => __('Cancel Reason', 'ffcertificate'),
            'close' => __('Close', 'ffcertificate'),
            'allDay' => __('All Day', 'ffcertificate'),
            'audience' => __('Audience', 'ffcertificate'),
            'customUsers' => __('Custom Users', 'ffcertificate'),
            'active' => __('Active', 'ffcertificate'),
            'cancelled' => __('Cancelled', 'ffcertificate'),
        );
    }


    /**
     * AJAX: Check for conflicts
     *
     * @return void
     */
    public function ajax_check_conflicts(): void {
        try {
            $this->verify_ajax_nonce('ffc_admin_nonce');
            $this->check_ajax_permission();

            $environment_id = $this->get_post_int('environment_id');
            $booking_date = $this->get_post_param('booking_date');
            $start_time = $this->get_post_param('start_time');
            $end_time = $this->get_post_param('end_time');
            $audience_ids = array_map('absint', $this->get_post_array('audience_ids'));
            $user_ids = array_map('absint', $this->get_post_array('user_ids'));

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
        } catch (\Throwable $e) {
            $this->handle_ajax_exception($e);
        }
    }

    /**
     * AJAX: Create booking
     *
     * @return void
     */
    public function ajax_create_booking(): void {
        $this->verify_ajax_nonce('ffc_admin_nonce');
        $this->check_ajax_permission();

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
        try {
            $this->verify_ajax_nonce('ffc_admin_nonce');
            $this->check_ajax_permission();

            $booking_id = $this->get_post_int('booking_id');
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified via verify_ajax_nonce() above.
            $reason = isset($_POST['reason']) ? sanitize_textarea_field(wp_unslash($_POST['reason'])) : '';

            if (!$booking_id) {
                wp_send_json_error(array('message' => __('Invalid booking ID.', 'ffcertificate')));
            }

            $booking = AudienceBookingRepository::get_by_id($booking_id);
            if (!$booking) {
                wp_send_json_error(array('message' => __('Booking not found.', 'ffcertificate')));
            }

            if ($booking->status === 'cancelled') {
                wp_send_json_error(array('message' => __('Booking is already cancelled.', 'ffcertificate')));
            }

            $result = AudienceBookingRepository::cancel($booking_id, $reason);
            if (!$result) {
                wp_send_json_error(array('message' => __('Failed to cancel booking.', 'ffcertificate')));
            }

            do_action('ffcertificate_audience_booking_cancelled', $booking_id, $reason);

            wp_send_json_success(array('message' => __('Booking cancelled successfully.', 'ffcertificate')));
        } catch (\Throwable $e) {
            $this->handle_ajax_exception($e);
        }
    }

    /**
     * AJAX: Get booking details
     *
     * @return void
     */
    public function ajax_get_booking(): void {
        try {
            $this->verify_ajax_nonce('ffc_admin_nonce');
            $this->check_ajax_permission();

            $booking_id = $this->get_post_int('booking_id');
            if (!$booking_id) {
                wp_send_json_error(array('message' => __('Invalid booking ID.', 'ffcertificate')));
            }

            $booking = AudienceBookingRepository::get_by_id($booking_id);
            if (!$booking) {
                wp_send_json_error(array('message' => __('Booking not found.', 'ffcertificate')));
            }

            // Get creator name
            $creator = get_userdata((int) $booking->created_by);
            $creator_name = $creator ? $creator->display_name : __('Unknown', 'ffcertificate');

            // Format audiences
            $audiences = array();
            if (!empty($booking->audiences)) {
                foreach ($booking->audiences as $aud) {
                    $audiences[] = array(
                        'id' => $aud->audience_id ?? $aud->id ?? 0,
                        'name' => $aud->name ?? $aud->audience_name ?? '',
                    );
                }
            }

            // Format users
            $users = array();
            if (!empty($booking->users)) {
                foreach ($booking->users as $u) {
                    $user_data = get_userdata((int) ($u->user_id ?? $u->ID ?? 0));
                    if ($user_data) {
                        $users[] = array(
                            'id' => $user_data->ID,
                            'name' => $user_data->display_name,
                            'email' => $user_data->user_email,
                        );
                    }
                }
            }

            wp_send_json_success(array(
                'id' => $booking->id,
                'booking_date' => $booking->booking_date,
                'start_time' => $booking->start_time,
                'end_time' => $booking->end_time,
                'is_all_day' => (int) ($booking->is_all_day ?? 0),
                'environment_name' => $booking->environment_name,
                'description' => $booking->description,
                'booking_type' => $booking->booking_type,
                'status' => $booking->status,
                'cancel_reason' => $booking->cancel_reason ?? '',
                'created_by' => $creator_name,
                'created_at' => $booking->created_at,
                'audiences' => $audiences,
                'users' => $users,
            ));
        } catch (\Throwable $e) {
            $this->handle_ajax_exception($e);
        }
    }

    /**
     * AJAX: Get schedule slots for a date range
     *
     * @return void
     */
    public function ajax_get_schedule_slots(): void {
        $this->verify_ajax_nonce('ffc_admin_nonce');
        $this->check_ajax_permission();

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
        try {
            $this->verify_ajax_nonce('ffc_search_users');
            $this->check_ajax_permission();

            $query = $this->get_post_param('query');

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
        } catch (\Throwable $e) {
            $this->handle_ajax_exception($e);
        }
    }

    /**
     * AJAX: Get environments by schedule ID
     *
     * @return void
     */
    public function ajax_get_environments(): void {
        try {
            $this->verify_ajax_nonce('ffc_admin_nonce');
            $this->check_ajax_permission();

            $schedule_id = $this->get_post_int('schedule_id');

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
        } catch (\Throwable $e) {
            $this->handle_ajax_exception($e);
        }
    }

    /**
     * AJAX: Add user permission to a schedule
     *
     * @return void
     */
    public function ajax_add_user_permission(): void {
        try {
            $this->verify_ajax_nonce('ffc_schedule_permissions', '_wpnonce');
            $this->check_ajax_permission();

            $schedule_id = $this->get_post_int('schedule_id');
            $user_id = $this->get_post_int('user_id');

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
            <tr data-user-id="<?php echo esc_attr((string) $user_id); ?>">
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
        } catch (\Throwable $e) {
            $this->handle_ajax_exception($e);
        }
    }

    /**
     * AJAX: Update a single user permission on a schedule
     *
     * @return void
     */
    public function ajax_update_user_permission(): void {
        try {
            $this->verify_ajax_nonce('ffc_schedule_permissions', '_wpnonce');
            $this->check_ajax_permission();

            $schedule_id = $this->get_post_int('schedule_id');
            $user_id = $this->get_post_int('user_id');
            $permission = $this->get_post_param('permission');
            $value = $this->get_post_int('value');

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
        } catch (\Throwable $e) {
            $this->handle_ajax_exception($e);
        }
    }

    /**
     * AJAX: Save custom fields for an audience (create/update/reorder)
     *
     * @since 4.11.0
     * @return void
     */
    public function ajax_save_custom_fields(): void {
        try {
            $this->verify_ajax_nonce('ffc_admin_nonce');
            $this->check_ajax_permission();

            $audience_id = $this->get_post_int('audience_id');
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON decoded and sanitized per-field below.
            $fields_json = isset($_POST['fields']) ? wp_unslash($_POST['fields']) : '[]';
            $fields = json_decode($fields_json, true);

            if (!$audience_id || !is_array($fields)) {
                wp_send_json_error(array('message' => __('Invalid data.', 'ffcertificate')));
            }

            $audience = AudienceRepository::get_by_id($audience_id);
            if (!$audience) {
                wp_send_json_error(array('message' => __('Audience not found.', 'ffcertificate')));
            }

            $saved_ids = array();
            $errors = array();

            foreach ($fields as $index => $field_data) {
                $field_id = $field_data['id'] ?? null;
                $is_new = !$field_id || strpos((string) $field_id, 'new_') === 0;

                $label = sanitize_text_field($field_data['label'] ?? '');
                if (empty($label)) {
                    $errors[] = sprintf(
                        /* translators: %d: field position */
                        __('Field #%d: label is required.', 'ffcertificate'),
                        $index + 1
                    );
                    continue;
                }

                // Build field_options JSON
                $options = array();
                if (!empty($field_data['choices'])) {
                    $choices = array_map('sanitize_text_field', $field_data['choices']);
                    $choices = array_values(array_filter($choices, function($c) { return $c !== ''; }));
                    $options['choices'] = $choices;
                }
                if (!empty($field_data['help_text'])) {
                    $options['help_text'] = sanitize_text_field($field_data['help_text']);
                }

                // Build validation_rules JSON
                $rules = array();
                if (!empty($field_data['format'])) {
                    $format = sanitize_text_field($field_data['format']);
                    if (in_array($format, \FreeFormCertificate\Reregistration\CustomFieldRepository::VALIDATION_FORMATS, true)) {
                        $rules['format'] = $format;
                        if ($format === 'custom_regex') {
                            $rules['custom_regex'] = $field_data['custom_regex'] ?? '';
                            $rules['custom_regex_message'] = sanitize_text_field($field_data['custom_regex_message'] ?? '');
                        }
                    }
                }

                $data = array(
                    'audience_id'      => $audience_id,
                    'field_label'      => $label,
                    'field_key'        => sanitize_key($field_data['key'] ?? ''),
                    'field_type'       => sanitize_text_field($field_data['type'] ?? 'text'),
                    'field_options'    => !empty($options) ? $options : null,
                    'validation_rules' => !empty($rules) ? $rules : null,
                    'sort_order'       => $index,
                    'is_required'      => !empty($field_data['is_required']) ? 1 : 0,
                    'is_active'        => isset($field_data['is_active']) ? (int) $field_data['is_active'] : 1,
                );

                if ($is_new) {
                    $new_id = \FreeFormCertificate\Reregistration\CustomFieldRepository::create($data);
                    if ($new_id) {
                        $saved_ids[] = $new_id;
                    } else {
                        $errors[] = sprintf(
                            /* translators: %s: field label */
                            __('Failed to create field "%s".', 'ffcertificate'),
                            $label
                        );
                    }
                } else {
                    $result = \FreeFormCertificate\Reregistration\CustomFieldRepository::update((int) $field_id, $data);
                    if ($result !== false) {
                        $saved_ids[] = (int) $field_id;
                    } else {
                        $errors[] = sprintf(
                            /* translators: %s: field label */
                            __('Failed to update field "%s".', 'ffcertificate'),
                            $label
                        );
                    }
                }
            }

            if (!empty($errors)) {
                wp_send_json_error(array(
                    'message' => implode(' ', $errors),
                    'saved_ids' => $saved_ids,
                ));
            }

            wp_send_json_success(array(
                'message' => __('Custom fields saved successfully.', 'ffcertificate'),
                'saved_ids' => $saved_ids,
            ));
        } catch (\Throwable $e) {
            $this->handle_ajax_exception($e);
        }
    }

    /**
     * AJAX: Delete a custom field
     *
     * @since 4.11.0
     * @return void
     */
    public function ajax_delete_custom_field(): void {
        try {
            $this->verify_ajax_nonce('ffc_admin_nonce');
            $this->check_ajax_permission();

            $field_id = $this->get_post_int('field_id');
            if (!$field_id) {
                wp_send_json_error(array('message' => __('Invalid field ID.', 'ffcertificate')));
            }

            $field = \FreeFormCertificate\Reregistration\CustomFieldRepository::get_by_id($field_id);
            if (!$field) {
                wp_send_json_error(array('message' => __('Field not found.', 'ffcertificate')));
            }

            $result = \FreeFormCertificate\Reregistration\CustomFieldRepository::delete($field_id);
            if (!$result) {
                wp_send_json_error(array('message' => __('Failed to delete field.', 'ffcertificate')));
            }

            wp_send_json_success(array('message' => __('Field deleted successfully.', 'ffcertificate')));
        } catch (\Throwable $e) {
            $this->handle_ajax_exception($e);
        }
    }

    /**
     * AJAX: Remove user permission from a schedule
     *
     * @return void
     */
    public function ajax_remove_user_permission(): void {
        try {
            $this->verify_ajax_nonce('ffc_schedule_permissions', '_wpnonce');
            $this->check_ajax_permission();

            $schedule_id = $this->get_post_int('schedule_id');
            $user_id = $this->get_post_int('user_id');

            if (!$schedule_id || !$user_id) {
                wp_send_json_error(array('message' => __('Missing required parameters.', 'ffcertificate')));
            }

            $result = AudienceScheduleRepository::remove_user_permissions($schedule_id, $user_id);

            if (!$result) {
                wp_send_json_error(array('message' => __('Error removing user access.', 'ffcertificate')));
            }

            wp_send_json_success();
        } catch (\Throwable $e) {
            $this->handle_ajax_exception($e);
        }
    }
}
