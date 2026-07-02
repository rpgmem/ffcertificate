<?php
/**
 * Recruitment Admin Page
 *
 * Top-level wp-admin menu (sibling of Audience and Reregistration; see
 * {@see RecruitmentAdminPage::register_menu()}). Renders four tabs
 * server-side:
 *
 *   - Notices       — list with status badges + create form.
 *   - Adjutancies   — list + create form + delete (gated).
 *   - Candidates    — search by CPF/RF (lookup-only MVP).
 *   - Settings      — points the admin at the existing Settings tab
 *                     where the email templates + public tuning live.
 *
 * This is a deliberate MVP: full polish (status-change modals, 15s
 * countdown for promote-preview, CSV upload UI, bulk-call UI, candidate
 * detail with decrypted-field reveal toggle) is tracked as a follow-up.
 * The REST surface (sprint 9.1) already supports every operation; the
 * admin UI here is sufficient to drive a production-grade recruitment
 * cycle from the wp-admin without falling back to curl/Postman.
 *
 * Form submissions go to the same REST endpoints via fetch() in a tiny
 * inline script; nonces use `wp_create_nonce('wp_rest')` so the REST
 * controllers' standard cookie auth + cap check picks them up.
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.0.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Recruitment;

use FreeFormCertificate\Core\BadgeHtml;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and renders the wp-admin Recruitment top-level page.
 */
final class RecruitmentAdminPage {

	/** Submenu slug — used as the `?page=` query param. */
	public const PAGE_SLUG = 'ffc-recruitment';

	/** Manage cap — every write (edit screens, dispatch, status changes). */
	private const CAP = 'ffc_manage_recruitment';

	/** Read-only "view" cap — opens the admin UI as the *só vê* tier. */
	private const VIEW_CAP = 'ffc_view_recruitment';

	/**
	 * Translate a notice-lifecycle enum value into a localized label.
	 *
	 * Used by every admin surface that surfaces the raw enum value
	 * (status badges on the notices list table, the Status section of
	 * the notice edit page, transition button labels). Keeps the raw
	 * enum-as-CSS-class while echoing a localizer-friendly word for the
	 * actual visible text.
	 *
	 * @param string $status Notice status enum (`draft`, `preliminary`, `definitive`, `closed`).
	 * @return string Localized label; falls back to the raw value for unknown enums.
	 */
	public static function notice_status_label( string $status ): string {
		$map = array(
			'draft'       => __( 'Draft', 'ffcertificate' ),
			'preliminary' => __( 'Preliminary', 'ffcertificate' ),
			'definitive'  => __( 'Definitive', 'ffcertificate' ),
			'closed'      => __( 'Closed', 'ffcertificate' ),
		);
		return $map[ $status ] ?? $status;
	}

	/**
	 * Translate a classification-status enum value into a localized
	 * label for the admin surface. Distinguishes `called` from
	 * `accepted` (the public shortcode collapses both to "Called" for
	 * candidates; admins need the distinction).
	 *
	 * @param string $status Classification status enum.
	 * @return string Localized label; falls back to the raw value for unknown enums.
	 */
	public static function classification_status_label( string $status ): string {
		$map = array(
			'empty'     => __( 'Waiting', 'ffcertificate' ),
			'called'    => __( 'Called', 'ffcertificate' ),
			'accepted'  => __( 'Accepted', 'ffcertificate' ),
			'not_shown' => __( 'Did not show up', 'ffcertificate' ),
			'hired'     => __( 'Hired', 'ffcertificate' ),
			'withdrew'  => __( 'Withdrew', 'ffcertificate' ),
		);
		return $map[ $status ] ?? $status;
	}

	/**
	 * Render a classification-status badge using the same inline-style
	 * approach as the public shortcode so the configured `status_color_*`
	 * Settings override applies on admin surfaces too.
	 *
	 * Mirrors {@see RecruitmentPublicShortcode::render_status_badge()};
	 * the admin label distinguishes Called from Accepted (the public
	 * surface collapses both to "Called" for candidates).
	 *
	 * @param string $status Classification status enum.
	 * @return string Already-escaped HTML.
	 */
	public static function classification_status_badge( string $status ): string {
		$settings = RecruitmentSettings::all();
		$colors   = array(
			'empty'     => (string) $settings['status_color_empty'],
			'called'    => (string) $settings['status_color_called'],
			'accepted'  => (string) $settings['status_color_called'],
			'hired'     => (string) $settings['status_color_hired'],
			'not_shown' => (string) $settings['status_color_not_shown'],
			'withdrew'  => (string) $settings['status_color_withdrew'],
		);
		return BadgeHtml::render(
			'ffc-status-badge',
			'ffc-status-' . $status,
			$colors[ $status ] ?? '#e9ecef',
			self::classification_status_label( $status )
		);
	}

	/**
	 * Render an adjutancy badge using the per-adjutancy color column.
	 * Mirrors {@see RecruitmentPublicShortcode::render_adjutancy_badge()}.
	 *
	 * @param object|null $adjutancy Adjutancy row (or null when the row was deleted).
	 * @return string Already-escaped HTML; empty when adjutancy is null.
	 */
	public static function adjutancy_badge( ?object $adjutancy ): string {
		if ( null === $adjutancy ) {
			return '';
		}
		$color_raw = $adjutancy->color ?? '';
		$color     = is_string( $color_raw ) && '' !== $color_raw
			? $color_raw
			: RecruitmentAdjutancyReader::DEFAULT_COLOR;
		$name      = $adjutancy->name ?? '';
		return BadgeHtml::render(
			'ffc-recruitment-adjutancy-badge',
			'',
			$color,
			is_string( $name ) ? $name : ''
		);
	}

	/**
	 * Render a notice-status badge driven by the configured
	 * `notice_status_color_*` Settings keys. Same shape as the other
	 * admin/public badges (inline-styled span). Used by the notices
	 * list table and by the public shortcode's status banner so both
	 * surfaces share one operator-tunable palette.
	 *
	 * @param string $status Notice status enum (`draft`/`preliminary`/`definitive`/`closed`).
	 * @return string Already-escaped HTML.
	 */
	public static function notice_status_badge( string $status ): string {
		$settings = RecruitmentSettings::all();
		$colors   = array(
			'draft'       => (string) $settings['notice_status_color_draft'],
			'preliminary' => (string) $settings['notice_status_color_preliminary'],
			'definitive'  => (string) $settings['notice_status_color_definitive'],
			'closed'      => (string) $settings['notice_status_color_closed'],
		);
		return BadgeHtml::render(
			'ffc-status-badge',
			'ffc-status-' . $status,
			$colors[ $status ] ?? '#e9ecef',
			self::notice_status_label( $status )
		);
	}

	/**
	 * Hook callback for `admin_menu` (priority 10).
	 *
	 * Registered as a top-level menu (icon + sidebar entry) at position
	 * 26.3, alongside Scheduling (26.1) and Reregistration (26.2) so the
	 * three sibling business modules sit together in the wp-admin sidebar.
	 *
	 * @return void
	 */
	public static function register_menu(): void {
		add_menu_page(
			__( 'Recruitment', 'ffcertificate' ),
			__( 'Recruitment', 'ffcertificate' ),
			self::VIEW_CAP,
			self::PAGE_SLUG,
			array( self::class, 'render_page' ),
			'dashicons-groups',
			// Float keeps the FFC block (Scheduling 26.1, Reregistration
			// 26.2, Recruitment 26.3) contiguous in the wp-admin sidebar:
			// other plugins picking integer 26 / 27 / 28 can no longer
			// interleave between our items.
			26.3
		);

		// WP auto-creates a duplicate "Recruitment" first submenu when
		// add_menu_page also registers a callback. Replace that auto-row
		// with explicit per-tab submenus below — same parent page, but
		// each link carries `&tab=…` so the existing render_page()
		// dispatcher lands on the right section.
		global $submenu;
		if ( isset( $submenu[ self::PAGE_SLUG ] ) ) {
			$submenu[ self::PAGE_SLUG ] = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Replacing the auto-generated duplicate row is the canonical pattern.
		}
		$tabs = array(
			'notices'     => __( 'Notices', 'ffcertificate' ),
			'adjutancies' => __( 'Adjutancies', 'ffcertificate' ),
			'reasons'     => __( 'Reasons', 'ffcertificate' ),
			'candidates'  => __( 'Candidates', 'ffcertificate' ),
			'settings'    => __( 'Settings', 'ffcertificate' ),
		);
		foreach ( $tabs as $tab => $label ) {
			// Settings tab carries its own view cap; the data tabs open read-only
			// under the recruitment view cap (writes stay manage-gated).
			$tab_cap = ( 'settings' === $tab ) ? 'ffc_view_recruitment_settings' : self::VIEW_CAP;
			add_submenu_page(
				self::PAGE_SLUG,
				$label,
				$label,
				$tab_cap,
				'notices' === $tab ? self::PAGE_SLUG : self::PAGE_SLUG . '&tab=' . $tab,
				array( self::class, 'render_page' )
			);
		}

		// Highlight the submenu row that matches the open `?tab=` — WP only
		// sees the `?page=` value (`ffc-recruitment`) and would otherwise
		// keep "Notices" highlighted on every tab.
		add_filter( 'submenu_file', array( self::class, 'highlight_active_tab' ) );
	}

	/**
	 * Map the current `?tab=` onto its submenu slug so the wp-admin sidebar
	 * highlights the open recruitment tab.
	 *
	 * The tab submenus register slugs of the form `ffc-recruitment&tab=<tab>`
	 * (with `notices` as the bare `ffc-recruitment`), but WordPress decides
	 * which row is "current" from the `?page=` request var alone — which is
	 * always `ffc-recruitment`. Without this `submenu_file` override the
	 * Notices row stays highlighted no matter which tab is open.
	 *
	 * @param string|null $submenu_file The submenu file WP resolved.
	 * @return string|null
	 */
	public static function highlight_active_tab( $submenu_file ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only menu-highlight routing.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
		if ( self::PAGE_SLUG !== $page ) {
			return $submenu_file;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only menu-highlight routing.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : 'notices';
		if ( ! in_array( $tab, array( 'notices', 'adjutancies', 'reasons', 'candidates', 'settings' ), true ) ) {
			$tab = 'notices';
		}
		return ( 'notices' === $tab ) ? self::PAGE_SLUG : self::PAGE_SLUG . '&tab=' . $tab;
	}

	/**
	 * Top-level page renderer. Dispatches by `?tab=` to the tab-specific
	 * partial. Defaults to `notices`.
	 *
	 * @return void
	 */
	public static function render_page(): void {
		// 3-state: the admin UI opens read-only for ffc_view_recruitment;
		// every write (edit screens, dispatch deletes, status changes, call,
		// import) stays gated by its own cap.
		if ( ! \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( self::VIEW_CAP ) ) {
			wp_die( esc_html__( 'Access denied.', 'ffcertificate' ) );
		}
		$can_edit = \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( self::CAP );

		// Action dispatcher — row actions / GET-link operations land here
		// before the default tab render runs. Each action validates its
		// own nonce and short-circuits with `wp_safe_redirect` so the
		// page reloads onto the canonical tab URL.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Each action runs `check_admin_referer`.
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( (string) $_GET['action'] ) ) : '';

		// Edit screens hijack the whole render — they have their own
		// chrome (h1 + back link) and don't share the tab strip.
		if ( 'edit-notice' === $action || 'edit-candidate' === $action || 'edit-reason' === $action || 'edit-adjutancy' === $action ) {
			if ( ! $can_edit ) {
				wp_die( esc_html__( 'Access denied.', 'ffcertificate' ) );
			}
			echo '<div class="wrap ffc-recruitment-admin">';
			echo '<h1>' . esc_html__( 'Recruitment', 'ffcertificate' ) . '</h1>';
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flash.
			$msg = isset( $_GET['ffc_msg'] ) ? sanitize_key( wp_unslash( (string) $_GET['ffc_msg'] ) ) : '';
			if ( '' !== $msg ) {
				RecruitmentAdminPageRenderer::render_flash_notice( $msg );
			}
			if ( 'edit-notice' === $action ) {
				RecruitmentNoticeEditPage::render();
			} elseif ( 'edit-candidate' === $action ) {
				RecruitmentCandidateEditPage::render();
			} elseif ( 'edit-adjutancy' === $action ) {
				RecruitmentAdjutancyEditPage::render();
			} else {
				RecruitmentReasonEditPage::render();
			}
			echo '</div>';
			return;
		}

		if ( '' !== $action ) {
			RecruitmentAdminActions::dispatch( $action );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Tab switching is read-only.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : 'notices';
		if ( ! in_array( $tab, array( 'notices', 'adjutancies', 'reasons', 'candidates', 'settings' ), true ) ) {
			$tab = 'notices';
		}
		// 3-state: the Settings tab needs its own view cap (só vê) — the umbrella
		// alone (held by a plain Recruitment Manager) is not enough.
		if ( 'settings' === $tab && ! self::can_view_settings() ) {
			$tab = 'notices';
		}
		// 3-state (GAP I): the Reasons tab is carved out the same way — its own
		// view/manage caps, not the page-level `ffc_view_recruitment`.
		if ( 'reasons' === $tab && ! self::can_view_reasons() ) {
			$tab = 'notices';
		}

		echo '<div class="wrap ffc-recruitment-admin">';
		echo '<h1>' . esc_html__( 'Recruitment', 'ffcertificate' ) . '</h1>';

		echo '<div class="ffc-settings-tabs" data-ffc-settings-tabs>';
		RecruitmentAdminPageRenderer::render_tabs( $tab );

		printf(
			'<div id="ffc-recruitment-tabpanel-%1$s" class="ffc-settings-tabs__panel" role="tabpanel" aria-labelledby="ffc-recruitment-tabnav-%1$s" tabindex="0">',
			esc_attr( $tab )
		);

		switch ( $tab ) {
			case 'adjutancies':
				RecruitmentAdminPageRenderer::render_adjutancies_tab();
				break;
			case 'reasons':
				RecruitmentAdminPageRenderer::render_reasons_tab();
				break;
			case 'candidates':
				RecruitmentAdminPageRenderer::render_candidates_tab();
				break;
			case 'settings':
				RecruitmentAdminPageRenderer::render_settings_tab();
				break;
			default:
				RecruitmentAdminPageRenderer::render_notices_tab();
				break;
		}

		echo '</div>'; // .ffc-settings-tabs__panel
		echo '</div>'; // .ffc-settings-tabs
		echo '</div>'; // .wrap
	}

	/**
	 * 3-state gate helpers for the recruitment Settings tab. Viewing needs the
	 * view cap (admins + Recruitment Admin); editing/saving needs the manage
	 * cap (which the options.php capability filter also enforces server-side).
	 *
	 * @return bool
	 */
	public static function can_view_settings(): bool {
		return \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_view_recruitment_settings' )
			|| \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_recruitment_settings' );
	}

	/**
	 * Whether the current user can edit/save the recruitment Settings tab.
	 *
	 * @return bool
	 */
	public static function can_edit_settings(): bool {
		return \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_recruitment_settings' );
	}

	/**
	 * 3-state gate helpers for the recruitment Reasons tab (GAP I), mirroring
	 * the Settings tab. Viewing the catalog needs the dedicated view cap (or the
	 * manage cap, which implies view); creating/editing/deleting a reason needs
	 * the manage cap *strictly* — the umbrella `ffc_manage_recruitment` no longer
	 * grants it. A behavior-preserving migration seeds both caps onto existing
	 * `view`/`manage` recruitment holders so nobody loses access on upgrade.
	 *
	 * @return bool
	 */
	public static function can_view_reasons(): bool {
		return \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_view_recruitment_reasons' )
			|| \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_recruitment_reasons' );
	}

	/**
	 * Whether the current user can create/edit/delete recruitment reasons.
	 *
	 * @return bool
	 */
	public static function can_edit_reasons(): bool {
		return \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_recruitment_reasons' );
	}
}
