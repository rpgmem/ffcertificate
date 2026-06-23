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
 * @phpstan-import-type NoticeRow         from RecruitmentNoticeReader
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

		include FFC_PLUGIN_DIR . 'templates/admin/recruitment/notice-edit/csv-import-section.php';
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

		// Pre-render the column-toggles grid (already-escaped HTML) so the
		// template can reference it without reaching back into a private
		// renderer helper.
		$columns_toggles = self::render_columns_toggles( (string) $notice->public_columns_config );

		include FFC_PLUGIN_DIR . 'templates/admin/recruitment/notice-edit/general-section.php';
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
		$defaults = (array) json_decode( RecruitmentNoticeReader::DEFAULT_PUBLIC_COLUMNS_CONFIG, true );
		$state    = array_merge( $defaults, $decoded );

		$mandatory = array( 'rank', 'name' );
		// `preview_reason` is rendered in its own dedicated row below
		// the column grid (see render_general_section()) because it's
		// not really a column — it's a per-edital toggle controlling
		// whether the preliminary-list reason text is exposed publicly.
		$rendered_in_grid = static fn( string $key ): bool => 'preview_reason' !== $key;

		$html = '<div class="ffc-recruitment-columns-toggles ffc-rec-columns-grid">';
		foreach ( $labels as $key => $label ) {
			if ( ! $rendered_in_grid( $key ) ) {
				continue;
			}
			$is_mandatory = in_array( $key, $mandatory, true );
			$checked      = $is_mandatory || ! empty( $state[ $key ] );
			$id_attr      = 'ffc-notice-pcc-' . $key;
			$html        .= '<div class="ffc-rec-flex-center-6">';
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
				$html .= ' <em class="ffc-rec-mandatory-note">(' . esc_html__( 'mandatory', 'ffcertificate' ) . ')</em>';
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

		echo '<div class="postbox ffc-rec-mt-20">';
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

		// LOGIC pass — the affordance transition set for the current state,
		// minus the prelim → definitive path already rendered above.
		$transitions = self::resolve_status_transitions( $current );

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

				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ffc-rec-inline-form"';
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

			echo '<hr class="ffc-rec-my-15">';
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

		// The confirm flow (data-ffc-confirm) intercepts the click and
		// re-fires it with data-ffc-confirm-ok=1; ffcRecruitmentSnapshotPromote
		// (in ffc-recruitment-notice-edit.js) skips the fetch until that flag
		// is present so the first click only opens the modal.

		echo '<hr class="ffc-rec-my-15">';
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

		$attached_objects = array_values(
			array_filter(
				$adjutancies,
				static function ( $a ) use ( $attached_set ) {
					return isset( $attached_set[ (int) $a->id ] );
				}
			)
		);

		$detached_objects = array_values(
			array_filter(
				$adjutancies,
				static function ( $a ) use ( $attached_set ) {
					return ! isset( $attached_set[ (int) $a->id ] );
				}
			)
		);

		include FFC_PLUGIN_DIR . 'templates/admin/recruitment/notice-edit/adjutancies-section.php';
	}

	/**
	 * LOGIC pass for {@see render_classifications_section()}: the two
	 * list_type fetches, the authoritative empties-by-adjutancy map, the
	 * default-tab resolution, and the filter read + apply. Returns a struct
	 * the section view consumes — no markup emitted.
	 *
	 * Order mirrors the previous inline body exactly so query counts and the
	 * "empties / has_definitive computed from the UNFILTERED list" invariant
	 * are preserved: fetch preview, fetch definitive, compute empties from the
	 * unfiltered definitive list, derive `has_definitive` from the unfiltered
	 * list, resolve the active tab, then read + apply the filters to both
	 * arrays.
	 *
	 * @param int $notice_id Notice id.
	 * @return array{
	 *     preview: array<int, object>,
	 *     definitive_rows: array<int, object>,
	 *     def_empties_by_adj: array<string, array<int, array{id:int, rank:int}>>,
	 *     active_tab: string,
	 *     filters: array<string, mixed>
	 * }
	 * @phpstan-return array{
	 *     preview: list<ClassificationRow>,
	 *     definitive_rows: list<ClassificationRow>,
	 *     def_empties_by_adj: array<string, array<int, array{id:int, rank:int}>>,
	 *     active_tab: string,
	 *     filters: array<string, mixed>
	 * }
	 */
	private static function prepare_classifications_section_data( int $notice_id ): array {
		$preview         = RecruitmentClassificationRepository::get_for_notice( $notice_id, 'preview' );
		$definitive_rows = RecruitmentClassificationRepository::get_for_notice( $notice_id, 'definitive' );

		// Authoritative out-of-order source for the client gate (#Item7):
		// computed from the UNFILTERED, unpaginated definitive list before the
		// filter below narrows $definitive_rows for display. Handed to the JS
		// via the definitive panel's data-ffc-empties attribute so the
		// justification prompt fires regardless of the active filter / page.
		$def_empties_by_adj = self::compute_empties_by_adjutancy( $definitive_rows );

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

		return array(
			'preview'            => $preview,
			'definitive_rows'    => $definitive_rows,
			'def_empties_by_adj' => $def_empties_by_adj,
			'active_tab'         => $active_tab,
			'filters'            => $filters,
		);
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
		$notice_id = (int) $notice->id;

		// LOGIC pass — repository fetches, empties map, default-tab
		// resolution, filter read + apply. View consumes the struct below.
		$data = self::prepare_classifications_section_data( $notice_id );

		$preview            = $data['preview'];
		$definitive_rows    = $data['definitive_rows'];
		$def_empties_by_adj = $data['def_empties_by_adj'];
		$active_tab         = $data['active_tab'];
		$filters            = $data['filters'];

		echo '<div class="postbox ffc-rec-mt-20">';
		echo '<h2 class="hndle"><span>' . esc_html__( 'Classifications', 'ffcertificate' ) . '</span></h2>';
		echo '<div class="inside">';

		self::render_classification_filters_form( $notice_id, $filters );

		// Tabs.
		$prev_is_active = 'preliminary' === $active_tab;
		$prev_tab_class = $prev_is_active ? 'nav-tab nav-tab-active' : 'nav-tab';
		$def_tab_class  = $prev_is_active ? 'nav-tab' : 'nav-tab nav-tab-active';
		$prev_display   = $prev_is_active ? 'block' : 'none';
		$def_display    = $prev_is_active ? 'none' : 'block';

		echo '<h2 class="nav-tab-wrapper ffc-rec-mb-1">';
		echo '<a href="#" class="' . esc_attr( $prev_tab_class ) . '" data-ffc-clstab="preliminary" onclick="return ffcRecruitmentClsTabSwitch(this);">' . esc_html__( 'Preliminary', 'ffcertificate' ) . '</a>';
		echo '<a href="#" class="' . esc_attr( $def_tab_class ) . '" data-ffc-clstab="definitive" onclick="return ffcRecruitmentClsTabSwitch(this);">' . esc_html__( 'Definitive', 'ffcertificate' ) . '</a>';
		echo '</h2>';

		echo '<div data-ffc-clspanel="preliminary" style="display:' . esc_attr( $prev_display ) . ';">';
		self::render_classifications_table( $preview, false, 'preliminary' );
		echo '</div>';

		echo '<div data-ffc-clspanel="definitive" data-ffc-empties="' . esc_attr( (string) wp_json_encode( $def_empties_by_adj ) ) . '" style="display:' . esc_attr( $def_display ) . ';">';
		self::render_classifications_table( $definitive_rows, true, 'definitive' );
		echo '</div>';

		// The tab toggle handler (ffcRecruitmentClsTabSwitch) ships in
		// ffc-recruitment-notice-edit.js: it swaps .nav-tab-active, shows the
		// matching [data-ffc-clspanel], and writes ffc_cls_tab into the URL so
		// a later pagination click preserves the operator's chosen tab.

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

		include FFC_PLUGIN_DIR . 'templates/admin/recruitment/notice-edit/classification-filters-form.php';
	}

	/**
	 * LOGIC pass for {@see render_classifications_table()}: pagination math,
	 * bulk candidate/adjutancy fetches, the PCD map and the conditional reason
	 * catalog. Returns a struct the view loop consumes — no markup emitted.
	 *
	 * The query/iteration order here mirrors the previous inline body exactly:
	 * the page slice happens first (so the bulk fetches only touch the visible
	 * page), then the single candidate round-trip feeds both the name and PCD
	 * maps, then the adjutancy slug lookup, and finally — only on the
	 * Preliminary tab — the reason catalog. The per-row adjutancy badge lookup
	 * stays inside the render loop (a separate, intentional per-row call).
	 *
	 * @param array<int, object> $rows         Classification rows (post-filter).
	 * @phpstan-param list<ClassificationRow> $rows
	 * @param bool               $with_actions Whether the Actions column renders.
	 * @param string             $tab_key      `preliminary` or `definitive`.
	 * @return array{
	 *     rows: array<int, object>,
	 *     total: int,
	 *     current_page: int,
	 *     per_page: int,
	 *     candidates: array<int, string>,
	 *     pcd_map: array<int, bool>,
	 *     adjutancies: array<int, string>,
	 *     reasons: array<int, object>
	 * }
	 * @phpstan-return array{
	 *     rows: list<ClassificationRow>,
	 *     total: int,
	 *     current_page: int,
	 *     per_page: int,
	 *     candidates: array<int, string>,
	 *     pcd_map: array<int, bool>,
	 *     adjutancies: array<int, string>,
	 *     reasons: list<ReasonRow>
	 * }
	 */
	private static function prepare_classifications_table_data( array $rows, bool $with_actions, string $tab_key ): array {
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

		// Bulk-load the full candidate rows once (we need `pcd_hash` for
		// the Subscription column; the `name` map below is fed from the
		// same payload to keep this a single SQL round-trip).
		$candidate_rows = RecruitmentCandidateReader::get_by_ids( array_unique( $candidate_ids ) );
		$candidates     = array();
		$pcd_map        = array();
		foreach ( $candidate_rows as $cand ) {
			$cid                = (int) $cand->id;
			$candidates[ $cid ] = (string) ( $cand->name ?? '' );
			// `verify` returns null on hash decode failure — fall back to
			// GERAL, mirroring the public shortcode's defensive default.
			$pcd_map[ $cid ] = true === RecruitmentPcdHasher::verify( (string) ( $cand->pcd_hash ?? '' ), $cid );
		}
		$adjutancies = self::lookup_map( array_unique( $adjutancy_ids ), array( RecruitmentAdjutancyRepository::class, 'get_by_id' ), 'slug' );

		// On the Preliminary tab the Status column is always "Waiting"
		// (the §5.2 invariant), so we replace it with the editable
		// preview_status + reason dropdown. The Definitive tab keeps the
		// existing Status badge.
		$is_preview_tab = ! $with_actions;
		$reasons        = $is_preview_tab ? RecruitmentReasonRepository::get_all() : array();

		return array(
			'rows'         => $rows,
			'total'        => $total,
			'current_page' => $current_page,
			'per_page'     => $per_page,
			'candidates'   => $candidates,
			'pcd_map'      => $pcd_map,
			'adjutancies'  => $adjutancies,
			'reasons'      => $reasons,
		);
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

		// LOGIC pass — pagination math, bulk candidate/adjutancy fetches,
		// PCD map, conditional reason catalog. View consumes the struct below.
		$data = self::prepare_classifications_table_data( $rows, $with_actions, $tab_key );

		$rows         = $data['rows'];
		$total        = $data['total'];
		$current_page = $data['current_page'];
		$per_page     = $data['per_page'];
		$candidates   = $data['candidates'];
		$pcd_map      = $data['pcd_map'];
		$adjutancies  = $data['adjutancies'];
		$reasons      = $data['reasons'];

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

		echo '<table class="widefat striped"><thead><tr>';
		if ( $with_actions ) {
			echo '<th class="ffc-rec-col-checkbox"><input type="checkbox" id="ffc-cls-bulk-all" onclick="ffcRecruitmentClsToggleAll(this);" title="' . esc_attr__( 'Select all waiting rows on this page', 'ffcertificate' ) . '"></th>';
		}
		echo '<th>' . esc_html__( 'Rank', 'ffcertificate' ) . '</th>';
		echo '<th>' . esc_html__( 'Candidate', 'ffcertificate' ) . '</th>';
		echo '<th>' . esc_html__( 'Adjutancy', 'ffcertificate' ) . '</th>';
		echo '<th>' . esc_html__( 'Subscription', 'ffcertificate' ) . '</th>';
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
			$is_pcd = $pcd_map[ (int) $row->candidate_id ] ?? false;
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper returns escaped HTML.
			echo '<td>' . RecruitmentPublicShortcodeRenderer::render_subscription_badge( $is_pcd ) . '</td>';
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

		// The per-row Call / bulk-call / preview-status handlers ship in
		// assets/js/ffc-recruitment-notice-edit.js (frontend-audit Item 10),
		// enqueued + localized by RecruitmentAdminAssetsManager. The markup
		// here only needs the data-* hooks the script reads.
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
		include FFC_PLUGIN_DIR . 'templates/admin/recruitment/notice-edit/bulk-call-toolbar.php';
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
				// Admin override (#Item 8): reset to waiting even when the
				// reopen-freeze would block the plain `reopen` above.
				$out .= self::cls_button( $id, 'override', __( 'Undo decision (admin)', 'ffcertificate' ), 'link-delete' );
				break;
			case 'hired':
			case 'withdrew':
				// Terminal under the normal lifecycle — but an admin may undo a
				// realized decision via the audited override (#Item 8): reopens
				// the vacancy and returns the candidate to the waiting queue.
				$out .= self::cls_button( $id, 'override', __( 'Undo decision (admin)', 'ffcertificate' ), 'link-delete' );
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
			'<button type="button" class="%s ffc-rec-mr-quarter" data-cls-id="%d" data-cls-action="%s" onclick="ffcRecruitmentClsAct(this);">%s</button>',
			esc_attr( $class ),
			$id,
			esc_attr( $action ),
			esc_html( $label )
		);
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
	 * Authoritative out-of-order map for the client gate (#Item7).
	 *
	 * Groups every `empty` classification by adjutancy slug, each list sorted
	 * ascending by rank. The client-side out-of-order detection in
	 * {@see render_classification_actions_script()} previously scanned the
	 * rendered DOM, but that table is both filtered (server-side) and
	 * paginated — so a narrowed view drops the true lower-rank empties and the
	 * called candidate looks like next-in-queue, skipping the justification
	 * prompt. This map is built from the full, unfiltered/unpaginated
	 * definitive list (the same queue the server's `find_lowest_rank_empty`
	 * enforces) and handed to the JS via the panel's `data-ffc-empties`
	 * attribute. Keyed by slug to match the rows' `data-cls-adjutancy`.
	 *
	 * @param array<int, object> $rows Definitive-list classification rows.
	 * @phpstan-param list<ClassificationRow> $rows
	 * @return array<string, array<int, array{id:int, rank:int}>>
	 */
	private static function compute_empties_by_adjutancy( array $rows ): array {
		$empties = array_filter( $rows, static fn( $r ) => 'empty' === (string) $r->status );
		if ( empty( $empties ) ) {
			return array();
		}

		$adj_ids = array_map( static fn( $r ) => (int) $r->adjutancy_id, $empties );
		$slugs   = self::lookup_map( array_unique( $adj_ids ), array( RecruitmentAdjutancyRepository::class, 'get_by_id' ), 'slug' );

		$map = array();
		foreach ( $empties as $r ) {
			$slug           = $slugs[ (int) $r->adjutancy_id ] ?? '#' . (int) $r->adjutancy_id;
			$map[ $slug ][] = array(
				'id'   => (int) $r->id,
				'rank' => (int) $r->rank,
			);
		}

		foreach ( $map as &$list ) {
			usort( $list, static fn( $a, $b ) => $a['rank'] <=> $b['rank'] );
		}
		unset( $list );

		return $map;
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
	 * LOGIC pass for {@see render_status_section()}: the transition affordance
	 * set actually offered as buttons for the current state. Identical to
	 * {@see transitions_from()} except the prelim → definitive path is dropped
	 * on the `preliminary` state, since that transition is rendered separately
	 * by {@see render_preliminary_to_final_options()} above the button strip.
	 *
	 * @param string $current Current notice status.
	 * @return array<string, string> map of target_status => label.
	 */
	private static function resolve_status_transitions( string $current ): array {
		$transitions = self::transitions_from( $current );

		if ( 'preliminary' === $current ) {
			// Already rendered the prelim → definitive controls above; here we
			// only need the back-to-draft path.
			unset( $transitions['definitive'] );
		}

		return $transitions;
	}
}
