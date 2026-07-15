<?php
/**
 * Recruitment Admin Page — Renderer.
 *
 * View-layer helpers split out of {@see RecruitmentAdminPage} per the #589
 * phase-2 god-object refactor (Sprint E2). Pure rendering methods: every tab
 * partial, create form, empty state, flash notice and REST pointer the admin
 * page composes. No behavior changes — all methods kept `static` and called
 * from the RecruitmentAdminPage controller exactly as before. The public
 * badge/label helpers and the permission gates stay on the controller; this
 * renderer calls them as `RecruitmentAdminPage::*`.
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.7.x
 */

declare(strict_types=1);

namespace FreeFormCertificate\Recruitment;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Recruitment admin page view-layer helpers.
 */
final class RecruitmentAdminPageRenderer {

	/**
	 * Render the vertical tab navigation (WooCommerce "Product data" style),
	 * matching the look of `page=ffc-settings` and the certificate form
	 * editor. Emits only the `<ul>` — the surrounding `.ffc-settings-tabs`
	 * container and `.ffc-settings-tabs__panel` are opened/closed by the
	 * caller in {@see RecruitmentAdminPage::render_page()}.
	 *
	 * @param string $active Current tab.
	 * @return void
	 */
	public static function render_tabs( string $active ): void {
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

		// Hide the Settings tab from users without its view cap (3-state).
		if ( ! RecruitmentAdminPage::can_view_settings() ) {
			unset( $tabs['settings'] );
		}
		// Hide the Reasons tab from users without its view cap (3-state, GAP I).
		if ( ! RecruitmentAdminPage::can_view_reasons() ) {
			unset( $tabs['reasons'] );
		}

		include FFC_PLUGIN_DIR . 'templates/admin/recruitment/admin-page/tabs.php';
	}

	/**
	 * Notices tab — list + create form.
	 *
	 * @return void
	 */
	public static function render_notices_tab(): void {
		echo '<h2>' . esc_html__( 'Notices', 'ffcertificate' ) . '</h2>';

		// First-run empty-state guidance: when no notices exist at all
		// (regardless of search/filter state), surface a card walking
		// the operator through the next steps. Keeps the standard list
		// table + create form intact below; the card is just an
		// orientation aid for fresh installs.
		$total_notices = count( RecruitmentNoticeReader::get_all() );
		if ( 0 === $total_notices ) {
			self::render_notices_empty_state();
		}

		$table = new RecruitmentNoticesListTable();
		$table->prepare_items();

		// Search box + form wrapper so WP's bulk-action + sort + search
		// query params are preserved on submit.
		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="' . esc_attr( RecruitmentAdminPage::PAGE_SLUG ) . '">';
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
	public static function render_notices_empty_state(): void {
		$adjutancies_url = add_query_arg(
			array(
				'page' => RecruitmentAdminPage::PAGE_SLUG,
				'tab'  => 'adjutancies',
			),
			admin_url( 'admin.php' )
		);
		include FFC_PLUGIN_DIR . 'templates/admin/recruitment/admin-page/notices-empty-state.php';
	}

	/**
	 * Render a one-line admin notice driven by `?ffc_msg=…` flash key.
	 * The edit pages write the key on every redirect; we map known keys
	 * to translated copy here.
	 *
	 * @param string $key Flash key.
	 * @return void
	 */
	public static function render_flash_notice( string $key ): void {
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
	public static function render_adjutancies_tab(): void {
		echo '<h2>' . esc_html__( 'Adjutancies', 'ffcertificate' ) . '</h2>';

		$table = new RecruitmentAdjutanciesListTable();
		$table->prepare_items();

		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="' . esc_attr( RecruitmentAdminPage::PAGE_SLUG ) . '">';
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
	public static function render_reasons_tab(): void {
		echo '<h2>' . esc_html__( 'Reasons', 'ffcertificate' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Global catalog of operator-defined labels attached to a preliminary-list candidate when setting their preliminary status. Reusable across every notice (no need to attach per-edital).', 'ffcertificate' ) . '</p>';

		$table = new RecruitmentReasonsListTable( RecruitmentAdminPage::can_edit_reasons() );
		$table->prepare_items();

		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="' . esc_attr( RecruitmentAdminPage::PAGE_SLUG ) . '">';
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
	public static function render_create_reason_form(): void {
		// 3-state (GAP I): read-only viewers don't get the create form. Gated by
		// the strict reasons-manage cap, not the umbrella — the REST endpoint
		// behind it enforces the same cap.
		if ( ! RecruitmentAdminPage::can_edit_reasons() ) {
			return;
		}
		$default_color = RecruitmentReasonReader::DEFAULT_COLOR;

		include FFC_PLUGIN_DIR . 'templates/admin/recruitment/admin-page/create-reason-form.php';
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
	public static function render_candidates_tab(): void {
		echo '<h2>' . esc_html__( 'Candidates', 'ffcertificate' ) . '</h2>';

		// Standalone CSV import — same backend as the per-notice
		// importer on the Notice Edit screen, exposed here so the
		// operator can pick the target notice without navigating
		// through the Notices tab first. Gated by the same capability
		// the REST endpoint enforces — the strict `ffc_import_recruitment`
		// tier (GAP H); the umbrella `ffc_manage_recruitment` no longer grants
		// it.
		if ( current_user_can( 'ffc_import_recruitment' ) ) {
			self::render_candidates_csv_import_section();
		} else {
			echo '<p>' . esc_html__( 'Candidates are imported per-notice via CSV — open the target notice (Notices tab → Edit) and use the "Import candidates (CSV)" section.', 'ffcertificate' ) . '</p>';
		}

		$table = new RecruitmentCandidatesListTable();
		$table->prepare_items();

		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="' . esc_attr( RecruitmentAdminPage::PAGE_SLUG ) . '">';
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
	public static function render_candidates_csv_import_section(): void {
		$all_notices = RecruitmentNoticeReader::get_all();
		$eligible    = array();
		foreach ( $all_notices as $row ) {
			$status = isset( $row->status ) ? (string) $row->status : '';
			if ( 'draft' === $status || 'preliminary' === $status ) {
				$eligible[] = $row;
			}
		}

		include FFC_PLUGIN_DIR . 'templates/admin/recruitment/admin-page/candidates-csv-import-section.php';
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
	public static function render_settings_tab(): void {
		$settings = RecruitmentSettings::all();
		$can_edit = RecruitmentAdminPage::can_edit_settings();

		include FFC_PLUGIN_DIR . 'templates/admin/recruitment/admin-page/settings-tab.php';
	}

	/**
	 * Render the create-notice form. Submit is handled by the delegated
	 * `data-ffc-create-endpoint` listener in `ffc-recruitment-admin.js`.
	 *
	 * @return void
	 */
	public static function render_create_notice_form(): void {
		if ( ! \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_recruitment' ) ) {
			return;
		}
		include FFC_PLUGIN_DIR . 'templates/admin/recruitment/admin-page/create-notice-form.php';
	}

	/**
	 * Render the create-adjutancy form. Submit is handled by the delegated
	 * `data-ffc-create-endpoint` listener in `ffc-recruitment-admin.js`.
	 *
	 * @return void
	 */
	public static function render_create_adjutancy_form(): void {
		if ( ! \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_recruitment' ) ) {
			return;
		}
		$default_color = RecruitmentAdjutancyReader::DEFAULT_COLOR;

		include FFC_PLUGIN_DIR . 'templates/admin/recruitment/admin-page/create-adjutancy-form.php';
	}

	/**
	 * Documentation block linking the admin to the REST surface.
	 *
	 * @return void
	 */
	public static function render_rest_pointer(): void {
		include FFC_PLUGIN_DIR . 'templates/admin/recruitment/admin-page/rest-pointer.php';
	}
}
