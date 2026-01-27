<?php
declare(strict_types=1);

/**
 * Calendar Admin
 *
 * Manages admin interface for calendars, including submenu pages.
 * Creates submenu structure:
 * - All Calendars (automatically created by CPT)
 * - Add New (automatically created by CPT)
 * - Appointments (custom submenu page)
 *
 * @since 4.1.0
 * @version 4.1.0
 */

namespace FreeFormCertificate\Calendars;

if (!defined('ABSPATH')) exit;

class CalendarAdmin {

    /**
     * Constructor
     */
    public function __construct() {
        add_action('admin_menu', array($this, 'add_submenu_pages'), 20);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
    }

    /**
     * Add submenu pages to Calendar menu
     *
     * @return void
     */
    public function add_submenu_pages(): void {
        // Add Appointments submenu page
        add_submenu_page(
            'edit.php?post_type=ffc_calendar', // Parent slug (Calendar CPT menu)
            __('Appointments', 'ffc'),          // Page title
            __('Appointments', 'ffc'),          // Menu title
            'edit_posts',                        // Capability
            'ffc-appointments',                  // Menu slug
            array($this, 'render_appointments_page') // Callback
        );
    }

    /**
     * Render Appointments page
     *
     * This page will list all appointments with filters and export options.
     *
     * @return void
     */
    public function render_appointments_page(): void {
        // Check permissions
        if (!\FreeFormCertificate\Core\Utils::current_user_can_manage()) {
            wp_die(esc_html__('You do not have permission to access this page.', 'ffc'));
        }

        // Include appointments list table
        require_once plugin_dir_path(__FILE__) . 'views/appointments-list.php';
    }

    /**
     * Enqueue admin assets
     *
     * @param string $hook
     * @return void
     */
    public function enqueue_admin_assets(string $hook): void {
        // Only load on calendar and appointment pages
        $calendar_screens = array(
            'ffc_calendar',
            'edit-ffc_calendar',
            'ffc-calendars_page_ffc-appointments'
        );

        $screen = get_current_screen();
        if (!$screen || !in_array($screen->id, $calendar_screens)) {
            return;
        }

        // Enqueue styles
        wp_enqueue_style(
            'ffc-calendar-admin',
            plugins_url('assets/css/calendar-admin.css', dirname(__FILE__, 2)),
            array(),
            '4.1.0'
        );

        // Enqueue scripts
        wp_enqueue_script(
            'ffc-calendar-admin',
            plugins_url('assets/js/calendar-admin.js', dirname(__FILE__, 2)),
            array('jquery', 'jquery-ui-sortable', 'jquery-ui-datepicker'),
            '4.1.0',
            true
        );

        // Localize script
        wp_localize_script('ffc-calendar-admin', 'ffcCalendarAdmin', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ffc_calendar_admin_nonce'),
            'strings' => array(
                'confirmDelete' => __('Are you sure you want to delete this?', 'ffc'),
                'confirmCancel' => __('Are you sure you want to cancel this appointment?', 'ffc'),
                'selectCalendar' => __('Please select a calendar', 'ffc'),
                'selectDate' => __('Please select a date', 'ffc'),
                'selectTime' => __('Please select a time', 'ffc'),
            )
        ));

        // Enqueue jQuery UI theme for datepicker
        wp_enqueue_style('jquery-ui-theme', '//code.jquery.com/ui/1.12.1/themes/smoothness/jquery-ui.css');
    }
}
