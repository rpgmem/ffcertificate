<?php
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
 * The Personal Calendars list (a CPT) and Appointments are registered under
 * the same menu by SelfSchedulingCPT and SelfSchedulingAdmin.
 *
 * Menu structure (#1761):
 * - Dashboard (overview of both systems)
 * - Personal Calendars (CPT - self-scheduling, auto-registered)
 * - Appointments (self-scheduling, registered by SelfSchedulingAdmin)
 * - Audience Calendars (two views: Calendars and Environments)
 * - Audiences
 * - Audience Bookings
 * - Settings (hosts Import & Export as a tab)
 *
 * @package FreeFormCertificate\Audience
 * @since 4.5.0
 * @version 4.6.0 - Unified scheduling menu with self-scheduling integration
 * @version 4.6.1 - Refactored into coordinator + 7 focused sub-page classes
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

use FreeFormCertificate\Core\RequestInput;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin page for audience admin.
 */
class AudienceAdminPage {

	/**
	 * Menu slug prefix (public so sub-classes can reference it)
	 */
	public const MENU_SLUG = 'ffc-scheduling';

	/**
	 * Description.
	 *
	 * @var AudienceAdminDashboard
	 */
	private AudienceAdminDashboard $dashboard;

	/**
	 * Description.
	 *
	 * @var AudienceAdminCalendar
	 */
	private AudienceAdminCalendar $calendar;

	/**
	 * Description.
	 *
	 * @var AudienceAdminEnvironment
	 */
	private AudienceAdminEnvironment $environment;

	/**
	 * Description.
	 *
	 * @var AudienceAdminAudience
	 */
	private AudienceAdminAudience $audience;

	/**
	 * Description.
	 *
	 * @var AudienceAdminBookings
	 */
	private AudienceAdminBookings $bookings;

	/**
	 * Description.
	 *
	 * @var AudienceAdminSettings
	 */
	private AudienceAdminSettings $settings;

	/**
	 * Description.
	 *
	 * @var AudienceAdminImport
	 */
	private AudienceAdminImport $import;

	/**
	 * Attendance screen of one booking (#1947).
	 *
	 * @var AudienceAdminAttendance
	 */
	private AudienceAdminAttendance $attendance;

	/**
	 * Initialize admin page
	 *
	 * @return void
	 */
	public function init(): void {
		// Instantiate sub-page handlers. Settings is constructed last and
		// receives the AudienceAdminImport instance so its 4th tab can
		// render the Import & Export body without a separate submenu. The
		// calendar page receives the environment handler, whose screen is
		// its second view (#1761).
		$this->dashboard   = new AudienceAdminDashboard( self::MENU_SLUG );
		$this->environment = new AudienceAdminEnvironment( self::MENU_SLUG );
		$this->calendar    = new AudienceAdminCalendar( self::MENU_SLUG, $this->environment );
		$this->audience    = new AudienceAdminAudience( self::MENU_SLUG );
		$this->bookings    = new AudienceAdminBookings( self::MENU_SLUG );
		$this->attendance  = new AudienceAdminAttendance( self::MENU_SLUG );
		$this->import      = new AudienceAdminImport( self::MENU_SLUG );
		$this->settings    = new AudienceAdminSettings( self::MENU_SLUG, $this->import );

		add_action( 'admin_menu', array( $this, 'add_admin_menus' ), 20 );
		add_action( 'admin_menu', array( $this, 'order_menu' ), 99 );
		// Permanent redirect for the previous standalone Import URL — the
		// page is now the `import` tab of ffc-scheduling-settings, but old
		// bookmarks / docs / dashboard links should not 404.
		add_action( 'admin_init', array( $this, 'redirect_legacy_import_url' ) );
		// The Environments screen is now a view of Audience Calendars (#1761).
		// Registered before handle_form_submissions so a GET is forwarded
		// first; a POST to the old slug is still handled where it lands.
		add_action( 'admin_init', array( $this, 'redirect_legacy_environments_url' ) );
		add_action( 'admin_init', array( $this, 'handle_form_submissions' ) );
	}

	/**
	 * Add admin menu pages
	 *
	 * @return void
	 */
	public function add_admin_menus(): void {
		// Main menu: Scheduling — unified for both systems.
		add_menu_page(
			\FreeFormCertificate\Core\PluginAreas::label( 'scheduling' ),
			\FreeFormCertificate\Core\PluginAreas::label( 'scheduling' ),
			'ffc_view_audiences',
			self::MENU_SLUG,
			array( $this->dashboard, 'render_dashboard_page' ),
			'none', // Drawn from the icon registry by AdminMenuIcons (#1640).
			// Floats avoid third-party plugins picking the same integer
			// from interleaving inside the FFC block. The block lives
			// at 26.x so all three FFC top-level menus stay contiguous.
			26.1
		);

		// --- Self-Scheduling items are auto-registered here by CPT (Personal Calendars, New)
		// --- and by SelfSchedulingAdmin (Appointments) at priority 25.

		// --- Audience section ---.

		// Submenu: Dashboard.
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Dashboard', 'ffcertificate' ),
			__( 'Dashboard', 'ffcertificate' ),
			'ffc_view_audiences',
			self::MENU_SLUG . '-dashboard',
			array( $this->dashboard, 'render_dashboard_page' )
		);

		// Submenu: Audience Calendars.
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Audience Calendars', 'ffcertificate' ),
			__( 'Audience Calendars', 'ffcertificate' ),
			'ffc_view_audiences',
			self::MENU_SLUG . '-calendars',
			array( $this->calendar, 'render_page' )
		);

		// Environments: a view of Audience Calendars since #1761, so it has no
		// row of its own. The slug stays registered, hidden (no parent) and
		// under the same capability, so `admin.php?page=ffc-scheduling-environments`
		// still passes WordPress's page-access check and reaches
		// redirect_legacy_environments_url(), and a POST from a form opened
		// before the move is still saved and rendered.
		add_submenu_page(
			'',
			__( 'Environments', 'ffcertificate' ),
			__( 'Environments', 'ffcertificate' ),
			'ffc_view_audiences',
			self::MENU_SLUG . '-environments',
			array( $this->calendar, 'render_page' )
		);

		// Submenu: Audiences.
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Audiences', 'ffcertificate' ),
			__( 'Audiences', 'ffcertificate' ),
			'ffc_view_audiences',
			self::MENU_SLUG . '-audiences',
			array( $this->audience, 'render_page' )
		);

		// Submenu: Audience Bookings.
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Audience Bookings', 'ffcertificate' ),
			__( 'Audience Bookings', 'ffcertificate' ),
			'ffc_view_audiences',
			self::MENU_SLUG . '-bookings',
			array( $this->bookings, 'render_page' )
		);

		// Attendance of one booking (#1947): reached from the bookings list's
		// row action, so it has no menu row (no parent), like Environments.
		add_submenu_page(
			'',
			__( 'Attendance', 'ffcertificate' ),
			__( 'Attendance', 'ffcertificate' ),
			AudienceAdminAttendance::CAP,
			self::MENU_SLUG . '-attendance',
			array( $this->attendance, 'render_page' )
		);

		// Import & Export used to be its own submenu here; it is now the
		// 4th tab of the Settings page (page=ffc-scheduling-settings&tab=import).
		// The submenu registration was removed so the sidebar is not
		// duplicated, and admin_init handles the 301 redirect for the
		// previous URL — see redirect_legacy_import_url().

		// Submenu: Settings.
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Settings', 'ffcertificate' ),
			__( 'Settings', 'ffcertificate' ),
			// A Personal Calendars Setup holder reaches it for the global holidays (#2244).
			AudienceAdminSettings::menu_capability(),
			self::MENU_SLUG . '-settings',
			array( $this->settings, 'render_page' )
		);
	}

	/**
	 * 301-redirect the previous standalone Import & Export URL
	 * (`page=ffc-scheduling-import`) onto the new tab inside Settings
	 * (`page=ffc-scheduling-settings&tab=import`), so old bookmarks /
	 * docs / dashboard links keep working after the submenu was removed.
	 *
	 * Every other query arg rides along (#1771), so an old bookmarked
	 * `&download_sample=…&_wpnonce=…` still reaches its handler instead of
	 * landing on the tab with the request dropped.
	 *
	 * @return void
	 */
	public function redirect_legacy_import_url(): void {
		if ( self::MENU_SLUG . '-import' !== RequestInput::get_get_key( 'page' ) ) {
			return;
		}

		$args         = self::forwarded_query_args();
		$args['page'] = self::MENU_SLUG . '-settings';
		$args['tab']  = 'import';

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ), 301 );
		exit;
	}

	/**
	 * Redirect the previous Environments screen (`page=ffc-scheduling-environments`)
	 * to its view inside Audience Calendars (`page=ffc-scheduling-calendars&view=environments`).
	 *
	 * Every other query arg rides along — `action`, `id`, `schedule_id`,
	 * `message` and the nonces — so a bookmarked list filter, an edit link or
	 * a nonce'd Deactivate / Delete link lands where it is handled. A POST is
	 * not redirected (a redirect drops its body): AudienceAdminEnvironment
	 * accepts the old slug, so the save runs and the page renders the view.
	 *
	 * @return void
	 */
	public function redirect_legacy_environments_url(): void {
		if ( self::MENU_SLUG . '-environments' !== RequestInput::get_get_key( 'page' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only asks whether the request carries a body; nothing is read.
		if ( ! empty( $_POST ) ) {
			return;
		}

		$args         = self::forwarded_query_args();
		$args['page'] = self::MENU_SLUG . '-calendars';
		$args['view'] = AudienceAdminEnvironment::VIEW;

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ), 301 );
		exit;
	}

	/**
	 * The current query string, ready to ride along on a legacy-URL redirect.
	 *
	 * Scalar values only, each key sanitized and each value sanitized and
	 * URL-encoded for `add_query_arg()`. The caller overwrites the keys that
	 * name the destination (`page`, and `tab` or `view`).
	 *
	 * @return array<string, string>
	 */
	private static function forwarded_query_args(): array {
		$args = array();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A redirect carrying the query string forward; the destination handler verifies its own nonce.
		foreach ( wp_unslash( $_GET ) as $key => $value ) {
			if ( ! is_string( $key ) || ! is_scalar( $value ) ) {
				continue;
			}
			$args[ sanitize_key( $key ) ] = rawurlencode( sanitize_text_field( (string) $value ) );
		}
		return $args;
	}

	/**
	 * Put the Scheduling submenu in its order.
	 *
	 * Runs at priority 99, after every item is registered — the CPT list by
	 * core, Appointments by SelfSchedulingAdmin at 25. Seven rows and no
	 * section labels (#1761): the "Self" / "Audience" separators, and the
	 * icon-font glyphs they and the Dashboard row drew, went with the
	 * reorganisation.
	 *
	 * @return void
	 */
	public function order_menu(): void {
		global $submenu;

		if ( ! isset( $submenu[ self::MENU_SLUG ] ) ) {
			return;
		}

		// Index all items by slug for easy lookup.
		$by_slug = array();
		foreach ( $submenu[ self::MENU_SLUG ] as $item ) {
			$by_slug[ $item[2] ] = $item;
		}

		$ordered_slugs = array(
			self::MENU_SLUG . '-dashboard',            // Dashboard.
			'edit.php?post_type=ffc_self_scheduling', // Personal Calendars.
			'ffc-appointments',                        // Appointments.
			self::MENU_SLUG . '-calendars',            // Audience Calendars (and its Environments view).
			self::MENU_SLUG . '-audiences',            // Audiences.
			self::MENU_SLUG . '-bookings',             // Audience Bookings.
			self::MENU_SLUG . '-settings',             // Settings (hosts Import & Export as a tab).
		);

		// Rows that stay off the menu even when something registers them:
		// the auto-generated duplicate of the parent, and "New Personal
		// Calendar", whose screen is reached from the list's Add New button
		// and the Dashboard's quick action (#1761).
		$dropped = array(
			self::MENU_SLUG,
			'post-new.php?post_type=ffc_self_scheduling',
		);

		$new_items = array();
		foreach ( $ordered_slugs as $slug ) {
			if ( isset( $by_slug[ $slug ] ) ) {
				$new_items[] = $by_slug[ $slug ];
			}
		}

		// Append any remaining items not in our ordered list (safety net).
		foreach ( $by_slug as $slug => $item ) {
			if ( in_array( $slug, $dropped, true ) || in_array( $slug, $ordered_slugs, true ) ) {
				continue;
			}
			$new_items[] = $item;
		}

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Intentionally reordering WP's own $submenu array to control admin menu appearance.
		$submenu[ self::MENU_SLUG ] = $new_items;
	}

	/**
	 * Handle form submissions — delegates to sub-page handlers
	 *
	 * @return void
	 */
	public function handle_form_submissions(): void {
		// Only process on our admin pages.
		if ( strpos( \FreeFormCertificate\Core\RequestInput::get_get_string( 'page' ), self::MENU_SLUG ) !== 0 ) {
			return;
		}

		$this->settings->handle_global_holiday_actions();
		$this->settings->handle_visibility_settings();
		$this->import->handle_csv_import();
		$this->calendar->handle_actions();
		$this->environment->handle_actions();
		$this->audience->handle_actions();
		$this->attendance->handle_actions();
	}
}
