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

	/** Cap gating menu visibility + every render. */
	private const CAP = 'ffc_manage_recruitment';

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
			: RecruitmentAdjutancyRepository::DEFAULT_COLOR;
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
			self::CAP,
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
			add_submenu_page(
				self::PAGE_SLUG,
				$label,
				$label,
				self::CAP,
				'notices' === $tab ? self::PAGE_SLUG : self::PAGE_SLUG . '&tab=' . $tab,
				array( self::class, 'render_page' )
			);
		}
	}

	/**
	 * Top-level page renderer. Dispatches by `?tab=` to the tab-specific
	 * partial. Defaults to `notices`.
	 *
	 * @return void
	 */
	public static function render_page(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Access denied.', 'ffcertificate' ) );
		}

		// Action dispatcher — row actions / GET-link operations land here
		// before the default tab render runs. Each action validates its
		// own nonce and short-circuits with `wp_safe_redirect` so the
		// page reloads onto the canonical tab URL.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Each action runs `check_admin_referer`.
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( (string) $_GET['action'] ) ) : '';

		// Edit screens hijack the whole render — they have their own
		// chrome (h1 + back link) and don't share the tab strip.
		if ( 'edit-notice' === $action || 'edit-candidate' === $action || 'edit-reason' === $action || 'edit-adjutancy' === $action ) {
			echo '<div class="wrap ffc-recruitment-admin">';
			echo '<h1>' . esc_html__( 'Recruitment', 'ffcertificate' ) . '</h1>';
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flash.
			$msg = isset( $_GET['ffc_msg'] ) ? sanitize_key( wp_unslash( (string) $_GET['ffc_msg'] ) ) : '';
			if ( '' !== $msg ) {
				self::render_flash_notice( $msg );
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

		echo '<div class="wrap ffc-recruitment-admin">';
		echo '<h1>' . esc_html__( 'Recruitment', 'ffcertificate' ) . '</h1>';

		echo '<div class="ffc-settings-tabs" data-ffc-settings-tabs>';
		self::render_tabs( $tab );

		printf(
			'<div id="ffc-recruitment-tabpanel-%1$s" class="ffc-settings-tabs__panel" role="tabpanel" aria-labelledby="ffc-recruitment-tabnav-%1$s" tabindex="0">',
			esc_attr( $tab )
		);

		switch ( $tab ) {
			case 'adjutancies':
				self::render_adjutancies_tab();
				break;
			case 'reasons':
				self::render_reasons_tab();
				break;
			case 'candidates':
				self::render_candidates_tab();
				break;
			case 'settings':
				self::render_settings_tab();
				break;
			default:
				self::render_notices_tab();
				break;
		}

		echo '</div>'; // .ffc-settings-tabs__panel
		echo '</div>'; // .ffc-settings-tabs
		echo '</div>'; // .wrap
	}

	/**
	 * Render the vertical tab navigation (WooCommerce "Product data" style),
	 * matching the look of `page=ffc-settings` and the certificate form
	 * editor. Emits only the `<ul>` — the surrounding `.ffc-settings-tabs`
	 * container and `.ffc-settings-tabs__panel` are opened/closed by the
	 * caller in {@see render_page()}.
	 *
	 * @param string $active Current tab.
	 * @return void
	 */
	private static function render_tabs( string $active ): void {
		$tabs = array(
			'notices'     => array(
				'label' => __( 'Notices', 'ffcertificate' ),
				'icon'  => 'megaphone',
			),
			'adjutancies' => array(
				'label' => __( 'Adjutancies', 'ffcertificate' ),
				'icon'  => 'building',
			),
			'reasons'     => array(
				'label' => __( 'Reasons', 'ffcertificate' ),
				'icon'  => 'format-status',
			),
			'candidates'  => array(
				'label' => __( 'Candidates', 'ffcertificate' ),
				'icon'  => 'id',
			),
			'settings'    => array(
				'label' => __( 'Settings', 'ffcertificate' ),
				'icon'  => 'admin-generic',
			),
		);

		echo '<ul class="ffc-settings-tabs__nav" role="tablist" aria-orientation="vertical">';
		foreach ( $tabs as $slug => $tab ) {
			$is_active = ( $slug === $active );
			$url       = add_query_arg(
				array(
					'page' => self::PAGE_SLUG,
					'tab'  => $slug,
				),
				admin_url( 'admin.php' )
			);
			printf(
				'<li class="ffc-settings-tabs__nav-item" role="presentation"><a href="%1$s" id="ffc-recruitment-tabnav-%2$s" class="ffc-settings-tabs__tab%3$s" role="tab" aria-selected="%4$s" aria-controls="ffc-recruitment-tabpanel-%2$s" tabindex="%5$s"><span class="ffc-settings-tabs__icon dashicons dashicons-%6$s" aria-hidden="true"></span><span class="ffc-settings-tabs__label">%7$s</span></a></li>',
				esc_url( $url ),
				esc_attr( $slug ),
				$is_active ? ' is-active' : '',
				$is_active ? 'true' : 'false',
				$is_active ? '0' : '-1',
				esc_attr( $tab['icon'] ),
				esc_html( $tab['label'] )
			);
		}
		echo '</ul>';
	}

	/**
	 * Notices tab — list + create form.
	 *
	 * @return void
	 */
	private static function render_notices_tab(): void {
		echo '<h2>' . esc_html__( 'Notices', 'ffcertificate' ) . '</h2>';

		// First-run empty-state guidance: when no notices exist at all
		// (regardless of search/filter state), surface a card walking
		// the operator through the next steps. Keeps the standard list
		// table + create form intact below; the card is just an
		// orientation aid for fresh installs.
		$total_notices = count( RecruitmentNoticeRepository::get_all() );
		if ( 0 === $total_notices ) {
			self::render_notices_empty_state();
		}

		$table = new RecruitmentNoticesListTable();
		$table->prepare_items();

		// Search box + form wrapper so WP's bulk-action + sort + search
		// query params are preserved on submit.
		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE_SLUG ) . '">';
		$table->search_box( __( 'Search notices', 'ffcertificate' ), 'ffc-recruitment-notices' );
		$table->display();
		echo '</form>';

		self::render_create_notice_form();
		self::render_rest_pointer();
	}

	/**
	 * Render the first-run guidance card above the empty Notices list
	 * table. Walks the operator through the linear setup path:
	 * Adjutancies → first notice → attach → import CSV → promote → call.
	 *
	 * @return void
	 */
	private static function render_notices_empty_state(): void {
		$adjutancies_url = add_query_arg(
			array(
				'page' => self::PAGE_SLUG,
				'tab'  => 'adjutancies',
			),
			admin_url( 'admin.php' )
		);
		echo '<div class="notice notice-info inline ffc-rec-welcome-notice">';
		echo '<h3 class="ffc-rec-mt-0">' . esc_html__( 'Welcome to Recruitment', 'ffcertificate' ) . '</h3>';
		echo '<p>' . esc_html__( 'No notices yet. The typical path to your first call is:', 'ffcertificate' ) . '</p>';
		echo '<ol class="ffc-rec-ml-20">';
		echo '<li>' . sprintf(
			/* translators: %s: link to the Adjutancies tab */
			wp_kses_post( __( 'Define at least one <a href="%s">adjutancy</a> (subject / role) — these are reusable across notices.', 'ffcertificate' ) ),
			esc_url( $adjutancies_url )
		) . '</li>';
		echo '<li>' . esc_html__( 'Create your first notice (Code + Name) using the form below this list.', 'ffcertificate' ) . '</li>';
		echo '<li>' . esc_html__( 'Open the new notice and attach the relevant adjutancies + import the candidate CSV.', 'ffcertificate' ) . '</li>';
		echo '<li>' . esc_html__( 'Promote the preliminary list to definitive once you\'re ready, and call candidates per row or in bulk.', 'ffcertificate' ) . '</li>';
		echo '</ol>';
		echo '</div>';
	}

	/**
	 * Dispatch a `?action=…` GET-link operation triggered from a row
	 * action in the list tables. Each branch validates its own nonce
	 * via `check_admin_referer` and short-circuits with `wp_safe_redirect`
	 * back to the canonical tab so the URL stays clean.
	 *
	 * Sprint A1 ships only `delete-notice`. Sprint B adds the rest of
	 * the row actions (edit-notice, status transitions, promote, etc.).
	 *
	 * @param string $action Sanitized action key from the request.
	 * @return void
	 */
	/**
	 * Render a one-line admin notice driven by `?ffc_msg=…` flash key.
	 * The edit pages write the key on every redirect; we map known keys
	 * to translated copy here.
	 *
	 * @param string $key Flash key.
	 * @return void
	 */
	private static function render_flash_notice( string $key ): void {
		$map = array(
			'saved'                       => array( 'success', __( 'Saved.', 'ffcertificate' ) ),
			'transitioned'                => array( 'success', __( 'Status transition applied.', 'ffcertificate' ) ),
			'transition-blocked-by-calls' => array( 'error', __( 'Status transition rejected: cannot move from `definitive` back to `preliminary` once any call has been issued in this notice.', 'ffcertificate' ) ),
			'transition-reason-required'  => array( 'error', __( 'Status transition rejected: this transition requires a reason (filled in the Reopen reason field).', 'ffcertificate' ) ),
			'transition-race-lost'        => array( 'error', __( 'Status transition lost a race against another concurrent change. Reload the page and try again.', 'ffcertificate' ) ),
			'transition-failed'           => array( 'error', __( 'Status transition rejected by the state machine. Check the current status; this move may not be allowed from the current state.', 'ffcertificate' ) ),
			'transition-invalid-target'   => array( 'error', __( 'Status transition rejected: the target status was missing or unrecognized.', 'ffcertificate' ) ),
			'deleted'                     => array( 'success', __( 'Candidate deleted.', 'ffcertificate' ) ),
			'delete-blocked'              => array( 'error', __( 'Delete blocked: candidate still has classifications. Remove them first or leave the candidate row in place.', 'ffcertificate' ) ),
			'link-user-ok'                => array( 'success', __( 'Candidate linked to the WP user.', 'ffcertificate' ) ),
			'link-user-not-found'         => array( 'error', __( 'No WP user found for that lookup. Try the numeric ID, exact login, or full email.', 'ffcertificate' ) ),
			'unlink-user-ok'              => array( 'success', __( 'Candidate unlinked from the WP user. The wp_user account was not deleted.', 'ffcertificate' ) ),
			'rank-mandatory'              => array( 'error', __( 'public_columns_config rejected: `rank` cannot be set to false (mandatory column).', 'ffcertificate' ) ),
			'name-mandatory'              => array( 'error', __( 'public_columns_config rejected: `name` cannot be set to false (mandatory column).', 'ffcertificate' ) ),
			'slug-taken'                  => array( 'error', __( 'Slug rejected: another adjutancy already uses this slug. Pick a different value.', 'ffcertificate' ) ),
			'save-failed'                 => array( 'error', __( 'Save failed. Please try again.', 'ffcertificate' ) ),
		);
		if ( ! isset( $map[ $key ] ) ) {
			return;
		}
		$class = 'success' === $map[ $key ][0] ? 'notice-success' : 'notice-error';
		echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $map[ $key ][1] ) . '</p></div>';
	}

	/**
	 * Adjutancies tab — list + create form.
	 *
	 * @return void
	 */
	private static function render_adjutancies_tab(): void {
		echo '<h2>' . esc_html__( 'Adjutancies', 'ffcertificate' ) . '</h2>';

		$table = new RecruitmentAdjutanciesListTable();
		$table->prepare_items();

		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE_SLUG ) . '">';
		echo '<input type="hidden" name="tab" value="adjutancies">';
		$table->search_box( __( 'Search adjutancies', 'ffcertificate' ), 'ffc-recruitment-adjutancies' );
		$table->display();
		echo '</form>';

		self::render_create_adjutancy_form();
	}

	/**
	 * Reasons tab — global catalog of operator-defined labels attached
	 * to a preliminary-list classification's preview_status.
	 *
	 * Reasons are reusable across every notice (no attach junction):
	 * the catalog is global. Deletion is gated on zero references in
	 * `classification.preview_reason_id`.
	 *
	 * @return void
	 */
	private static function render_reasons_tab(): void {
		echo '<h2>' . esc_html__( 'Reasons', 'ffcertificate' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Global catalog of operator-defined labels attached to a preliminary-list candidate when setting their preliminary status. Reusable across every notice (no need to attach per-edital).', 'ffcertificate' ) . '</p>';

		$table = new RecruitmentReasonsListTable();
		$table->prepare_items();

		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE_SLUG ) . '">';
		echo '<input type="hidden" name="tab" value="reasons">';
		$table->search_box( __( 'Search reasons', 'ffcertificate' ), 'ffc-recruitment-reasons' );
		$table->display();
		echo '</form>';

		self::render_create_reason_form();
	}

	/**
	 * Render the create-reason form. Mirrors the create-adjutancy form
	 * but adds the four "applies to which preview status" checkboxes.
	 *
	 * @return void
	 */
	private static function render_create_reason_form(): void {
		$default_color = RecruitmentReasonRepository::DEFAULT_COLOR;

		echo '<h3>' . esc_html__( 'Create new reason', 'ffcertificate' ) . '</h3>';
		echo '<form id="ffc-create-reason" method="post" data-ffc-create-endpoint="reasons">';
		echo '<table class="form-table"><tbody>';
		echo '<tr><th><label for="ffc-reason-slug">' . esc_html__( 'Slug', 'ffcertificate' ) . '</label></th>';
		echo '<td><input id="ffc-reason-slug" name="slug" type="text" class="regular-text" required></td></tr>';
		echo '<tr><th><label for="ffc-reason-label">' . esc_html__( 'Label', 'ffcertificate' ) . '</label></th>';
		echo '<td><input id="ffc-reason-label" name="label" type="text" class="regular-text" required></td></tr>';
		echo '<tr><th><label for="ffc-reason-color">' . esc_html__( 'Badge color', 'ffcertificate' ) . '</label></th>';
		echo '<td><input id="ffc-reason-color" name="color" type="color" value="' . esc_attr( $default_color ) . '">';
		echo '<p class="description">' . esc_html__( 'Background color for the reason badge when surfaced. Accepts #RGB / #RRGGBB / #RRGGBBAA.', 'ffcertificate' ) . '</p>';
		echo '</td></tr>';

		$applies_options = array(
			'denied'         => __( 'Denied', 'ffcertificate' ),
			'granted'        => __( 'Granted', 'ffcertificate' ),
			'appeal_denied'  => __( 'Appeal denied', 'ffcertificate' ),
			'appeal_granted' => __( 'Appeal granted', 'ffcertificate' ),
		);
		echo '<tr><th>' . esc_html__( 'Applies to', 'ffcertificate' ) . '</th><td>';
		echo '<div class="ffc-rec-flex-wrap">';
		foreach ( $applies_options as $key => $label ) {
			$id_attr = 'ffc-reason-applies-' . $key;
			echo '<label for="' . esc_attr( $id_attr ) . '" class="ffc-rec-flex-center-6">';
			echo '<input id="' . esc_attr( $id_attr ) . '" type="checkbox" name="applies_to[]" value="' . esc_attr( $key ) . '">';
			echo esc_html( $label );
			echo '</label>';
		}
		echo '</div>';
		echo '<p class="description">' . esc_html__( 'Leave all unchecked to make this reason applicable to every preliminary status.', 'ffcertificate' ) . '</p>';
		echo '</td></tr>';

		echo '</tbody></table>';
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Create', 'ffcertificate' ) . '</button></p>';
		echo '</form>';
	}

	/**
	 * Candidates tab — CSV import flow.
	 *
	 * Admin selects the target notice, picks a CSV file, and the form POSTs
	 * via fetch() to `/notices/{id}/import`. The endpoint writes to
	 * `list_type='preview'` and is gated to notices in `draft` or
	 * `preliminary` (per §5.1 of the plan); already-active notices reject
	 * the import. The full per-candidate UI (search, edit, hard-delete)
	 * stays as a §7-bis follow-up — for the operator-facing CSV import
	 * flow this is the canonical entry point.
	 *
	 * @return void
	 */
	private static function render_candidates_tab(): void {
		echo '<h2>' . esc_html__( 'Candidates', 'ffcertificate' ) . '</h2>';

		// Standalone CSV import — same backend as the per-notice
		// importer on the Notice Edit screen, exposed here so the
		// operator can pick the target notice without navigating
		// through the Notices tab first. Gated by the same capability
		// the REST endpoint enforces.
		if ( current_user_can( 'ffc_import_recruitment_csv' ) || current_user_can( 'ffc_manage_recruitment' ) ) {
			self::render_candidates_csv_import_section();
		} else {
			echo '<p>' . esc_html__( 'Candidates are imported per-notice via CSV — open the target notice (Notices tab → Edit) and use the "Import candidates (CSV)" section.', 'ffcertificate' ) . '</p>';
		}

		$table = new RecruitmentCandidatesListTable();
		$table->prepare_items();

		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE_SLUG ) . '">';
		echo '<input type="hidden" name="tab" value="candidates">';
		$table->search_box( __( 'Search by name', 'ffcertificate' ), 'ffc-recruitment-candidates' );
		$table->display();
		echo '</form>';
	}

	/**
	 * Standalone CSV import section on the Candidates tab.
	 *
	 * Mirrors the per-notice importer rendered on the Notice Edit
	 * screen (see {@see RecruitmentNoticeEditPageRenderer::render_csv_import_section})
	 * but lets the operator pick the target notice up front. Routes
	 * to the exact same REST endpoints — no new backend surface
	 * required, no duplication of the importer service or activity
	 * logging.
	 *
	 * Notice eligibility:
	 *   - `draft`       → only the preliminary list can be imported.
	 *   - `preliminary` → both preliminary and definitive lists are
	 *                     possible (definitive_import also transitions
	 *                     the notice to `definitive`).
	 *   - `definitive` / `closed` → import is blocked; not shown in
	 *     the picker.
	 *
	 * @since 6.6.2
	 * @return void
	 */
	private static function render_candidates_csv_import_section(): void {
		$all_notices = RecruitmentNoticeRepository::get_all();
		$eligible    = array();
		foreach ( $all_notices as $row ) {
			$status = isset( $row->status ) ? (string) $row->status : '';
			if ( 'draft' === $status || 'preliminary' === $status ) {
				$eligible[] = $row;
			}
		}

		echo '<div class="postbox ffc-rec-mt-20">';
		echo '<h2 class="hndle"><span>' . esc_html__( 'Import candidates (CSV)', 'ffcertificate' ) . '</span></h2>';
		echo '<div class="inside">';

		if ( empty( $eligible ) ) {
			echo '<p>' . esc_html__( 'No notices in `draft` or `preliminary` status are available for CSV import. Create a notice (Notices tab) or move an existing one back to `preliminary` (allowed only when zero calls have been issued).', 'ffcertificate' ) . '</p>';
			echo '</div></div>';
			return;
		}

		$example_url = wp_nonce_url(
			add_query_arg(
				array( 'action' => 'ffc_recruitment_download_csv_example' ),
				admin_url( 'admin-post.php' )
			),
			'ffc_recruitment_download_csv_example'
		);

		echo '<p>' . esc_html__( 'Pick a notice, select the target list, and upload your CSV. The notice picker only lists notices where import is allowed.', 'ffcertificate' ) . '</p>';
		echo '<p><a class="button" href="' . esc_url( $example_url ) . '">&darr; ' . esc_html__( 'Download example CSV', 'ffcertificate' ) . '</a> ';
		echo '<span class="description ffc-rec-ml-half">' . esc_html__( 'UTF-8 CSV (BOM optional). Required headers (English): name, cpf, rf, email, adjutancy, rank, score, pcd. Optional: phone, time_points, hab_emebs.', 'ffcertificate' ) . '</span></p>';

		echo '<form id="ffc-recruitment-candidates-import" method="post" enctype="multipart/form-data" onsubmit="return ffcRecruitmentImportFromCandidates(this);">';
		echo '<table class="form-table"><tbody>';

		echo '<tr><th><label for="ffc-cand-import-notice">' . esc_html__( 'Target notice', 'ffcertificate' ) . '</label></th><td>';
		echo '<select id="ffc-cand-import-notice" name="notice_id" required onchange="ffcRecruitmentImportNoticeChanged(this);">';
		echo '<option value="" data-status="">' . esc_html__( '— Select a notice —', 'ffcertificate' ) . '</option>';
		foreach ( $eligible as $n ) {
			$nid    = (int) $n->id;
			$code   = isset( $n->code ) ? (string) $n->code : '';
			$name   = isset( $n->name ) ? (string) $n->name : '';
			$status = isset( $n->status ) ? (string) $n->status : '';
			/* translators: 1: notice code, 2: notice name, 3: notice status */
			$label = sprintf( _x( '%1$s — %2$s (%3$s)', 'recruitment notice picker', 'ffcertificate' ), $code, $name, $status );
			echo '<option value="' . esc_attr( (string) $nid ) . '" data-status="' . esc_attr( $status ) . '">' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		echo '</td></tr>';

		// Target list radios. The "definitive" option is rendered but
		// disabled by default — the onchange handler enables it only
		// when the selected notice's status is `preliminary`.
		echo '<tr><th><label>' . esc_html__( 'Target list', 'ffcertificate' ) . '</label></th><td>';
		echo '<label class="ffc-rec-mr-1"><input type="radio" name="list_target" value="preliminary" checked> ' . esc_html__( 'Preliminary list', 'ffcertificate' ) . '</label>';
		echo '<label><input type="radio" name="list_target" value="definitive" disabled> ' . esc_html__( 'Definitive list (also transitions notice to `definitive`)', 'ffcertificate' ) . '</label>';
		echo '<p class="description" id="ffc-cand-import-target-help">' . esc_html__( 'Pick a notice above to see which lists can receive the import.', 'ffcertificate' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th><label for="ffc-cand-csv-file">' . esc_html__( 'CSV file', 'ffcertificate' ) . '</label></th><td>';
		echo '<input id="ffc-cand-csv-file" name="csv_file" type="file" accept=".csv,text/csv" required>';
		echo '</td></tr>';

		echo '</tbody></table>';
		echo '<p>';
		echo '<button id="ffc-cand-csv-submit" type="submit" class="button button-primary">' . esc_html__( 'Import', 'ffcertificate' ) . '</button> ';
		echo '<span id="ffc-cand-csv-progress" class="ffc-rec-progress-inline">';
		echo '<span class="spinner is-active ffc-rec-spinner-flush"></span>';
		echo '<span id="ffc-cand-csv-progress-text"></span>';
		echo '</span>';
		echo '<span id="ffc-cand-csv-status" class="ffc-rec-mono-status"></span>';
		echo '</p>';
		echo '</form>';

		// The CSV-import handlers (ffcRecruitmentImportNoticeChanged /
		// ffcRecruitmentImportFromCandidates) ship in
		// assets/js/ffc-recruitment-candidates-import.js, enqueued +
		// localized by RecruitmentAdminAssetsManager. They mirror
		// ffcRecruitmentImportFromEdit on the Notice Edit page, reusing the
		// same REST endpoints (no new backend) so the importer service and
		// activity logger fire unchanged.
		echo '</div></div>';
	}

	/**
	 * Settings tab — editable form backed by the WP Settings API.
	 *
	 * Posts to `options.php` with `settings_fields(OPTION_GROUP)` so the
	 * registered `sanitize` callback runs on save. Settings panel is
	 * gated by the same `ffc_manage_recruitment` cap as the rest of the
	 * page (enforced at render_page() entry).
	 *
	 * @return void
	 */
	private static function render_settings_tab(): void {
		$settings = RecruitmentSettings::all();

		echo '<h2>' . esc_html__( 'Settings', 'ffcertificate' ) . '</h2>';
		echo '<p>' . esc_html__( 'Email templates and public shortcode tuning. Saved values populate the convocation email and the public shortcode cache/rate-limit/page-size knobs.', 'ffcertificate' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'options.php' ) ) . '">';
		settings_fields( RecruitmentSettings::OPTION_GROUP );

		$opt = RecruitmentSettings::OPTION_NAME;

		echo '<div class="card">';
		echo '<h2 class="ffc-icon-email">' . esc_html__( 'Email template', 'ffcertificate' ) . '</h2>';
		echo '<table class="form-table"><tbody>';

		echo '<tr><th><label for="ffc-rs-subject">' . esc_html__( 'Subject', 'ffcertificate' ) . '</label></th><td>';
		echo '<input id="ffc-rs-subject" type="text" class="large-text" name="' . esc_attr( $opt ) . '[email_subject]" value="' . esc_attr( (string) $settings['email_subject'] ) . '">';
		echo '<p class="description">' . esc_html__( 'Placeholders: {{notice_code}}, {{notice_name}}, {{adjutancy}}, {{name}}, {{rank}}, {{score}}, {{date_to_assume}}, {{time_to_assume}}, {{is_pcd}}, {{site_name}}, {{site_url}}, {{notes}}, and the masked variants {{cpf_masked}}, {{rf_masked}}, {{email_masked}}.', 'ffcertificate' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th><label for="ffc-rs-from-address">' . esc_html__( 'From address', 'ffcertificate' ) . '</label></th><td>';
		echo '<input id="ffc-rs-from-address" type="email" class="regular-text" name="' . esc_attr( $opt ) . '[email_from_address]" value="' . esc_attr( (string) $settings['email_from_address'] ) . '" placeholder="(falls back to wp_mail default)">';
		echo '</td></tr>';

		echo '<tr><th><label for="ffc-rs-from-name">' . esc_html__( 'From name', 'ffcertificate' ) . '</label></th><td>';
		echo '<input id="ffc-rs-from-name" type="text" class="regular-text" name="' . esc_attr( $opt ) . '[email_from_name]" value="' . esc_attr( (string) $settings['email_from_name'] ) . '" placeholder="(falls back to site name)">';
		echo '</td></tr>';

		echo '<tr><th><label for="ffc-rs-body">' . esc_html__( 'Body (HTML)', 'ffcertificate' ) . '</label></th><td>';
		echo '<textarea id="ffc-rs-body" name="' . esc_attr( $opt ) . '[email_body_html]" rows="12" class="large-text code">' . esc_textarea( (string) $settings['email_body_html'] ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'Same placeholder set as the subject. The text/plain alternative is auto-derived via wp_strip_all_tags.', 'ffcertificate' ) . '</p>';
		echo '</td></tr>';
		echo '</tbody></table>';
		echo '</div>';

		echo '<div class="card">';
		echo '<h2 class="ffc-icon-link">' . esc_html__( 'Public shortcode', 'ffcertificate' ) . '</h2>';
		echo '<table class="form-table"><tbody>';

		echo '<tr><th><label for="ffc-rs-cache">' . esc_html__( 'Cache TTL (seconds)', 'ffcertificate' ) . '</label></th><td>';
		echo '<input id="ffc-rs-cache" type="number" min="0" name="' . esc_attr( $opt ) . '[public_cache_seconds]" value="' . esc_attr( (string) $settings['public_cache_seconds'] ) . '">';
		echo '<p class="description">' . esc_html__( 'Transient cache for the public shortcode. 0 disables.', 'ffcertificate' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th><label for="ffc-rs-rate">' . esc_html__( 'Rate limit (requests / minute / IP)', 'ffcertificate' ) . '</label></th><td>';
		echo '<input id="ffc-rs-rate" type="number" min="0" name="' . esc_attr( $opt ) . '[public_rate_limit_per_minute]" value="' . esc_attr( (string) $settings['public_rate_limit_per_minute'] ) . '">';
		echo '<p class="description">' . esc_html__( '0 disables the per-IP rate limit.', 'ffcertificate' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th><label for="ffc-rs-pagesize">' . esc_html__( 'Default page size', 'ffcertificate' ) . '</label></th><td>';
		echo '<input id="ffc-rs-pagesize" type="number" min="1" max="500" name="' . esc_attr( $opt ) . '[public_default_page_size]" value="' . esc_attr( (string) $settings['public_default_page_size'] ) . '">';
		echo '</td></tr>';
		echo '</tbody></table>';
		echo '</div>';

		echo '<div class="card">';
		echo '<h2 class="ffc-icon-palette">' . esc_html__( 'Status badge colors', 'ffcertificate' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Background color used for each classification status pill on the public shortcode. Accepts #RGB / #RRGGBB / #RRGGBBAA. Bad values silently fall back to defaults.', 'ffcertificate' ) . '</p>';
		echo '<table class="form-table"><tbody>';

		$status_color_rows = array(
			'status_color_empty'     => __( 'Waiting (empty)', 'ffcertificate' ),
			'status_color_called'    => __( 'Called / Accepted', 'ffcertificate' ),
			'status_color_hired'     => __( 'Hired', 'ffcertificate' ),
			'status_color_not_shown' => __( 'Did not show up', 'ffcertificate' ),
			'status_color_withdrew'  => __( 'Withdrew', 'ffcertificate' ),
		);
		foreach ( $status_color_rows as $field => $label ) {
			echo '<tr><th><label for="ffc-rs-' . esc_attr( $field ) . '">' . esc_html( $label ) . '</label></th><td>';
			echo '<input id="ffc-rs-' . esc_attr( $field ) . '" type="color" name="' . esc_attr( $opt ) . '[' . esc_attr( $field ) . ']" value="' . esc_attr( (string) $settings[ $field ] ) . '">';
			echo ' <code class="ffc-rec-ml-half">' . esc_html( (string) $settings[ $field ] ) . '</code>';
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		echo '</div>';

		echo '<div class="card">';
		echo '<h2 class="ffc-icon-palette">' . esc_html__( 'Preliminary list — badge colors', 'ffcertificate' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Background color used for each preliminary-list visual status on the public shortcode. These statuses do not change the candidate flow; they only affect the badge color.', 'ffcertificate' ) . '</p>';
		echo '<table class="form-table"><tbody>';

		$preview_color_rows = array(
			'preview_color_empty'          => __( 'Empty (no decision)', 'ffcertificate' ),
			'preview_color_denied'         => __( 'Denied', 'ffcertificate' ),
			'preview_color_granted'        => __( 'Granted', 'ffcertificate' ),
			'preview_color_appeal_denied'  => __( 'Appeal denied', 'ffcertificate' ),
			'preview_color_appeal_granted' => __( 'Appeal granted', 'ffcertificate' ),
		);
		foreach ( $preview_color_rows as $field => $label ) {
			echo '<tr><th><label for="ffc-rs-' . esc_attr( $field ) . '">' . esc_html( $label ) . '</label></th><td>';
			echo '<input id="ffc-rs-' . esc_attr( $field ) . '" type="color" name="' . esc_attr( $opt ) . '[' . esc_attr( $field ) . ']" value="' . esc_attr( (string) $settings[ $field ] ) . '">';
			echo ' <code class="ffc-rec-ml-half">' . esc_html( (string) $settings[ $field ] ) . '</code>';
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		echo '</div>';

		echo '<div class="card">';
		echo '<h2 class="ffc-icon-clipboard">' . esc_html__( 'Preliminary list — reason required?', 'ffcertificate' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Per-status flag controlling whether a reason from the Reasons catalog must be supplied when an admin sets that preliminary status on a row.', 'ffcertificate' ) . '</p>';
		echo '<table class="form-table"><tbody>';

		$reason_required_rows = array(
			'preview_reason_required_denied'         => __( 'Denied requires a reason', 'ffcertificate' ),
			'preview_reason_required_granted'        => __( 'Granted requires a reason', 'ffcertificate' ),
			'preview_reason_required_appeal_denied'  => __( 'Appeal denied requires a reason', 'ffcertificate' ),
			'preview_reason_required_appeal_granted' => __( 'Appeal granted requires a reason', 'ffcertificate' ),
		);
		foreach ( $reason_required_rows as $field => $label ) {
			echo '<tr><th>' . esc_html( $label ) . '</th><td>';
			\FreeFormCertificate\Admin\AdminUI::render_toggle(
				array(
					'name'    => $opt . '[' . $field . ']',
					'id'      => 'ffc-rs-' . $field,
					'checked' => ! empty( $settings[ $field ] ),
					'data'    => array( 'ffc-autosave-key' => 'recruitment_' . $field ),
				)
			);
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		echo '</div>';

		echo '<div class="card">';
		echo '<h2 class="ffc-icon-palette">' . esc_html__( 'Subscription type — badge colors', 'ffcertificate' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Background color used on the public + admin subscription-type badges. Each candidate is either PCD (pessoa com deficiência) or GERAL — these two knobs paint the corresponding pill.', 'ffcertificate' ) . '</p>';
		echo '<table class="form-table"><tbody>';

		$subscription_color_rows = array(
			'subscription_color_pcd'   => __( 'PCD', 'ffcertificate' ),
			'subscription_color_geral' => __( 'GERAL', 'ffcertificate' ),
		);
		foreach ( $subscription_color_rows as $field => $label ) {
			echo '<tr><th><label for="ffc-rs-' . esc_attr( $field ) . '">' . esc_html( $label ) . '</label></th><td>';
			echo '<input id="ffc-rs-' . esc_attr( $field ) . '" type="color" name="' . esc_attr( $opt ) . '[' . esc_attr( $field ) . ']" value="' . esc_attr( (string) $settings[ $field ] ) . '">';
			echo ' <code class="ffc-rec-ml-half">' . esc_html( (string) $settings[ $field ] ) . '</code>';
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		echo '</div>';

		echo '<div class="card">';
		echo '<h2 class="ffc-icon-palette">' . esc_html__( 'Notice status — badge colors', 'ffcertificate' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Background color used for each notice lifecycle status (Draft / Preliminary / Definitive / Closed). Drives both the admin Notices list table and the public shortcode banner so both surfaces share one palette.', 'ffcertificate' ) . '</p>';
		echo '<table class="form-table"><tbody>';

		$notice_status_color_rows = array(
			'notice_status_color_draft'       => __( 'Draft', 'ffcertificate' ),
			'notice_status_color_preliminary' => __( 'Preliminary', 'ffcertificate' ),
			'notice_status_color_definitive'  => __( 'Definitive', 'ffcertificate' ),
			'notice_status_color_closed'      => __( 'Closed', 'ffcertificate' ),
		);
		foreach ( $notice_status_color_rows as $field => $label ) {
			echo '<tr><th><label for="ffc-rs-' . esc_attr( $field ) . '">' . esc_html( $label ) . '</label></th><td>';
			echo '<input id="ffc-rs-' . esc_attr( $field ) . '" type="color" name="' . esc_attr( $opt ) . '[' . esc_attr( $field ) . ']" value="' . esc_attr( (string) $settings[ $field ] ) . '">';
			echo ' <code class="ffc-rec-ml-half">' . esc_html( (string) $settings[ $field ] ) . '</code>';
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		echo '</div>';

		// PII / audit toggle (#330). Lives at the bottom of the Settings
		// tab because it's a security knob, not a visual one — operators
		// who land here are usually adjusting palettes. The default is
		// `true` so the first save after the upgrade keeps auditing on.
		echo '<div class="card">';
		echo '<h2 class="ffc-icon-shield">' . esc_html__( 'PII access audit', 'ffcertificate' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'When enabled, every reveal of CPF / RF on the candidate detail screen by a non-admin user writes a row to the activity log (with a 60-second dedup per user + candidate + field). Recommended ON for compliance.', 'ffcertificate' ) . '</p>';
		echo '<table class="form-table"><tbody>';
		echo '<tr><th>' . esc_html__( 'Audit PII reveals', 'ffcertificate' ) . '</th><td>';
		\FreeFormCertificate\Admin\AdminUI::render_toggle(
			array(
				'name'    => $opt . '[audit_pii_reveals]',
				'id'      => 'ffc-rs-audit-pii-reveals',
				'checked' => ! empty( $settings['audit_pii_reveals'] ),
				'data'    => array( 'ffc-autosave-key' => 'recruitment_audit_pii_reveals' ),
			)
		);
		echo '</td></tr>';
		echo '</tbody></table>';
		echo '</div>';

		submit_button();
		echo '</form>';
	}

	/**
	 * Render the create-notice form. Submit is handled by the delegated
	 * `data-ffc-create-endpoint` listener in `ffc-recruitment-admin.js`.
	 *
	 * @return void
	 */
	private static function render_create_notice_form(): void {
		echo '<h3>' . esc_html__( 'Create new notice', 'ffcertificate' ) . '</h3>';
		echo '<form id="ffc-create-notice" method="post" data-ffc-create-endpoint="notices">';
		echo '<table class="form-table"><tbody>';
		echo '<tr><th><label for="ffc-notice-code">' . esc_html__( 'Code', 'ffcertificate' ) . '</label></th>';
		echo '<td><input id="ffc-notice-code" name="code" type="text" class="regular-text" required></td></tr>';
		echo '<tr><th><label for="ffc-notice-name">' . esc_html__( 'Name', 'ffcertificate' ) . '</label></th>';
		echo '<td><input id="ffc-notice-name" name="name" type="text" class="regular-text" required></td></tr>';
		echo '</tbody></table>';
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Create', 'ffcertificate' ) . '</button></p>';
		echo '</form>';
	}

	/**
	 * Render the create-adjutancy form. Submit is handled by the delegated
	 * `data-ffc-create-endpoint` listener in `ffc-recruitment-admin.js`.
	 *
	 * @return void
	 */
	private static function render_create_adjutancy_form(): void {
		$default_color = RecruitmentAdjutancyRepository::DEFAULT_COLOR;

		echo '<h3>' . esc_html__( 'Create new adjutancy', 'ffcertificate' ) . '</h3>';
		echo '<form id="ffc-create-adjutancy" method="post" data-ffc-create-endpoint="adjutancies">';
		echo '<table class="form-table"><tbody>';
		echo '<tr><th><label for="ffc-adj-slug">' . esc_html__( 'Slug', 'ffcertificate' ) . '</label></th>';
		echo '<td><input id="ffc-adj-slug" name="slug" type="text" class="regular-text" required></td></tr>';
		echo '<tr><th><label for="ffc-adj-name">' . esc_html__( 'Name', 'ffcertificate' ) . '</label></th>';
		echo '<td><input id="ffc-adj-name" name="name" type="text" class="regular-text" required></td></tr>';
		echo '<tr><th><label for="ffc-adj-color">' . esc_html__( 'Badge color', 'ffcertificate' ) . '</label></th>';
		echo '<td><input id="ffc-adj-color" name="color" type="color" value="' . esc_attr( $default_color ) . '">';
		echo '<p class="description">' . esc_html__( 'Background color for this adjutancy badge on the public shortcode. Accepts #RGB / #RRGGBB / #RRGGBBAA. Bad values silently fall back to the default.', 'ffcertificate' ) . '</p>';
		echo '</td></tr>';
		echo '</tbody></table>';
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Create', 'ffcertificate' ) . '</button></p>';
		echo '</form>';
	}

	/**
	 * Documentation block linking the admin to the REST surface.
	 *
	 * @return void
	 */
	private static function render_rest_pointer(): void {
		echo '<details class="ffc-rec-mt-1"><summary>' . esc_html__( 'Available REST endpoints', 'ffcertificate' ) . '</summary>';
		echo '<pre class="ffc-rec-pre-block">'
			. esc_html(
				"GET    /wp-json/ffcertificate/v1/recruitment/notices\n"
				. "POST   /wp-json/ffcertificate/v1/recruitment/notices\n"
				. "PATCH  /wp-json/ffcertificate/v1/recruitment/notices/{id}\n"
				. "GET    /wp-json/ffcertificate/v1/recruitment/notices/{id}/classifications\n"
				. "POST   /wp-json/ffcertificate/v1/recruitment/notices/{id}/import\n"
				. "POST   /wp-json/ffcertificate/v1/recruitment/notices/{id}/promote-preview\n"
				. "POST   /wp-json/ffcertificate/v1/recruitment/classifications/{id}/call\n"
				. "POST   /wp-json/ffcertificate/v1/recruitment/classifications/bulk-call\n"
				. "PATCH  /wp-json/ffcertificate/v1/recruitment/classifications/{id}/status\n"
				. "DELETE /wp-json/ffcertificate/v1/recruitment/classifications/{id}\n"
				. "GET    /wp-json/ffcertificate/v1/recruitment/adjutancies\n"
				. "DELETE /wp-json/ffcertificate/v1/recruitment/adjutancies/{id}\n"
				. "GET    /wp-json/ffcertificate/v1/recruitment/candidates?cpf={digits}\n"
				. "GET    /wp-json/ffcertificate/v1/recruitment/candidates/{id}\n"
				. "PATCH  /wp-json/ffcertificate/v1/recruitment/candidates/{id}\n"
				. "DELETE /wp-json/ffcertificate/v1/recruitment/candidates/{id}\n"
				. "GET    /wp-json/ffcertificate/v1/recruitment/me/recruitment\n"
			)
			. '</pre>';
		echo '<p>' . esc_html__( 'All admin endpoints require the ffc_manage_recruitment capability.', 'ffcertificate' ) . '</p>';
		echo '</details>';
	}
}
