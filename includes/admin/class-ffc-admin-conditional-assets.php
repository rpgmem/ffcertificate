<?php
/**
 * AdminConditionalAssets
 *
 * Per-screen conditional admin asset loading, extracted from
 * {@see \FreeFormCertificate\Admin\AdminAssetsManager} so the manager
 * retains only the always-on/core assets plus the orchestration.
 *
 * Each public/private method here is gated by a screen-detection helper and
 * enqueues exactly the assets the matching admin screen needs — identical
 * handles, paths, deps, version args, in_footer flags and localized data to
 * the pre-split manager.
 *
 * @package FreeFormCertificate\Admin
 * @since 6.7.x (Extracted from AdminAssetsManager — #591 phase-3, Sprint E5e)
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Conditional, per-screen admin asset loader.
 */
class AdminConditionalAssets {

	/**
	 * Enqueue conditional assets based on current page
	 *
	 * - Settings CSS (only on settings page)
	 * - Submission Edit CSS + JS (only on edit page)
	 */
	public function enqueue_conditional_assets(): void {
		$s = \FreeFormCertificate\Core\AssetHelper::asset_suffix();

		// Settings page styles + scripts.
		if ( AdminAssetsManager::is_settings_page() ) {
			wp_enqueue_style(
				'ffc-admin-settings',
				FFC_PLUGIN_URL . "assets/css/ffc-admin-settings{$s}.css",
				array( 'ffc-admin-css' ),
				FFC_VERSION
			);

			wp_enqueue_script(
				'ffc-admin-migrations',
				FFC_PLUGIN_URL . "assets/js/ffc-admin-migrations{$s}.js",
				array( 'jquery', 'ffc-core', 'ffc-admin-js' ),
				FFC_VERSION,
				true
			);

			wp_localize_script(
				'ffc-admin-migrations',
				'ffcMigrations',
				array(
					'nonce'   => wp_create_nonce( \FreeFormCertificate\Admin\MigrationActionsAjaxEndpoint::AJAX_ACTION ),
					'strings' => array(
						'processing'         => __( 'Processing...', 'ffcertificate' ),
						'complete'           => __( 'Complete', 'ffcertificate' ),
						'processed'          => __( 'Processed ', 'ffcertificate' ),
						'records'            => __( 'records...', 'ffcertificate' ),
						'migrationComplete'  => __( 'Migration Complete', 'ffcertificate' ),
						'allRecordsMigrated' => __( 'All records have been successfully migrated.', 'ffcertificate' ),
						'errorOccurred'      => __( 'Error occurred. Please try again.', 'ffcertificate' ),
					),
				)
			);
		}

		// Submission edit page assets.
		if ( $this->is_submission_edit_page() ) {
			$this->enqueue_submission_edit_assets();
		}

		// Submissions list page (when filtered by a single form): "Move to form…" modal.
		if ( $this->is_submissions_list_page() ) {
			$this->enqueue_move_submissions_assets();
			$this->enqueue_submissions_bulk_assets();
		}

		// Certificates Dashboard (calendar view).
		if ( $this->is_certificates_dashboard_page() ) {
			$this->enqueue_certificates_dashboard_assets();
		}

		// Documentation tab — in-page search filter for the Quick Navigation tree.
		if ( $this->is_documentation_tab() ) {
			wp_enqueue_script(
				'ffc-doc-search',
				FFC_PLUGIN_URL . "assets/js/ffc-doc-search{$s}.js",
				array(),
				FFC_VERSION,
				true
			);
		}
	}

	/**
	 * Check if current screen is the Documentation tab of the main
	 * Certificate Settings page (`page=ffc-settings&tab=documentation`).
	 *
	 * @return bool
	 */
	private function is_documentation_tab(): bool {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Page routing check for asset loading.
		if ( ! isset( $_GET['page'], $_GET['tab'] ) ) {
			return false;
		}
		return sanitize_key( wp_unslash( $_GET['page'] ) ) === 'ffc-settings'
			&& sanitize_key( wp_unslash( $_GET['tab'] ) ) === 'documentation';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Check if current page is the Certificates Dashboard.
	 *
	 * @return bool
	 */
	private function is_certificates_dashboard_page(): bool {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Page routing check for asset loading.
		return isset( $_GET['page'] )
			&& sanitize_key( wp_unslash( $_GET['page'] ) ) === \FreeFormCertificate\Admin\CertificatesDashboard::MENU_SLUG;
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Enqueue assets for the Certificates Dashboard page.
	 *
	 * Loads the shared FFCCalendarCore grid plus the dashboard-specific
	 * script that wires the REST endpoint, day badges and side list.
	 *
	 * @return void
	 */
	private function enqueue_certificates_dashboard_assets(): void {
		$s = \FreeFormCertificate\Core\AssetHelper::asset_suffix();

		wp_enqueue_style(
			'ffc-certificates-dashboard',
			FFC_PLUGIN_URL . "assets/css/ffc-certificates-dashboard{$s}.css",
			array( 'ffc-admin-css' ),
			FFC_VERSION
		);

		wp_enqueue_script(
			'ffc-calendar-core',
			FFC_PLUGIN_URL . "assets/js/ffc-calendar-core{$s}.js",
			array( 'jquery' ),
			FFC_VERSION,
			true
		);

		wp_enqueue_script(
			'ffc-certificates-dashboard',
			FFC_PLUGIN_URL . "assets/js/ffc-certificates-dashboard{$s}.js",
			array( 'jquery', 'ffc-calendar-core' ),
			FFC_VERSION,
			true
		);

		wp_localize_script(
			'ffc-certificates-dashboard',
			'ffcCertificatesDashboard',
			array(
				'restUrl'            => esc_url_raw( rest_url( 'ffc/v1/' ) ),
				'nonce'              => wp_create_nonce( 'wp_rest' ),
				'submissionsUrlBase' => esc_url_raw( admin_url( 'edit.php?post_type=ffc_form&page=ffc-submissions' ) ),
				'i18n'               => array(
					'legendGeofence'  => __( 'GeoFence start', 'ffcertificate' ),
					'legendFallback'  => __( 'Publication date (fallback)', 'ffcertificate' ),
					'sourceGeofence'  => __( 'GeoFence', 'ffcertificate' ),
					'sourcePostDate'  => __( 'Publication date', 'ffcertificate' ),
					'noFormsForDay'   => __( 'No forms scheduled for this day.', 'ffcertificate' ),
					'viewSubmissions' => __( 'View submissions for this form', 'ffcertificate' ),
					'calendarStrings' => array(
						'months'   => array(
							__( 'January', 'ffcertificate' ),
							__( 'February', 'ffcertificate' ),
							__( 'March', 'ffcertificate' ),
							__( 'April', 'ffcertificate' ),
							__( 'May', 'ffcertificate' ),
							__( 'June', 'ffcertificate' ),
							__( 'July', 'ffcertificate' ),
							__( 'August', 'ffcertificate' ),
							__( 'September', 'ffcertificate' ),
							__( 'October', 'ffcertificate' ),
							__( 'November', 'ffcertificate' ),
							__( 'December', 'ffcertificate' ),
						),
						'weekdays' => array(
							__( 'Sun', 'ffcertificate' ),
							__( 'Mon', 'ffcertificate' ),
							__( 'Tue', 'ffcertificate' ),
							__( 'Wed', 'ffcertificate' ),
							__( 'Thu', 'ffcertificate' ),
							__( 'Fri', 'ffcertificate' ),
							__( 'Sat', 'ffcertificate' ),
						),
						'today'    => __( 'Today', 'ffcertificate' ),
					),
				),
			)
		);
	}

	/**
	 * Enqueue the "Move to form…" modal assets on the submissions list page.
	 *
	 * Only fires when exactly one `filter_form_id` is set in the query, since
	 * that's the only state in which the bulk action surfaces in the list
	 * table. Localizes the available forms (other than the current filter)
	 * so the modal's <select> can render without an extra round-trip.
	 *
	 * @return void
	 */
	/**
	 * Enqueue the inline bulk-actions JS on the Submissions list. Handles
	 * trash / restore / delete from both the WP-list-table bulk form and
	 * the per-row buttons — `move_to_form` keeps its own dedicated modal
	 * flow (see {@see enqueue_move_submissions_assets()}).
	 */
	private function enqueue_submissions_bulk_assets(): void {
		$s = \FreeFormCertificate\Core\AssetHelper::asset_suffix();

		wp_enqueue_script(
			'ffc-core',
			FFC_PLUGIN_URL . "assets/js/ffc-core{$s}.js",
			array( 'jquery' ),
			FFC_VERSION,
			true
		);
		wp_enqueue_script(
			'ffc-admin-submissions-bulk',
			FFC_PLUGIN_URL . "assets/js/ffc-admin-submissions-bulk{$s}.js",
			array( 'jquery', 'ffc-core', 'ffc-admin-js' ),
			FFC_VERSION,
			true
		);
		wp_localize_script(
			'ffc-admin-submissions-bulk',
			'ffcSubmissionsBulk',
			array(
				'nonce'   => wp_create_nonce( \FreeFormCertificate\Admin\SubmissionsBulkActionsAjaxEndpoint::AJAX_ACTION ),
				'strings' => array(
					'error'             => __( 'Action failed.', 'ffcertificate' ),
					'confirmDelete'     => __( 'Permanently delete this submission?', 'ffcertificate' ),
					'confirmBulkDelete' => __( 'Permanently delete the selected submissions? This cannot be undone.', 'ffcertificate' ),
				),
			)
		);
	}

	/**
	 * Enqueue the "Move to form…" modal assets on the Submissions list
	 * (only when the list is filtered by a single source form so the
	 * conflict-detection scope is well-defined).
	 */
	private function enqueue_move_submissions_assets(): void {
		$source_form_id = $this->resolve_single_filter_form_id();
		if ( $source_form_id <= 0 ) {
			return;
		}

		$s = \FreeFormCertificate\Core\AssetHelper::asset_suffix();

		wp_enqueue_style(
			'ffc-admin-move-submissions',
			FFC_PLUGIN_URL . "assets/css/ffc-admin-move-submissions{$s}.css",
			array(),
			FFC_VERSION
		);

		wp_enqueue_script(
			'ffc-admin-move-submissions',
			FFC_PLUGIN_URL . "assets/js/ffc-admin-move-submissions{$s}.js",
			array( 'jquery' ),
			FFC_VERSION,
			true
		);

		$forms = get_posts(
			array(
				'post_type'        => 'ffc_form',
				'post_status'      => array( 'publish', 'draft', 'pending', 'private' ),
				'numberposts'      => -1,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'exclude'          => array( $source_form_id ),
				'suppress_filters' => false,
			)
		);

		$form_options = array();
		foreach ( $forms as $form_post ) {
			$form_options[] = array(
				'id'    => (int) $form_post->ID,
				'title' => (string) $form_post->post_title,
			);
		}

		wp_localize_script(
			'ffc-admin-move-submissions',
			'ffcMoveSubmissions',
			array(
				'sourceFormId' => $source_form_id,
				'forms'        => $form_options,
				'strings'      => array(
					'modalTitle'   => __( 'Move submissions to another form', 'ffcertificate' ),
					'modalIntro'   => __( 'Choose the target form. Submissions whose CPF, RF, e-mail, or user already exist in the target will be kept in the original form and reported back.', 'ffcertificate' ),
					'targetLabel'  => __( 'Target form', 'ffcertificate' ),
					'placeholder'  => __( '— Select a form —', 'ffcertificate' ),
					'cancel'       => __( 'Cancel', 'ffcertificate' ),
					'confirm'      => __( 'Move submissions', 'ffcertificate' ),
					'noSelection'  => __( 'Please select a target form.', 'ffcertificate' ),
					'noRowsPicked' => __( 'Please select at least one submission.', 'ffcertificate' ),
				),
			)
		);
	}

	/**
	 * Resolve the single `filter_form_id` from the query, if present.
	 *
	 * Returns 0 when missing, blank, or set to multiple values — the
	 * "Move to form…" UI is only valid against a single source form.
	 *
	 * @return int Source form ID, or 0 when not applicable.
	 */
	private function resolve_single_filter_form_id(): int {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display-only filter routing.
		if ( empty( $_GET['filter_form_id'] ) ) {
			return 0;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- type/length guards below.
		$raw = wp_unslash( $_GET['filter_form_id'] );
		if ( is_array( $raw ) ) {
			return 1 === count( $raw ) ? absint( reset( $raw ) ) : 0;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		return absint( $raw );
	}

	/**
	 * Check if current page is the submissions list page (no edit/action subpath).
	 *
	 * @return bool
	 */
	private function is_submissions_list_page(): bool {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Page routing check for asset loading.
		if ( ! isset( $_GET['page'] ) ) {
			return false;
		}
		if ( sanitize_key( wp_unslash( $_GET['page'] ) ) !== 'ffc-submissions' ) {
			return false;
		}
		// Skip the edit subpage (handled by is_submission_edit_page()).
		if ( isset( $_GET['action'] ) && sanitize_key( wp_unslash( $_GET['action'] ) ) === 'edit' ) {
			return false;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		return true;
	}

	/**
	 * Enqueue submission edit page specific assets
	 */
	private function enqueue_submission_edit_assets(): void {
		$s = \FreeFormCertificate\Core\AssetHelper::asset_suffix();

		// Edit page CSS.
		wp_enqueue_style(
			'ffc-admin-submission-edit',
			FFC_PLUGIN_URL . "assets/css/ffc-admin-submission-edit{$s}.css",
			array( 'ffc-admin-css' ),
			FFC_VERSION
		);

		// Edit page JS.
		wp_enqueue_script(
			'ffc-admin-submission-edit',
			FFC_PLUGIN_URL . "assets/js/ffc-admin-submission-edit{$s}.js",
			array( 'jquery' ),
			FFC_VERSION,
			true
		);

		// Localize edit script.
		wp_localize_script(
			'ffc-admin-submission-edit',
			'ffc_submission_edit',
			array(
				'copied_text'      => __( 'Copied!', 'ffcertificate' ),
				'search_min_chars' => __( 'Please enter at least 2 characters.', 'ffcertificate' ),
				'no_users_found'   => __( 'No users found.', 'ffcertificate' ),
				'search_error'     => __( 'Error searching for users. Please try again.', 'ffcertificate' ),
				'clear_selection'  => __( 'Clear', 'ffcertificate' ),
				'reveal_error'     => __( 'Unable to reveal this value.', 'ffcertificate' ),
			)
		);
	}

	/**
	 * Check if current page is submission edit page
	 *
	 * @return bool
	 */
	private function is_submission_edit_page(): bool {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Page routing check for asset loading.
		return isset( $_GET['page'] )
			&& sanitize_key( wp_unslash( $_GET['page'] ) ) === 'ffc-submissions'
			&& isset( $_GET['action'] )
			&& sanitize_key( wp_unslash( $_GET['action'] ) ) === 'edit';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
	}
}
