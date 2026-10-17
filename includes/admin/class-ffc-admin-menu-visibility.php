<?php
/**
 * AdminMenuVisibility
 *
 * Defense-in-depth UX layer that hides core WP admin menus, blocks
 * direct-URL access to non-FFC admin pages, and prunes the top admin
 * bar — all gated by FFC role membership.
 *
 * The wp-admin menu items registered by the FFC plugin already auto-hide
 * when the user lacks the corresponding capability (built-in WP behaviour
 * for `add_menu_page()`'s `$capability` argument). This class covers
 * what WP doesn't: it removes the *core* WP menus (Posts, Comments,
 * Tools, Plugins, etc.) from operators that have no business there, and
 * blocks URL access in case someone digs up an admin URL by hand.
 *
 * NOT a security boundary — caps remain the source of truth. This is
 * UX-layer scoping so a "Recruitment Manager" doesn't see a wall of
 * irrelevant WP menus.
 *
 * Bypass: any user with `manage_options` (WP admin) is exempt from
 * every restriction in this class.
 *
 * @package FreeFormCertificate\Admin
 * @since   6.2.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

use FreeFormCertificate\Audience\AudienceAdminPage;
use FreeFormCertificate\Core\RequestInput;
use FreeFormCertificate\Recruitment\RecruitmentAdminPage;
use FreeFormCertificate\Reregistration\ReregistrationAdmin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hides core WP admin menus + blocks URL access + prunes the admin bar
 * for FFC operator roles.
 */
final class AdminMenuVisibility {

	/**
	 * `?page=` of the Appointments list, registered by
	 * `SelfScheduling\SelfSchedulingAdmin::add_submenu_pages()`.
	 */
	public const APPOINTMENTS_PAGE = 'ffc-appointments';

	/**
	 * Post type of the Personal Calendars, registered by
	 * `SelfScheduling\SelfSchedulingCPT::register_calendar_cpt()`.
	 */
	public const PERSONAL_CALENDAR_POST_TYPE = 'ffc_self_scheduling';

	/**
	 * `admin_action_*` the Personal Calendars "Duplicate" row action posts to.
	 */
	public const DUPLICATE_CALENDAR_ACTION = 'ffc_duplicate_calendar';

	/**
	 * Suffixes `AudienceAdminPage::add_admin_menus()` appends to its menu slug.
	 *
	 * @var list<string>
	 */
	public const AUDIENCE_SCREEN_SUFFIXES = array( 'dashboard', 'calendars', 'environments', 'audiences', 'bookings', 'settings' );

	/**
	 * `?page=` of the Short URLs list, registered by
	 * `UrlShortener\UrlShortenerAdminPage::register_menu()`.
	 */
	public const SHORT_URLS_PAGE = 'ffc-short-urls';

	/**
	 * `?page=` of the Date Messages screen, registered by
	 * `DateMessages\DateMessagesAdminPage::register_menu()`.
	 */
	public const DATE_MESSAGES_PAGE = 'ffc-date-messages';

	/**
	 * `?page=` of the QR Code Generator, registered by
	 * `UrlShortener\QrGeneratorPage::register_menu()`.
	 */
	public const QR_GENERATOR_PAGE = 'ffc-qr-generator';

	/**
	 * Per-role visibility policy. Each entry is consumed at three
	 * sites — `apply_menu_visibility()` (admin_menu), `block_url_access()`
	 * (admin_init), and `prune_admin_bar()` (admin_bar_menu).
	 *
	 * - `allowed_pages`: list of `?page=…` slugs that are reachable for
	 *   this role. The role's own admin landing page should be the first
	 *   entry; that's what `block_url_access()` redirects to when an
	 *   operator hits a forbidden URL. Allowed admin filenames
	 *   (`profile.php`, `admin-ajax.php`, `admin-post.php`) are added
	 *   uniformly to every role and don't need to be listed.
	 * - `hide_core_menus`: list of WP-core top-level menu slugs to
	 *   `remove_menu_page()`. Submenus removed in their own array.
	 * - `hide_admin_bar_nodes`: list of `$wp_admin_bar->remove_node()`
	 *   ids to drop from the top bar (e.g. `new-content`, `comments`).
	 *
	 * Adding a new role here is the only action required to give it
	 * the same scoping treatment as the existing entries.
	 *
	 * @return array<string, array{landing_page: string, allowed_pages: list<string>, hide_core_menus: list<string>, hide_admin_bar_nodes: list<string>}>
	 */
	private static function policy(): array {
		// Common boilerplate shared by every operator role: hide the
		// content-creation surfaces (Posts / Comments / Pages / Tools /
		// Plugins / Themes / Users) and prune the matching admin-bar nodes.
		$shared_hidden_menus    = array(
			'edit.php',
			'edit.php?post_type=page',
			'edit-comments.php',
			'tools.php',
			'plugins.php',
			'themes.php',
			'users.php',
		);
		$shared_admin_bar_nodes = array( 'new-content', 'comments' );

		// The Scheduling screens (#1769). The audience half is derived from
		// the menu's own slug; the self-scheduling half is literal, because
		// naming SelfScheduling here would add an Admin → SelfScheduling
		// edge (AdminMenuVisibilitySchedulingTest holds the literals to the
		// registrations).
		$personal_calendars = 'edit.php?post_type=' . self::PERSONAL_CALENDAR_POST_TYPE;
		$audience_screens   = array( AudienceAdminPage::MENU_SLUG );
		foreach ( self::AUDIENCE_SCREEN_SUFFIXES as $suffix ) {
			$audience_screens[] = AudienceAdminPage::MENU_SLUG . '-' . $suffix;
		}

		// The Certificates screens (#1776). The forms' list is also the parent
		// of the Dashboard and Submissions submenus, whose menu links take the
		// `edit.php?post_type=ffc_form&page=…` form; the certificate roles hold
		// no forms capability, so they land on the Dashboard instead.
		$forms_list          = 'edit.php?post_type=' . CPT::POST_TYPE;
		$certificate_screens = array( CertificatesDashboard::MENU_SLUG, $forms_list, Admin::SUBMISSIONS_PAGE );
		// The forms side: the forms' list (and its editor), the Document
		// Templates list and their read-only sample-data preview.
		$form_screens = array( $forms_list, 'edit.php?post_type=' . CertTemplateCpt::POST_TYPE, 'admin.php?action=' . CertTemplateAdminScreen::PREVIEW_ACTION );
		// The forms' write actions, page-less `admin.php?action=…` URLs whose
		// handlers all check `ffc_manage_forms` (#1781): form Duplicate and
		// the Document Templates row and toolbar actions.
		$form_write_actions = array(
			'admin.php?action=' . CPT::DUPLICATE_ACTION,
			'admin.php?action=' . CertTemplateAdminScreen::TOGGLE_ACTION,
			'admin.php?action=' . CertTemplateAdminScreen::DUPLICATE_ACTION,
			'admin.php?action=' . CertTemplateAdminScreen::EXPORT_ACTION,
			'admin.php?action=' . CertTemplateAdminScreen::RESTORE_ACTION,
		);

		return array(
			// Certificates ladder.
			'ffc_certificates_viewer'     => array(
				'landing_page'         => CertificatesDashboard::MENU_SLUG,
				'allowed_pages'        => $certificate_screens,
				'hide_core_menus'      => $shared_hidden_menus,
				'hide_admin_bar_nodes' => $shared_admin_bar_nodes,
			),
			'ffc_certificates_operator'   => array(
				'landing_page'         => CertificatesDashboard::MENU_SLUG,
				'allowed_pages'        => $certificate_screens,
				'hide_core_menus'      => $shared_hidden_menus,
				'hide_admin_bar_nodes' => $shared_admin_bar_nodes,
			),
			'ffc_certificates_manager'    => array(
				'landing_page'         => CertificatesDashboard::MENU_SLUG,
				'allowed_pages'        => $certificate_screens,
				'hide_core_menus'      => $shared_hidden_menus,
				'hide_admin_bar_nodes' => $shared_admin_bar_nodes,
			),
			'ffc_certificates_admin'      => array(
				'landing_page'         => CertificatesDashboard::MENU_SLUG,
				'allowed_pages'        => $certificate_screens,
				'hide_core_menus'      => $shared_hidden_menus,
				'hide_admin_bar_nodes' => $shared_admin_bar_nodes,
			),
			// Forms (certificate-form structure).
			'ffc_forms_viewer'            => array(
				'landing_page'         => $forms_list,
				'allowed_pages'        => $form_screens,
				'hide_core_menus'      => $shared_hidden_menus,
				'hide_admin_bar_nodes' => $shared_admin_bar_nodes,
			),
			'ffc_forms_manager'           => array(
				'landing_page'         => $forms_list,
				'allowed_pages'        => array_merge( $form_screens, $form_write_actions ),
				'hide_core_menus'      => $shared_hidden_menus,
				'hide_admin_bar_nodes' => $shared_admin_bar_nodes,
			),
			// Appointments ladder. Every tier holds `ffc_view_appointments`
			// and nothing on the calendars or audiences side, so the
			// Appointments list (and its detail view, same slug) is the
			// whole Scheduling surface its capabilities open.
			'ffc_appointments_viewer'     => array(
				'landing_page'         => self::APPOINTMENTS_PAGE,
				'allowed_pages'        => array( self::APPOINTMENTS_PAGE ),
				'hide_core_menus'      => $shared_hidden_menus,
				'hide_admin_bar_nodes' => $shared_admin_bar_nodes,
			),
			'ffc_appointments_operator'   => array(
				'landing_page'         => self::APPOINTMENTS_PAGE,
				'allowed_pages'        => array( self::APPOINTMENTS_PAGE ),
				'hide_core_menus'      => $shared_hidden_menus,
				'hide_admin_bar_nodes' => $shared_admin_bar_nodes,
			),
			'ffc_appointments_admin'      => array(
				'landing_page'         => self::APPOINTMENTS_PAGE,
				'allowed_pages'        => array( self::APPOINTMENTS_PAGE ),
				'hide_core_menus'      => $shared_hidden_menus,
				'hide_admin_bar_nodes' => $shared_admin_bar_nodes,
			),
			'ffc_appointments_manager'    => array(
				'landing_page'         => self::APPOINTMENTS_PAGE,
				'allowed_pages'        => array( self::APPOINTMENTS_PAGE ),
				'hide_core_menus'      => $shared_hidden_menus,
				'hide_admin_bar_nodes' => $shared_admin_bar_nodes,
			),
			// Calendars (self-scheduling structure): the Personal Calendars
			// post type. Its list entry also opens `post.php` / `post-new.php`
			// for that post type (see block_url_access()).
			'ffc_calendars_viewer'        => array(
				'landing_page'         => $personal_calendars,
				'allowed_pages'        => array( $personal_calendars ),
				'hide_core_menus'      => $shared_hidden_menus,
				'hide_admin_bar_nodes' => $shared_admin_bar_nodes,
			),
			'ffc_calendars_manager'       => array(
				'landing_page'         => $personal_calendars,
				'allowed_pages'        => array( $personal_calendars, 'admin.php?action=' . self::DUPLICATE_CALENDAR_ACTION ),
				'hide_core_menus'      => $shared_hidden_menus,
				'hide_admin_bar_nodes' => $shared_admin_bar_nodes,
			),
			// Audiences ladder: every screen under the Scheduling menu that is
			// gated on `ffc_view_audiences`.
			'ffc_audiences_viewer'        => array(
				'landing_page'         => AudienceAdminPage::MENU_SLUG,
				'allowed_pages'        => $audience_screens,
				'hide_core_menus'      => $shared_hidden_menus,
				'hide_admin_bar_nodes' => $shared_admin_bar_nodes,
			),
			'ffc_audiences_operator'      => array(
				'landing_page'         => AudienceAdminPage::MENU_SLUG,
				'allowed_pages'        => $audience_screens,
				'hide_core_menus'      => $shared_hidden_menus,
				'hide_admin_bar_nodes' => $shared_admin_bar_nodes,
			),
			'ffc_audiences_manager'       => array(
				'landing_page'         => AudienceAdminPage::MENU_SLUG,
				'allowed_pages'        => $audience_screens,
				'hide_core_menus'      => $shared_hidden_menus,
				'hide_admin_bar_nodes' => $shared_admin_bar_nodes,
			),
			'ffc_audiences_admin'         => array(
				'landing_page'         => AudienceAdminPage::MENU_SLUG,
				'allowed_pages'        => $audience_screens,
				'hide_core_menus'      => $shared_hidden_menus,
				'hide_admin_bar_nodes' => $shared_admin_bar_nodes,
			),
			// Reregistration ladder. Custom Fields is registered under
			// `ffc_manage_reregistration`, so only the manager lists it (#1782).
			'ffc_reregistration_viewer'   => array(
				'landing_page'         => ReregistrationAdmin::MENU_SLUG,
				'allowed_pages'        => array( ReregistrationAdmin::MENU_SLUG ),
				'hide_core_menus'      => $shared_hidden_menus,
				'hide_admin_bar_nodes' => $shared_admin_bar_nodes,
			),
			'ffc_reregistration_operator' => array(
				'landing_page'         => ReregistrationAdmin::MENU_SLUG,
				'allowed_pages'        => array( ReregistrationAdmin::MENU_SLUG ),
				'hide_core_menus'      => $shared_hidden_menus,
				'hide_admin_bar_nodes' => $shared_admin_bar_nodes,
			),
			'ffc_reregistration_manager'  => array(
				'landing_page'         => ReregistrationAdmin::MENU_SLUG,
				'allowed_pages'        => array( ReregistrationAdmin::MENU_SLUG, ReregistrationAdmin::CUSTOM_FIELDS_SLUG ),
				'hide_core_menus'      => $shared_hidden_menus,
				'hide_admin_bar_nodes' => $shared_admin_bar_nodes,
			),
			'ffc_reregistration_admin'    => array(
				'landing_page'         => ReregistrationAdmin::MENU_SLUG,
				'allowed_pages'        => array( ReregistrationAdmin::MENU_SLUG, ReregistrationAdmin::CUSTOM_FIELDS_SLUG ),
				'hide_core_menus'      => $shared_hidden_menus,
				'hide_admin_bar_nodes' => $shared_admin_bar_nodes,
			),
			// Cross-domain read-only: every screen one of its view caps opens
			// (#1776). Writes stay with each handler's own capability check.
			// It lands on the Certificates dashboard since the activity log,
			// its old landing, became the FFC Administrator's alone (#1860).
			'ffc_readonly'                => array(
				'landing_page'         => CertificatesDashboard::MENU_SLUG,
				'allowed_pages'        => array_values(
					array_unique(
						array_merge(
							$certificate_screens,
							$form_screens,
							array( ReregistrationAdmin::MENU_SLUG, RecruitmentAdminPage::PAGE_SLUG, self::APPOINTMENTS_PAGE, $personal_calendars, self::SHORT_URLS_PAGE, self::DATE_MESSAGES_PAGE ),
							$audience_screens
						)
					)
				),
				'hide_core_menus'      => $shared_hidden_menus,
				'hide_admin_bar_nodes' => $shared_admin_bar_nodes,
			),
			// Recruitment ladder (every tier shares the recruitment landing; its
			// settings are a tab of that screen, not FFC Settings, #1782).
			'ffc_recruitment_viewer'      => array(
				'landing_page'         => RecruitmentAdminPage::PAGE_SLUG,
				'allowed_pages'        => array( RecruitmentAdminPage::PAGE_SLUG ),
				'hide_core_menus'      => $shared_hidden_menus,
				'hide_admin_bar_nodes' => $shared_admin_bar_nodes,
			),
			'ffc_recruitment_operator'    => array(
				'landing_page'         => RecruitmentAdminPage::PAGE_SLUG,
				'allowed_pages'        => array( RecruitmentAdminPage::PAGE_SLUG ),
				'hide_core_menus'      => $shared_hidden_menus,
				'hide_admin_bar_nodes' => $shared_admin_bar_nodes,
			),
			'ffc_recruitment_manager'     => array(
				'landing_page'         => RecruitmentAdminPage::PAGE_SLUG,
				'allowed_pages'        => array( RecruitmentAdminPage::PAGE_SLUG ),
				'hide_core_menus'      => $shared_hidden_menus,
				'hide_admin_bar_nodes' => $shared_admin_bar_nodes,
			),
			'ffc_recruitment_admin'       => array(
				'landing_page'         => RecruitmentAdminPage::PAGE_SLUG,
				'allowed_pages'        => array( RecruitmentAdminPage::PAGE_SLUG ),
				'hide_core_menus'      => $shared_hidden_menus,
				'hide_admin_bar_nodes' => $shared_admin_bar_nodes,
			),
			// Short URLs ladder (#1860). The QR generator is registered under
			// `ffc_manage_url_shortener`, so only the manager tiers list it.
			'ffc_url_shortener_viewer'    => array(
				'landing_page'         => self::SHORT_URLS_PAGE,
				'allowed_pages'        => array( self::SHORT_URLS_PAGE ),
				'hide_core_menus'      => $shared_hidden_menus,
				'hide_admin_bar_nodes' => $shared_admin_bar_nodes,
			),
			'ffc_url_shortener_operator'  => array(
				'landing_page'         => self::SHORT_URLS_PAGE,
				'allowed_pages'        => array( self::SHORT_URLS_PAGE ),
				'hide_core_menus'      => $shared_hidden_menus,
				'hide_admin_bar_nodes' => $shared_admin_bar_nodes,
			),
			'ffc_url_shortener_manager'   => array(
				'landing_page'         => self::SHORT_URLS_PAGE,
				'allowed_pages'        => array( self::SHORT_URLS_PAGE, self::QR_GENERATOR_PAGE ),
				'hide_core_menus'      => $shared_hidden_menus,
				'hide_admin_bar_nodes' => $shared_admin_bar_nodes,
			),
			'ffc_url_shortener_admin'     => array(
				'landing_page'         => self::SHORT_URLS_PAGE,
				'allowed_pages'        => array( self::SHORT_URLS_PAGE, self::QR_GENERATOR_PAGE ),
				'hide_core_menus'      => $shared_hidden_menus,
				'hide_admin_bar_nodes' => $shared_admin_bar_nodes,
			),
			// Date Messages ladder (#1860): one screen, its tabs included.
			'ffc_date_messages_viewer'    => array(
				'landing_page'         => self::DATE_MESSAGES_PAGE,
				'allowed_pages'        => array( self::DATE_MESSAGES_PAGE ),
				'hide_core_menus'      => $shared_hidden_menus,
				'hide_admin_bar_nodes' => $shared_admin_bar_nodes,
			),
			'ffc_date_messages_operator'  => array(
				'landing_page'         => self::DATE_MESSAGES_PAGE,
				'allowed_pages'        => array( self::DATE_MESSAGES_PAGE ),
				'hide_core_menus'      => $shared_hidden_menus,
				'hide_admin_bar_nodes' => $shared_admin_bar_nodes,
			),
			'ffc_date_messages_manager'   => array(
				'landing_page'         => self::DATE_MESSAGES_PAGE,
				'allowed_pages'        => array( self::DATE_MESSAGES_PAGE ),
				'hide_core_menus'      => $shared_hidden_menus,
				'hide_admin_bar_nodes' => $shared_admin_bar_nodes,
			),
			'ffc_date_messages_admin'     => array(
				'landing_page'         => self::DATE_MESSAGES_PAGE,
				'allowed_pages'        => array( self::DATE_MESSAGES_PAGE ),
				'hide_core_menus'      => $shared_hidden_menus,
				'hide_admin_bar_nodes' => $shared_admin_bar_nodes,
			),
			// FFC Settings ladder (#1860): the Settings page; each tab gates
			// itself on its own capability.
			'ffc_settings_viewer'         => array(
				'landing_page'         => Settings::MENU_SLUG,
				'allowed_pages'        => array( Settings::MENU_SLUG ),
				'hide_core_menus'      => $shared_hidden_menus,
				'hide_admin_bar_nodes' => $shared_admin_bar_nodes,
			),
			'ffc_settings_operator'       => array(
				'landing_page'         => Settings::MENU_SLUG,
				'allowed_pages'        => array( Settings::MENU_SLUG ),
				'hide_core_menus'      => $shared_hidden_menus,
				'hide_admin_bar_nodes' => $shared_admin_bar_nodes,
			),
			'ffc_settings_manager'        => array(
				'landing_page'         => Settings::MENU_SLUG,
				'allowed_pages'        => array( Settings::MENU_SLUG ),
				'hide_core_menus'      => $shared_hidden_menus,
				'hide_admin_bar_nodes' => $shared_admin_bar_nodes,
			),
			'ffc_settings_admin'          => array(
				'landing_page'         => Settings::MENU_SLUG,
				'allowed_pages'        => array( Settings::MENU_SLUG ),
				'hide_core_menus'      => $shared_hidden_menus,
				'hide_admin_bar_nodes' => $shared_admin_bar_nodes,
			),
		);
	}

	/**
	 * Hook every WP admin filter we need to scope the surface.
	 *
	 * Hook priorities: late so we run after every other plugin/theme
	 * that might add menus + admin-bar nodes.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'apply_menu_visibility' ), 9999 );
		add_action( 'admin_init', array( __CLASS__, 'block_url_access' ) );
		add_action( 'admin_bar_menu', array( __CLASS__, 'prune_admin_bar' ), 9999 );
	}

	/**
	 * Resolve the policy that applies to the current user, if any.
	 *
	 * Site admins (`manage_options`) bypass -- return null. The scope applies
	 * only when EVERY role the user holds is an FFC role (`ffc_` prefix): one
	 * WordPress role (editor, author...) or another plugin's role means the
	 * account is also someone's normal wp-admin login, and hiding Posts or
	 * redirecting `edit.php` would take that away (#1600). It is the same
	 * "every role" rule `AccessControl::block_wp_admin()` uses. Among FFC roles,
	 * the first one with a policy wins.
	 *
	 * @return array{landing_page: string, allowed_pages: list<string>, hide_core_menus: list<string>, hide_admin_bar_nodes: list<string>}|null
	 */
	private static function resolve_policy_for_current_user(): ?array {
		if ( current_user_can( 'manage_options' ) ) {
			return null;
		}
		$user = wp_get_current_user();
		if ( empty( $user->roles ) ) {
			return null;
		}
		foreach ( (array) $user->roles as $role ) {
			if ( ! str_starts_with( (string) $role, 'ffc_' ) ) {
				return null;
			}
		}
		$policy_map = self::policy();
		foreach ( (array) $user->roles as $role ) {
			if ( isset( $policy_map[ $role ] ) ) {
				return $policy_map[ $role ];
			}
		}
		return null;
	}

	/**
	 * Remove core WP top-level menus per the role's policy. Hooked late
	 * (`admin_menu` priority 9999) so it runs after every other registrar.
	 *
	 * @return void
	 */
	public static function apply_menu_visibility(): void {
		$policy = self::resolve_policy_for_current_user();
		if ( null === $policy ) {
			return;
		}
		foreach ( $policy['hide_core_menus'] as $slug ) {
			remove_menu_page( $slug );
		}
	}

	/**
	 * Block URL access to admin pages outside the role's allow-list.
	 *
	 * If an operator hits `/wp-admin/edit.php` directly (because they
	 * remember the URL or land on a stale bookmark), redirect to their
	 * landing page instead. Wp-ajax / wp-post / profile.php are
	 * unconditionally allowed — those underpin essential UX.
	 *
	 * @return void
	 */
	public static function block_url_access(): void {
		if ( wp_doing_ajax() ) {
			return;
		}

		$policy = self::resolve_policy_for_current_user();
		if ( null === $policy ) {
			return;
		}

		global $pagenow;
		$current_page = isset( $pagenow ) ? (string) $pagenow : '';

		// Always-allowed admin filenames.
		$always_allowed = array( 'admin-ajax.php', 'admin-post.php', 'profile.php', 'index.php' );
		if ( in_array( $current_page, $always_allowed, true ) ) {
			return;
		}

		// `admin.php?page=…` — check the page slug against the allow-list.
		if ( 'admin.php' === $current_page ) {
			$page = RequestInput::get_get_key( 'page' );
			// `admin.php?action=…` with no page dispatches `admin_action_*`
			// (the calendar and form "Duplicate" row actions, the Document
			// Templates actions); only an action the
			// role lists passes, and the handler checks its own capability.
			$action = RequestInput::get_get_key( 'action' );
			if ( '' === $page && '' !== $action && in_array( 'admin.php?action=' . $action, $policy['allowed_pages'], true ) ) {
				return;
			}
			foreach ( $policy['allowed_pages'] as $allowed ) {
				if ( $page === $allowed || 'admin.php?page=' . $page === $allowed ) {
					return;
				}
				// Some allow-list entries store the full `?page=…` form;
				// strip the leading `admin.php?page=` for the equality check.
				if ( str_starts_with( $allowed, 'admin.php?page=' ) && substr( $allowed, 15 ) === $page ) {
					return;
				}
			}
		} else {
			// Non-`admin.php` filename (e.g. `edit.php`) — accept if the
			// full filename (with optional query string) matches an allow-list
			// entry; reject otherwise.
			$query_string = isset( $_SERVER['QUERY_STRING'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['QUERY_STRING'] ) ) : '';
			$candidate    = '' === $query_string ? $current_page : $current_page . '?' . $query_string;

			foreach ( $policy['allowed_pages'] as $allowed ) {
				if ( $current_page === $allowed || str_starts_with( $candidate, $allowed ) ) {
					return;
				}
				// A post type's list (`edit.php?post_type=X`) opens every screen
				// of that post type: the list whatever order its filters put in
				// the query string, `post-new.php?post_type=X`, and `post.php`
				// for a post of type X — read from `?post=` on a GET and from
				// `post_ID` on the editor's save POST (#1769).
				if ( ! str_starts_with( $allowed, 'edit.php?post_type=' ) ) {
					continue;
				}
				$post_type = substr( $allowed, strlen( 'edit.php?post_type=' ) );
				if ( in_array( $current_page, array( 'edit.php', 'post-new.php' ), true ) && RequestInput::get_get_key( 'post_type' ) === $post_type ) {
					return;
				}
				if ( 'post.php' === $current_page ) {
					$post_id = RequestInput::get_get_int( 'post' );
					if ( 0 === $post_id ) {
						$post_id = RequestInput::get_post_int( 'post_ID' );
					}
					if ( $post_id > 0 && get_post_type( $post_id ) === $post_type ) {
						return;
					}
				}
			}
		}

		// Fall-through → redirect to the role's landing page.
		$landing_url = str_starts_with( $policy['landing_page'], 'admin.php' ) || str_contains( $policy['landing_page'], '.php' )
			? admin_url( $policy['landing_page'] )
			: admin_url( 'admin.php?page=' . $policy['landing_page'] );

		wp_safe_redirect( $landing_url );
		exit;
	}

	/**
	 * Prune nodes from the top admin bar that don't apply to FFC operators.
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar Admin bar instance.
	 * @return void
	 */
	public static function prune_admin_bar( $wp_admin_bar ): void {
		$policy = self::resolve_policy_for_current_user();
		if ( null === $policy ) {
			return;
		}
		foreach ( $policy['hide_admin_bar_nodes'] as $node_id ) {
			$wp_admin_bar->remove_node( $node_id );
		}
	}
}
