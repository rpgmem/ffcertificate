<?php
/**
 * Recruitment Notice Edit Page — Renderer.
 *
 * View-layer helpers split out of {@see RecruitmentNoticeEditPage} per the
 * sprint S1 god-object refactor (rpgmem/ffcertificate#141). Pure rendering
 * methods: every section/table/script generator the edit screen composes.
 * No behavior changes — all methods kept `static` and called from the
 * EditPage facade exactly as before.
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.1.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Recruitment;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Notice edit screen view-layer helpers.
 *
 * @phpstan-import-type NoticeRow         from RecruitmentNoticeRepository
 * @phpstan-import-type ClassificationRow from RecruitmentClassificationRepository
 * @phpstan-import-type ReasonRow         from RecruitmentReasonRepository
 */
final class RecruitmentNoticeEditPageRenderer {

	/**
	 * Section: CSV import — list_type selector targeting the current notice.
	 *
	 * Two paths are surfaced based on the notice's status:
	 *
	 *   - draft / preliminary → `POST /notices/{id}/import` writes to the
	 *     `preview` list_type (the "preliminary list" the operator works
	 *     on before promotion).
	 *   - preliminary → `POST /notices/{id}/promote-preview` with
	 *     `mode=definitive_import` writes to `definitive` and transitions
	 *     the notice to `definitive` in one shot (the §5.1 promote flow).
	 *   - definitive / closed → import is disabled per §5.1; the section
	 *     renders an explanation instead of a form.
	 *
	 * Replaces the per-Candidates-tab CSV form (sprint 6.0.4) which
	 * required an explicit notice picker; living on the edit screen
	 * removes that ambiguity entirely.
	 *
	 * @param object $notice Notice row.
	 * @phpstan-param NoticeRow $notice
	 * @return void
	 */
	public static function render_csv_import_section( object $notice ): void {
		$notice_id = (int) $notice->id;
		$status    = (string) $notice->status;
		$nonce     = wp_create_nonce( 'wp_rest' );

		echo '<div class="postbox" style="margin-top:20px;">';
		echo '<h2 class="hndle"><span>' . esc_html__( 'Import candidates (CSV)', 'ffcertificate' ) . '</span></h2>';
		echo '<div class="inside">';

		if ( 'definitive' === $status || 'closed' === $status ) {
			echo '<p>' . esc_html__( 'Import is disabled for notices in `definitive` or `closed` status. Move the notice back to `preliminary` (allowed only when zero calls have been issued) to re-import.', 'ffcertificate' ) . '</p>';
			echo '</div></div>';
			return;
		}

		echo '<p>' . esc_html__( 'UTF-8 CSV (BOM optional). Required headers (English): name, cpf, rf, email, adjutancy, rank, score, pcd. Optional headers: phone, time_points, hab_emebs. At least one of cpf/rf required per row. Comma or semicolon delimiter is auto-detected.', 'ffcertificate' ) . '</p>';

		$example_url = wp_nonce_url(
			add_query_arg(
				array( 'action' => 'ffc_recruitment_download_csv_example' ),
				admin_url( 'admin-post.php' )
			),
			'ffc_recruitment_download_csv_example'
		);
		echo '<p><a class="button" href="' . esc_url( $example_url ) . '">&darr; ' . esc_html__( 'Download example CSV', 'ffcertificate' ) . '</a> ';
		echo '<span class="description" style="margin-left:.5em;">' . esc_html__( 'Two-row sample with every column populated. Use it as a starting point for your own file.', 'ffcertificate' ) . '</span></p>';

		echo '<form id="ffc-recruitment-edit-import" method="post" enctype="multipart/form-data" onsubmit="return ffcRecruitmentImportFromEdit(this);">';
		echo '<table class="form-table"><tbody>';

		echo '<tr><th><label>' . esc_html__( 'Target list', 'ffcertificate' ) . '</label></th><td>';
		echo '<label style="margin-right:1em;"><input type="radio" name="list_target" value="preliminary" checked> ' . esc_html__( 'Preliminary list', 'ffcertificate' ) . '</label>';
		if ( 'preliminary' === $status ) {
			echo '<label><input type="radio" name="list_target" value="definitive"> ' . esc_html__( 'Definitive list (also transitions notice to `definitive`)', 'ffcertificate' ) . '</label>';
		}
		echo '</td></tr>';

		echo '<tr><th><label for="ffc-edit-csv-file">' . esc_html__( 'CSV file', 'ffcertificate' ) . '</label></th><td>';
		echo '<input id="ffc-edit-csv-file" name="csv_file" type="file" accept=".csv,text/csv" required>';
		echo '</td></tr>';

		echo '</tbody></table>';
		echo '<p>';
		echo '<button id="ffc-edit-csv-submit" type="submit" class="button button-primary">' . esc_html__( 'Import', 'ffcertificate' ) . '</button> ';
		// Spinner + elapsed counter sits in the same line. Hidden until
		// submit fires; revealed by the inline JS below. The importer is
		// a single atomic request (no streaming progress hook) so this is
		// an "activity indicator with elapsed seconds", not a real
		// progress bar — the goal is to make it clear the request is in
		// flight on large CSVs that may take 5–30s to commit.
		// Progress widget — `<progress>` + counter for the batched preview
		// flow; falls back to the spinner-only look for the definitive
		// flow (which still posts in one shot to /promote-preview).
		echo '<span id="ffc-edit-csv-progress" style="display:none;align-items:center;gap:.5em;">';
		echo '<span class="spinner is-active" style="float:none;margin:0;"></span>';
		echo '<progress id="ffc-edit-csv-progress-bar" max="1" value="0" style="width:200px;height:14px;"></progress>';
		echo '<span id="ffc-edit-csv-progress-text"></span>';
		echo '</span>';
		echo '<span id="ffc-edit-csv-status" style="margin-left:1em;font-family:monospace;font-size:12px;"></span>';
		echo '</p>';
		// Per-line validation errors land here when /import-job/validate
		// returns a non-empty list. Hidden by default; the orchestrator
		// fills it in and the operator scrolls through what to fix.
		echo '<ul id="ffc-edit-csv-errors" style="margin:.5em 0 0;padding-left:1.5em;font-family:monospace;font-size:12px;color:#b32d2e;"></ul>';
		echo '</form>';

		// Inline submit handler. The `preview` flow hands off to
		// `window.ffcRecruitmentImportBatched.run()` (start → loop batch →
		// commit) so notices with hundreds of candidates stop racing the
		// gateway timeout. The `definitive` flow keeps the old single-
		// request shape against /promote-preview because that endpoint
		// also performs the snapshot + state transition under a 15-second
		// countdown — batching there would need a different design.
		$strings   = array(
			'ingesting'        => __( 'Ingesting…', 'ffcertificate' ),
			'validating'       => __( 'Validating…', 'ffcertificate' ),
			'processing'       => __( 'Processing…', 'ffcertificate' ),
			'committing'       => __( 'Finalising…', 'ffcertificate' ),
			'done'             => __( 'OK', 'ffcertificate' ),
			'errorPrefix'      => __( 'Error:', 'ffcertificate' ),
			'networkError'     => __( 'Network error', 'ffcertificate' ),
			'validationFailed' => __( 'Validation failed — review the per-line errors below and re-import.', 'ffcertificate' ),
		);
		$rest_root = rest_url( 'ffcertificate/v1/recruitment/' );
		echo '<script>'
			. 'function ffcRecruitmentImportFromEdit(form){'
			. 'var nid=' . (int) $notice_id . ';'
			. 'var target=form.list_target.value;'
			. 'var file=form.csv_file.files[0];'
			. 'var btn=document.getElementById("ffc-edit-csv-submit");'
			. 'var status=document.getElementById("ffc-edit-csv-status");'
			. 'var progress=document.getElementById("ffc-edit-csv-progress");'
			. 'var progressBar=document.getElementById("ffc-edit-csv-progress-bar");'
			. 'var progressText=document.getElementById("ffc-edit-csv-progress-text");'
			. 'var errorList=document.getElementById("ffc-edit-csv-errors");'
			. 'btn.disabled=true;status.textContent="";if(errorList){errorList.innerHTML="";}'
			. 'if(target!=="definitive"&&window.ffcRecruitmentImportBatched){'
			// Preview list → staging-based orchestrator (4 phases).
			. 'window.ffcRecruitmentImportBatched.run({'
			. 'noticeId:nid,file:file,'
			. 'restRoot:' . wp_json_encode( esc_url_raw( $rest_root ) ) . ','
			. 'nonce:' . wp_json_encode( $nonce ) . ','
			. 'btn:btn,status:status,'
			. 'progressWrap:progress,progressBar:progressBar,progressText:progressText,'
			. 'errorList:errorList,'
			. 'strings:' . wp_json_encode( $strings )
			. '}).catch(function(){});'
			. 'return false;'
			. '}'
			// Definitive list → single-shot /promote-preview (unchanged).
			. 'var fd=new FormData();fd.append("csv_file",file);'
			. 'fd.append("mode","definitive_import");'
			. 'var url=' . wp_json_encode( esc_url_raw( $rest_root ) ) . '+"notices/"+nid+"/promote-preview";'
			. 'progress.style.display="inline-flex";'
			. 'progressBar.style.display="none";'
			. 'progressText.textContent=' . wp_json_encode( __( 'Processing CSV…', 'ffcertificate' ) ) . ';'
			. 'function cleanup(){progress.style.display="none";progressBar.style.display="";btn.disabled=false;}'
			. 'fetch(url,{method:"POST",headers:{"X-WP-Nonce":' . wp_json_encode( $nonce ) . '},body:fd,credentials:"same-origin"})'
			. '.then(function(r){return r.json().then(function(d){return{status:r.status,body:d};});}).then(function(o){'
			. 'cleanup();'
			. 'if(o.status>=200&&o.status<300){status.textContent="OK ("+((o.body&&o.body.message)?o.body.message:JSON.stringify(o.body))+")";location.reload();}'
			. 'else{status.textContent="Error: "+((o.body&&o.body.message)?o.body.message:JSON.stringify(o.body));}'
			. '}).catch(function(e){cleanup();status.textContent="Network error: "+e.message;});'
			. 'return false;}'
			. '</script>';

		echo '</div></div>';
	}

	/**
	 * Section 1: General (code + name + public_columns_config).
	 *
	 * @param object $notice Notice row.
	 * @phpstan-param NoticeRow $notice
	 * @return void
	 */
	public static function render_general_section( object $notice ): void {
		$nonce_action = 'ffc_recruitment_save_notice_' . (int) $notice->id;

		echo '<div class="postbox" style="margin-top:20px;">';
		echo '<h2 class="hndle"><span>' . esc_html__( 'General', 'ffcertificate' ) . '</span></h2>';
		echo '<div class="inside">';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="ffc_recruitment_save_notice">';
		echo '<input type="hidden" name="notice_id" value="' . esc_attr( (string) $notice->id ) . '">';
		wp_nonce_field( $nonce_action );

		echo '<table class="form-table"><tbody>';

		echo '<tr><th><label>' . esc_html__( 'Code', 'ffcertificate' ) . '</label></th>';
		echo '<td><code>' . esc_html( (string) $notice->code ) . '</code> ';
		// §3.2 — codes are stable identifiers; never editable post-creation.
		echo '<span class="description">' . esc_html__( '(read-only — codes are stable identifiers and cannot be changed after creation)', 'ffcertificate' ) . '</span></td></tr>';

		echo '<tr><th><label for="ffc-notice-name">' . esc_html__( 'Name', 'ffcertificate' ) . '</label></th>';
		echo '<td><input id="ffc-notice-name" type="text" class="regular-text" name="name" value="' . esc_attr( (string) $notice->name ) . '" required></td></tr>';

		echo '<tr><th>' . esc_html__( 'Public columns', 'ffcertificate' ) . '</th>';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_columns_toggles() returns already-escaped HTML.
		echo '<td>' . self::render_columns_toggles( (string) $notice->public_columns_config );
		echo '<p class="description">' . esc_html__( 'Toggle which columns the public shortcode renders. Rank and Name are mandatory and cannot be turned off.', 'ffcertificate' ) . '</p></td></tr>';

		// Dedicated row for the preliminary-reason public visibility
		// toggle. Stored under the same `public_columns_config.preview_reason`
		// key as the column grid so the save handler stays unchanged,
		// but rendered separately because it isn't a column — it's a
		// per-edital all-or-nothing toggle for whether the preliminary
		// reason text shows up next to the badge on the public listing.
		$decoded         = json_decode( (string) $notice->public_columns_config, true );
		$decoded         = is_array( $decoded ) ? $decoded : array();
		$preview_default = (array) json_decode( RecruitmentNoticeRepository::DEFAULT_PUBLIC_COLUMNS_CONFIG, true );
		$preview_state   = array_merge( $preview_default, $decoded );
		$preview_checked = ! empty( $preview_state['preview_reason'] );

		echo '<tr><th>' . esc_html__( 'Preliminary reasons', 'ffcertificate' ) . '</th><td>';
		\FreeFormCertificate\Admin\AdminUI::render_toggle(
			array(
				'name'    => 'public_columns[preview_reason]',
				'id'      => 'ffc-notice-pcc-preview_reason',
				'checked' => $preview_checked,
				'label'   => __( 'Show preliminary reasons publicly on this notice', 'ffcertificate' ),
			)
		);
		echo '<p class="description">' . esc_html__( 'When on, the public shortcode will render the reason label next to the preliminary status badge. Off by default per notice — operators decide all-or-nothing per edital.', 'ffcertificate' ) . '</p>';
		echo '</td></tr>';

		echo '</tbody></table>';
		submit_button( __( 'Save general', 'ffcertificate' ) );
		echo '</form>';

		echo '</div></div>';
	}

	/**
	 * Render the public_columns_config field as a grid of on/off
	 * checkboxes (one per supported column). The mandatory columns
	 * (rank, name) render as disabled checkboxes pre-checked + a
	 * hidden input with `value=1` so they always survive the save —
	 * disabled inputs don't post their value, but a sibling hidden
	 * field with the same name keeps the boolean true on the server.
	 *
	 * Stored as the same JSON shape `parse_columns_config()` already
	 * consumes, so the public shortcode side needs no change.
	 *
	 * @param string $current_json Stored `public_columns_config` JSON.
	 * @return string
	 */
	private static function render_columns_toggles( string $current_json ): string {
		$labels = self::columns_label_map();

		$decoded = json_decode( $current_json, true );
		$decoded = is_array( $decoded ) ? $decoded : array();
		/**
		 * Defaults from the repository so a brand-new notice with an
		 * empty/invalid stored config still renders the canonical "on"
		 * set instead of every checkbox unchecked.
		 *
		 * @var array<string,bool> $defaults
		 */
		$defaults = (array) json_decode( RecruitmentNoticeRepository::DEFAULT_PUBLIC_COLUMNS_CONFIG, true );
		$state    = array_merge( $defaults, $decoded );

		$mandatory = array( 'rank', 'name' );
		// `preview_reason` is rendered in its own dedicated row below
		// the column grid (see render_general_section()) because it's
		// not really a column — it's a per-edital toggle controlling
		// whether the preliminary-list reason text is exposed publicly.
		$rendered_in_grid = static fn( string $key ): bool => 'preview_reason' !== $key;

		$html = '<div class="ffc-recruitment-columns-toggles" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:6px 16px;">';
		foreach ( $labels as $key => $label ) {
			if ( ! $rendered_in_grid( $key ) ) {
				continue;
			}
			$is_mandatory = in_array( $key, $mandatory, true );
			$checked      = $is_mandatory || ! empty( $state[ $key ] );
			$id_attr      = 'ffc-notice-pcc-' . $key;
			$html        .= '<div style="display:flex;align-items:center;gap:6px;">';
			if ( $is_mandatory ) {
				// A disabled toggle doesn't post; a hidden sibling pins the
				// value=1 so the save handler always sees the mandatory
				// column as on.
				$html .= '<input type="hidden" name="public_columns[' . esc_attr( $key ) . ']" value="1">';
				$html .= \FreeFormCertificate\Admin\AdminUI::get_toggle(
					array(
						'name'     => 'public_columns[' . $key . ']',
						'id'       => $id_attr,
						'checked'  => true,
						'disabled' => true,
						'label'    => $label,
					)
				);
				$html .= ' <em style="color:#646970;font-size:11px;">(' . esc_html__( 'mandatory', 'ffcertificate' ) . ')</em>';
			} else {
				$html .= \FreeFormCertificate\Admin\AdminUI::get_toggle(
					array(
						'name'    => 'public_columns[' . $key . ']',
						'id'      => $id_attr,
						'checked' => $checked,
						'label'   => $label,
					)
				);
			}
			$html .= '</div>';
		}
		$html .= '</div>';

		return $html;
	}

	/**
	 * Display labels for every column the public shortcode supports.
	 * The order here drives the order the toggles render in the grid.
	 *
	 * @return array<string,string>
	 */
	public static function columns_label_map(): array {
		return array(
			'rank'           => __( 'Rank', 'ffcertificate' ),
			'name'           => __( 'Name', 'ffcertificate' ),
			'adjutancy'      => __( 'Adjutancy', 'ffcertificate' ),
			'status'         => __( 'Status', 'ffcertificate' ),
			// Storage key kept as `pcd_badge` for backward compatibility
			// (existing notices' public_columns_config JSON keeps working);
			// surfaced as "Subscription type" because the PCD column on
			// the CSV is a boolean PCD/GERAL flag.
			'pcd_badge'      => __( 'Subscription type (PCD / GERAL)', 'ffcertificate' ),
			'date_to_assume' => __( 'Date to assume', 'ffcertificate' ),
			'time_to_assume' => __( 'Time to assume', 'ffcertificate' ),
			'score'          => __( 'Score', 'ffcertificate' ),
			'time_points'    => __( 'Time points', 'ffcertificate' ),
			'hab_emebs'      => __( 'HAB. EMEBs', 'ffcertificate' ),
			'cpf_masked'     => __( 'CPF (masked)', 'ffcertificate' ),
			'rf_masked'      => __( 'RF (masked)', 'ffcertificate' ),
			'email_masked'   => __( 'Email (masked)', 'ffcertificate' ),
			// Rendered as a dedicated row in render_general_section() rather
			// than inside the column-toggles grid; key kept here so the
			// save handler can iterate it the same way as the others.
			'preview_reason' => __( 'Show preliminary reasons publicly', 'ffcertificate' ),
		);
	}

	/**
	 * Modal copy per (current → target) status transition.
	 *
	 * Returns the title / body / consequence bullets / CTA / style /
	 * optional reason_label that the confirm-modal (ffc-recruitment-admin.js)
	 * renders for each transition. Keys are target statuses; if a target
	 * is missing the form falls back to a plain submit (no modal).
	 *
	 * The `closed` current-state branch wires the reopen reason input
	 * into the modal itself — the inline `<input name="reason">` that
	 * used to sit alongside the buttons is gone.
	 *
	 * @param string $current Current notice status.
	 * @return array<string, array{title:string,body:string,consequences:array<int,string>,cta:string,style:string,reason_label?:string,countdown?:int}>
	 */
	private static function transition_modal_config( string $current ): array {
		if ( 'closed' === $current ) {
			return array(
				'definitive' => array(
					'title'        => __( 'Reopen the closed notice?', 'ffcertificate' ),
					'body'         => __( 'Reopening flips the notice back to `definitive` so calls can resume.', 'ffcertificate' ),
					'consequences' => array(
						__( 'A reopen reason is recorded with this transition.', 'ffcertificate' ),
						__( 'Hired and not_shown classifications stay permanently frozen — they cannot be reopened later.', 'ffcertificate' ),
						__( 'The public shortcode no longer shows the "Notice closed." banner.', 'ffcertificate' ),
					),
					'cta'          => __( 'Reopen notice', 'ffcertificate' ),
					'style'        => 'primary',
					'reason_label' => __( 'Reopen reason (required)', 'ffcertificate' ),
				),
			);
		}

		return array(
			'preliminary' => array(
				'title'        => __( 'Publish preliminary list?', 'ffcertificate' ),
				'body'         => __( 'The notice moves to `preliminary`.', 'ffcertificate' ),
				'consequences' => array(
					__( 'The imported candidate list becomes visible on the public shortcode.', 'ffcertificate' ),
					__( 'Candidates can see their position and any preliminary classification reasons exposed by the column toggles.', 'ffcertificate' ),
				),
				'cta'          => __( 'Publish as preliminary', 'ffcertificate' ),
				'style'        => 'primary',
			),
			'definitive'  => array(
				'title'        => __( 'Promote to definitive?', 'ffcertificate' ),
				'body'         => __( 'The notice moves to `definitive`. The classification list is locked as final.', 'ffcertificate' ),
				'consequences' => array(
					__( 'Calls can be issued from this point on.', 'ffcertificate' ),
					__( 'The public shortcode flips to the "Final classification." banner.', 'ffcertificate' ),
					__( 'Going back to `preliminary` is only possible if no call has been issued yet.', 'ffcertificate' ),
				),
				'cta'          => __( 'Promote to definitive', 'ffcertificate' ),
				'style'        => 'primary',
				// 15s countdown — issue #262 item 2. The promote step
				// locks the classification list as final, so the modal
				// adds a forced read pause before the CTA enables.
				'countdown'    => 15,
			),
			'closed'      => array(
				'title'        => __( 'Close this notice?', 'ffcertificate' ),
				'body'         => __( 'Closing freezes the notice and its calls history.', 'ffcertificate' ),
				'consequences' => array(
					__( 'No new calls, status changes or cancellations can happen while closed.', 'ffcertificate' ),
					__( 'The public shortcode shows the "Notice closed." banner above the list.', 'ffcertificate' ),
					__( 'Reopening later requires a reason and permanently freezes hired/withdrew/not_shown classifications.', 'ffcertificate' ),
				),
				'cta'          => __( 'Close notice', 'ffcertificate' ),
				'style'        => 'destructive',
			),
			'draft'       => array(
				'title'        => __( 'Move notice back to draft?', 'ffcertificate' ),
				'body'         => __( 'The notice returns to `draft`.', 'ffcertificate' ),
				'consequences' => array(
					__( 'The candidate list is hidden from the public shortcode.', 'ffcertificate' ),
					__( 'No data is lost — you can publish again later.', 'ffcertificate' ),
				),
				'cta'          => __( 'Move to draft', 'ffcertificate' ),
				'style'        => 'primary',
			),
		);
	}

	/**
	 * Section 2: Status — badge + transition buttons.
	 *
	 * Each button posts to `admin-post.php?action=ffc_recruitment_transition_notice`
	 * with the target status; `handle_transition` validates the nonce
	 * and delegates to `NoticeStateMachine::transition_to`. The state
	 * machine surfaces all the §5.1 guards (zero-calls precondition,
	 * reopen-freeze, etc.) — the UI just enumerates the transitions
	 * theoretically valid from the current state.
	 *
	 * @param object $notice Notice row.
	 * @phpstan-param NoticeRow $notice
	 * @return void
	 */
	public static function render_status_section( object $notice ): void {
		$current      = (string) $notice->status;
		$nonce_action = 'ffc_recruitment_transition_notice_' . (int) $notice->id;

		echo '<div class="postbox" style="margin-top:20px;">';
		echo '<h2 class="hndle"><span>' . esc_html__( 'Status', 'ffcertificate' ) . '</span></h2>';
		echo '<div class="inside">';

		echo '<p><strong>' . esc_html__( 'Current state:', 'ffcertificate' ) . '</strong> ';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper returns escaped HTML.
		echo RecruitmentAdminPage::notice_status_badge( $current );
		if ( '1' === (string) $notice->was_reopened ) {
			echo ' <em>(' . esc_html__( 'previously reopened — hired/withdrew/not_shown classifications are frozen', 'ffcertificate' ) . ')</em>';
		}
		echo '</p>';

		// Special-case the preliminary → definitive transition: it has two
		// paths per §5.1 — snapshot the preview list as definitive, or
		// import a brand-new definitive CSV. We surface the choice
		// inline only when there are no definitive rows yet; otherwise
		// the regular "Promote to definitive" button is enough (it just
		// flips the status).
		if ( 'preliminary' === $current ) {
			self::render_preliminary_to_final_options( $notice, $nonce_action );
		}

		$transitions = self::transitions_from( $current );

		if ( 'preliminary' === $current ) {
			// Already rendered the prelim → definitive controls above; here we
			// only need the back-to-draft path.
			unset( $transitions['definitive'] );
		}

		if ( empty( $transitions ) ) {
			if ( 'preliminary' !== $current ) {
				echo '<p>' . esc_html__( 'No transitions available from this state.', 'ffcertificate' ) . '</p>';
			}
		} else {
			// Per-target modal copy. Each transition has a side effect we
			// want the operator to acknowledge before committing.
			$modal_config = self::transition_modal_config( $current );

			echo '<p>';
			foreach ( $transitions as $target => $label ) {
				$cfg          = isset( $modal_config[ $target ] ) ? $modal_config[ $target ] : null;
				$consequences = wp_json_encode( $cfg ? $cfg['consequences'] : array() );

				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline;margin-right:.5em;"';
				if ( $cfg ) {
					echo ' data-ffc-confirm'
						. ' data-ffc-confirm-title="' . esc_attr( $cfg['title'] ) . '"'
						. ' data-ffc-confirm-body="' . esc_attr( $cfg['body'] ) . '"'
						. ' data-ffc-confirm-consequences="' . esc_attr( (string) $consequences ) . '"'
						. ' data-ffc-confirm-cta="' . esc_attr( $cfg['cta'] ) . '"'
						. ' data-ffc-confirm-style="' . esc_attr( $cfg['style'] ) . '"';
					if ( ! empty( $cfg['reason_label'] ) ) {
						echo ' data-ffc-confirm-reason-label="' . esc_attr( $cfg['reason_label'] ) . '"';
						echo ' data-ffc-confirm-reason-name="reason"';
					}
					if ( ! empty( $cfg['countdown'] ) ) {
						echo ' data-ffc-confirm-countdown="' . esc_attr( (string) (int) $cfg['countdown'] ) . '"';
					}
				}
				echo '>';
				echo '<input type="hidden" name="action" value="ffc_recruitment_transition_notice">';
				echo '<input type="hidden" name="notice_id" value="' . esc_attr( (string) $notice->id ) . '">';
				echo '<input type="hidden" name="target_status" value="' . esc_attr( $target ) . '">';
				wp_nonce_field( $nonce_action );
				echo '<button type="submit" class="button button-secondary">';
				echo esc_html( $label );
				echo '</button>';
				echo '</form>';
			}
			echo '</p>';
		}

		echo '</div></div>';
	}

	/**
	 * Render the preliminary → definitive dual-path UI.
	 *
	 * §5.1 promotion has two modes:
	 *   - snapshot — copy the current `preview` list into `definitive`
	 *     (no CSV).
	 *   - definitive_import — upload a brand-new CSV that becomes the
	 *     definitive list.
	 *
	 * If `definitive` rows already exist (e.g. from a prior promote
	 * cycle), neither mode applies — the operator just promotes the
	 * status. We detect that here and route accordingly.
	 *
	 * @param object $notice       Notice row.
	 * @phpstan-param NoticeRow $notice
	 * @param string $nonce_action Nonce key shared with handle_transition.
	 * @return void
	 */
	private static function render_preliminary_to_final_options( object $notice, string $nonce_action ): void {
		$id              = (int) $notice->id;
		$definitive_rows = RecruitmentClassificationRepository::get_for_notice( $id, 'definitive' );

		echo '<h3>' . esc_html__( 'Promote to definitive', 'ffcertificate' ) . '</h3>';

		if ( ! empty( $definitive_rows ) ) {
			// Definitive list already exists — single-button path.
			echo '<p>' . esc_html__( 'A definitive list already exists for this notice. Promoting just flips the status to `definitive` (the existing definitive list is preserved).', 'ffcertificate' ) . '</p>';

			$promote_consequences = wp_json_encode(
				array(
					__( 'The notice transitions from `preliminary` to `definitive`.', 'ffcertificate' ),
					__( 'The existing definitive list is preserved as-is.', 'ffcertificate' ),
					__( 'Calls can be issued from this point on; going back is only possible if no call has been issued.', 'ffcertificate' ),
				)
			);
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"'
				. ' data-ffc-confirm'
				. ' data-ffc-confirm-title="' . esc_attr__( 'Promote to definitive?', 'ffcertificate' ) . '"'
				. ' data-ffc-confirm-body="' . esc_attr__( 'You are about to flip the notice status to `definitive`.', 'ffcertificate' ) . '"'
				. ' data-ffc-confirm-consequences="' . esc_attr( (string) $promote_consequences ) . '"'
				. ' data-ffc-confirm-cta="' . esc_attr__( 'Promote to definitive', 'ffcertificate' ) . '"'
				. ' data-ffc-confirm-style="primary"'
				. ' data-ffc-confirm-countdown="15">';
			echo '<input type="hidden" name="action" value="ffc_recruitment_transition_notice">';
			echo '<input type="hidden" name="notice_id" value="' . esc_attr( (string) $id ) . '">';
			echo '<input type="hidden" name="target_status" value="definitive">';
			wp_nonce_field( $nonce_action );
			submit_button( __( 'Promote to definitive', 'ffcertificate' ), 'primary', '', false );
			echo '</form>';

			echo '<hr style="margin:1.5em 0;">';
			return;
		}

		echo '<p>' . esc_html__( 'No definitive list exists yet. Choose how to populate it:', 'ffcertificate' ) . '</p>';

		// Path A — snapshot. Hits POST /notices/{id}/promote-preview
		// mode=snapshot via fetch (the endpoint does the copy +
		// status flip atomically). Confirmation goes through the shared
		// confirm-modal in ffc-recruitment-admin.js — we put the data
		// attributes on the button itself; the modal intercepts the
		// click and on confirm sets data-ffc-confirm-ok=1 so the
		// existing handler proceeds.
		$rest_nonce            = wp_create_nonce( 'wp_rest' );
		$snapshot_consequences = wp_json_encode(
			array(
				__( 'The current preliminary list is copied verbatim into the definitive list.', 'ffcertificate' ),
				__( 'The notice transitions from `preliminary` to `definitive`.', 'ffcertificate' ),
				__( 'Calls can be issued from this point on; going back is only possible if no call has been issued.', 'ffcertificate' ),
			)
		);
		echo '<p>';
		echo '<button type="button" class="button button-primary"'
			. ' data-ffc-confirm'
			. ' data-ffc-confirm-title="' . esc_attr__( 'Promote (snapshot) to definitive?', 'ffcertificate' ) . '"'
			. ' data-ffc-confirm-body="' . esc_attr__( 'You are about to snapshot the preliminary list as the definitive list.', 'ffcertificate' ) . '"'
			. ' data-ffc-confirm-consequences="' . esc_attr( (string) $snapshot_consequences ) . '"'
			. ' data-ffc-confirm-cta="' . esc_attr__( 'Promote to definitive', 'ffcertificate' ) . '"'
			. ' data-ffc-confirm-style="primary"'
			. ' data-ffc-confirm-countdown="15"'
			. ' onclick="ffcRecruitmentSnapshotPromote(' . (int) $id . ', this);">'
			. esc_html__( 'A — Publish preliminary as definitive (snapshot, no changes)', 'ffcertificate' ) . '</button> ';
		echo '<button type="button" class="button button-secondary" onclick="document.getElementById(\'ffc-recruitment-edit-import\').scrollIntoView({behavior:\'smooth\'});">' . esc_html__( 'B — Import a new list as definitive', 'ffcertificate' ) . '</button>';
		echo '</p>';

		// The confirm flow intercepts the click and re-fires it after
		// the user confirms (with data-ffc-confirm-ok=1 set). The
		// handler skips the fetch unless that flag is present so the
		// first click only opens the modal.
		echo '<script>'
			. 'function ffcRecruitmentSnapshotPromote(nid, btn){'
			. 'if(btn&&btn.getAttribute("data-ffc-confirm-ok")!=="1"){return false;}'
			. 'var fd=new FormData();fd.append("mode","snapshot");'
			. 'fetch("' . esc_url_raw( rest_url( 'ffcertificate/v1/recruitment/notices/' ) ) . '"+nid+"/promote-preview",{'
			. 'method:"POST",headers:{"X-WP-Nonce":"' . esc_attr( $rest_nonce ) . '"},body:fd,credentials:"same-origin"'
			. '}).then(function(r){return r.json().then(function(d){return{status:r.status,body:d};});}).then(function(o){'
			. 'if(o.status>=200&&o.status<300){location.reload();}else{alert((o.body&&o.body.message)?o.body.message:JSON.stringify(o.body));}'
			. '}).catch(function(e){alert("Network error: "+e.message);});'
			. '}'
			. '</script>';

		echo '<hr style="margin:1.5em 0;">';
	}

	/**
	 * Section 3: Adjutancies — attached pills + attach selector.
	 *
	 * The same controls that lived inline on the Notices row pre-A1,
	 * relocated to the edit screen. Markup unchanged; the inline
	 * `<script>` handlers move to assets/js/ffc-recruitment-admin.js
	 * in a follow-up so this commit doesn't grow the diff further.
	 *
	 * @param object $notice Notice row.
	 * @phpstan-param NoticeRow $notice
	 * @return void
	 */
	public static function render_adjutancies_section( object $notice ): void {
		$notice_id    = (int) $notice->id;
		$adjutancies  = RecruitmentAdjutancyRepository::get_all();
		$attached_ids = array_values( RecruitmentNoticeAdjutancyRepository::get_adjutancy_ids_for_notice( $notice_id ) );
		$attached_set = array_flip( $attached_ids );
		$nonce        = wp_create_nonce( 'wp_rest' );

		echo '<div class="postbox" style="margin-top:20px;">';
		echo '<h2 class="hndle"><span>' . esc_html__( 'Adjutancies', 'ffcertificate' ) . '</span></h2>';
		echo '<div class="inside">';
		echo '<p>' . esc_html__( 'Adjutancies referenced by CSV imports must be attached to the notice via this section.', 'ffcertificate' ) . '</p>';

		$attached_objects = array_values(
			array_filter(
				$adjutancies,
				static function ( $a ) use ( $attached_set ) {
					return isset( $attached_set[ (int) $a->id ] );
				}
			)
		);

		if ( empty( $attached_objects ) ) {
			echo '<p><em>' . esc_html__( 'No adjutancies attached yet.', 'ffcertificate' ) . '</em></p>';
		} else {
			echo '<p><span class="ffc-attached-list">';
			foreach ( $attached_objects as $a ) {
				echo '<span class="ffc-attached">';
				echo esc_html( (string) $a->slug );
				echo ' <a href="#" data-notice="' . esc_attr( (string) $notice_id ) . '" data-adjutancy="' . esc_attr( (string) $a->id ) . '" onclick="return ffcDetachAdjutancy(this);" title="' . esc_attr__( 'Detach', 'ffcertificate' ) . '">×</a>';
				echo '</span>';
			}
			echo '</span></p>';
		}

		$detached_objects = array_values(
			array_filter(
				$adjutancies,
				static function ( $a ) use ( $attached_set ) {
					return ! isset( $attached_set[ (int) $a->id ] );
				}
			)
		);
		if ( ! empty( $detached_objects ) ) {
			echo '<form onsubmit="return ffcAttachAdjutancy(this);" data-notice="' . esc_attr( (string) $notice_id ) . '">';
			echo '<select name="adjutancy_id">';
			foreach ( $detached_objects as $a ) {
				echo '<option value="' . esc_attr( (string) $a->id ) . '">' . esc_html( (string) $a->slug ) . ' — ' . esc_html( (string) $a->name ) . '</option>';
			}
			echo '</select>';
			echo ' <button type="submit" class="button button-secondary">' . esc_html__( 'Attach', 'ffcertificate' ) . '</button>';
			echo '</form>';
		}

		// Same inline handlers as sprint A1 — to be consolidated into
		// the assets manager bundle in sprint C polish.
		echo '<script>'
			. 'function ffcAttachAdjutancy(form){'
			. 'var nid=form.getAttribute("data-notice");'
			. 'var aid=form.adjutancy_id.value;'
			. 'fetch("' . esc_url_raw( rest_url( 'ffcertificate/v1/recruitment/notices/' ) ) . '"+nid+"/adjutancies/"+aid,{'
			. 'method:"PUT",headers:{"X-WP-Nonce":"' . esc_attr( $nonce ) . '"},credentials:"same-origin"'
			. '}).then(function(r){return r.json().then(function(d){return{status:r.status,body:d};});}).then(function(o){'
			. 'if(o.status>=200&&o.status<300){location.reload();}else{alert((o.body&&o.body.message)?o.body.message:JSON.stringify(o.body));}'
			. '});return false;}'
			. 'function ffcDetachAdjutancy(a){'
			. 'var nid=a.getAttribute("data-notice");'
			. 'var aid=a.getAttribute("data-adjutancy");'
			. 'fetch("' . esc_url_raw( rest_url( 'ffcertificate/v1/recruitment/notices/' ) ) . '"+nid+"/adjutancies/"+aid,{'
			. 'method:"DELETE",headers:{"X-WP-Nonce":"' . esc_attr( $nonce ) . '"},credentials:"same-origin"'
			. '}).then(function(r){return r.json().then(function(d){return{status:r.status,body:d};});}).then(function(o){'
			. 'if(o.status>=200&&o.status<300){location.reload();}else{alert((o.body&&o.body.message)?o.body.message:JSON.stringify(o.body));}'
			. '});return false;}'
			. '</script>';

		echo '</div></div>';
	}

	/**
	 * Section 4: Classifications — preliminary + definitive tabs.
	 *
	 * The two list_type stores get separate sub-tabs (one per list).
	 * The Definitive tab additionally exposes per-row action buttons
	 * (call / mark accepted / mark not_shown / mark hired / mark withdrew / cancel /
	 * reopen) so the operator can drive the §5.2 classification
	 * transitions without leaving the edit screen. Preliminary stays
	 * read-only since per the §5.2 invariant preview rows are always
	 * status='empty'.
	 *
	 * Tab switching is pure CSS-free DOM toggle in the inline JS;
	 * the two `<table>` blocks render in the same `<div>` and the
	 * active one gets `style="display:block"`.
	 *
	 * @param object $notice Notice row.
	 * @phpstan-param NoticeRow $notice
	 * @return void
	 */
	public static function render_classifications_section( object $notice ): void {
		$notice_id       = (int) $notice->id;
		$preview         = RecruitmentClassificationRepository::get_for_notice( $notice_id, 'preview' );
		$definitive_rows = RecruitmentClassificationRepository::get_for_notice( $notice_id, 'definitive' );

		// Once a definitive list exists for the notice the preview tab
		// becomes mostly archival — operators are working off the
		// definitive ranking. Captured from the unfiltered query so an
		// in-flight filter that happens to zero-out the definitive view
		// doesn't bounce the default back to Preliminary mid-session.
		$has_definitive = ! empty( $definitive_rows );
		// `ffc_cls_tab` is set by pagination links (so reload stays on
		// the same tab) and by the JS tab-switch handler via
		// `history.replaceState`. Falls back to the default-tab rule.
		$tab_override = isset( $_GET['ffc_cls_tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['ffc_cls_tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display preference.
		$active_tab   = in_array( $tab_override, array( 'preliminary', 'definitive' ), true )
			? $tab_override
			: ( $has_definitive ? 'definitive' : 'preliminary' );

		// Mirror the filters available on the Candidates list table —
		// adjutancy / name substring / CPF / RF — so operators can narrow
		// a long classifications list (notices with hundreds of rows) the
		// same way they do on the candidate browser. Filtering is applied
		// uniformly to both Preliminary and Definitive arrays so the
		// search context survives a tab switch.
		$filters         = RecruitmentClassificationFilterManager::read_filters( $notice_id );
		$preview         = RecruitmentClassificationFilterManager::apply_filters( $preview, $filters );
		$definitive_rows = RecruitmentClassificationFilterManager::apply_filters( $definitive_rows, $filters );

		echo '<div class="postbox" style="margin-top:20px;">';
		echo '<h2 class="hndle"><span>' . esc_html__( 'Classifications', 'ffcertificate' ) . '</span></h2>';
		echo '<div class="inside">';

		self::render_classification_filters_form( $notice_id, $filters );

		// Tabs.
		$prev_is_active = 'preliminary' === $active_tab;
		$prev_tab_class = $prev_is_active ? 'nav-tab nav-tab-active' : 'nav-tab';
		$def_tab_class  = $prev_is_active ? 'nav-tab' : 'nav-tab nav-tab-active';
		$prev_display   = $prev_is_active ? 'block' : 'none';
		$def_display    = $prev_is_active ? 'none' : 'block';

		echo '<h2 class="nav-tab-wrapper" style="margin:0 0 1em;">';
		echo '<a href="#" class="' . esc_attr( $prev_tab_class ) . '" data-ffc-clstab="preliminary" onclick="return ffcRecruitmentClsTabSwitch(this);">' . esc_html__( 'Preliminary', 'ffcertificate' ) . '</a>';
		echo '<a href="#" class="' . esc_attr( $def_tab_class ) . '" data-ffc-clstab="definitive" onclick="return ffcRecruitmentClsTabSwitch(this);">' . esc_html__( 'Definitive', 'ffcertificate' ) . '</a>';
		echo '</h2>';

		echo '<div data-ffc-clspanel="preliminary" style="display:' . esc_attr( $prev_display ) . ';">';
		self::render_classifications_table( $preview, false, 'preliminary' );
		echo '</div>';

		echo '<div data-ffc-clspanel="definitive" style="display:' . esc_attr( $def_display ) . ';">';
		self::render_classifications_table( $definitive_rows, true, 'definitive' );
		echo '</div>';

		// Tab toggle handler. Switches the .nav-tab-active class, shows
		// the matching panel, and writes `ffc_cls_tab` into the URL via
		// history.replaceState so a subsequent pagination link click
		// preserves whichever tab the operator chose manually.
		echo '<script>'
			. 'function ffcRecruitmentClsTabSwitch(a){'
			. 'var key=a.getAttribute("data-ffc-clstab");'
			. 'var nav=a.parentNode;'
			. 'var tabs=nav.querySelectorAll(".nav-tab");'
			. 'for(var i=0;i<tabs.length;i++){tabs[i].classList.remove("nav-tab-active");}'
			. 'a.classList.add("nav-tab-active");'
			. 'var panels=nav.parentNode.querySelectorAll("[data-ffc-clspanel]");'
			. 'for(var j=0;j<panels.length;j++){panels[j].style.display=panels[j].getAttribute("data-ffc-clspanel")===key?"block":"none";}'
			. 'try{var u=new URL(window.location.href);u.searchParams.set("ffc_cls_tab",key);history.replaceState(null,"",u.toString());}catch(e){}'
			. 'return false;}'
			. '</script>';

		echo '</div></div>';
	}

	/**
	 * Render the filter form sitting above the Preliminary/Definitive
	 * tab strip. Submits via GET so the URL stays shareable; preserves
	 * the page+action+notice_id triple via hidden inputs so the form
	 * lands back on the same edit screen.
	 *
	 * @param int                  $notice_id Notice id.
	 * @param array<string, mixed> $filters   Resolved filter values (echoed back).
	 * @return void
	 */
	private static function render_classification_filters_form( int $notice_id, array $filters ): void {
		$adjutancies  = RecruitmentNoticeAdjutancyRepository::get_adjutancy_ids_for_notice( $notice_id );
		$adj_id       = (int) ( $filters['adjutancy_id'] ?? 0 );
		$query        = (string) ( $filters['query'] ?? '' );
		$cpf          = (string) ( $filters['cpf'] ?? '' );
		$rf           = (string) ( $filters['rf'] ?? '' );
		$subscription = (string) ( $filters['subscription'] ?? '' );

		$reset_url = add_query_arg(
			array(
				'page'      => RecruitmentAdminPage::PAGE_SLUG,
				'action'    => 'edit-notice',
				'notice_id' => $notice_id,
			),
			admin_url( 'admin.php' )
		);

		echo '<form method="get" class="ffc-cls-filters" style="margin-bottom:1em;display:flex;flex-wrap:wrap;gap:6px;align-items:center;">';
		echo '<input type="hidden" name="page" value="' . esc_attr( RecruitmentAdminPage::PAGE_SLUG ) . '">';
		echo '<input type="hidden" name="action" value="edit-notice">';
		echo '<input type="hidden" name="notice_id" value="' . esc_attr( (string) $notice_id ) . '">';

		echo '<input type="text" name="ffc_cls_q" value="' . esc_attr( $query ) . '" placeholder="' . esc_attr__( 'Name (substring)', 'ffcertificate' ) . '" size="20">';
		echo ' <input type="text" name="ffc_cls_cpf" value="' . esc_attr( $cpf ) . '" placeholder="' . esc_attr__( 'CPF (digits only)', 'ffcertificate' ) . '" size="15">';
		echo ' <input type="text" name="ffc_cls_rf" value="' . esc_attr( $rf ) . '" placeholder="' . esc_attr__( 'RF (digits only)', 'ffcertificate' ) . '" size="10">';

		if ( ! empty( $adjutancies ) ) {
			echo ' <select name="ffc_cls_adj">';
			echo '<option value="0">' . esc_html__( 'All adjutancies', 'ffcertificate' ) . '</option>';
			foreach ( $adjutancies as $aid ) {
				$row = RecruitmentAdjutancyRepository::get_by_id( (int) $aid );
				if ( null === $row ) {
					continue;
				}
				$is_selected = (int) $row->id === $adj_id ? ' selected' : '';
				echo '<option value="' . esc_attr( (string) (int) $row->id ) . '"' . esc_attr( $is_selected ) . '>' . esc_html( (string) $row->name ) . '</option>';
			}
			echo '</select>';
		}

		echo ' <select name="ffc_cls_sub">';
		echo '<option value=""' . selected( '', $subscription, false ) . '>' . esc_html__( 'All subscription types', 'ffcertificate' ) . '</option>';
		echo '<option value="pcd"' . selected( 'pcd', $subscription, false ) . '>' . esc_html__( 'PCD only', 'ffcertificate' ) . '</option>';
		echo '<option value="geral"' . selected( 'geral', $subscription, false ) . '>' . esc_html__( 'GERAL only', 'ffcertificate' ) . '</option>';
		echo '</select>';

		echo ' <button type="submit" class="button">' . esc_html__( 'Filter', 'ffcertificate' ) . '</button>';
		echo ' <a href="' . esc_url( $reset_url ) . '" class="button-link">' . esc_html__( 'Reset', 'ffcertificate' ) . '</a>';
		echo '</form>';
	}

	/**
	 * Render a classifications table for one list_type.
	 *
	 * When `$with_actions` is true (the Definitive tab), each row gets
	 * an extra Actions column with the legal §5.2 transitions exposed
	 * as buttons. The buttons fire inline `fetch()` calls against
	 * `POST /classifications/{id}/call` and
	 * `PATCH /classifications/{id}/status`.
	 *
	 * @param array<int, object> $rows         Classification rows (post-filter).
	 * @phpstan-param list<ClassificationRow> $rows
	 * @param bool               $with_actions Whether to render the action column.
	 * @param string             $tab_key      `preliminary` or `definitive` — used
	 *                                          to scope the `ffc_cls_paged_<key>`
	 *                                          URL param so each tab paginates
	 *                                          independently.
	 * @return void
	 */
	private static function render_classifications_table( array $rows, bool $with_actions, string $tab_key = 'preliminary' ): void {
		if ( empty( $rows ) ) {
			echo '<p><em>' . esc_html__( '(no rows)', 'ffcertificate' ) . '</em></p>';
			return;
		}

		// Pagination. Per-page matches the activity log helper so the
		// admin viewport feels consistent across the plugin.
		$per_page    = 50;
		$page_param  = 'ffc_cls_paged_' . $tab_key;
		$total       = count( $rows );
		$total_pages = (int) max( 1, ceil( $total / $per_page ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page preference.
		$current_page = isset( $_GET[ $page_param ] ) ? max( 1, (int) $_GET[ $page_param ] ) : 1;
		$current_page = min( $current_page, $total_pages );
		$offset       = ( $current_page - 1 ) * $per_page;
		$rows         = array_slice( $rows, $offset, $per_page );

		// Pre-fetch candidate + adjutancy lookups in a single pass to
		// avoid N+1 inside the render loop.
		$candidate_ids = array_map( static fn( $r ) => (int) $r->candidate_id, $rows );
		$adjutancy_ids = array_map( static fn( $r ) => (int) $r->adjutancy_id, $rows );

		$candidates  = self::lookup_map( array_unique( $candidate_ids ), array( RecruitmentCandidateRepository::class, 'get_by_id' ), 'name' );
		$adjutancies = self::lookup_map( array_unique( $adjutancy_ids ), array( RecruitmentAdjutancyRepository::class, 'get_by_id' ), 'slug' );

		// Bulk-call toolbar (Definitive tab only): selected `empty` rows
		// can be called together via POST /classifications/bulk-call. The
		// REST endpoint enforces atomicity (all-or-nothing per §6) so a
		// race-loss on any single row rolls back the entire batch.
		if ( $with_actions ) {
			self::render_bulk_call_toolbar();
		}

		// On the Preliminary tab the Status column is always "Waiting"
		// (the §5.2 invariant), so we replace it with the editable
		// preview_status + reason dropdown. The Definitive tab keeps the
		// existing Status badge.
		$is_preview_tab = ! $with_actions;
		$reasons        = $is_preview_tab ? RecruitmentReasonRepository::get_all() : array();

		echo '<table class="widefat striped"><thead><tr>';
		if ( $with_actions ) {
			echo '<th style="width:1%;"><input type="checkbox" id="ffc-cls-bulk-all" onclick="ffcRecruitmentClsToggleAll(this);" title="' . esc_attr__( 'Select all waiting rows on this page', 'ffcertificate' ) . '"></th>';
		}
		echo '<th>' . esc_html__( 'Rank', 'ffcertificate' ) . '</th>';
		echo '<th>' . esc_html__( 'Candidate', 'ffcertificate' ) . '</th>';
		echo '<th>' . esc_html__( 'Adjutancy', 'ffcertificate' ) . '</th>';
		echo '<th>' . esc_html__( 'Score', 'ffcertificate' ) . '</th>';
		if ( $is_preview_tab ) {
			echo '<th>' . esc_html__( 'Preliminary status', 'ffcertificate' ) . '</th>';
			echo '<th>' . esc_html__( 'Reason', 'ffcertificate' ) . '</th>';
		} else {
			echo '<th>' . esc_html__( 'Status', 'ffcertificate' ) . '</th>';
		}
		if ( $with_actions ) {
			echo '<th>' . esc_html__( 'Actions', 'ffcertificate' ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$candidate_name = $candidates[ (int) $row->candidate_id ] ?? '#' . (int) $row->candidate_id;
			$adjutancy_slug = $adjutancies[ (int) $row->adjutancy_id ] ?? '#' . (int) $row->adjutancy_id;
			// data-cls-* attributes drive the out-of-order detection in
			// render_classification_actions_script(): the JS scans all
			// rows to compute the lowest-rank `empty` row per adjutancy
			// and flags clicks/bulk-selects that skip ahead of it.
			printf(
				'<tr data-cls-id="%d" data-cls-rank="%d" data-cls-adjutancy="%s" data-cls-status="%s">',
				(int) $row->id,
				(int) $row->rank,
				esc_attr( (string) $adjutancy_slug ),
				esc_attr( (string) $row->status )
			);
			if ( $with_actions ) {
				// Only `empty` rows can be bulk-called (the §5.2
				// state-machine guard rejects everything else with
				// race_lost), so the checkbox is rendered disabled
				// otherwise.
				$is_empty = 'empty' === (string) $row->status;
				echo '<td><input type="checkbox" class="ffc-cls-bulk-cb" value="' . esc_attr( (string) $row->id ) . '"' . ( $is_empty ? '' : ' disabled' ) . '></td>';
			}
			echo '<td>' . esc_html( (string) $row->rank ) . '</td>';
			echo '<td>' . esc_html( $candidate_name ) . '</td>';
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper returns escaped HTML.
			echo '<td>' . RecruitmentAdminPage::adjutancy_badge( RecruitmentAdjutancyRepository::get_by_id( (int) $row->adjutancy_id ) ) . '</td>';
			echo '<td>' . esc_html( (string) $row->score ) . '</td>';
			if ( $is_preview_tab ) {
				$current_preview = isset( $row->preview_status ) ? (string) $row->preview_status : 'empty';
				$current_reason  = isset( $row->preview_reason_id ) && null !== $row->preview_reason_id ? (int) $row->preview_reason_id : 0;
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper returns escaped HTML.
				echo '<td>' . self::render_preview_status_select( (int) $row->id, $current_preview ) . '</td>';
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper returns escaped HTML.
				echo '<td>' . self::render_preview_reason_select( (int) $row->id, $current_preview, $current_reason, $reasons ) . '</td>';
			} else {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper returns escaped HTML.
				echo '<td>' . RecruitmentAdminPage::classification_status_badge( (string) $row->status ) . '</td>';
			}
			if ( $with_actions ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_classification_actions returns escaped HTML.
				echo '<td>' . self::render_classification_actions( (int) $row->id, (string) $row->status ) . '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';

		self::render_classifications_pagination( $tab_key, $total, $current_page, $per_page );

		if ( $with_actions ) {
			self::render_classification_actions_script();
		}
		if ( $is_preview_tab ) {
			self::render_preview_status_script();
		}
	}

	/**
	 * Pagination strip below a classifications table.
	 *
	 * Outputs WordPress' `paginate_links()` scoped to the tab-specific
	 * `ffc_cls_paged_<tab>` URL parameter, plus a localized
	 * "X candidates" count. Links carry `ffc_cls_tab=<tab>` so reload
	 * lands the operator back on the same tab.
	 *
	 * @param string $tab_key      `preliminary` or `definitive`.
	 * @param int    $total        Total post-filter row count.
	 * @param int    $current_page Currently displayed page (1-indexed).
	 * @param int    $per_page     Page size.
	 * @return void
	 */
	private static function render_classifications_pagination( string $tab_key, int $total, int $current_page, int $per_page ): void {
		$total_pages = (int) ceil( $total / max( 1, $per_page ) );
		$page_param  = 'ffc_cls_paged_' . $tab_key;

		echo '<div class="tablenav bottom"><div class="tablenav-pages">';
		echo '<span class="displaying-num">';
		printf(
			/* translators: %s: number of candidate rows */
			esc_html( _n( '%s candidate', '%s candidates', $total, 'ffcertificate' ) ),
			esc_html( number_format_i18n( $total ) )
		);
		echo '</span>';

		if ( $total_pages > 1 ) {
			// `paginate_links` uses the current request URL as base by
			// default; we add `ffc_cls_tab` so the operator lands back on
			// the same tab after the navigation reload.
			$base  = add_query_arg( 'ffc_cls_tab', $tab_key );
			$base  = remove_query_arg( $page_param, $base );
			$links = paginate_links(
				array(
					'base'      => add_query_arg( $page_param, '%#%', $base ),
					'format'    => '',
					'prev_text' => '&laquo;',
					'next_text' => '&raquo;',
					'total'     => $total_pages,
					'current'   => $current_page,
				)
			);
			echo wp_kses_post( (string) $links );
		}

		echo '</div></div>';
	}

	/**
	 * Localized labels for the five preview-status enum values.
	 *
	 * @return array<string, string>
	 */
	private static function preview_status_label_map(): array {
		return array(
			'empty'          => __( 'Empty (no decision)', 'ffcertificate' ),
			'denied'         => __( 'Denied', 'ffcertificate' ),
			'granted'        => __( 'Granted', 'ffcertificate' ),
			'appeal_denied'  => __( 'Appeal denied', 'ffcertificate' ),
			'appeal_granted' => __( 'Appeal granted', 'ffcertificate' ),
		);
	}

	/**
	 * Render the preview-status <select> for one preliminary-list row.
	 *
	 * The change handler installed by {@see self::render_preview_status_script()}
	 * PATCHes the row via REST when the operator commits a value.
	 *
	 * @param int    $cls_id  Classification ID.
	 * @param string $current Current `preview_status` enum value.
	 * @return string Already-escaped HTML.
	 */
	private static function render_preview_status_select( int $cls_id, string $current ): string {
		$options = self::preview_status_label_map();
		$html    = '<select class="ffc-cls-preview-status" data-cls-id="' . esc_attr( (string) $cls_id ) . '">';
		foreach ( $options as $value => $label ) {
			$html .= '<option value="' . esc_attr( $value ) . '"' . selected( $value, $current, false ) . '>' . esc_html( $label ) . '</option>';
		}
		$html .= '</select>';
		return $html;
	}

	/**
	 * Render the per-row reason <select>.
	 *
	 * The dropdown's enabled state, option set, and selected value all
	 * depend on the chosen preview_status: when status is "empty" we
	 * disable the dropdown (no reason can apply); otherwise we list every
	 * reason whose `applies_to` covers the chosen status (or whose
	 * `applies_to` is empty = "applies to all"). The change handler
	 * re-PATCHes the row.
	 *
	 * @param int                $cls_id      Classification ID.
	 * @param string             $current     Current preview_status enum.
	 * @param int                $reason_id   Currently-selected reason id (0 = none).
	 * @param array<int, object> $reasons     Full reason catalog (passed in from the caller to avoid N+1 lookups).
	 * @phpstan-param list<ReasonRow> $reasons
	 * @return string Already-escaped HTML.
	 */
	private static function render_preview_reason_select( int $cls_id, string $current, int $reason_id, array $reasons ): string {
		$disabled = ( 'empty' === $current ) ? ' disabled' : '';
		$html     = '<select class="ffc-cls-preview-reason" data-cls-id="' . esc_attr( (string) $cls_id ) . '"' . $disabled . '>';
		$html    .= '<option value="0">' . esc_html__( '— none —', 'ffcertificate' ) . '</option>';
		foreach ( $reasons as $reason ) {
			$applies = RecruitmentReasonRepository::decode_applies_to( (string) ( $reason->applies_to ?? '' ) );
			// `data-applies` lets the JS re-filter the options without a
			// round-trip when the operator flips the status dropdown.
			$applies_attr = implode( ',', $applies );
			// On first render, hide options that don't cover the current
			// status. The JS will reveal them as needed when the status
			// changes.
			$visible = 'empty' === $current || in_array( $current, $applies, true );
			$style   = $visible ? '' : 'display:none;';
			$rid     = (int) $reason->id;
			$html   .= '<option value="' . esc_attr( (string) $rid ) . '"'
				. ' data-applies="' . esc_attr( $applies_attr ) . '"'
				. selected( $rid, $reason_id, false )
				. ( '' !== $style ? ' style="' . esc_attr( $style ) . '"' : '' )
				. '>'
				. esc_html( (string) $reason->label )
				. '</option>';
		}
		$html .= '</select>';
		return $html;
	}

	/**
	 * Render the bulk-call toolbar above the Definitive classifications
	 * table. Single date+time form that applies to every `empty`-status
	 * row checked in the table; submit hits POST /classifications/bulk-call
	 * which is atomic per §6.
	 *
	 * @return void
	 */
	private static function render_bulk_call_toolbar(): void {
		echo '<div class="ffc-cls-bulk-toolbar" style="background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;padding:10px 12px;margin-bottom:8px;">';
		echo '<strong>' . esc_html__( 'Bulk call', 'ffcertificate' ) . '</strong> ';
		echo '<span style="margin-left:.5em;">' . esc_html__( 'Date:', 'ffcertificate' ) . ' </span>';
		echo '<input type="date" id="ffc-bulk-date" style="margin-right:.5em;">';
		echo '<span>' . esc_html__( 'Time:', 'ffcertificate' ) . ' </span>';
		echo '<input type="time" id="ffc-bulk-time" style="margin-right:.5em;">';
		echo '<button type="button" class="button button-primary" onclick="ffcRecruitmentBulkCall();">' . esc_html__( 'Call selected', 'ffcertificate' ) . '</button>';
		echo '<span id="ffc-bulk-status" style="margin-left:1em;font-family:monospace;font-size:12px;"></span>';
		// §6 — bulk call is atomic; any single race-loss rolls back the entire batch.
		echo '<p class="description" style="margin:6px 0 0;">' . esc_html__( 'Select rows in waiting status to bulk-call them with the same date and time. The operation is atomic — any single conflict rolls back the entire batch. Date and time are remembered from the previous successful call.', 'ffcertificate' ) . '</p>';
		echo '</div>';

		// Pre-fill the date / time inputs from localStorage so a follow-
		// up bulk-call doesn't make the operator retype the same values
		// the next time they open this notice. The values are written
		// from ffcRecruitmentBulkCall() on a successful submit.
		echo '<script>'
			. '(function(){'
			. 'try{'
			. 'var d=localStorage.getItem("ffcRecruitmentLastBulkDate");'
			. 'var t=localStorage.getItem("ffcRecruitmentLastBulkTime");'
			. 'if(d){document.getElementById("ffc-bulk-date").value=d;}'
			. 'if(t){document.getElementById("ffc-bulk-time").value=t;}'
			. '}catch(e){}'
			. '})();'
			. '</script>';
	}

	/**
	 * Render the per-row action buttons appropriate for a classification's
	 * current status (the §5.2 state machine).
	 *
	 * Returns escaped HTML — already safe to echo.
	 *
	 * @param int    $id      Classification ID.
	 * @param string $current Current status.
	 * @return string
	 */
	private static function render_classification_actions( int $id, string $current ): string {
		$out = '';
		switch ( $current ) {
			case 'empty':
				$out .= self::cls_button( $id, 'call', __( 'Call', 'ffcertificate' ), 'primary' );
				break;
			case 'called':
				$out .= self::cls_button( $id, 'accepted', __( 'Mark accepted', 'ffcertificate' ), 'secondary' );
				$out .= self::cls_button( $id, 'hired', __( 'Mark hired', 'ffcertificate' ), 'secondary' );
				$out .= self::cls_button( $id, 'not_shown', __( 'Mark not_shown', 'ffcertificate' ), 'secondary' );
				$out .= self::cls_button( $id, 'withdrew', __( 'Mark withdrew', 'ffcertificate' ), 'secondary' );
				$out .= self::cls_button( $id, 'cancel', __( 'Cancel call', 'ffcertificate' ), 'link-delete' );
				break;
			case 'accepted':
				$out .= self::cls_button( $id, 'hired', __( 'Mark hired', 'ffcertificate' ), 'secondary' );
				$out .= self::cls_button( $id, 'not_shown', __( 'Mark not_shown', 'ffcertificate' ), 'secondary' );
				$out .= self::cls_button( $id, 'withdrew', __( 'Mark withdrew', 'ffcertificate' ), 'secondary' );
				$out .= self::cls_button( $id, 'cancel', __( 'Cancel call', 'ffcertificate' ), 'link-delete' );
				break;
			case 'not_shown':
				$out .= self::cls_button( $id, 'reopen', __( 'Reopen (if not frozen)', 'ffcertificate' ), 'secondary' );
				break;
			case 'hired':
			case 'withdrew':
				// Terminal — no actions.
				$out .= '<em>' . esc_html__( '(terminal)', 'ffcertificate' ) . '</em>';
				break;
		}
		return $out;
	}

	/**
	 * Render a single action button. `data-action` drives the inline
	 * fetch handler in render_classification_actions_script(); each
	 * action maps to either a /call POST (status='empty' → 'called')
	 * or a /status PATCH (every other transition).
	 *
	 * @param int    $id     Classification ID.
	 * @param string $action Action key.
	 * @param string $label  Button label (already i18n'd).
	 * @param string $variant 'primary' | 'secondary' | 'link-delete'.
	 * @return string
	 */
	private static function cls_button( int $id, string $action, string $label, string $variant ): string {
		$class = 'button button-small';
		if ( 'primary' === $variant ) {
			$class .= ' button-primary';
		} elseif ( 'link-delete' === $variant ) {
			$class .= ' button-link-delete';
		}
		return sprintf(
			'<button type="button" class="%s" style="margin-right:.25em;" data-cls-id="%d" data-cls-action="%s" onclick="ffcRecruitmentClsAct(this);">%s</button>',
			esc_attr( $class ),
			$id,
			esc_attr( $action ),
			esc_html( $label )
		);
	}

	/**
	 * Render the inline JS that drives the per-row action buttons.
	 * Rendered once after the Definitive table.
	 *
	 * @return void
	 */
	private static function render_classification_actions_script(): void {
		$nonce              = wp_create_nonce( 'wp_rest' );
		$call_url           = esc_url_raw( rest_url( 'ffcertificate/v1/recruitment/classifications/' ) );
		$bulk_url           = esc_url_raw( rest_url( 'ffcertificate/v1/recruitment/classifications/bulk-call' ) );
		$bulk_no_sel        = esc_js( __( 'Select at least one row first.', 'ffcertificate' ) );
		$bulk_no_date       = esc_js( __( 'Date and time are required for bulk call.', 'ffcertificate' ) );
		$confirm_ooo_single = esc_js( __( 'This call would skip a higher-ranked candidate (out of order). Continue?', 'ffcertificate' ) );
		$prompt_ooo_reason  = esc_js( __( 'Justification for calling out of order (required):', 'ffcertificate' ) );
		$reason_required    = esc_js( __( 'A justification is required to proceed.', 'ffcertificate' ) );
		// Bulk-call modal copy (issue #262 item 4 — confirms now go
		// through ffcRecruitmentAdmin.openConfirmModal instead of native
		// confirm()/prompt()). Body template uses sprintf-style {count},
		// {date}, {time} placeholders that the JS resolves at click time
		// since the modal config is built dynamically.
		$bulk_modal_title = esc_js( __( 'Issue calls for the selected candidates?', 'ffcertificate' ) );
		/* translators: 1: number of selected rows, 2: date, 3: time */
		$bulk_modal_body_tpl     = esc_js( __( 'About to issue {count} call(s) for {date} at {time}.', 'ffcertificate' ) );
		$bulk_modal_cta          = esc_js( __( 'Issue calls', 'ffcertificate' ) );
		$bulk_consequence_atomic = esc_js( __( 'Atomic — any single conflict rolls back the entire batch.', 'ffcertificate' ) );
		$bulk_consequence_log    = esc_js( __( 'A "bulk call" audit entry is recorded with the selected candidates.', 'ffcertificate' ) );
		$bulk_consequence_ooo    = esc_js( __( 'One or more selected rows would skip a higher-ranked candidate (out of order).', 'ffcertificate' ) );
		$bulk_reason_label       = esc_js( __( 'Justification for calling out of order (required)', 'ffcertificate' ) );

		echo '<script>'
			// Compute the lowest-rank `empty` row per adjutancy from the
			// rendered DOM. Used by both the single-row Call handler and
			// the bulk-call handler to detect skips before any prompt.
			//
			// Scoped to the Definitive panel: the Preliminary table also
			// renders `data-cls-*` rows and they are ALWAYS status="empty"
			// per the §5.2 invariant (preview rows can't be called); a
			// global scan would let those preview rows pin the lowest-rank
			// empty in their adjutancy and then every definitive call ≥ 2
			// would falsely trip the OOO prompt.
			. 'function ffcRecruitmentLowestEmpty(){'
			. 'var panel=document.querySelector(\'[data-ffc-clspanel="definitive"]\');'
			. 'if(!panel)return {};'
			. 'var rows=panel.querySelectorAll("tr[data-cls-id]");'
			. 'var lowest={};'
			. 'for(var i=0;i<rows.length;i++){'
			. 'var tr=rows[i];'
			. 'if(tr.getAttribute("data-cls-status")!=="empty")continue;'
			. 'var adj=tr.getAttribute("data-cls-adjutancy");'
			. 'var rank=parseInt(tr.getAttribute("data-cls-rank"),10);'
			. 'if(!lowest[adj]||rank<lowest[adj]){lowest[adj]=rank;}'
			. '}'
			. 'return lowest;'
			. '}'
			// Bulk-call helpers — toggle-all + submit handler.
			. 'function ffcRecruitmentClsToggleAll(cb){'
			. 'var boxes=document.querySelectorAll(".ffc-cls-bulk-cb:not([disabled])");'
			. 'for(var i=0;i<boxes.length;i++){boxes[i].checked=cb.checked;}'
			. '}'
			. 'function ffcRecruitmentBulkCall(){'
			. 'var status=document.getElementById("ffc-bulk-status");'
			. 'var date=document.getElementById("ffc-bulk-date").value;'
			. 'var time=document.getElementById("ffc-bulk-time").value;'
			. 'if(!date||!time){status.textContent="' . esc_attr( $bulk_no_date ) . '";return;}'
			. 'var ids=[];var boxes=document.querySelectorAll(".ffc-cls-bulk-cb:checked");'
			. 'for(var i=0;i<boxes.length;i++){ids.push(parseInt(boxes[i].value,10));}'
			. 'if(ids.length===0){status.textContent="' . esc_attr( $bulk_no_sel ) . '";return;}'
			// Out-of-order detection: per adjutancy, find the lowest-rank
			// empty row that's NOT in this bulk selection (the
			// "threshold"). Any selected row in that adjutancy with
			// rank > threshold means the bulk would skip someone.
			//
			// Naïvely comparing against the global lowest-empty rank
			// (the previous logic) tripped on every legitimate
			// in-order bulk: selecting ranks 1+2+3 from empties [1,2,3]
			// would flag rank 2 and rank 3 as OOO because rank 1 is
			// still empty at scan time, even though rank 1 is also in
			// the same selection and gets called atomically alongside.
			. 'var panel=document.querySelector(\'[data-ffc-clspanel="definitive"]\');'
			. 'var emptyByAdj={};'
			. 'if(panel){'
			. 'var allRows=panel.querySelectorAll("tr[data-cls-id]");'
			. 'for(var p=0;p<allRows.length;p++){'
			. 'var ptr=allRows[p];'
			. 'if(ptr.getAttribute("data-cls-status")!=="empty")continue;'
			. 'var padj=ptr.getAttribute("data-cls-adjutancy");'
			. 'if(!emptyByAdj[padj])emptyByAdj[padj]=[];'
			. 'emptyByAdj[padj].push({id:parseInt(ptr.getAttribute("data-cls-id"),10),rank:parseInt(ptr.getAttribute("data-cls-rank"),10)});'
			. '}'
			. '}'
			. 'for(var k in emptyByAdj){emptyByAdj[k].sort(function(a,b){return a.rank-b.rank;});}'
			. 'var selSet={};'
			. 'for(var s=0;s<ids.length;s++){selSet[String(ids[s])]=true;}'
			. 'var anyOoO=false;'
			. 'for(var adjKey in emptyByAdj){'
			. 'var threshold=Infinity;'
			. 'var rows=emptyByAdj[adjKey];'
			. 'for(var t=0;t<rows.length;t++){'
			. 'if(!selSet[String(rows[t].id)]){threshold=rows[t].rank;break;}'
			. '}'
			. 'for(var u=0;u<rows.length;u++){'
			. 'if(selSet[String(rows[u].id)]&&rows[u].rank>threshold){anyOoO=true;break;}'
			. '}'
			. 'if(anyOoO)break;'
			. '}'
			// Build modal config dynamically — copy/style/reason-gate depend
			// on whether OOO was detected. Confirmation always goes through
			// the shared confirm-modal (issue #262 item 4) so the operator
			// gets the same look-and-feel as the destructive transitions.
			. 'var consequences=["' . esc_attr( $bulk_consequence_atomic ) . '","' . esc_attr( $bulk_consequence_log ) . '"];'
			. 'if(anyOoO){consequences.push("' . esc_attr( $bulk_consequence_ooo ) . '");}'
			. 'var bodyTpl="' . esc_attr( $bulk_modal_body_tpl ) . '";'
			. 'var bodyText=bodyTpl.replace("{count}",String(ids.length)).replace("{date}",date).replace("{time}",time);'
			. 'var modalCfg={'
			. 'title:"' . esc_attr( $bulk_modal_title ) . '",'
			. 'body:bodyText,'
			. 'consequences:consequences,'
			. 'cta:"' . esc_attr( $bulk_modal_cta ) . '",'
			. 'style:anyOoO?"destructive":"primary",'
			. 'reasonLabel:anyOoO?"' . esc_attr( $bulk_reason_label ) . '":""'
			. '};'
			. 'window.ffcRecruitmentAdmin.openConfirmModal(modalCfg,function(sharedReason){'
			. 'var reasons={};'
			. 'if(anyOoO){for(var k=0;k<ids.length;k++){reasons[String(ids[k])]=sharedReason;}}'
			. 'status.textContent="…";'
			. 'var bulkPayload={classification_ids:ids,date_to_assume:date,time_to_assume:time};'
			. 'if(anyOoO){bulkPayload.out_of_order_reasons=reasons;}'
			. 'fetch("' . esc_js( $bulk_url ) . '",{'
			. 'method:"POST",'
			. 'headers:{"X-WP-Nonce":"' . esc_js( $nonce ) . '","Content-Type":"application/json"},'
			. 'body:JSON.stringify(bulkPayload),'
			. 'credentials:"same-origin"'
			. '}).then(function(r){return r.json().then(function(d){return{status:r.status,body:d};});}).then(function(o){'
			. 'if(o.status>=200&&o.status<300){'
			// Persist the just-used values so the next bulk call (on
			// this notice or any other) opens with the same defaults.
			// Most operators issue calls in batches with identical
			// date/time; remembering saves a few keystrokes per round.
			. 'try{localStorage.setItem("ffcRecruitmentLastBulkDate",date);localStorage.setItem("ffcRecruitmentLastBulkTime",time);}catch(e){}'
			. 'location.reload();'
			. '}'
			. 'else{status.textContent="Error: "+((o.body&&o.body.message)?o.body.message:JSON.stringify(o.body));}'
			. '}).catch(function(e){status.textContent="Network error: "+e.message;});'
			. '});' // close openConfirmModal callback.
			. '}'
			// Per-row action handler (Call / Mark accepted / etc.).
			. 'function ffcRecruitmentClsAct(btn){'
			. 'var id=btn.getAttribute("data-cls-id");'
			. 'var action=btn.getAttribute("data-cls-action");'
			. 'var nonce="' . esc_js( $nonce ) . '";'
			. 'var base="' . esc_js( $call_url ) . '";'
			. 'var url,init;'
			. 'if(action==="call"){'
			// Out-of-order is detected BEFORE asking date/time so the
			// admin sees the warning/justification step at the top of
			// the flow rather than after committing to a schedule.
			. 'var oooReason="";'
			. 'var tr=document.querySelector(\'tr[data-cls-id="\'+id+\'"]\');'
			. 'if(tr){'
			. 'var rank=parseInt(tr.getAttribute("data-cls-rank"),10);'
			. 'var adj=tr.getAttribute("data-cls-adjutancy");'
			. 'var lowest=ffcRecruitmentLowestEmpty();'
			. 'if(lowest[adj]&&rank>lowest[adj]){'
			. 'if(!confirm("' . esc_attr( $confirm_ooo_single ) . '"))return;'
			. 'oooReason=prompt("' . esc_attr( $prompt_ooo_reason ) . '")||"";'
			. 'if(!oooReason.trim()){alert("' . esc_attr( $reason_required ) . '");return;}'
			. '}'
			. '}'
			. 'var date=prompt("' . esc_js( __( 'Date to assume (YYYY-MM-DD):', 'ffcertificate' ) ) . '");'
			. 'if(!date)return;'
			. 'var time=prompt("' . esc_js( __( 'Time to assume (HH:MM):', 'ffcertificate' ) ) . '");'
			. 'if(!time)return;'
			. 'var fd=new FormData();fd.append("date_to_assume",date);fd.append("time_to_assume",time);'
			. 'if(oooReason)fd.append("out_of_order_reason",oooReason);'
			. 'url=base+id+"/call";init={method:"POST",headers:{"X-WP-Nonce":nonce},body:fd,credentials:"same-origin"};'
			. '}else if(action==="cancel"){'
			. 'var reason=prompt("' . esc_js( __( 'Cancellation reason (required):', 'ffcertificate' ) ) . '");'
			. 'if(!reason)return;'
			. 'var p=new URLSearchParams();p.append("status","empty");p.append("reason",reason);'
			. 'url=base+id+"/status";init={method:"PUT",headers:{"X-WP-Nonce":nonce,"Content-Type":"application/x-www-form-urlencoded"},body:p.toString(),credentials:"same-origin"};'
			. '}else if(action==="reopen"){'
			. 'var reason2=prompt("' . esc_js( __( 'Reopen reason (required):', 'ffcertificate' ) ) . '");'
			. 'if(!reason2)return;'
			. 'var p2=new URLSearchParams();p2.append("status","empty");p2.append("reason",reason2);'
			. 'url=base+id+"/status";init={method:"PUT",headers:{"X-WP-Nonce":nonce,"Content-Type":"application/x-www-form-urlencoded"},body:p2.toString(),credentials:"same-origin"};'
			. '}else{'
			. 'var p3=new URLSearchParams();p3.append("status",action);'
			. 'url=base+id+"/status";init={method:"PUT",headers:{"X-WP-Nonce":nonce,"Content-Type":"application/x-www-form-urlencoded"},body:p3.toString(),credentials:"same-origin"};'
			. '}'
			. 'btn.disabled=true;'
			. 'fetch(url,init).then(function(r){return r.json().then(function(d){return{status:r.status,body:d};});}).then(function(o){'
			. 'if(o.status>=200&&o.status<300){location.reload();}'
			. 'else{alert((o.body&&o.body.message)?o.body.message:JSON.stringify(o.body));btn.disabled=false;}'
			. '}).catch(function(e){alert("Network error: "+e.message);btn.disabled=false;});'
			. '}'
			. '</script>';
	}

	/**
	 * Inline JS that drives the per-row preview_status + reason
	 * dropdowns on the Preliminary tab. Listens at `change` so the
	 * REST PATCH fires once per commit. The reason dropdown is
	 * filtered + cleared when the status flips so operators can't
	 * pick a reason that doesn't apply to the chosen status.
	 *
	 * @return void
	 */
	private static function render_preview_status_script(): void {
		$nonce    = wp_create_nonce( 'wp_rest' );
		$base_url = esc_url_raw( rest_url( 'ffcertificate/v1/recruitment/classifications/' ) );
		$settings = RecruitmentSettings::all();
		// Per-status reason-required flags surfaced to the JS so the
		// dropdown UX can preflight the requirement client-side
		// instead of round-tripping the rejection from the server.
		$required_map = array(
			'denied'         => ! empty( $settings['preview_reason_required_denied'] ),
			'granted'        => ! empty( $settings['preview_reason_required_granted'] ),
			'appeal_denied'  => ! empty( $settings['preview_reason_required_appeal_denied'] ),
			'appeal_granted' => ! empty( $settings['preview_reason_required_appeal_granted'] ),
		);

		echo '<script>'
			. '(function(){'
			. 'var ffcReasonRequired=' . wp_json_encode( $required_map ) . ';'
			. 'function ffcRecruitmentPreviewMarkRequired(reasonSel,required){'
			. 'reasonSel.style.outline=required?"2px solid #d63638":"";'
			. 'reasonSel.style.outlineOffset=required?"2px":"";'
			. 'reasonSel.setAttribute("aria-required",required?"true":"false");'
			. '}'
			. 'function ffcRecruitmentPreviewSync(row){'
			. 'var id=row.getAttribute("data-cls-id");'
			. 'var statusSel=row.querySelector(".ffc-cls-preview-status");'
			. 'var reasonSel=row.querySelector(".ffc-cls-preview-reason");'
			. 'var status=statusSel.value;'
			. 'var reasonId=parseInt(reasonSel.value,10)||0;'
			// Preflight: if the chosen status requires a reason and the
			// dropdown is at "— none —", flag the dropdown red and skip
			// the PATCH. The server-side check still runs as a backstop;
			// this just spares the round-trip + alert() for the common
			// case where the operator just hasn\'t picked yet.
			. 'if(ffcReasonRequired[status]===true&&reasonId<=0){'
			. 'ffcRecruitmentPreviewMarkRequired(reasonSel,true);'
			. 'return;'
			. '}'
			. 'ffcRecruitmentPreviewMarkRequired(reasonSel,false);'
			. 'var fd=new FormData();fd.append("preview_status",status);'
			. 'if(reasonId>0){fd.append("preview_reason_id",String(reasonId));}'
			. 'fetch(' . wp_json_encode( $base_url ) . '+id+"/preview-status",{'
			. 'method:"POST",'
			. 'headers:{"X-WP-Nonce":"' . esc_js( $nonce ) . '","X-HTTP-Method-Override":"PATCH"},'
			. 'body:fd,'
			. 'credentials:"same-origin"'
			. '}).then(function(r){return r.json().then(function(d){return{status:r.status,body:d};});}).then(function(o){'
			. 'if(o.status>=200&&o.status<300){return;}'
			. 'alert((o.body&&o.body.message)?o.body.message:JSON.stringify(o.body));'
			. '});'
			. '}'
			. 'document.querySelectorAll("tr[data-cls-id]").forEach(function(row){'
			. 'var statusSel=row.querySelector(".ffc-cls-preview-status");'
			. 'var reasonSel=row.querySelector(".ffc-cls-preview-reason");'
			. 'if(!statusSel||!reasonSel)return;'
			. 'statusSel.addEventListener("change",function(){'
			. 'var status=statusSel.value;'
			. 'var opts=reasonSel.querySelectorAll("option[data-applies]");'
			. 'opts.forEach(function(opt){'
			. 'var applies=(opt.getAttribute("data-applies")||"").split(",");'
			. 'var allowed=applies.length===0||applies[0]===""||applies.indexOf(status)!==-1;'
			. 'opt.style.display=allowed?"":"none";'
			. '});'
			. 'if(status==="empty"){reasonSel.value="0";reasonSel.disabled=true;ffcRecruitmentPreviewMarkRequired(reasonSel,false);}'
			. 'else{reasonSel.disabled=false;'
			// If the previously-selected reason is no longer allowed for
			// the new status, reset to "none" so the server doesn't reject
			// the PATCH with a status_mismatch.
			. 'var current=reasonSel.options[reasonSel.selectedIndex];'
			. 'if(current&&current.style.display==="none"){reasonSel.value="0";}'
			. '}'
			. 'ffcRecruitmentPreviewSync(row);'
			. '});'
			. 'reasonSel.addEventListener("change",function(){ffcRecruitmentPreviewSync(row);});'
			. '});'
			. '})();'
			. '</script>';
	}

	/**
	 * Build a {id → field} map by calling a repository getter for each id.
	 *
	 * @param array<int, int> $ids       Unique entity ids.
	 * @param callable        $getter    Repository static method `(int) => ?object`.
	 * @param string          $field     Field name to read off the resolved object.
	 * @return array<int, string>
	 */
	private static function lookup_map( array $ids, callable $getter, string $field ): array {
		$out = array();
		foreach ( $ids as $id ) {
			$row = $getter( $id );
			if ( null !== $row && isset( $row->$field ) ) {
				$out[ $id ] = (string) $row->$field;
			}
		}
		return $out;
	}

	/**
	 * Allowed transitions from `$current` per §5.1.
	 *
	 * The state machine still enforces the runtime guards (zero-calls,
	 * reopen-freeze, etc.); this map is for UI affordance only.
	 *
	 * @param string $current Current notice status.
	 * @return array<string, string> map of target_status => label.
	 */
	private static function transitions_from( string $current ): array {
		switch ( $current ) {
			case 'draft':
				return array( 'preliminary' => __( 'Move to preliminary', 'ffcertificate' ) );
			case 'preliminary':
				return array(
					'definitive' => __( 'Promote to definitive', 'ffcertificate' ),
					'draft'      => __( 'Back to draft', 'ffcertificate' ),
				);
			case 'definitive':
				return array(
					'preliminary' => __( 'Back to preliminary (zero-calls only)', 'ffcertificate' ),
					'closed'      => __( 'Close', 'ffcertificate' ),
				);
			case 'closed':
				return array(
					'definitive' => __( 'Reopen (closed → definitive)', 'ffcertificate' ),
				);
			default:
				return array();
		}
	}
}
