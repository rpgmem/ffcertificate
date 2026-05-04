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
		RecruitmentReasonEditPage::register();

		// Idempotent schema migrations (option-versioned). 6.1.0 adds the
		// `active` → `definitive` enum rename; future steps append.
		//
		// In-place plugin updates (replacing files via `wp-admin/plugins.php`'s
		// "Update" button or by overwriting the directory) DO NOT fire the
		// `register_activation_hook` callback. Without the two hooks below,
		// installs upgrading from a pre-recruitment release (≤ 5.4.x) onto a
		// recruitment-bearing release (≥ 6.0.0) end up with a fully-loaded
		// recruitment module that has no tables and no manager role.
		//
		// Both `create_tables()` and `register_recruitment_manager_role()`
		// are idempotent — each individual table-create + role-register
		// short-circuits when the artifact already exists. Safe to call on
		// every `plugins_loaded` of every page load.
		add_action( 'plugins_loaded', array( RecruitmentActivator::class, 'create_tables' ), 9 );
		add_action(
			'plugins_loaded',
			static function (): void {
				if ( class_exists( '\FreeFormCertificate\UserDashboard\CapabilityManager' ) ) {
					\FreeFormCertificate\UserDashboard\CapabilityManager::register_recruitment_manager_role();
				}
			},
			10
		);
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
