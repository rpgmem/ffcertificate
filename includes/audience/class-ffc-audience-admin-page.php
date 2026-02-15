<?php
declare(strict_types=1);

/**
 * Audience Admin Page (Coordinator)
 *
 * Thin coordinator that registers the unified Scheduling menu and delegates
 * rendering / action-handling to specialised sub-page classes:
 *
 *   AudienceAdminDashboard   – Dashboard stats
 *   AudienceAdminCalendar    – Audience calendar CRUD + holidays
 *   AudienceAdminEnvironment – Environment CRUD
 *   AudienceAdminAudience    – Audience CRUD + member management
 *   AudienceAdminBookings    – Booking list with filters
 *   AudienceAdminSettings    – Settings tabs + global holidays
 *   AudienceAdminImport      – CSV import for members & audiences
 *
 * Self-Scheduling CPT items (All Calendars, Add New) and Appointments are
 * registered under the same menu by SelfSchedulingCPT and SelfSchedulingAdmin.
 *
 * Menu structure:
 * - Dashboard (overview of both systems)
 * - All Calendars (CPT - self-scheduling, auto-registered)
 * - Add New (CPT - self-scheduling, auto-registered)
 * - Appointments (self-scheduling, registered by SelfSchedulingAdmin)
 * - Audience Calendars
 * - Environments
 * - Audiences
 * - Audience Bookings
 * - Import & Export
 * - Settings
 *
 * @since 4.5.0
 * @version 4.6.0 - Unified scheduling menu with self-scheduling integration
 * @version 4.6.1 - Refactored into coordinator + 7 focused sub-page classes
 * @package FreeFormCertificate\Audience
 */

namespace FreeFormCertificate\Audience;

if (!defined('ABSPATH')) {
    exit;
}

class AudienceAdminPage {

    /**
     * Menu slug prefix (public so sub-classes can reference it)
     */
    public const MENU_SLUG = 'ffc-scheduling';

    /** @var AudienceAdminDashboard */
    private AudienceAdminDashboard $dashboard;

    /** @var AudienceAdminCalendar */
    private AudienceAdminCalendar $calendar;

    /** @var AudienceAdminEnvironment */
    private AudienceAdminEnvironment $environment;

    /** @var AudienceAdminAudience */
    private AudienceAdminAudience $audience;

    /** @var AudienceAdminBookings */
    private AudienceAdminBookings $bookings;

    /** @var AudienceAdminSettings */
    private AudienceAdminSettings $settings;

    /** @var AudienceAdminImport */
    private AudienceAdminImport $import;

    /**
     * Initialize admin page
     *
     * @return void
     */
    public function init(): void {
        // Instantiate sub-page handlers
        $this->dashboard   = new AudienceAdminDashboard(self::MENU_SLUG);
        $this->calendar    = new AudienceAdminCalendar(self::MENU_SLUG);
        $this->environment = new AudienceAdminEnvironment(self::MENU_SLUG);
        $this->audience    = new AudienceAdminAudience(self::MENU_SLUG);
        $this->bookings    = new AudienceAdminBookings(self::MENU_SLUG);
        $this->settings    = new AudienceAdminSettings(self::MENU_SLUG);
        $this->import      = new AudienceAdminImport(self::MENU_SLUG);

        add_action('admin_menu', array($this, 'add_admin_menus'), 20);
        add_action('admin_menu', array($this, 'add_menu_separators'), 99);
        add_action('admin_head', array($this, 'print_menu_separator_css'));
        add_action('admin_init', array($this, 'handle_form_submissions'));
    }

    /**
     * Add admin menu pages
     *
     * @return void
     */
    public function add_admin_menus(): void {
        // Main menu: Scheduling — unified for both systems
        add_menu_page(
            __('Scheduling', 'ffcertificate'),
            __('Scheduling', 'ffcertificate'),
            'manage_options',
            self::MENU_SLUG,
            array($this->dashboard, 'render_dashboard_page'),
            'dashicons-calendar-alt',
            26
        );

        // --- Self-Scheduling items are auto-registered here by CPT (Personal Calendars, New)
        // --- and by SelfSchedulingAdmin (Appointments) at priority 25

        // --- Audience section ---

        // Submenu: Dashboard
        add_submenu_page(
            self::MENU_SLUG,
            __('Dashboard', 'ffcertificate'),
            __('Dashboard', 'ffcertificate'),
            'manage_options',
            self::MENU_SLUG . '-dashboard',
            array($this->dashboard, 'render_dashboard_page')
        );

        // Submenu: Audience Calendars
        add_submenu_page(
            self::MENU_SLUG,
            __('Audience Calendars', 'ffcertificate'),
            __('Audience Calendars', 'ffcertificate'),
            'manage_options',
            self::MENU_SLUG . '-calendars',
            array($this->calendar, 'render_page')
        );

        // Submenu: Environments
        add_submenu_page(
            self::MENU_SLUG,
            __('Environments', 'ffcertificate'),
            __('Environments', 'ffcertificate'),
            'manage_options',
            self::MENU_SLUG . '-environments',
            array($this->environment, 'render_page')
        );

        // Submenu: Audiences
        add_submenu_page(
            self::MENU_SLUG,
            __('Audiences', 'ffcertificate'),
            __('Audiences', 'ffcertificate'),
            'manage_options',
            self::MENU_SLUG . '-audiences',
            array($this->audience, 'render_page')
        );

        // Submenu: Audience Bookings
        add_submenu_page(
            self::MENU_SLUG,
            __('Audience Bookings', 'ffcertificate'),
            __('Audience Bookings', 'ffcertificate'),
            'manage_options',
            self::MENU_SLUG . '-bookings',
            array($this->bookings, 'render_page')
        );

        // --- Tools section ---

        // Submenu: Import & Export
        add_submenu_page(
            self::MENU_SLUG,
            __('Import & Export', 'ffcertificate'),
            __('Import & Export', 'ffcertificate'),
            'manage_options',
            self::MENU_SLUG . '-import',
            array($this->import, 'render_page')
        );

        // Submenu: Settings
        add_submenu_page(
            self::MENU_SLUG,
            __('Settings', 'ffcertificate'),
            __('Settings', 'ffcertificate'),
            'manage_options',
            self::MENU_SLUG . '-settings',
            array($this->settings, 'render_page')
        );
    }

    /**
     * Insert visual separators between menu sections
     *
     * Runs at priority 99 so all submenu items are already registered.
     * Inserts non-clickable separator labels before "Audience Calendars" and "Import".
     *
     * @return void
     */
    public function add_menu_separators(): void {
        global $submenu;

        if (!isset($submenu[self::MENU_SLUG])) {
            return;
        }

        // Index all items by slug for easy lookup
        $by_slug = array();
        foreach ($submenu[self::MENU_SLUG] as $item) {
            $by_slug[$item[2]] = $item;
        }

        // Define the desired order with separators
        $ordered_slugs = array(
            // Dashboard at top
            self::MENU_SLUG . '-dashboard',                          // Dashboard
            // Self section
            '#ffc-separator-self',
            'edit.php?post_type=ffc_self_scheduling',               // Personal Calendars
            'post-new.php?post_type=ffc_self_scheduling',           // New Personal Calendar
            'ffc-appointments',                                      // Appointments
            // Audience section
            '#ffc-separator-audience',
            self::MENU_SLUG . '-calendars',                          // Audience Calendars
            self::MENU_SLUG . '-environments',                       // Environments
            self::MENU_SLUG . '-audiences',                          // Audiences
            self::MENU_SLUG . '-bookings',                           // Audience Bookings
            // Reregistration
            'ffc-reregistration',                                    // Reregistration
            // Tools section
            '#ffc-separator-tools',
            self::MENU_SLUG . '-import',                             // Import & Export
            self::MENU_SLUG . '-settings',                           // Settings
        );

        // Build separators
        $separators = array(
            '#ffc-separator-self'     => array(__('Self', 'ffcertificate'), 'manage_options', '#ffc-separator-self'),
            '#ffc-separator-audience' => array(__('Audience', 'ffcertificate'), 'manage_options', '#ffc-separator-audience'),
            '#ffc-separator-tools'    => array(__('Tools', 'ffcertificate'), 'manage_options', '#ffc-separator-tools'),
        );

        // Rebuild submenu in the desired order
        $new_items = array();
        foreach ($ordered_slugs as $slug) {
            if (isset($separators[$slug])) {
                $new_items[] = $separators[$slug];
            } elseif (isset($by_slug[$slug])) {
                $new_items[] = $by_slug[$slug];
            }
        }

        // Append any remaining items not in our ordered list (safety net)
        foreach ($by_slug as $slug => $item) {
            if ($slug === self::MENU_SLUG) {
                continue; // Skip the auto-generated parent duplicate
            }
            if (!in_array($slug, $ordered_slugs, true)) {
                $new_items[] = $item;
            }
        }

        $submenu[self::MENU_SLUG] = $new_items;
    }

    /**
     * Print CSS to style menu separators with dashicons
     *
     * Must remain inline — loaded via admin_head on ALL admin pages,
     * while ffc-audience-admin.css only loads on scheduling pages.
     *
     * @return void
     */
    public function print_menu_separator_css(): void {
        ?>
        <style>
            #adminmenu .wp-submenu a[href^="#ffc-separator-"] {
                pointer-events: none;
                cursor: default;
                color: #a7aaad !important;
                font-size: 11px;
                font-weight: 600;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                border-top: 1px solid rgba(255,255,255,0.1);
                margin-top: 6px;
                padding-top: 8px;
            }
            #adminmenu .wp-submenu a[href^="#ffc-separator-"]:hover {
                color: #a7aaad !important;
                background: none !important;
            }
            #adminmenu .wp-submenu a[href^="#ffc-separator-"]::before {
                font-family: dashicons;
                font-size: 14px;
                margin-right: 4px;
                vertical-align: middle;
                position: relative;
                top: -1px;
            }
            #adminmenu .wp-submenu a[href="#ffc-separator-self"]::before {
                content: "\f110"; /* dashicons-admin-users */
            }
            #adminmenu .wp-submenu a[href="#ffc-separator-audience"]::before {
                content: "\f307"; /* dashicons-groups */
            }
            #adminmenu .wp-submenu a[href="#ffc-separator-tools"]::before {
                content: "\f107"; /* dashicons-admin-tools */
            }
            #adminmenu .wp-submenu a[href$="ffc-scheduling-dashboard"]::before {
                font-family: dashicons;
                font-size: 14px;
                margin-right: 4px;
                vertical-align: middle;
                position: relative;
                top: -1px;
                content: "\f226"; /* dashicons-dashboard */
            }
        </style>
        <?php
    }

    /**
     * Handle form submissions — delegates to sub-page handlers
     *
     * @return void
     */
    public function handle_form_submissions(): void {
        // Only process on our admin pages
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (!isset($_GET['page']) || strpos(sanitize_text_field(wp_unslash($_GET['page'])), self::MENU_SLUG) !== 0) {
            return;
        }

        $this->settings->handle_global_holiday_actions();
        $this->settings->handle_visibility_settings();
        $this->import->handle_csv_import();
        $this->calendar->handle_actions();
        $this->environment->handle_actions();
        $this->audience->handle_actions();
    }
}
