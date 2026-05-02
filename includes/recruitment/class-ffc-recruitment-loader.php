<?php
/**
 * Recruitment Loader
 *
 * Boot orchestrator for the recruitment module. Hooks `rest_api_init` to
 * register the REST controller (sprint 9.1). Future sprints extend this
 * loader: 9.2 wires the Settings tab, 11 registers the public shortcode,
 * 12 hooks the admin pages.
 *
 * The recruitment activator (sprint 1.1) and the cap/role registration
 * (sprint 3) run on plugin activation only — they don't need to live
 * here.
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.0.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Recruitment;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Recruitment module loader.
 *
 * Registers WP hooks. Each hook is justified inline:
 *
 *   - `rest_api_init` (priority 10) — canonical hook for REST registration.
 *     Default priority because no ordering constraint vs other plugins.
 */
final class RecruitmentLoader {

	/**
	 * Boot the module.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ), 10 );
		add_action( 'admin_init', array( $this, 'register_settings' ), 10 );
		add_action( 'init', array( $this, 'register_shortcode' ), 10 );
		add_action( 'init', array( $this, 'register_dashboard_section' ), 10 );
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ), 10 );
		RecruitmentAdminAssetsManager::register();
		RecruitmentNoticeEditPage::register();
		RecruitmentCandidateEditPage::register();

		// Idempotent schema migrations (option-versioned). 6.1.0 adds the
		// `active` → `definitive` enum rename; future steps append.
		add_action( 'plugins_loaded', array( RecruitmentActivator::class, 'maybe_migrate' ), 11 );
	}

	/**
	 * Instantiate and register REST routes.
	 *
	 * @return void
	 */
	public function register_rest_routes(): void {
		$controller = new RecruitmentRestController();
		$controller->register_routes();
	}

	/**
	 * Register the recruitment settings option with the WP Settings API.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		RecruitmentSettings::register();
	}

	/**
	 * Register the public shortcode `[ffc_recruitment_queue]`.
	 *
	 * @return void
	 */
	public function register_shortcode(): void {
		RecruitmentPublicShortcode::register();
	}

	/**
	 * Register the candidate-self dashboard section shortcode
	 * `[ffc_recruitment_my_calls]`. Meant to live on the user-dashboard
	 * page alongside `[user_dashboard_personal]`.
	 *
	 * @return void
	 */
	public function register_dashboard_section(): void {
		RecruitmentDashboardSection::register();
	}

	/**
	 * Register the wp-admin "Recrutamento" submenu page.
	 *
	 * @return void
	 */
	public function register_admin_menu(): void {
		RecruitmentAdminPage::register_menu();
	}
}
