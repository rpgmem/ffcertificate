<?php
/**
 * Reregistration module loader.
 *
 * Single bootstrap entry point for the Reregistration module — wires the
 * module's runtime surface (admin screens, the frontend shortcode/AJAX, and
 * the standard-fields seeder) behind one call so the orchestrator (`Loader`)
 * touches a single symbol instead of three. Mirrors the per-module loader
 * pattern used by `AudienceLoader` / `RecruitmentLoader` / `AdminLoader`
 * (#563 B3 coupling reduction).
 *
 * Note: the cron-side wiring (`ReregistrationRepository::expire_overdue`,
 * `ReregistrationEmailHandler::run_automated_reminders`) stays in the
 * orchestrator — it is scheduled-event registration, not module bootstrap.
 *
 * @package FreeFormCertificate\Reregistration
 * @since   6.12.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Reregistration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bootstraps the Reregistration module.
 */
class ReregistrationLoader {

	/**
	 * Wire the module's admin + frontend surface.
	 *
	 * Order matches the previous inline sequence in `Loader::init_plugin()`:
	 * admin (when `is_admin()`) → frontend → standard-fields seeder.
	 *
	 * @return void
	 */
	public function init(): void {
		if ( is_admin() ) {
			( new ReregistrationAdmin() )->init();
		}

		ReregistrationFrontend::init();

		if ( class_exists( ReregistrationStandardFieldsSeeder::class ) ) {
			ReregistrationStandardFieldsSeeder::register();
		}
	}
}
