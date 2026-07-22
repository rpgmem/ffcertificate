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

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hides core WP admin menus + blocks URL access + prunes the admin bar
 * for FFC operator roles.
 */
final class AdminMenuVisibility {

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
		// Per-domain navigation (#739 §3.2 — one page-set per domain, reused
		// across that domain's tier ladder). Each entry is built by
		// {@see self::policy_entry()}, which adds the shared core-menu hiding +
		// admin-bar pruning; all tiers of a domain share the same navigation
		// (the capability gates decide read vs write), so the menu scope is
		// per-domain, not per-tier.
		$certificates_pages   = array( 'edit.php?post_type=ffc_form', 'ffc-submissions', 'ffc-settings', 'ffc-activity-log' );
		$forms_pages          = array( 'edit.php?post_type=ffc_form', 'ffc-settings' );
		$appointments_pages   = array( 'ffc-self-scheduling', 'ffc-self-scheduling-appointments', 'ffc-self-scheduling-settings' );
		$calendars_pages      = array( 'ffc-self-scheduling', 'ffc-self-scheduling-settings' );
		$audiences_pages      = array( 'ffc-scheduling', 'ffc-audiences', 'ffc-environments', 'ffc-aud-import', 'ffc-aud-settings' );
		$reregistration_pages = array( 'ffc-reregistration', 'ffc-custom-fields' );
		$recruitment_pages    = array( 'ffc-recruitment' );
		$recruitment_admin_pg = array( 'ffc-recruitment', 'ffc-settings' );

		return array(
			// Certificates ladder.
			'ffc_certificates_viewer'     => self::policy_entry( 'edit.php?post_type=ffc_form', $certificates_pages ),
			'ffc_certificates_operator'   => self::policy_entry( 'edit.php?post_type=ffc_form', $certificates_pages ),
			'ffc_certificates_manager'    => self::policy_entry( 'edit.php?post_type=ffc_form', $certificates_pages ),
			// Forms (certificate-form structure).
			'ffc_forms_viewer'            => self::policy_entry( 'edit.php?post_type=ffc_form', $forms_pages ),
			'ffc_forms_manager'           => self::policy_entry( 'edit.php?post_type=ffc_form', $forms_pages ),
			// Appointments ladder.
			'ffc_appointments_viewer'     => self::policy_entry( 'ffc-self-scheduling', $appointments_pages ),
			'ffc_appointments_operator'   => self::policy_entry( 'ffc-self-scheduling', $appointments_pages ),
			'ffc_appointments_manager'    => self::policy_entry( 'ffc-self-scheduling', $appointments_pages ),
			// Calendars (self-scheduling structure).
			'ffc_calendars_viewer'        => self::policy_entry( 'ffc-self-scheduling', $calendars_pages ),
			'ffc_calendars_manager'       => self::policy_entry( 'ffc-self-scheduling', $calendars_pages ),
			// Audiences ladder.
			'ffc_audiences_viewer'        => self::policy_entry( 'ffc-scheduling', $audiences_pages ),
			'ffc_audiences_operator'      => self::policy_entry( 'ffc-scheduling', $audiences_pages ),
			'ffc_audiences_manager'       => self::policy_entry( 'ffc-scheduling', $audiences_pages ),
			// Reregistration ladder.
			'ffc_reregistration_viewer'   => self::policy_entry( 'ffc-reregistration', $reregistration_pages ),
			'ffc_reregistration_operator' => self::policy_entry( 'ffc-reregistration', $reregistration_pages ),
			'ffc_reregistration_manager'  => self::policy_entry( 'ffc-reregistration', $reregistration_pages ),
			// Cross-domain read-only.
			'ffc_readonly'                => self::policy_entry( 'ffc-activity-log', array( 'ffc-activity-log', 'ffc-recruitment' ) ),
			// Recruitment ladder (every tier shares the recruitment landing).
			'ffc_recruitment_viewer'      => self::policy_entry( 'ffc-recruitment', $recruitment_pages ),
			'ffc_recruitment_operator'    => self::policy_entry( 'ffc-recruitment', $recruitment_pages ),
			'ffc_recruitment_manager'     => self::policy_entry( 'ffc-recruitment', $recruitment_admin_pg ),
			'ffc_recruitment_admin'       => self::policy_entry( 'ffc-recruitment', $recruitment_admin_pg ),
		);
	}

	/**
	 * Build one per-role policy entry.
	 *
	 * Every role shares the same core-menu hiding + admin-bar pruning (hide the
	 * content-creation surfaces — Posts / Comments / Pages / Tools / Plugins /
	 * Themes / Users — and prune the matching admin-bar nodes) and only differs
	 * in its landing page + the pages it may reach.
	 *
	 * @param string       $landing Landing-page slug (where a forbidden URL redirects).
	 * @param list<string> $pages   Allowed-page slugs for this role.
	 * @return array{landing_page: string, allowed_pages: list<string>, hide_core_menus: list<string>, hide_admin_bar_nodes: list<string>}
	 */
	private static function policy_entry( string $landing, array $pages ): array {
		return array(
			'landing_page'         => $landing,
			'allowed_pages'        => $pages,
			'hide_core_menus'      => array(
				'edit.php',                // Posts.
				'edit.php?post_type=page', // Pages.
				'edit-comments.php',       // Comments.
				'tools.php',               // Tools.
				'plugins.php',             // Plugins.
				'themes.php',              // Appearance.
				'users.php',               // Users.
			),
			'hide_admin_bar_nodes' => array(
				'new-content', // Top "+ New" dropdown.
				'comments',    // Pending comment count.
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
	 * Site admins (`manage_options`) bypass — return null. Multi-role
	 * users return the policy of the first matching FFC role found, but
	 * only if NONE of their other roles is a non-FFC role with admin-like
	 * capabilities (we just check for `manage_options` here as a proxy —
	 * the bypass branch above already returned).
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
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing decision.
			$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
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
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing decision.
			$query_string = isset( $_SERVER['QUERY_STRING'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['QUERY_STRING'] ) ) : '';
			$candidate    = '' === $query_string ? $current_page : $current_page . '?' . $query_string;

			foreach ( $policy['allowed_pages'] as $allowed ) {
				if ( $current_page === $allowed || str_starts_with( $candidate, $allowed ) ) {
					return;
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
