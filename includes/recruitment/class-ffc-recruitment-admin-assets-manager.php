<?php
/**
 * Recruitment Admin Assets Manager.
 *
 * Enqueues the dedicated CSS / JS bundle for the recruitment admin
 * page. Gated by screen ID so unrelated wp-admin pages don't pay the
 * cost. Mirrors the pattern used by
 * {@see \FreeFormCertificate\Admin\AdminAssetsManager}.
 *
 * Sprint A1 ships the skeleton (CSS for status badges + the
 * attached-adjutancy pills already inline; JS bundle is a stub that
 * will eat the per-render inline `<script>` blocks in subsequent
 * sprints). The manager is hookable from now on, so future sprints
 * just append to the existing files.
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
 * Asset enqueue manager scoped to the recruitment admin screens.
 */
final class RecruitmentAdminAssetsManager {

	/**
	 * Handle prefix for the recruitment admin assets.
	 */
	public const HANDLE_CSS = 'ffc-recruitment-admin';
	public const HANDLE_JS  = 'ffc-recruitment-admin';

	/**
	 * Hook into `admin_enqueue_scripts`. Default priority — no ordering
	 * constraint vs other plugins.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_enqueue_scripts', array( self::class, 'maybe_enqueue' ), 10 );
	}

	/**
	 * Enqueue assets only when we're on a recruitment admin screen.
	 *
	 * The hook suffix WP passes is `toplevel_page_ffc-recruitment` for
	 * the main page (since the top-level menu's slug is the same as the
	 * page slug); future sub-pages will be `ffc-recruitment_page_…`.
	 * We match both prefixes.
	 *
	 * @param string $hook_suffix Current admin screen hook suffix.
	 * @return void
	 */
	public static function maybe_enqueue( string $hook_suffix ): void {
		if ( ! self::is_recruitment_screen( $hook_suffix ) ) {
			return;
		}

		// ffc-common.css carries the .ffc-control-toggle switch styles used by the
		// notice/reason editors (render_toggle); load it as a dependency.
		$s = \FreeFormCertificate\Core\AssetHelper::asset_suffix();
		\FreeFormCertificate\Core\AssetHelper::enqueue_common_style();

		// The vertical-tab layout for the recruitment admin nav lives in
		// ffc-admin-settings.css (the .ffc-settings-tabs__* rules introduced
		// in #429 and reused here). Enqueue it so this page picks up the
		// same look as page=ffc-settings; the other rules in that file are
		// scoped under .ffc-settings-wrap and stay dormant here.
		\FreeFormCertificate\Core\AssetRegistry::enqueue_styles( 'ffc-admin-settings' );

		wp_enqueue_style(
			self::HANDLE_CSS,
			FFC_PLUGIN_URL . "assets/css/ffc-recruitment-admin{$s}.css",
			array( 'ffc-common', 'ffc-admin-settings' ),
			FFC_VERSION
		);

		// The badge colours are hex values the operator picks, so no static
		// sheet can know them. They come from here as generated rules, printed
		// AFTER this handle's file -- which is what makes the operator's choice
		// win with no specificity trick (#1193).
		RecruitmentBadgePalette::attach( self::HANDLE_CSS );

		wp_enqueue_script(
			self::HANDLE_JS,
			FFC_PLUGIN_URL . "assets/js/ffc-recruitment-admin{$s}.js",
			array(),
			FFC_VERSION,
			true
		);

		// The convocation subject + body moved to the global email-body hub
		// (Settings → SMTP → Email texts) in #964, so the settings tab no
		// longer carries an editor or its "Restore Default Text" wiring.

		// Batched CSV-import orchestrator, used by the notice edit page's
		// preview-list flow. The inline submit handler hands off to
		// `window.ffcRecruitmentImportBatched.run()` so the dependency
		// here is a script enqueue, not a localized object.
		wp_enqueue_script(
			'ffc-recruitment-import-batched',
			FFC_PLUGIN_URL . "assets/js/ffc-recruitment-import-batched{$s}.js",
			array(),
			FFC_VERSION,
			true
		);
		// Lines that match a stored candidate whose data differs are confirmed
		// in the shared dialog before any import writes (#2245); the three
		// import screens read these strings through the batched importer.
		wp_localize_script(
			'ffc-recruitment-import-batched',
			'ffcRecruitmentImportIdentity',
			array(
				'title'     => __( 'Some lines differ from stored candidates', 'ffcertificate' ),
				'body'      => __( 'These lines match a candidate already stored, but their data differs. The import attaches them to that candidate and keeps the stored name, CPF, RF and e-mail. Correct the candidate by hand if the file is right.', 'ffcertificate' ),
				'cta'       => __( 'Import and keep stored data', 'ffcertificate' ),
				/* translators: {n}: number of lines not listed */
				'more'      => __( '+{n} more, listed below the form', 'ffcertificate' ),
				'cancelled' => __( 'Import cancelled. Nothing was imported.', 'ffcertificate' ),
			)
		);
		// A file that replaces a list with rows shows what it changes before
		// anything is written (#2245).
		wp_localize_script(
			'ffc-recruitment-import-batched',
			'ffcRecruitmentImportDiff',
			array(
				'title'     => __( 'Replace the list?', 'ffcertificate' ),
				'body'      => __( 'This file replaces a list that already has rows. Every row that enters, leaves or changes is listed below the form. Manual preliminary statuses are kept for the rows that stay.', 'ffcertificate' ),
				'cta'       => __( 'Replace list', 'ffcertificate' ),
				'cancelled' => __( 'Import cancelled. Nothing was imported.', 'ffcertificate' ),
			)
		);

		// Candidate-edit page interactions (PII reveal + adjutancy swap),
		// extracted from inline <script> blocks (frontend-audit Item 3). The
		// handlers are document-delegated and inert when their buttons aren't
		// rendered, so enqueuing on every recruitment screen is harmless.
		// ffc-core: the swap's outcome is the shared status line, FFC.status() (#1843).
		\FreeFormCertificate\Core\AssetRegistry::enqueue_scripts( 'ffc-core' );
		// ffc-field-behaviours: the phone field types through the shared
		// phone mask, like every other phone the plugin edits (#1920).
		\FreeFormCertificate\Core\AssetRegistry::enqueue_scripts( 'ffc-field-behaviours' );
		wp_enqueue_script(
			'ffc-recruitment-candidate-edit',
			FFC_PLUGIN_URL . "assets/js/ffc-recruitment-candidate-edit{$s}.js",
			array( 'ffc-core', 'ffc-field-behaviours' ),
			FFC_VERSION,
			true
		);
		wp_localize_script(
			'ffc-recruitment-candidate-edit',
			'ffcRecruitmentCandidateEdit',
			array(
				'revealRoot' => esc_url_raw( rest_url( 'ffcertificate/v1/recruitment/candidates/' ) ),
				'classRoot'  => esc_url_raw( rest_url( 'ffcertificate/v1/recruitment/classifications/' ) ),
				'nonce'      => wp_create_nonce( 'wp_rest' ),
				'strings'    => array(
					'hide'   => __( 'Hide', 'ffcertificate' ),
					'reveal' => __( 'Reveal', 'ffcertificate' ),
					'saved'  => __( 'Saved', 'ffcertificate' ),
					'error'  => __( 'Error', 'ffcertificate' ),
				),
			)
		);

		// CSV-import handlers for the Candidates tab's import section,
		// extracted from an inline <script> in RecruitmentAdminPage
		// (frontend-audit Item 10). The two functions stay global because
		// the markup invokes them via inline onchange/onsubmit; their config
		// (REST notices root + nonce + i18n) arrives via the localized object
		// below so the PHP carries no inline interpolation.
		wp_enqueue_script(
			'ffc-recruitment-candidates-import',
			FFC_PLUGIN_URL . "assets/js/ffc-recruitment-candidates-import{$s}.js",
			array( 'ffc-core', self::HANDLE_JS, 'ffc-recruitment-import-batched' ),
			FFC_VERSION,
			true
		);
		wp_localize_script(
			'ffc-recruitment-candidates-import',
			'ffcRecruitmentCandidatesImport',
			array(
				'noticesRoot' => esc_url_raw( rest_url( 'ffcertificate/v1/recruitment/notices/' ) ),
				'nonce'       => wp_create_nonce( 'wp_rest' ),
				'strings'     => array(
					'bothLists'    => __( 'Both lists are available for this notice.', 'ffcertificate' ),
					'draftOnly'    => __( 'Draft notices can only receive the preliminary list.', 'ffcertificate' ),
					'pickNotice'   => __( 'Pick a notice above to see which lists can receive the import.', 'ffcertificate' ),
					'selectTarget' => __( 'Please select a target notice.', 'ffcertificate' ),
					'processing'   => __( 'Processing CSV…', 'ffcertificate' ),
					'elapsed'      => __( 'elapsed', 'ffcertificate' ),
				),
			)
		);

		// Notice Edit page handlers (CSV import-from-edit, snapshot promote,
		// adjutancy attach/detach, classification tab switch, per-row
		// Call/bulk-call/status, preview-status dropdowns), extracted from the
		// seven inline <script> blocks in RecruitmentNoticeEditPageRenderer
		// (frontend-audit Item 10). Functions stay global (inline
		// onclick/onsubmit); config arrives via the localized object below so
		// the renderer carries no inline interpolation. Depends on the admin
		// bundle (shared confirm modal) + the batched importer.
		$rec_settings = RecruitmentSettings::all();
		// The shared batched-export driver and its progress overlay, for the
		// definitive list and calls CSV (#2245).
		\FreeFormCertificate\Core\AssetRegistry::enqueue_scripts( 'ffc-batched-export' );
		\FreeFormCertificate\Core\AssetRegistry::enqueue_styles( 'ffc-progress-overlay' );
		wp_enqueue_script(
			'ffc-recruitment-notice-edit',
			FFC_PLUGIN_URL . "assets/js/ffc-recruitment-notice-edit{$s}.js",
			array( self::HANDLE_JS, 'ffc-recruitment-import-batched' ),
			FFC_VERSION,
			true
		);
		wp_localize_script(
			'ffc-recruitment-notice-edit',
			'ffcRecruitmentNoticeEdit',
			array(
				'restRoot'       => esc_url_raw( rest_url( 'ffcertificate/v1/recruitment/' ) ),
				// The definitive list and calls CSV (#2245) run through the
				// batched export dispatcher.
				'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
				'exportNonce'    => wp_create_nonce( RecruitmentExportSource::NONCE ),
				'nonce'          => wp_create_nonce( 'wp_rest' ),
				// Convocation send mode (#794). Only 'ask' surfaces the per-call
				// "notify by email" prompt in the call flows.
				'emailMode'      => (string) $rec_settings['email_mode'],
				// The site clock, so the call dialog can tell a past date
				// to assume (#2245). The server decides again on save.
				'siteNow'        => wp_date( 'Y-m-d\TH:i' ),
				'reasonRequired' => array(
					'denied'         => ! empty( $rec_settings['preview_reason_required_denied'] ),
					'granted'        => ! empty( $rec_settings['preview_reason_required_granted'] ),
					'appeal_denied'  => ! empty( $rec_settings['preview_reason_required_appeal_denied'] ),
					'appeal_granted' => ! empty( $rec_settings['preview_reason_required_appeal_granted'] ),
				),
				'importStrings'  => array(
					'ingesting'        => __( 'Ingesting…', 'ffcertificate' ),
					'validating'       => __( 'Validating…', 'ffcertificate' ),
					'processing'       => __( 'Processing…', 'ffcertificate' ),
					'committing'       => __( 'Finalising…', 'ffcertificate' ),
					'done'             => __( 'OK', 'ffcertificate' ),
					'errorPrefix'      => __( 'Error:', 'ffcertificate' ),
					'networkError'     => __( 'Network error', 'ffcertificate' ),
					'validationFailed' => __( 'Validation failed — review the per-line errors below and re-import.', 'ffcertificate' ),
				),
				'strings'        => array(
					'exportPreparing'        => __( 'Preparing…', 'ffcertificate' ),
					/* translators: %1$d processed, %2$d total */
					'exportProgress'         => __( 'Exporting %1$d/%2$d…', 'ffcertificate' ),
					'exportDone'             => __( 'Done!', 'ffcertificate' ),
					'exportError'            => __( 'An error occurred.', 'ffcertificate' ),
					'networkError'           => __( 'Network error:', 'ffcertificate' ),
					'processingCsv'          => __( 'Processing CSV…', 'ffcertificate' ),
					'bulkNoSel'              => __( 'Select at least one row first.', 'ffcertificate' ),
					'bulkNoDate'             => __( 'Date and time are required for bulk call.', 'ffcertificate' ),
					'confirmOooSingle'       => __( 'This call skips a higher-ranked candidate (out of order), so it needs a justification.', 'ffcertificate' ),
					'promptOooReason'        => __( 'Justification for calling out of order (required)', 'ffcertificate' ),
					'bulkModalTitle'         => __( 'Issue calls for the selected candidates?', 'ffcertificate' ),
					/* translators: 1: number of selected rows, 2: date, 3: time */
					'bulkModalBodyTpl'       => __( 'About to issue {count} call(s) for {date} at {time}.', 'ffcertificate' ),
					'bulkModalCta'           => __( 'Issue calls', 'ffcertificate' ),
					'bulkConsequenceAtomic'  => __( 'Atomic — any single conflict rolls back the entire batch.', 'ffcertificate' ),
					'bulkConsequenceLog'     => __( 'A "bulk call" audit entry is recorded with the selected candidates.', 'ffcertificate' ),
					'bulkConsequenceOoo'     => __( 'One or more selected rows would skip a higher-ranked candidate (out of order).', 'ffcertificate' ),
					'bulkReasonLabel'        => __( 'Justification for calling out of order (required)', 'ffcertificate' ),
					'dateToAssume'           => __( 'Date to assume', 'ffcertificate' ),
					'timeToAssume'           => __( 'Time to assume', 'ffcertificate' ),
					'callModalTitle'         => __( 'Call this candidate?', 'ffcertificate' ),
					'callModalCta'           => __( 'Call', 'ffcertificate' ),
					'callPastWarning'        => __( 'This date is already over: the call is recorded and no e-mail is sent.', 'ffcertificate' ),
					'cancellationReason'     => __( 'Cancellation reason (required)', 'ffcertificate' ),
					'cancelModalTitle'       => __( 'Cancel this call?', 'ffcertificate' ),
					'cancelModalBody'        => __( 'The candidate returns to the waiting list and the call ends everywhere: the .ics file, "My calls" and the next call.', 'ffcertificate' ),
					'cancelModalCta'         => __( 'Cancel call', 'ffcertificate' ),
					'reopenReason'           => __( 'Reopen reason (required)', 'ffcertificate' ),
					'reopenModalTitle'       => __( 'Reopen this vacancy?', 'ffcertificate' ),
					'reopenModalBody'        => __( 'The candidate returns to the waiting list and the call that led to the no-show ends.', 'ffcertificate' ),
					'reopenModalCta'         => __( 'Reopen', 'ffcertificate' ),
					'confirmOverride'        => __( 'This will undo a realized decision (hired / withdrew / no-show), reopen the vacancy and return the candidate to the waiting queue at their original position. The candidate is NOT notified.', 'ffcertificate' ),
					'overrideReason'         => __( 'Reason for undoing this decision (required, audited)', 'ffcertificate' ),
					'overrideModalTitle'     => __( 'Undo this decision?', 'ffcertificate' ),
					'overrideModalCta'       => __( 'Undo decision', 'ffcertificate' ),
					'notifyByEmailAsk'       => __( 'Notify the candidate by email about this call', 'ffcertificate' ),
					/* translators: {count} is replaced in JS with the number of candidates */
					'noEmailContactManually' => __( '{count} called candidate(s) have no email on file and were not notified. Contact them manually.', 'ffcertificate' ),
				),
			)
		);

		// Autosave infra for the Settings tab — `data-ffc-autosave-key`
		// toggles in `render_settings_tab()` bind via the shared
		// ffc-admin-autosave widget against SettingsAjaxEndpoint. Mirrors
		// SettingsTab::enqueue_autosave_infra() so this off-page screen
		// (it's `toplevel_page_ffc-recruitment`, not `toplevel_page_ffc-settings`)
		// can opt-in without depending on the settings-page asset loader.
		\FreeFormCertificate\Core\AssetRegistry::enqueue_scripts( 'ffc-core', 'ffc-admin-js', 'ffc-admin-autosave', 'ffc-section-collapse' );
		wp_localize_script(
			'ffc-admin-autosave',
			'ffcAdminAutosave',
			array(
				'nonce'   => wp_create_nonce( \FreeFormCertificate\Admin\SettingsAjaxEndpoint::AJAX_ACTION ),
				'strings' => \FreeFormCertificate\Admin\AdminUI::autosave_strings(),
			)
		);
		// The notice and reason edit screens save their toggles per record (#1757).
		\FreeFormCertificate\Core\RecordAutosave::enqueue();

		// Localize the REST root + nonce so the JS can post against the
		// recruitment endpoints without an inline `<script>` block.
		wp_localize_script(
			self::HANDLE_JS,
			'ffcRecruitmentAdmin',
			array(
				'restRoot'            => esc_url_raw( rest_url( 'ffcertificate/v1/recruitment/' ) ),
				'nonce'               => wp_create_nonce( 'wp_rest' ),
				'confirmModalStrings' => array(
					'closeLabel'   => __( 'Close', 'ffcertificate' ),
					'cancelLabel'  => __( 'Cancel', 'ffcertificate' ),
					'defaultTitle' => __( 'Confirm action', 'ffcertificate' ),
					'defaultCta'   => __( 'Confirm', 'ffcertificate' ),
				),
			)
		);
	}

	/**
	 * Is the current admin screen one of the recruitment pages?
	 *
	 * @param string $hook_suffix Current screen hook suffix.
	 * @return bool
	 */
	private static function is_recruitment_screen( string $hook_suffix ): bool {
		// Top-level page hook is `toplevel_page_<slug>`; sub-pages would
		// be `<parent>_page_<slug>`. Both contain the page slug.
		return false !== strpos( $hook_suffix, RecruitmentAdminPage::PAGE_SLUG );
	}
}
