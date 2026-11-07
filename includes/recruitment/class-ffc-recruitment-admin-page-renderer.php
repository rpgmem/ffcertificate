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
 * @since   6.12.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Recruitment;

use FreeFormCertificate\Core\LabelSorter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Recruitment admin page view-layer helpers.
 */
final class RecruitmentAdminPageRenderer {

	/** The New notice dialog. */
	public const NOTICE_DIALOG = 'ffc-new-notice-dialog';

	/** The New adjutancy dialog. */
	public const ADJUTANCY_DIALOG = 'ffc-new-adjutancy-dialog';

	/** The New reason dialog. */
	public const REASON_DIALOG = 'ffc-new-reason-dialog';

	/**
	 * Open a box for a section of a page: a `.card` whose heading carries the
	 * icon of its own subject.
	 *
	 * @param string $icon  `.ffc-icon-*` class.
	 * @param string $title Heading text.
	 * @return void
	 */
	public static function open_section_card( string $icon, string $title ): void {
		printf( '<div class="card"><h2 class="%1$s">%2$s</h2>', esc_attr( $icon ), esc_html( $title ) );
	}

	/**
	 * The page header: the title, the page's create action beside it, and
	 * the line WordPress moves its notices below (#2486).
	 *
	 * @param string $tab `dashboard` or a key of {@see RecruitmentAdminPage::section_labels()}.
	 * @return void
	 */
	public static function render_header( string $tab ): void {
		$labels = RecruitmentAdminPage::section_labels();
		$title  = $labels[ $tab ] ?? \FreeFormCertificate\Core\PluginAreas::label( 'recruitment' );
		echo '<h1 class="wp-heading-inline">' . esc_html( $title ) . '</h1>';

		$can_manage = \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_recruitment' );
		$action     = null;
		if ( ( 'dashboard' === $tab || 'notices' === $tab ) && $can_manage ) {
			$action = array( __( 'New notice', 'ffcertificate' ), self::NOTICE_DIALOG );
		} elseif ( 'adjutancies' === $tab && $can_manage ) {
			$action = array( __( 'New adjutancy', 'ffcertificate' ), self::ADJUTANCY_DIALOG );
		} elseif ( 'reasons' === $tab && RecruitmentAdminPage::can_edit_reasons() ) {
			$action = array( __( 'New reason', 'ffcertificate' ), self::REASON_DIALOG );
		}
		if ( null !== $action ) {
			\FreeFormCertificate\Core\Button::render(
				array(
					'label'    => $action[0],
					'category' => 'create',
					'icon'     => 'plus',
					'attrs'    => array(
						'data-ffc-dialog-open' => $action[1],
						'aria-haspopup'        => 'dialog',
					),
				)
			);
		}
		echo '<hr class="wp-header-end">';
	}

	/**
	 * Open a create dialog: the overlay, its title and close button, and the
	 * form whose fields follow. Hidden until its header button opens it; the
	 * form posts to the REST endpoint through `data-ffc-create-endpoint`.
	 *
	 * @param string               $id       Dialog id, one of the `*_DIALOG` constants.
	 * @param string               $title    Dialog title.
	 * @param string               $form_id  Form id.
	 * @param string               $endpoint REST path the form posts to.
	 * @param array<string,string> $attrs    Further form attributes.
	 * @return void
	 */
	public static function open_dialog( string $id, string $title, string $form_id, string $endpoint, array $attrs = array() ): void {
		$close = \FreeFormCertificate\Core\Button::html(
			array(
				'label'     => __( 'Close', 'ffcertificate' ),
				'icon'      => 'cross',
				'icon_only' => true,
				'attrs'     => array( 'data-ffc-dialog-close' => true ),
			)
		);
		printf(
			'<div class="ffc-confirm-modal-overlay ffc-create-dialog" id="%1$s" role="dialog" aria-modal="true" aria-labelledby="%1$s-title" hidden><div class="ffc-confirm-modal" data-style="primary"><div class="ffc-confirm-modal-header"><h2 id="%1$s-title">%2$s</h2>%3$s</div>',
			esc_attr( $id ),
			esc_html( $title ),
			$close // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Button::html escapes every part.
		);
		$extra = '';
		foreach ( $attrs as $name => $value ) {
			$extra .= sprintf( ' %s="%s"', esc_attr( $name ), esc_attr( $value ) );
		}
		printf(
			'<form id="%1$s" method="post" data-ffc-create-endpoint="%2$s"%3$s><div class="ffc-confirm-modal-body ffc-create-dialog__body">',
			esc_attr( $form_id ),
			esc_attr( $endpoint ),
			$extra // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each name and value escaped above.
		);
	}

	/**
	 * Close a create dialog: the error line, Cancel and the submit button.
	 *
	 * @param string $submit Submit button label.
	 * @return void
	 */
	public static function close_dialog( string $submit ): void {
		echo '<p class="ffc-create-dialog__error" role="alert" hidden></p>';
		echo '</div><div class="ffc-confirm-modal-footer">';
		\FreeFormCertificate\Core\Button::render(
			array(
				'label' => __( 'Cancel', 'ffcertificate' ),
				'icon'  => 'cross',
				'attrs' => array( 'data-ffc-dialog-close' => true ),
			)
		);
		\FreeFormCertificate\Core\Button::render(
			array(
				'label'    => $submit,
				'category' => 'create',
				'icon'     => 'plus',
				'type'     => 'submit',
			)
		);
		echo '</div></form></div></div>';
	}

	/**
	 * A labelled field of a create dialog.
	 *
	 * @param string $label Field label.
	 * @param string $input The input markup, already escaped.
	 * @param string $input_id Id of the input the label names.
	 * @param string $help  Help text under the input.
	 * @return void
	 */
	public static function dialog_field( string $label, string $input, string $input_id, string $help = '' ): void {
		echo '<div class="ffc-create-dialog__field">';
		printf( '<label for="%1$s">%2$s</label>', esc_attr( $input_id ), esc_html( $label ) );
		echo $input; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the caller.
		if ( '' !== $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * The search card above a section list: one labelled field, Filter, and
	 * Clear once something is searched (#2486). The form it opens wraps the
	 * list table too, so the bulk actions, the sort and the search travel
	 * together; the caller displays the table and closes the form.
	 *
	 * @param string $tab         Section slug.
	 * @param string $placeholder Field placeholder.
	 * @return void
	 */
	public static function open_list_form( string $tab, string $placeholder ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter.
		$search = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['s'] ) ) : '';
		$id     = 'ffc-recruitment-' . $tab . '-search';
		echo '<form method="get" class="ffc-list">';
		echo '<input type="hidden" name="page" value="' . esc_attr( RecruitmentAdminPage::PAGE_SLUG ) . '">';
		echo '<input type="hidden" name="tab" value="' . esc_attr( $tab ) . '">';
		echo '<div class="ffc-list-filters">';
		echo '<div class="ffc-list-filters__field">';
		echo '<label class="ffc-list-filters__label" for="' . esc_attr( $id ) . '">' . esc_html__( 'Search', 'ffcertificate' ) . '</label>';
		echo '<input type="search" id="' . esc_attr( $id ) . '" name="s" class="regular-text" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr( $placeholder ) . '">';
		echo '</div><div class="ffc-list-filters__actions">';
		\FreeFormCertificate\Core\Button::render(
			array(
				'label' => __( 'Filter', 'ffcertificate' ),
				'icon'  => 'filter',
				'type'  => 'submit',
			)
		);
		if ( '' !== $search ) {
			\FreeFormCertificate\Core\Button::render(
				array(
					'label' => __( 'Clear', 'ffcertificate' ),
					'icon'  => 'eraser',
					'href'  => RecruitmentAdminPage::section_url( $tab ),
				)
			);
		}
		echo '</div></div>';
	}

	/**
	 * Notices page — the status views, the search and the list.
	 *
	 * @return void
	 */
	public static function render_notices_tab(): void {
		// First-run guidance: when no notice exists at all (whatever the
		// search), a card walks the operator through the next steps.
		if ( 0 === count( RecruitmentNoticeReader::get_all() ) ) {
			self::render_notices_empty_state();
		}

		$table = new RecruitmentNoticesListTable();
		$table->prepare_items();

		self::open_list_form( 'notices', __( 'Code or name', 'ffcertificate' ) );
		$status = RecruitmentNoticesListTable::status_filter();
		if ( '' !== $status ) {
			echo '<input type="hidden" name="notice_status" value="' . esc_attr( $status ) . '">';
		}
		$table->views();
		$table->display();
		echo '</form>';
		self::render_rest_pointer();
		self::render_create_notice_form();
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
			'transition-requires-promote' => array( 'error', __( 'Promote a preliminary notice with "Copy preliminary as definitive" or by importing a definitive list.', 'ffcertificate' ) ),
			'transition-blocked-by-calls' => array( 'error', __( 'Status transition rejected: cannot move from `definitive` back to `preliminary` once any call has been issued in this notice.', 'ffcertificate' ) ),
			'transition-reason-required'  => array( 'error', __( 'Status transition rejected: this transition requires a reason (filled in the Reopen reason field).', 'ffcertificate' ) ),
			'transition-race-lost'        => array( 'error', __( 'Status transition lost a race against another concurrent change. Reload the page and try again.', 'ffcertificate' ) ),
			'transition-failed'           => array( 'error', __( 'Status transition rejected by the state machine. Check the current status; this move may not be allowed from the current state.', 'ffcertificate' ) ),
			'transition-invalid-target'   => array( 'error', __( 'Status transition rejected: the target status was missing or unrecognized.', 'ffcertificate' ) ),
			'deleted'                     => array( 'success', __( 'Candidate deleted.', 'ffcertificate' ) ),
			'delete-blocked'              => array( 'error', __( 'Delete blocked: candidate still has classifications. Remove them first or leave the candidate row in place.', 'ffcertificate' ) ),
			'link-user-ok'                => array( 'success', __( 'Candidate linked to the WP user.', 'ffcertificate' ) ),
			'link-user-not-found'         => array( 'error', __( 'No WP user found for that lookup. Try the numeric ID, exact login, or full email.', 'ffcertificate' ) ),
			'link-user-self'              => array( 'error', __( 'You cannot link a candidate to your own account.', 'ffcertificate' ) ),
			'link-user-mismatch'          => array( 'error', __( 'That account does not carry this candidate\'s CPF, RF or e-mail. Only a holder of the PII capability can link it.', 'ffcertificate' ) ),
			'unlink-user-ok'              => array( 'success', __( 'Candidate unlinked from the WP user. The wp_user account was not deleted.', 'ffcertificate' ) ),
			'rank-mandatory'              => array( 'error', __( 'public_columns_config rejected: `rank` cannot be set to false (mandatory column).', 'ffcertificate' ) ),
			'name-mandatory'              => array( 'error', __( 'public_columns_config rejected: `name` cannot be set to false (mandatory column).', 'ffcertificate' ) ),
			'slug-taken'                  => array( 'error', __( 'Slug rejected: another adjutancy already uses this slug. Pick a different value.', 'ffcertificate' ) ),
			'save-failed'                 => array( 'error', __( 'Save failed. Please try again.', 'ffcertificate' ) ),
			'adjutancy-deleted'           => array( 'success', __( 'Adjutancy deleted.', 'ffcertificate' ) ),
			'adjutancy-in-use'            => array( 'error', __( 'The adjutancy was not deleted: a notice or a classification still uses it. Detach it from every notice first; one that classified candidates stays for the record.', 'ffcertificate' ) ),
			'reason-deleted'              => array( 'success', __( 'Reason deleted.', 'ffcertificate' ) ),
			'reason-in-use'               => array( 'error', __( 'The reason was not deleted: a classification still uses it.', 'ffcertificate' ) ),
		);
		if ( ! isset( $map[ $key ] ) ) {
			return;
		}
		\FreeFormCertificate\Core\AdminNotice::render(
			$map[ $key ][0],
			esc_html( $map[ $key ][1] )
		);
	}

	/**
	 * Adjutancies page — the search and the list.
	 *
	 * @return void
	 */
	public static function render_adjutancies_tab(): void {
		$table = new RecruitmentAdjutanciesListTable();
		$table->prepare_items();

		self::open_list_form( 'adjutancies', __( 'Slug or name', 'ffcertificate' ) );
		$table->display();
		echo '</form>';

		self::render_create_adjutancy_form();
	}

	/**
	 * Reasons page — global catalog of operator-defined labels attached
	 * to a preliminary-list classification's preview_status.
	 *
	 * Reasons are reusable across every notice (no attach junction):
	 * the catalog is global. Deletion is gated on zero references in
	 * `classification.preview_reason_id`.
	 *
	 * @return void
	 */
	public static function render_reasons_tab(): void {
		echo '<p class="description">' . esc_html__( 'Global catalog of operator-defined labels attached to a preliminary-list candidate when setting their preliminary status. Reusable across every notice (no need to attach per-edital).', 'ffcertificate' ) . '</p>';

		$table = new RecruitmentReasonsListTable( RecruitmentAdminPage::can_edit_reasons() );
		$table->prepare_items();

		self::open_list_form( 'reasons', __( 'Slug or label', 'ffcertificate' ) );
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
	 * Candidates page — the list and the CSV import.
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
		// Without the strict `ffc_import_recruitment` tier (GAP H; the umbrella
		// `ffc_manage_recruitment` no longer grants it) the import box below is
		// not drawn, so the list says where importing happens instead.
		$can_import = current_user_can( 'ffc_import_recruitment' );
		if ( ! $can_import ) {
			echo '<p class="description">' . esc_html__( 'Candidates are imported per notice: open the notice (Recruitment → Notices → Edit) and use its "Import candidates (CSV)" section.', 'ffcertificate' ) . '</p>';
		}

		$table = new RecruitmentCandidatesListTable();
		$table->prepare_items();

		self::open_list_form( 'candidates', __( 'Name', 'ffcertificate' ) );
		$table->display();
		echo '</form>';

		// Standalone CSV import — same backend as the per-notice importer on
		// the Notice Edit screen, exposed here so the operator can pick the
		// target notice without opening the notice first.
		if ( $can_import ) {
			self::render_candidates_csv_import_section();
		}
	}

	/**
	 * Standalone CSV import section on the Candidates page.
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
			if ( RecruitmentNoticeLocks::allows( RecruitmentNoticeLocks::IMPORT_PREVIEW, $status ) ) {
				$eligible[] = $row;
			}
		}

		include FFC_PLUGIN_DIR . 'templates/admin/recruitment/admin-page/candidates-csv-import-section.php';
	}

	/**
	 * Settings page — editable form backed by the WP Settings API.
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
	 * Render the New notice dialog, its code suggested from the most recent
	 * notice. Submit is handled by the delegated `data-ffc-create-endpoint`
	 * listener in `ffc-recruitment-admin.js`, which opens the new notice.
	 *
	 * @return void
	 */
	public static function render_create_notice_form(): void {
		if ( ! \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_recruitment' ) ) {
			return;
		}
		// The latest notice is the one created last: the highest id, since
		// rows created in one batch share their `created_at` second.
		$latest = '';
		$top_id = 0;
		foreach ( RecruitmentNoticeReader::get_all() as $notice ) {
			if ( (int) $notice->id > $top_id ) {
				$top_id = (int) $notice->id;
				$latest = (string) $notice->code;
			}
		}
		$suggested = RecruitmentNoticeReader::next_code_after( $latest );
		include FFC_PLUGIN_DIR . 'templates/admin/recruitment/admin-page/create-notice-form.php';
	}

	/**
	 * Render the New adjutancy dialog. Submit is handled by the delegated
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
