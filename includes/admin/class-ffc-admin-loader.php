<?php
/**
 * Admin module loader.
 *
 * Single bootstrap entry point for the Admin module — wires every admin-only
 * `Admin\…` class behind one call so the plugin orchestrator (`Loader`) touches
 * a single symbol (`AdminLoader`) instead of newing-up ~20 Admin classes
 * directly. Mirrors the per-module loader pattern already used by
 * `AudienceLoader` / `RecruitmentLoader` / `UrlShortenerLoader`, and narrows
 * the `Root → Admin` dependency surface (#563 B3 coupling reduction).
 *
 * `init()` must be called only in the `is_admin()` context, exactly as the
 * inline block it replaces was guarded.
 *
 * @package FreeFormCertificate\Admin
 * @since   6.12.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

use FreeFormCertificate\Submissions\SubmissionHandler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bootstraps the admin-only surface of the Admin module.
 */
class AdminLoader {

	/**
	 * Shared submission handler, injected from the orchestrator.
	 *
	 * @var SubmissionHandler
	 */
	private SubmissionHandler $submission_handler;

	/**
	 * CSV exporter — held to keep the instance alive for its hooks.
	 *
	 * @var CsvExporter|null
	 */
	protected ?CsvExporter $csv_exporter = null;

	/**
	 * Admin screens controller — held to keep the instance alive for its hooks.
	 *
	 * @var Admin|null
	 */
	protected ?Admin $admin = null;

	/**
	 * Admin AJAX controller — held to keep the instance alive for its hooks.
	 *
	 * @var AdminAjax|null
	 */
	protected ?AdminAjax $admin_ajax = null;

	/**
	 * Inject the shared submission handler the Admin screens depend on.
	 *
	 * @param SubmissionHandler $submission_handler Shared handler the Admin screens depend on.
	 */
	public function __construct( SubmissionHandler $submission_handler ) {
		$this->submission_handler = $submission_handler;
	}

	/**
	 * Wire every admin-only Admin-module class. Call only when `is_admin()`.
	 *
	 * Order matches the previous inline sequence in `Loader::init_plugin()`
	 * exactly — behavior-preserving.
	 *
	 * @return void
	 */
	public function init(): void {
		$this->csv_exporter = new CsvExporter();
		$this->admin        = new Admin( $this->submission_handler, $this->csv_exporter );
		$this->admin_ajax   = new AdminAjax();

		AdminUserColumns::init();
		AdminUserCapabilities::init();
		RoleCapabilityEditor::init();
		AdminMenuVisibility::init();
		DeviceThresholdUpgradeNotice::init();
		SettingsAjaxEndpoint::init();
		FormMetaAjaxEndpoint::init();
		LocationsAjaxEndpoint::init();
		CacheActionsAjaxEndpoint::init();
		FormFeaturesAjaxEndpoint::init();
		MigrationActionsAjaxEndpoint::init();
		ActivityLogAjaxEndpoint::init();
		SubmissionsBulkActionsAjaxEndpoint::init();
		ExpiredTicketsCleanup::init();
		FormListColumns::init();
		AdminUserCustomFields::init();
	}
}
