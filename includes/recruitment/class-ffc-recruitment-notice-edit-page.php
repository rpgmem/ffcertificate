<?php
/**
 * Recruitment Notice Edit Page.
 *
 * Dedicated wp-admin screen reached via the "Edit" row action on the
 * Notices list table:
 *
 *   admin.php?page=ffc-recruitment&tab=notices&action=edit-notice&notice_id=N
 *
 * Renders four metabox-style sections inspired by the certificate form
 * editor pattern (Admin\FormEditor) but adapted to the recruitment
 * domain's custom-table backing:
 *
 *   1. General        — code (read-only after creation per §3.2 stable
 *                       identifier rule) + name + public_columns_config.
 *   2. Status         — current state badge + transition buttons that
 *                       hit NoticeStateMachine::transition_to (driver
 *                       for all draft↔preliminary↔definitive↔closed moves).
 *   3. Adjutancies    — attach/detach UI relocated from the row inline
 *                       (sprint A1) and wired to the existing REST
 *                       routes via the assets manager's fetch helper.
 *   4. Classifications — preview + definitive lists side by side; pure
 *                       reader for now (per-classification status edit
 *                       lands when the bulk-call modal does).
 *
 * Save flow runs through the admin-post handler at
 * `admin.php?action=ffc_recruitment_save_notice`, gated by a per-notice
 * nonce. The state-machine guards reject illegal transitions at the
 * service layer regardless of which UI surface invokes them.
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
 * Notice edit screen renderer + admin-post save handler.
 *
 * @phpstan-import-type NoticeRow         from RecruitmentNoticeRepository
 * @phpstan-import-type ClassificationRow from RecruitmentClassificationRepository
 */
final class RecruitmentNoticeEditPage {

	/**
	 * Cap gating render + save.
	 */
	private const CAP = 'ffc_manage_recruitment';

	/**
	 * Hook the admin-post save endpoint. Called from RecruitmentLoader.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_post_ffc_recruitment_save_notice', array( self::class, 'handle_save' ), 10 );
		add_action( 'admin_post_ffc_recruitment_transition_notice', array( self::class, 'handle_transition' ), 10 );
	}

	/**
	 * Render the edit screen body. Called by RecruitmentAdminPage when
	 * `?action=edit-notice` is detected on the page.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Access denied.', 'ffcertificate' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only render; mutating actions live on the dedicated handlers.
		$notice_id = isset( $_GET['notice_id'] ) ? absint( wp_unslash( (string) $_GET['notice_id'] ) ) : 0;
		$notice    = $notice_id > 0 ? RecruitmentNoticeRepository::get_by_id( $notice_id ) : null;
		if ( null === $notice ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Notice not found.', 'ffcertificate' ) . '</p></div>';
			echo '<p><a href="' . esc_url( self::back_url() ) . '">&larr; ' . esc_html__( 'Back to Notices', 'ffcertificate' ) . '</a></p>';
			return;
		}

		echo '<p><a href="' . esc_url( self::back_url() ) . '">&larr; ' . esc_html__( 'Back to Notices', 'ffcertificate' ) . '</a></p>';
		echo '<h2>' . sprintf(
			/* translators: %s — notice code */
			esc_html__( 'Edit notice — %s', 'ffcertificate' ),
			'<code>' . esc_html( (string) $notice->code ) . '</code>'
		) . '</h2>';

		self::render_general_section( $notice );
		self::render_status_section( $notice );
		self::render_adjutancies_section( $notice );
		self::render_csv_import_section( $notice );
		self::render_classifications_section( $notice );
	}

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
	private static function render_csv_import_section( object $notice ): void {
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

		echo '<p>' . esc_html__( 'UTF-8 CSV (BOM optional). Headers (English): name, cpf, rf, email, phone, adjutancy, rank, score, pcd. At least one of cpf/rf required per row. Comma or semicolon delimiter is auto-detected.', 'ffcertificate' ) . '</p>';

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
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Import', 'ffcertificate' ) . '</button> ';
		echo '<span id="ffc-edit-csv-status" style="margin-left:1em;font-family:monospace;font-size:12px;"></span></p>';
		echo '</form>';

		// Inline fetch handler — selects between /import (preview) and
		// /promote-preview (definitive_import + transitions to definitive)
		// based on the radio choice.
		echo '<script>'
			. 'function ffcRecruitmentImportFromEdit(form){'
			. 'var nid=' . (int) $notice_id . ';'
			. 'var target=form.list_target.value;'
			. 'var fd=new FormData();'
			. 'fd.append("csv_file",form.csv_file.files[0]);'
			. 'var url;'
			. 'if(target==="definitive"){'
			. 'url="' . esc_url_raw( rest_url( 'ffcertificate/v1/recruitment/notices/' ) ) . '"+nid+"/promote-preview";'
			. 'fd.append("mode","definitive_import");'
			. '}else{'
			. 'url="' . esc_url_raw( rest_url( 'ffcertificate/v1/recruitment/notices/' ) ) . '"+nid+"/import";'
			. '}'
			. 'var status=document.getElementById("ffc-edit-csv-status");'
			. 'status.textContent="…";'
			. 'fetch(url,{method:"POST",headers:{"X-WP-Nonce":"' . esc_attr( $nonce ) . '"},body:fd,credentials:"same-origin"})'
			. '.then(function(r){return r.json().then(function(d){return{status:r.status,body:d};});}).then(function(o){'
			. 'if(o.status>=200&&o.status<300){status.textContent="OK ("+JSON.stringify(o.body)+")";location.reload();}'
			. 'else{status.textContent="Error: "+JSON.stringify(o.body);}'
			. '}).catch(function(e){status.textContent="Network error: "+e.message;});'
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
	private static function render_general_section( object $notice ): void {
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
		echo '<span class="description">' . esc_html__( '(read-only — codes are stable identifiers per §3.2 of the plan)', 'ffcertificate' ) . '</span></td></tr>';

		echo '<tr><th><label for="ffc-notice-name">' . esc_html__( 'Name', 'ffcertificate' ) . '</label></th>';
		echo '<td><input id="ffc-notice-name" type="text" class="regular-text" name="name" value="' . esc_attr( (string) $notice->name ) . '" required></td></tr>';

		echo '<tr><th>' . esc_html__( 'Public columns', 'ffcertificate' ) . '</th>';
		echo '<td>' . self::render_columns_toggles( (string) $notice->public_columns_config );
		echo '<p class="description">' . esc_html__( 'Toggle which columns the public shortcode renders. Rank and Name are mandatory and cannot be turned off.', 'ffcertificate' ) . '</p></td></tr>';

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

		$html = '<div class="ffc-recruitment-columns-toggles" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:6px 16px;">';
		foreach ( $labels as $key => $label ) {
			$is_mandatory = in_array( $key, $mandatory, true );
			$checked      = $is_mandatory || ! empty( $state[ $key ] );
			$id_attr      = 'ffc-notice-pcc-' . $key;
			$html        .= '<label for="' . esc_attr( $id_attr ) . '" style="display:flex;align-items:center;gap:6px;">';
			if ( $is_mandatory ) {
				// Disabled checkboxes don't post; a hidden sibling pins
				// the value=1 so the save handler always sees the
				// mandatory column as on.
				$html .= '<input type="hidden" name="public_columns[' . esc_attr( $key ) . ']" value="1">';
				$html .= '<input id="' . esc_attr( $id_attr ) . '" type="checkbox" checked disabled aria-disabled="true">';
			} else {
				$html .= '<input id="' . esc_attr( $id_attr ) . '" type="checkbox" name="public_columns[' . esc_attr( $key ) . ']" value="1"' . ( $checked ? ' checked' : '' ) . '>';
			}
			$html .= esc_html( $label );
			if ( $is_mandatory ) {
				$html .= ' <em style="color:#646970;font-size:11px;">(' . esc_html__( 'mandatory', 'ffcertificate' ) . ')</em>';
			}
			$html .= '</label>';
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
	private static function columns_label_map(): array {
		return array(
			'rank'           => __( 'Rank', 'ffcertificate' ),
			'name'           => __( 'Name', 'ffcertificate' ),
			'adjutancy'      => __( 'Adjutancy', 'ffcertificate' ),
			'status'         => __( 'Status', 'ffcertificate' ),
			'pcd_badge'      => __( 'PCD badge', 'ffcertificate' ),
			'date_to_assume' => __( 'Date to assume', 'ffcertificate' ),
			'time_to_assume' => __( 'Time to assume', 'ffcertificate' ),
			'score'          => __( 'Score', 'ffcertificate' ),
			'cpf_masked'     => __( 'CPF (masked)', 'ffcertificate' ),
			'rf_masked'      => __( 'RF (masked)', 'ffcertificate' ),
			'email_masked'   => __( 'Email (masked)', 'ffcertificate' ),
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
	private static function render_status_section( object $notice ): void {
		$current      = (string) $notice->status;
		$nonce_action = 'ffc_recruitment_transition_notice_' . (int) $notice->id;

		echo '<div class="postbox" style="margin-top:20px;">';
		echo '<h2 class="hndle"><span>' . esc_html__( 'Status', 'ffcertificate' ) . '</span></h2>';
		echo '<div class="inside">';

		echo '<p><strong>' . esc_html__( 'Current state:', 'ffcertificate' ) . '</strong> ';
		echo '<span class="ffc-status-badge ffc-status-' . esc_attr( $current ) . '">' . esc_html( $current ) . '</span>';
		if ( '1' === (string) $notice->was_reopened ) {
			echo ' <em>(' . esc_html__( 'previously reopened — hired/not_shown classifications are frozen', 'ffcertificate' ) . ')</em>';
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
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline;" onsubmit="return ffcRecruitmentConfirmTransition(this);">';
			echo '<input type="hidden" name="action" value="ffc_recruitment_transition_notice">';
			echo '<input type="hidden" name="notice_id" value="' . esc_attr( (string) $notice->id ) . '">';
			wp_nonce_field( $nonce_action );

			echo '<p>';
			foreach ( $transitions as $target => $label ) {
				echo '<button type="submit" name="target_status" value="' . esc_attr( $target ) . '" class="button button-secondary" style="margin-right:.5em;">';
				echo esc_html( $label );
				echo '</button>';
			}
			echo '</p>';

			// Closed → definitive needs a reason; reuse the same form with
			// a single reason input that's only meaningful for that move.
			if ( 'closed' === $current ) {
				echo '<p><label for="ffc-reopen-reason">' . esc_html__( 'Reopen reason (required for closed → definitive):', 'ffcertificate' ) . '</label><br>';
				echo '<input id="ffc-reopen-reason" type="text" class="large-text" name="reason"></p>';
			}

			echo '</form>';

			// Per-target confirm prompts. draft → preliminary publishes
			// the candidate list to the public shortcode, so we surface
			// that side-effect explicitly. Closing a notice is also
			// destructive for the operator (calls history goes read-only,
			// no more status changes), so we surface that too.
			echo '<script>'
				. 'function ffcRecruitmentConfirmTransition(form){'
				. 'var t=form.target_status?form.target_status.value:(document.activeElement&&document.activeElement.name==="target_status"?document.activeElement.value:"");'
				. 'if(t==="preliminary"){'
				. 'return confirm("' . esc_js( __( 'Moving the notice to `preliminary` publishes the imported candidate list on the public shortcode. Continue?', 'ffcertificate' ) ) . '");'
				. '}'
				. 'if(t==="closed"){'
				. 'return confirm("' . esc_js( __( 'Closing the notice freezes its calls history (no new calls, no status changes, no cancellations). The public shortcode will display the "Notice closed." banner above the list. To reopen later, you will need to provide a reason and the hired/not_shown classifications will become permanently locked. Continue?', 'ffcertificate' ) ) . '");'
				. '}'
				. 'return true;}'
				. '</script>';
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

			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
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
		// status flip atomically).
		$rest_nonce = wp_create_nonce( 'wp_rest' );
		echo '<p>';
		echo '<button type="button" class="button button-primary" onclick="ffcRecruitmentSnapshotPromote(' . (int) $id . ');">' . esc_html__( 'A — Publish preliminary as definitive (snapshot, no changes)', 'ffcertificate' ) . '</button> ';
		echo '<button type="button" class="button button-secondary" onclick="document.getElementById(\'ffc-recruitment-edit-import\').scrollIntoView({behavior:\'smooth\'});">' . esc_html__( 'B — Import a new list as definitive', 'ffcertificate' ) . '</button>';
		echo '</p>';

		echo '<script>'
			. 'function ffcRecruitmentSnapshotPromote(nid){'
			. 'if(!confirm("' . esc_js( __( 'Snapshot the preliminary list as the definitive list and transition the notice to `definitive`. Continue?', 'ffcertificate' ) ) . '")){return false;}'
			. 'var fd=new FormData();fd.append("mode","snapshot");'
			. 'fetch("' . esc_url_raw( rest_url( 'ffcertificate/v1/recruitment/notices/' ) ) . '"+nid+"/promote-preview",{'
			. 'method:"POST",headers:{"X-WP-Nonce":"' . esc_attr( $rest_nonce ) . '"},body:fd,credentials:"same-origin"'
			. '}).then(function(r){return r.json().then(function(d){return{status:r.status,body:d};});}).then(function(o){'
			. 'if(o.status>=200&&o.status<300){location.reload();}else{alert(JSON.stringify(o.body));}'
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
	private static function render_adjutancies_section( object $notice ): void {
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
			. 'if(o.status>=200&&o.status<300){location.reload();}else{alert(JSON.stringify(o.body));}'
			. '});return false;}'
			. 'function ffcDetachAdjutancy(a){'
			. 'var nid=a.getAttribute("data-notice");'
			. 'var aid=a.getAttribute("data-adjutancy");'
			. 'fetch("' . esc_url_raw( rest_url( 'ffcertificate/v1/recruitment/notices/' ) ) . '"+nid+"/adjutancies/"+aid,{'
			. 'method:"DELETE",headers:{"X-WP-Nonce":"' . esc_attr( $nonce ) . '"},credentials:"same-origin"'
			. '}).then(function(r){return r.json().then(function(d){return{status:r.status,body:d};});}).then(function(o){'
			. 'if(o.status>=200&&o.status<300){location.reload();}else{alert(JSON.stringify(o.body));}'
			. '});return false;}'
			. '</script>';

		echo '</div></div>';
	}

	/**
	 * Section 4: Classifications — preliminary + definitive tabs.
	 *
	 * The two list_type stores get separate sub-tabs (one per list).
	 * The Definitive tab additionally exposes per-row action buttons
	 * (call / mark accepted / mark not_shown / mark hired / cancel /
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
	private static function render_classifications_section( object $notice ): void {
		$notice_id       = (int) $notice->id;
		$preview         = RecruitmentClassificationRepository::get_for_notice( $notice_id, 'preview' );
		$definitive_rows = RecruitmentClassificationRepository::get_for_notice( $notice_id, 'definitive' );

		echo '<div class="postbox" style="margin-top:20px;">';
		echo '<h2 class="hndle"><span>' . esc_html__( 'Classifications', 'ffcertificate' ) . '</span></h2>';
		echo '<div class="inside">';

		// Tabs.
		echo '<h2 class="nav-tab-wrapper" style="margin:0 0 1em;">';
		echo '<a href="#" class="nav-tab nav-tab-active" data-ffc-clstab="preliminary" onclick="return ffcRecruitmentClsTabSwitch(this);">' . esc_html__( 'Preliminary', 'ffcertificate' ) . '</a>';
		echo '<a href="#" class="nav-tab" data-ffc-clstab="definitive" onclick="return ffcRecruitmentClsTabSwitch(this);">' . esc_html__( 'Definitive', 'ffcertificate' ) . '</a>';
		echo '</h2>';

		echo '<div data-ffc-clspanel="preliminary" style="display:block;">';
		self::render_classifications_table( $preview, false );
		echo '</div>';

		echo '<div data-ffc-clspanel="definitive" style="display:none;">';
		self::render_classifications_table( $definitive_rows, true );
		echo '</div>';

		// Tab toggle handler. Switches the .nav-tab-active class and
		// shows the matching panel.
		echo '<script>'
			. 'function ffcRecruitmentClsTabSwitch(a){'
			. 'var key=a.getAttribute("data-ffc-clstab");'
			. 'var nav=a.parentNode;'
			. 'var tabs=nav.querySelectorAll(".nav-tab");'
			. 'for(var i=0;i<tabs.length;i++){tabs[i].classList.remove("nav-tab-active");}'
			. 'a.classList.add("nav-tab-active");'
			. 'var panels=nav.parentNode.querySelectorAll("[data-ffc-clspanel]");'
			. 'for(var j=0;j<panels.length;j++){panels[j].style.display=panels[j].getAttribute("data-ffc-clspanel")===key?"block":"none";}'
			. 'return false;}'
			. '</script>';

		echo '</div></div>';
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
	 * @param array<int, object> $rows         Classification rows.
	 * @phpstan-param list<ClassificationRow> $rows
	 * @param bool               $with_actions Whether to render the action column.
	 * @return void
	 */
	private static function render_classifications_table( array $rows, bool $with_actions ): void {
		if ( empty( $rows ) ) {
			echo '<p><em>' . esc_html__( '(no rows)', 'ffcertificate' ) . '</em></p>';
			return;
		}

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

		echo '<table class="widefat striped"><thead><tr>';
		if ( $with_actions ) {
			echo '<th style="width:1%;"><input type="checkbox" id="ffc-cls-bulk-all" onclick="ffcRecruitmentClsToggleAll(this);" title="' . esc_attr__( 'Select all `empty` rows on this page', 'ffcertificate' ) . '"></th>';
		}
		echo '<th>' . esc_html__( 'Rank', 'ffcertificate' ) . '</th>';
		echo '<th>' . esc_html__( 'Candidate', 'ffcertificate' ) . '</th>';
		echo '<th>' . esc_html__( 'Adjutancy', 'ffcertificate' ) . '</th>';
		echo '<th>' . esc_html__( 'Score', 'ffcertificate' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'ffcertificate' ) . '</th>';
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
			echo '<td><code>' . esc_html( $adjutancy_slug ) . '</code></td>';
			echo '<td>' . esc_html( (string) $row->score ) . '</td>';
			echo '<td><span class="ffc-status-badge ffc-status-' . esc_attr( (string) $row->status ) . '">' . esc_html( (string) $row->status ) . '</span></td>';
			if ( $with_actions ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_classification_actions returns escaped HTML.
				echo '<td>' . self::render_classification_actions( (int) $row->id, (string) $row->status ) . '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';

		if ( $with_actions ) {
			self::render_classification_actions_script();
		}
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
		echo '<p class="description" style="margin:6px 0 0;">' . esc_html__( 'Select rows in `empty` status to bulk-call them with the same date and time. Atomic per §6 — any single race-loss rolls back the entire batch.', 'ffcertificate' ) . '</p>';
		echo '</div>';
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
				$out .= self::cls_button( $id, 'cancel', __( 'Cancel call', 'ffcertificate' ), 'link-delete' );
				break;
			case 'accepted':
				$out .= self::cls_button( $id, 'hired', __( 'Mark hired', 'ffcertificate' ), 'secondary' );
				$out .= self::cls_button( $id, 'not_shown', __( 'Mark not_shown', 'ffcertificate' ), 'secondary' );
				$out .= self::cls_button( $id, 'cancel', __( 'Cancel call', 'ffcertificate' ), 'link-delete' );
				break;
			case 'not_shown':
				$out .= self::cls_button( $id, 'reopen', __( 'Reopen (if not frozen)', 'ffcertificate' ), 'secondary' );
				break;
			case 'hired':
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
		$confirm_ooo_bulk   = esc_js( __( 'One or more selected rows would skip a higher-ranked candidate (out of order). Continue?', 'ffcertificate' ) );
		$reason_required    = esc_js( __( 'A justification is required to proceed.', 'ffcertificate' ) );

		echo '<script>'
			// Compute the lowest-rank `empty` row per adjutancy from the
			// rendered DOM. Used by both the single-row Call handler and
			// the bulk-call handler to detect skips before any prompt.
			. 'function ffcRecruitmentLowestEmpty(){'
			. 'var rows=document.querySelectorAll("tr[data-cls-id]");'
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
			// Out-of-order detection: any selected id whose rank > the
			// lowest-empty-rank in the same adjutancy means the bulk
			// would skip someone. Build the reasons map upfront if so —
			// the user supplies one shared justification that we apply
			// to every selected id (server ignores it for in-order ids).
			. 'var lowest=ffcRecruitmentLowestEmpty();'
			. 'var anyOoO=false;'
			. 'for(var j=0;j<ids.length;j++){'
			. 'var tr=document.querySelector(\'tr[data-cls-id="\'+ids[j]+\'"]\');'
			. 'if(!tr)continue;'
			. 'var rank=parseInt(tr.getAttribute("data-cls-rank"),10);'
			. 'var adj=tr.getAttribute("data-cls-adjutancy");'
			. 'if(lowest[adj]&&rank>lowest[adj]){anyOoO=true;break;}'
			. '}'
			. 'var reasons={};'
			. 'if(anyOoO){'
			. 'if(!confirm("' . esc_attr( $confirm_ooo_bulk ) . '"))return;'
			. 'var sharedReason=prompt("' . esc_attr( $prompt_ooo_reason ) . '");'
			. 'if(!sharedReason||!sharedReason.trim()){alert("' . esc_attr( $reason_required ) . '");return;}'
			. 'for(var k=0;k<ids.length;k++){reasons[String(ids[k])]=sharedReason;}'
			. '}'
			. 'status.textContent="…";'
			. 'var bulkPayload={classification_ids:ids,date_to_assume:date,time_to_assume:time};'
			. 'if(anyOoO){bulkPayload.out_of_order_reasons=reasons;}'
			. 'fetch("' . esc_js( $bulk_url ) . '",{'
			. 'method:"POST",'
			. 'headers:{"X-WP-Nonce":"' . esc_js( $nonce ) . '","Content-Type":"application/json"},'
			. 'body:JSON.stringify(bulkPayload),'
			. 'credentials:"same-origin"'
			. '}).then(function(r){return r.json().then(function(d){return{status:r.status,body:d};});}).then(function(o){'
			. 'if(o.status>=200&&o.status<300){location.reload();}'
			. 'else{status.textContent="Error: "+JSON.stringify(o.body);}'
			. '}).catch(function(e){status.textContent="Network error: "+e.message;});'
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
			. 'else{alert(JSON.stringify(o.body));btn.disabled=false;}'
			. '}).catch(function(e){alert("Network error: "+e.message);btn.disabled=false;});'
			. '}'
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

	/**
	 * Admin-post handler for the General section save.
	 *
	 * @return void
	 */
	public static function handle_save(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Access denied.', 'ffcertificate' ) );
		}
		$notice_id = isset( $_POST['notice_id'] ) ? absint( wp_unslash( (string) $_POST['notice_id'] ) ) : 0;
		check_admin_referer( 'ffc_recruitment_save_notice_' . $notice_id );

		if ( $notice_id <= 0 ) {
			wp_safe_redirect( self::back_url() );
			exit;
		}

		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['name'] ) ) : '';

		// Build the JSON config from the checkbox grid. Every key in
		// columns_label_map() emits an entry; unchecked checkboxes don't
		// POST so we treat them as `false`, while the mandatory columns
		// (rank, name) ride on hidden value=1 inputs that always post.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified above via check_admin_referer.
		$posted = isset( $_POST['public_columns'] ) && is_array( $_POST['public_columns'] )
			? wp_unslash( $_POST['public_columns'] )
			: array();
		$labels = self::columns_label_map();
		$built  = array();
		foreach ( array_keys( $labels ) as $key ) {
			$built[ $key ] = isset( $posted[ $key ] ) && '' !== (string) $posted[ $key ];
		}
		$built['rank'] = true;
		$built['name'] = true;
		$config        = wp_json_encode( $built );

		RecruitmentNoticeRepository::update(
			$notice_id,
			array(
				'name'                  => $name,
				'public_columns_config' => is_string( $config ) ? $config : '{}',
			)
		);

		self::redirect_with_notice( $notice_id, 'saved' );
	}

	/**
	 * Admin-post handler for status transitions.
	 *
	 * @return void
	 */
	public static function handle_transition(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Access denied.', 'ffcertificate' ) );
		}
		$notice_id = isset( $_POST['notice_id'] ) ? absint( wp_unslash( (string) $_POST['notice_id'] ) ) : 0;
		check_admin_referer( 'ffc_recruitment_transition_notice_' . $notice_id );

		$target = isset( $_POST['target_status'] ) ? sanitize_key( wp_unslash( (string) $_POST['target_status'] ) ) : '';
		$reason = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['reason'] ) ) : null;
		if ( '' !== (string) $reason && null === $reason ) {
			$reason = null;
		}

		// Default to a generic invalid-target failure when the operator
		// somehow lands here with a non-enum value (form-tampering case).
		$flash = 'transition-invalid-target';

		if ( $notice_id > 0 && in_array( $target, array( 'draft', 'preliminary', 'definitive', 'closed' ), true ) ) {
			$result = RecruitmentNoticeStateMachine::transition_to( $notice_id, $target, '' === (string) $reason ? null : $reason );
			if ( true === $result['success'] ) {
				$flash = 'transitioned';
			} else {
				// Map the state-machine error code to a flash key the
				// renderer's notice map knows. Anything we don't have a
				// dedicated copy for falls through to a generic
				// transition-failed key carrying the raw code so the
				// operator can see what blocked it.
				$first_error = empty( $result['errors'] ) ? '' : (string) $result['errors'][0];
				switch ( $first_error ) {
					case 'recruitment_definitive_to_preliminary_blocked_by_calls':
						$flash = 'transition-blocked-by-calls';
						break;
					case 'recruitment_transition_reason_required':
						$flash = 'transition-reason-required';
						break;
					case 'recruitment_transition_race_lost':
						$flash = 'transition-race-lost';
						break;
					default:
						// Includes recruitment_invalid_transition: …
						// and recruitment_notice_not_found.
						$flash = 'transition-failed';
						break;
				}
			}
		}

		self::redirect_with_notice( $notice_id, $flash );
	}

	/**
	 * Back-to-list URL.
	 *
	 * @return string
	 */
	private static function back_url(): string {
		return add_query_arg(
			array(
				'page' => RecruitmentAdminPage::PAGE_SLUG,
				'tab'  => 'notices',
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Redirect back to the edit screen with a `?ffc_msg=…` flash for
	 * the next render to surface (admin notice).
	 *
	 * @param int    $notice_id Notice ID.
	 * @param string $message_key Flash key.
	 * @return never
	 */
	private static function redirect_with_notice( int $notice_id, string $message_key ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => RecruitmentAdminPage::PAGE_SLUG,
					'tab'       => 'notices',
					'action'    => 'edit-notice',
					'notice_id' => $notice_id,
					'ffc_msg'   => $message_key,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
