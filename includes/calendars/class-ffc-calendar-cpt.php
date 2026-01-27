<?php
declare(strict_types=1);

/**
 * Calendar CPT
 *
 * Manages the Custom Post Type for calendars.
 * Creates independent menu structure as per requirements:
 * - FFC Calendars (parent menu, independent from FFC Forms)
 *   - All Calendars
 *   - Add New
 *   - Appointments
 *
 * @since 4.1.0
 * @version 4.1.0
 */

namespace FreeFormCertificate\Calendars;

if (!defined('ABSPATH')) exit;

class CalendarCPT {

    /**
     * Calendar repository instance
     *
     * @var \FreeFormCertificate\Repositories\CalendarRepository
     */
    private $calendar_repository;

    /**
     * Constructor
     */
    public function __construct() {
        add_action('init', array($this, 'register_calendar_cpt'));
        add_filter('post_row_actions', array($this, 'add_duplicate_link'), 10, 2);
        add_action('admin_action_ffc_duplicate_calendar', array($this, 'handle_calendar_duplication'));

        // Hook into post save to create/update calendar record in database
        add_action('save_post_ffc_calendar', array($this, 'sync_calendar_data'), 10, 3);

        // Hook into post delete to clean up calendar records
        add_action('before_delete_post', array($this, 'cleanup_calendar_data'), 10, 2);
    }

    /**
     * Initialize repository
     *
     * @return void
     */
    private function init_repository(): void {
        if (!$this->calendar_repository) {
            $this->calendar_repository = new \FreeFormCertificate\Repositories\CalendarRepository();
        }
    }

    /**
     * Register 'ffc_calendar' Custom Post Type
     *
     * Creates independent menu structure below FFC Forms menu.
     *
     * @return void
     */
    public function register_calendar_cpt(): void {
        $labels = array(
            'name'                  => _x('Calendars', 'Post Type General Name', 'ffc'),
            'singular_name'         => _x('Calendar', 'Post Type Singular Name', 'ffc'),
            'menu_name'             => __('FFC Calendars', 'ffc'),
            'name_admin_bar'        => __('FFC Calendar', 'ffc'),
            'add_new'               => __('Add New', 'ffc'),
            'add_new_item'          => __('Add New Calendar', 'ffc'),
            'new_item'              => __('New Calendar', 'ffc'),
            'edit_item'             => __('Edit Calendar', 'ffc'),
            'view_item'             => __('View Calendar', 'ffc'),
            'all_items'             => __('All Calendars', 'ffc'),
            'search_items'          => __('Search Calendars', 'ffc'),
            'not_found'             => __('No calendars found.', 'ffc'),
            'not_found_in_trash'    => __('No calendars found in Trash.', 'ffc'),
        );

        $args = array(
            'labels'             => $labels,
            'public'             => false,
            'show_ui'            => true,
            'show_in_menu'       => true, // Independent menu (not under Forms)
            'query_var'          => true,
            'capability_type'    => 'post',
            'has_archive'        => false,
            'hierarchical'       => false,
            'menu_icon'          => 'dashicons-calendar-alt',
            'menu_position'      => 26, // Position below FFC Forms (which is usually 25)
            'supports'           => array('title'),
            'rewrite'            => array('slug' => 'ffc-calendar'),
        );

        register_post_type('ffc_calendar', $args);
    }

    /**
     * Add duplicate link to calendar row actions
     *
     * @param array $actions
     * @param object $post
     * @return array
     */
    public function add_duplicate_link(array $actions, object $post): array {
        if ($post->post_type !== 'ffc_calendar') {
            return $actions;
        }

        if (!\FreeFormCertificate\Core\Utils::current_user_can_manage()) {
            return $actions;
        }

        $url = wp_nonce_url(
            admin_url('admin.php?action=ffc_duplicate_calendar&post=' . $post->ID),
            'ffc_duplicate_calendar_nonce'
        );

        $actions['duplicate'] = '<a href="' . esc_url($url) . '" title="' . esc_attr__('Duplicate this calendar', 'ffc') . '">' . __('Duplicate', 'ffc') . '</a>';

        return $actions;
    }

    /**
     * Handle calendar duplication
     *
     * @return void
     */
    public function handle_calendar_duplication(): void {
        if (!\FreeFormCertificate\Core\Utils::current_user_can_manage()) {
            \FreeFormCertificate\Core\Utils::debug_log('Unauthorized calendar duplication attempt', array(
                'user_id' => get_current_user_id(),
                'ip' => \FreeFormCertificate\Core\Utils::get_user_ip()
            ));
            wp_die(esc_html__('You do not have permission to duplicate this calendar.', 'ffc'));
        }

        $post_id = (isset($_GET['post']) ? absint($_GET['post']) : 0);

        check_admin_referer('ffc_duplicate_calendar_nonce');

        $post = get_post($post_id);

        if (!$post || $post->post_type !== 'ffc_calendar') {
            \FreeFormCertificate\Core\Utils::debug_log('Invalid calendar duplication request', array(
                'post_id' => $post_id,
                'user_id' => get_current_user_id()
            ));
            wp_die(esc_html__('Invalid calendar.', 'ffc'));
        }

        $original_title = $post->post_title;
        $new_title = sprintf(__('%s (Copy)', 'ffc'), $original_title);

        // Create new post
        $new_post_args = array(
            'post_title'  => $new_title,
            'post_status' => 'draft',
            'post_type'   => $post->post_type,
            'post_author' => get_current_user_id(),
        );

        $new_post_id = wp_insert_post($new_post_args);

        if (is_wp_error($new_post_id)) {
            \FreeFormCertificate\Core\Utils::debug_log('Calendar duplication failed', array(
                'error' => $new_post_id->get_error_message(),
                'original_post_id' => $post_id
            ));
            wp_die($new_post_id->get_error_message());
        }

        // Copy all calendar metadata
        $config = get_post_meta($post_id, '_ffc_calendar_config', true);
        $working_hours = get_post_meta($post_id, '_ffc_calendar_working_hours', true);
        $email_config = get_post_meta($post_id, '_ffc_calendar_email_config', true);

        $metadata_copied = array();

        if ($config) {
            update_post_meta($new_post_id, '_ffc_calendar_config', $config);
            $metadata_copied[] = 'config';
        }

        if ($working_hours) {
            update_post_meta($new_post_id, '_ffc_calendar_working_hours', $working_hours);
            $metadata_copied[] = 'working_hours';
        }

        if ($email_config) {
            update_post_meta($new_post_id, '_ffc_calendar_email_config', $email_config);
            $metadata_copied[] = 'email_config';
        }

        \FreeFormCertificate\Core\Utils::debug_log('Calendar duplicated successfully', array(
            'original_post_id' => $post_id,
            'new_post_id' => $new_post_id,
            'metadata_copied' => $metadata_copied,
            'user_id' => get_current_user_id()
        ));

        wp_safe_redirect(admin_url('edit.php?post_type=ffc_calendar'));
        exit;
    }

    /**
     * Sync calendar data to database table
     *
     * When a calendar post is saved, update the calendar record in wp_ffc_calendars table.
     *
     * @param int $post_id
     * @param object $post
     * @param bool $update
     * @return void
     */
    public function sync_calendar_data(int $post_id, object $post, bool $update): void {
        // Skip autosaves and revisions
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (wp_is_post_revision($post_id)) {
            return;
        }

        // Only sync published calendars
        if ($post->post_status !== 'publish') {
            return;
        }

        $this->init_repository();

        // Check if calendar record exists
        $existing = $this->calendar_repository->findByPostId($post_id);

        // Get metadata
        $config = get_post_meta($post_id, '_ffc_calendar_config', true);
        $working_hours = get_post_meta($post_id, '_ffc_calendar_working_hours', true);
        $email_config = get_post_meta($post_id, '_ffc_calendar_email_config', true);

        // Parse config into database fields
        $data = $this->parse_calendar_config($config);
        $data['title'] = $post->post_title;
        $data['post_id'] = $post_id;
        $data['working_hours'] = is_array($working_hours) ? json_encode($working_hours) : $working_hours;
        $data['email_config'] = is_array($email_config) ? json_encode($email_config) : $email_config;
        $data['updated_at'] = current_time('mysql');
        $data['updated_by'] = get_current_user_id();

        if ($existing) {
            // Update existing record
            $this->calendar_repository->update((int)$existing['id'], $data);
        } else {
            // Create new record
            $data['created_at'] = current_time('mysql');
            $data['created_by'] = get_current_user_id();
            $this->calendar_repository->createFromPost($post_id, $data);
        }
    }

    /**
     * Parse calendar config into database fields
     *
     * @param array|string $config
     * @return array
     */
    private function parse_calendar_config($config): array {
        if (is_string($config)) {
            $config = json_decode($config, true);
        }

        if (!is_array($config)) {
            $config = array();
        }

        return array(
            'description' => $config['description'] ?? '',
            'slot_duration' => $config['slot_duration'] ?? 30,
            'slot_interval' => $config['slot_interval'] ?? 0,
            'slots_per_day' => $config['slots_per_day'] ?? 0,
            'advance_booking_min' => $config['advance_booking_min'] ?? 0,
            'advance_booking_max' => $config['advance_booking_max'] ?? 30,
            'allow_cancellation' => $config['allow_cancellation'] ?? 1,
            'cancellation_min_hours' => $config['cancellation_min_hours'] ?? 24,
            'requires_approval' => $config['requires_approval'] ?? 0,
            'max_appointments_per_slot' => $config['max_appointments_per_slot'] ?? 1,
            'require_login' => $config['require_login'] ?? 0,
            'allowed_roles' => isset($config['allowed_roles']) ? json_encode($config['allowed_roles']) : null,
            'status' => $config['status'] ?? 'active'
        );
    }

    /**
     * Cleanup calendar data when post is deleted
     *
     * @param int $post_id
     * @param object $post
     * @return void
     */
    public function cleanup_calendar_data(int $post_id, object $post): void {
        if ($post->post_type !== 'ffc_calendar') {
            return;
        }

        $this->init_repository();

        // Find calendar record
        $calendar = $this->calendar_repository->findByPostId($post_id);

        if ($calendar) {
            // Delete calendar record
            $this->calendar_repository->delete((int)$calendar['id']);

            // TODO: Optionally cancel all future appointments for this calendar
            // This should be configurable - admin may want to keep appointments data

            \FreeFormCertificate\Core\Utils::debug_log('Calendar data cleaned up', array(
                'post_id' => $post_id,
                'calendar_id' => $calendar['id'],
                'user_id' => get_current_user_id()
            ));
        }
    }
}
