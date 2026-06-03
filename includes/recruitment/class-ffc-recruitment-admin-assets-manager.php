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

		$css_path = FFC_PLUGIN_DIR . 'assets/css/ffc-recruitment-admin.css';
		$js_path  = FFC_PLUGIN_DIR . 'assets/js/ffc-recruitment-admin.js';

		// Bust cache via filemtime so admins editing local copies see the
		// fresh asset without bumping FFC_VERSION on every change.
		$css_ver = file_exists( $css_path ) ? (string) filemtime( $css_path ) : FFC_VERSION;
		$js_ver  = file_exists( $js_path ) ? (string) filemtime( $js_path ) : FFC_VERSION;

		// ffc-common.css carries the .ffc-toggle switch styles used by the
		// notice/reason editors (render_toggle); load it as a dependency.
		$s = \FreeFormCertificate\Core\Utils::asset_suffix();
		wp_enqueue_style(
			'ffc-common',
			FFC_PLUGIN_URL . "assets/css/ffc-common{$s}.css",
			array(),
			FFC_VERSION
		);

		// The vertical-tab layout for the recruitment admin nav lives in
		// ffc-admin-settings.css (the .ffc-settings-tabs__* rules introduced
		// in #429 and reused here). Enqueue it so this page picks up the
		// same look as page=ffc-settings; the other rules in that file are
		// scoped under .ffc-settings-wrap and stay dormant here.
		wp_enqueue_style(
			'ffc-admin-settings',
			FFC_PLUGIN_URL . "assets/css/ffc-admin-settings{$s}.css",
			array( 'ffc-common' ),
			FFC_VERSION
		);

		wp_enqueue_style(
			self::HANDLE_CSS,
			FFC_PLUGIN_URL . 'assets/css/ffc-recruitment-admin.css',
			array( 'ffc-common', 'ffc-admin-settings' ),
			$css_ver
		);

		wp_enqueue_script(
			self::HANDLE_JS,
			FFC_PLUGIN_URL . 'assets/js/ffc-recruitment-admin.js',
			array(),
			$js_ver,
			true
		);

		// Batched CSV-import orchestrator, used by the notice edit page's
		// preview-list flow. The inline submit handler hands off to
		// `window.ffcRecruitmentImportBatched.run()` so the dependency
		// here is a script enqueue, not a localized object.
		wp_enqueue_script(
			'ffc-recruitment-import-batched',
			FFC_PLUGIN_URL . "assets/js/ffc-recruitment-import-batched{$s}.js",
			array(),
			$js_ver,
			true
		);

		// Candidate-edit page interactions (PII reveal + adjutancy swap),
		// extracted from inline <script> blocks (frontend-audit Item 3). The
		// handlers are document-delegated and inert when their buttons aren't
		// rendered, so enqueuing on every recruitment screen is harmless.
		wp_enqueue_script(
			'ffc-recruitment-candidate-edit',
			FFC_PLUGIN_URL . "assets/js/ffc-recruitment-candidate-edit{$s}.js",
			array(),
			$js_ver,
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
			array(),
			$js_ver,
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
		wp_enqueue_script(
			'ffc-recruitment-notice-edit',
			FFC_PLUGIN_URL . "assets/js/ffc-recruitment-notice-edit{$s}.js",
			array( self::HANDLE_JS, 'ffc-recruitment-import-batched' ),
			$js_ver,
			true
		);
		wp_localize_script(
			'ffc-recruitment-notice-edit',
			'ffcRecruitmentNoticeEdit',
			array(
				'restRoot'       => esc_url_raw( rest_url( 'ffcertificate/v1/recruitment/' ) ),
				'nonce'          => wp_create_nonce( 'wp_rest' ),
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
					'processingCsv'         => __( 'Processing CSV…', 'ffcertificate' ),
					'bulkNoSel'             => __( 'Select at least one row first.', 'ffcertificate' ),
					'bulkNoDate'            => __( 'Date and time are required for bulk call.', 'ffcertificate' ),
					'confirmOooSingle'      => __( 'This call would skip a higher-ranked candidate (out of order). Continue?', 'ffcertificate' ),
					'promptOooReason'       => __( 'Justification for calling out of order (required):', 'ffcertificate' ),
					'reasonRequired'        => __( 'A justification is required to proceed.', 'ffcertificate' ),
					'bulkModalTitle'        => __( 'Issue calls for the selected candidates?', 'ffcertificate' ),
					/* translators: 1: number of selected rows, 2: date, 3: time */
					'bulkModalBodyTpl'      => __( 'About to issue {count} call(s) for {date} at {time}.', 'ffcertificate' ),
					'bulkModalCta'          => __( 'Issue calls', 'ffcertificate' ),
					'bulkConsequenceAtomic' => __( 'Atomic — any single conflict rolls back the entire batch.', 'ffcertificate' ),
					'bulkConsequenceLog'    => __( 'A "bulk call" audit entry is recorded with the selected candidates.', 'ffcertificate' ),
					'bulkConsequenceOoo'    => __( 'One or more selected rows would skip a higher-ranked candidate (out of order).', 'ffcertificate' ),
					'bulkReasonLabel'       => __( 'Justification for calling out of order (required)', 'ffcertificate' ),
					'dateToAssume'          => __( 'Date to assume (YYYY-MM-DD):', 'ffcertificate' ),
					'timeToAssume'          => __( 'Time to assume (HH:MM):', 'ffcertificate' ),
					'cancellationReason'    => __( 'Cancellation reason (required):', 'ffcertificate' ),
					'reopenReason'          => __( 'Reopen reason (required):', 'ffcertificate' ),
				),
			)
		);

		// Autosave infra for the Settings tab — `data-ffc-autosave-key`
		// toggles in `render_settings_tab()` bind via the shared
		// ffc-admin-autosave widget against SettingsAjaxEndpoint. Mirrors
		// SettingsTab::enqueue_autosave_infra() so this off-page screen
		// (it's `toplevel_page_ffc-recruitment`, not `ffc_form_page_ffc-settings`)
		// can opt-in without depending on the settings-page asset loader.
		wp_enqueue_script(
			'ffc-core',
			FFC_PLUGIN_URL . "assets/js/ffc-core{$s}.js",
			array( 'jquery' ),
			FFC_VERSION,
			true
		);
		wp_enqueue_script(
			'ffc-admin-js',
			FFC_PLUGIN_URL . "assets/js/ffc-admin{$s}.js",
			array( 'jquery', 'ffc-core' ),
			FFC_VERSION,
			true
		);
		wp_enqueue_script(
			'ffc-admin-autosave',
			FFC_PLUGIN_URL . "assets/js/ffc-admin-autosave{$s}.js",
			array( 'jquery', 'ffc-core', 'ffc-admin-js' ),
			FFC_VERSION,
			true
		);
		wp_enqueue_script(
			'ffc-section-collapse',
			FFC_PLUGIN_URL . "assets/js/ffc-section-collapse{$s}.js",
			array( 'jquery' ),
			FFC_VERSION,
			true
		);
		wp_localize_script(
			'ffc-admin-autosave',
			'ffcAdminAutosave',
			array(
				'nonce' => wp_create_nonce( \FreeFormCertificate\Admin\SettingsAjaxEndpoint::AJAX_ACTION ),
			)
		);

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
