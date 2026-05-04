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
		// Common boilerplate shared by every operator role: hide the
		// content-creation surfaces (Posts / Comments / Pages / Tools /
		// Plugins / Themes / Users) and prune the matching admin-bar
		// nodes. Roles override `landing_page` and `allowed_pages` per
		// domain.
		$shared_hidden_menus    = array(
			'edit.php',                        // Posts.
			'edit.php?post_type=page',         // Pages.
			'edit-comments.php',               // Comments.
			'tools.php',                       // Tools.
			'plugins.php',                     // Plugins.
			'themes.php',                      // Appearance.
			'users.php',                       // Users.
		);
		$shared_admin_bar_nodes = array(
			'new-content', // Top "+ New" dropdown.
			'comments',    // Pending comment count.
		);

		return array(
			'ffc_certificate_manager'     => array(
				'landing_page'         => 'edit.php?post_type=ffc_form',
				'allowed_pages'        => array( 'edit.php?post_type=ffc_form', 'ffc-submissions', 'ffc-settings', 'ffc-activity-log' ),
				'hide_core_menus'      => $shared_hidden_menus,
				'hide_admin_bar_nodes' => $shared_admin_bar_nodes,
			),
			'ffc_self_scheduling_manager' => array(
				'landing_page'         => 'ffc-self-scheduling',
				'allowed_pages'        => array( 'ffc-self-scheduling', 'ffc-self-scheduling-appointments', 'ffc-self-scheduling-settings' ),
				'hide_core_menus'      => $shared_hidden_menus,
				'hide_admin_bar_nodes' => $shared_admin_bar_nodes,
			),
			'ffc_audience_manager'        => array(
				'landing_page'         => 'ffc-scheduling',
				'allowed_pages'        => array( 'ffc-scheduling', 'ffc-audiences', 'ffc-environments', 'ffc-aud-import', 'ffc-aud-settings' ),
				'hide_core_menus'      => $shared_hidden_menus,
				'hide_admin_bar_nodes' => $shared_admin_bar_nodes,
			),
			'ffc_reregistration_manager'  => array(
				'landing_page'         => 'ffc-reregistration',
				'allowed_pages'        => array( 'ffc-reregistration', 'ffc-custom-fields' ),
				'hide_core_menus'      => $shared_hidden_menus,
				'hide_admin_bar_nodes' => $shared_admin_bar_nodes,
			),
			'ffc_operator'                => array(
				'landing_page'         => 'ffc-activity-log',
				'allowed_pages'        => array( 'ffc-activity-log', 'ffc-recruitment' ),
				'hide_core_menus'      => $shared_hidden_menus,
				'hide_admin_bar_nodes' => $shared_admin_bar_nodes,
			),
			// Every recruitment tier shares the recruitment admin landing.
			'ffc_recruitment_auditor'     => array(
				'landing_page'         => 'ffc-recruitment',
				'allowed_pages'        => array( 'ffc-recruitment' ),
				'hide_core_menus'      => $shared_hidden_menus,
				'hide_admin_bar_nodes' => $shared_admin_bar_nodes,
			),
			'ffc_recruitment_operator'    => array(
				'landing_page'         => 'ffc-recruitment',
				'allowed_pages'        => array( 'ffc-recruitment' ),
				'hide_core_menus'      => $shared_hidden_menus,
				'hide_admin_bar_nodes' => $shared_admin_bar_nodes,
			),
			'ffc_recruitment_manager'     => array(
				'landing_page'         => 'ffc-recruitment',
				'allowed_pages'        => array( 'ffc-recruitment', 'ffc-settings' ),
				'hide_core_menus'      => $shared_hidden_menus,
				'hide_admin_bar_nodes' => $shared_admin_bar_nodes,
			),
			'ffc_recruitment_admin'       => array(
				'landing_page'         => 'ffc-recruitment',
				'allowed_pages'        => array( 'ffc-recruitment', 'ffc-settings' ),
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
