<?php
declare(strict_types=1);

/**
 * Self-Scheduling Admin
 *
 * Manages admin interface for self-scheduling appointments.
 * Registers "Appointments" submenu under the unified Scheduling menu.
 *
 * @since 4.1.0
 * @version 4.6.0 - Migrated to unified Scheduling menu
 */

namespace FreeFormCertificate\SelfScheduling;

if (!defined('ABSPATH')) exit;

class SelfSchedulingAdmin {

    /**
     * Constructor
     */
    public function __construct() {
        add_action('admin_menu', array($this, 'add_submenu_pages'), 25);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
    }

    /**
     * Add submenu pages to unified Scheduling menu
     *
     * @return void
     */
    public function add_submenu_pages(): void {
        // Add Appointments submenu under unified Scheduling menu
        add_submenu_page(
            'ffc-scheduling',
            __('Appointments', 'ffcertificate'),
            __('Appointments', 'ffcertificate'),
            'edit_posts',
            'ffc-appointments',
            array($this, 'render_appointments_page')
        );
    }

    /**
     * Render Appointments page
     *
     * @return void
     */
    public function render_appointments_page(): void {
        if (!\FreeFormCertificate\Core\Utils::current_user_can_manage()) {
            wp_die(esc_html__('You do not have permission to access this page.', 'ffcertificate'));
        }

        require_once plugin_dir_path(__FILE__) . 'views/appointments-list.php';
    }

    /**
     * Enqueue admin assets
     *
     * @param string $hook
     * @return void
     */
    public function enqueue_admin_assets(string $hook): void {
        $screen = get_current_screen();
        if (!$screen) {
            return;
        }

        // Match self-scheduling screens (CPT edit/list + appointments page)
        $is_self_scheduling = (
            $screen->post_type === 'ffc_self_scheduling' ||
            strpos($screen->id, 'ffc-appointments') !== false
        );

        if (!$is_self_scheduling) {
            return;
        }

        $s = \FreeFormCertificate\Core\Utils::asset_suffix();

        wp_enqueue_style(
            'ffc-calendar-admin',
            plugins_url("assets/css/ffc-calendar-admin{$s}.css", dirname(__FILE__, 2)),
            array(),
            '4.1.0'
        );

        wp_enqueue_script(
            'ffc-calendar-admin',
            plugins_url("assets/js/ffc-calendar-admin{$s}.js", dirname(__FILE__, 2)),
            array('jquery', 'jquery-ui-sortable', 'jquery-ui-datepicker'),
            '4.1.0',
            true
        );

        wp_localize_script('ffc-calendar-admin', 'ffcSelfSchedulingAdmin', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ffc_self_scheduling_admin_nonce'),
            'strings' => array(
                'confirmDelete' => __('Are you sure you want to delete this?', 'ffcertificate'),
                'confirmCancel' => __('Are you sure you want to cancel this appointment?', 'ffcertificate'),
                'selectCalendar' => __('Please select a calendar', 'ffcertificate'),
                'selectDate' => __('Please select a date', 'ffcertificate'),
                'selectTime' => __('Please select a time', 'ffcertificate'),
            )
        ));

        // phpcs:ignore PluginCheck.CodeAnalysis.EnqueuedResourceOffloading.OffloadedContent -- jQuery UI CSS from official CDN, standard practice.
        wp_enqueue_style('jquery-ui-theme', '//code.jquery.com/ui/1.12.1/themes/smoothness/jquery-ui.css', array(), '1.12.1');
    }
}
