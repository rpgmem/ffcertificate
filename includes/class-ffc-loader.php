<?php
/**
 * Loader v3.0.0
 * Fixed textdomain loading + REST API integration
 *
 * @package FreeFormCertificate
 * @version 4.0.0 - Removed alias usage (Phase 4)
 * @version 3.3.0 - Added strict types and type hints
 * @version 3.2.0 - Migrated to namespace (Phase 2) - Removed require_once (autoloader handles)
 */

declare(strict_types=1);

namespace FreeFormCertificate;

use FreeFormCertificate\Submissions\SubmissionHandler;
use FreeFormCertificate\Integrations\EmailHandler;
use FreeFormCertificate\Admin\AdminLoader;
use FreeFormCertificate\Admin\CPT;
use FreeFormCertificate\Frontend\Frontend;
use FreeFormCertificate\API\RestController;
use FreeFormCertificate\Shortcodes\DashboardShortcode;
use FreeFormCertificate\UserDashboard\AccessControl;
use FreeFormCertificate\UserDashboard\UserCleanup;
use FreeFormCertificate\SelfScheduling\SelfSchedulingLoader;
use FreeFormCertificate\Audience\AudienceLoader;
use FreeFormCertificate\Privacy\PrivacyHandler;
use FreeFormCertificate\Core\ActivityLogSubscriber;
use FreeFormCertificate\Reregistration\ReregistrationLoader;
use FreeFormCertificate\Reregistration\ReregistrationRepository;
use FreeFormCertificate\Reregistration\ReregistrationEmailHandler;
use FreeFormCertificate\UrlShortener\UrlShortenerActivator;
use FreeFormCertificate\UrlShortener\UrlShortenerLoader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loader for plugin module.
 */
class Loader {

	/**
	 * Submission handler.
	 *
	 * @var \FreeFormCertificate\Submissions\SubmissionHandler
	 */
	protected $submission_handler;
	/**
	 * Email handler.
	 *
	 * @var \FreeFormCertificate\Integrations\EmailHandler
	 */
	protected $email_handler;
	/**
	 * Admin module loader.
	 *
	 * @var \FreeFormCertificate\Admin\AdminLoader|null
	 */
	protected $admin_loader;
	/**
	 * Cpt.
	 *
	 * @var \FreeFormCertificate\Admin\CPT
	 */
	protected $cpt;
	/**
	 * Frontend.
	 *
	 * @var \FreeFormCertificate\Frontend\Frontend
	 */
	protected $frontend;
	/**
	 * Self-Scheduling module loader.
	 *
	 * @var \FreeFormCertificate\SelfScheduling\SelfSchedulingLoader|null
	 */
	protected $self_scheduling_loader;
	/**
	 * Audience loader.
	 *
	 * @var \FreeFormCertificate\Audience\AudienceLoader
	 */
	protected $audience_loader;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'plugins_loaded', array( $this, 'init_plugin' ), 10 );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_frontend_assets' ) );
		// Mirror the frontend register_frontend_assets pattern in admin.
		// `ffc-core` carries `window.FFC.request()` and is used by several
		// admin enqueues (URL Shortener metabox, etc.). Pre-6.6.9 it was
		// only registered when AdminAssetsManager decided the current page
		// was an FFC page (post_type === 'ffc_form' OR page === 'ffc-*').
		// On regular post/page admin screens where the URL Shortener
		// metabox is rendered, that gate failed and the dep silently
		// dropped, leaving `window.FFC` undefined and the QR download /
		// regenerate / copy buttons inert. Registering early on every
		// admin request makes `ffc-core` resolvable as a dep regardless
		// of post type. Enqueue happens elsewhere; this is register-only.
		add_action( 'admin_enqueue_scripts', array( $this, 'register_admin_core_assets' ), 1 );
		$this->define_activation_hooks();
	}

	/**
	 * Initialize plugin.
	 */
	public function init_plugin(): void {
		// Ensure submissions table schema is current (runs add_columns on version change).
		\FreeFormCertificate\Activator::maybe_add_columns();

		// 6.5.0: idx_created on candidate / notice / reregistration_submissions
		// for ORDER BY created_at queries (issue #144 S1). Idempotent — gated
		// on FFC_VERSION so the ALTER TABLE runs once per release.
		\FreeFormCertificate\Activator::maybe_add_perf_indexes();

		// 6.6.0: `submission_date` DATETIME → unix UTC BIGINT (#249 sub-escopo a).
		// Idempotent — gated on a one-shot option flag.
		\FreeFormCertificate\Activator::maybe_migrate_submission_date_to_unix();

		// 6.6.0: `submitted_at` (ffc_reregistration_submissions) DATETIME → unix UTC
		// BIGINT NULL (#249 sub-escopo b). Idempotent — option-flag gated.
		\FreeFormCertificate\Activator::maybe_migrate_submitted_at_to_unix();

		// 6.6.0: sibling instant columns (#249 sub-escopo d) — consent_date,
		// edited_at, reviewed_at, cancelled_at × 2, approved_at, reminder_sent_at.
		// Idempotent — option-flag gated.
		\FreeFormCertificate\Activator::maybe_migrate_sibling_instants_to_unix();

		// Ensure rate-limit tables (incl. ffc_device_signals added in 6.3.0) exist
		// even after in-place plugin updates that bypass register_activation_hook.
		if ( class_exists( '\FreeFormCertificate\Security\RateLimitActivator' ) ) {
			\FreeFormCertificate\Security\RateLimitActivator::maybe_create_tables();
		}

		// Ensure activity_log schema is healed. Installs created in a
		// pre-`action`-column plugin version skipped dbDelta on every
		// upgrade because create_table() used to early-return on
		// `table_exists`. Without this, PreflightStatsService queries
		// fail with `Unknown column 'action'`.
		if ( class_exists( '\FreeFormCertificate\Core\ActivityLog' ) ) {
			\FreeFormCertificate\Core\ActivityLog::maybe_create_table();
		}

		// In-place plugin updates (drop new files via `wp-admin/plugins.php`'s
		// "Update" button) DO NOT fire `register_activation_hook`. Re-register
		// the canonical FFC role + the 6.2.0 module-manager / recruitment-tier
		// roles so they survive upgrades that bumped the role catalog without
		// a full deactivate/reactivate cycle.
		//
		// Hooked on `init` priority 1 (NOT `plugins_loaded`) because
		// `register_role()` / `register_module_roles()` call `__()` for the
		// translated role labels, and WordPress 6.7+ emits a "translation
		// loading … too early" notice when text-domain translations are
		// resolved before `init` fires. Running at `init:1` is still very
		// early but after WP loads the textdomain.
		add_action( 'init', array( $this, 'register_ffc_roles_safe' ), 1 );

		if ( class_exists( '\FreeFormCertificate\SelfScheduling\SelfSchedulingActivator' ) ) {
			\FreeFormCertificate\SelfScheduling\SelfSchedulingActivator::maybe_migrate();
		}
		if ( class_exists( '\FreeFormCertificate\Audience\AudienceActivator' ) ) {
			\FreeFormCertificate\Audience\AudienceActivator::maybe_migrate();
		}
		if ( class_exists( UrlShortenerActivator::class ) ) {
			UrlShortenerActivator::maybe_migrate();
		}

		// Shared classes (needed in both admin and frontend contexts).
		$this->submission_handler = new SubmissionHandler();
		$this->email_handler      = new EmailHandler();
		$this->cpt                = new CPT();

		// Admin-only classes skipped on frontend.
		if ( is_admin() ) {
			// Admin module — single bootstrap entry point (#563 B3): wires
			// every admin-only Admin\… class behind one symbol instead of
			// newing-up ~20 classes here. Mirrors AudienceLoader/RecruitmentLoader.
			$this->admin_loader = new AdminLoader( $this->submission_handler );
			$this->admin_loader->init();
		}

		// Frontend + AJAX classes.
		$this->frontend = new Frontend( $this->submission_handler );

		DashboardShortcode::init();
		// Reregistration module — single bootstrap entry point (#563 B3).
		( new ReregistrationLoader() )->init();
		// UserDashboard has no module loader by design (#563 B3): these two
		// init() calls are its only bootstrap wiring. Its larger
		// Root→UserDashboard surface is capability/role lifecycle
		// (register_ffc_roles_safe() / ensure_*_caps() below) — orchestrator
		// responsibility, not module bootstrap, so a loader would narrow
		// nothing. See CLAUDE.md "Module bootstrap (per-module loaders)".
		AccessControl::init();
		UserCleanup::init();
		PrivacyHandler::init();

		// Self-Scheduling module — single bootstrap entry point (#563 B3).
		$this->self_scheduling_loader = new SelfSchedulingLoader();
		$this->self_scheduling_loader->init();

		$this->audience_loader = AudienceLoader::get_instance();
		$this->audience_loader->init();

		// URL Shortener module (v5.1.0).
		if ( class_exists( UrlShortenerLoader::class ) ) {
			$url_shortener = new UrlShortenerLoader();
			$url_shortener->init();
		}

		// Recruitment module (v6.0.0).
		if ( class_exists( '\FreeFormCertificate\Recruitment\RecruitmentLoader' ) ) {
			$recruitment_loader = new \FreeFormCertificate\Recruitment\RecruitmentLoader();
			$recruitment_loader->init();
		}

		new ActivityLogSubscriber();

		// Ensure daily cleanup cron is scheduled.
		if ( ! wp_next_scheduled( 'ffcertificate_daily_cleanup_hook' ) ) {
			wp_schedule_event( time(), 'daily', 'ffcertificate_daily_cleanup_hook' );
		}

		// Ensure reregistration expiry cron is scheduled.
		if ( ! wp_next_scheduled( 'ffcertificate_reregistration_expire_hook' ) ) {
			wp_schedule_event( time(), 'daily', 'ffcertificate_reregistration_expire_hook' );
		}

		// Ensure the self-scheduling appointment-reminder scan cron is scheduled
		// (hourly, since reminders fire N hours before an appointment) (#650).
		if ( ! wp_next_scheduled( \FreeFormCertificate\SelfScheduling\AppointmentReminderScanner::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'hourly', \FreeFormCertificate\SelfScheduling\AppointmentReminderScanner::CRON_HOOK );
		}

		$this->ensure_admin_capabilities();
		$this->ensure_legacy_caps_renamed();
		$this->ensure_taxonomy_renamed();
		$this->ensure_delete_caps_granted();
		$this->ensure_export_caps_granted();
		$this->ensure_import_caps_granted();
		$this->ensure_reasons_caps_wired();
		$this->ensure_settings_split_caps_granted();
		$this->define_admin_hooks();
		$this->init_rest_api();
	}

	/**
	 * Register the FFC user-side role + the 6.2.0 module-manager /
	 * recruitment-tier roles. Hooked on `init` priority 1 from
	 * `init_plugin()` because the role labels are translated via
	 * `__( …, 'ffcertificate' )` and WP 6.7+ emits a notice when
	 * translation lookups happen before the `init` hook fires.
	 *
	 * Idempotent: existing roles are upgraded with any newly
	 * introduced caps; manually granted caps are preserved.
	 *
	 * @since 6.2.0
	 * @return void
	 */
	public function register_ffc_roles_safe(): void {
		if ( class_exists( '\FreeFormCertificate\UserDashboard\CapabilityManager' ) ) {
			\FreeFormCertificate\UserDashboard\RoleRegistrar::register_role();
			\FreeFormCertificate\UserDashboard\RoleRegistrar::register_module_roles();

			// Re-apply translated labels every time WP loads its roles.
			// Without this, `users.php` would show the verbatim English
			// label that was stored in the database at registration time.
			// See `RoleRegistrar::relabel_ffc_roles()` for full
			// rationale.
			add_action( 'wp_roles_init', array( '\FreeFormCertificate\UserDashboard\RoleRegistrar', 'relabel_ffc_roles' ) );
		}
	}

	/**
	 * One-time migration that renames the three legacy certificate caps
	 * (`view_own_certificates`, `download_own_certificates`,
	 * `view_certificate_history`) to their `ffc_*` namespaced equivalents.
	 *
	 * Idempotent + version-flagged via the `ffc_legacy_caps_renamed_v1`
	 * option. Runs on `plugins_loaded` so in-place plugin updates trigger
	 * the rewrite without needing a deactivate/reactivate cycle.
	 *
	 * @since 6.2.0
	 */
	private function ensure_legacy_caps_renamed(): void {
		$flag = 'ffc_legacy_caps_renamed_v1';
		if ( '1' === get_option( $flag, '' ) ) {
			return;
		}
		if ( class_exists( '\FreeFormCertificate\UserDashboard\CapabilityManager' ) ) {
			\FreeFormCertificate\UserDashboard\CapabilityMigrator::migrate_legacy_certificate_caps();
		}
		update_option( $flag, '1', true );
	}

	/**
	 * One-time migration that renames capabilities to the plugin-wide naming
	 * standard (`ffc_<action>_[own_]<domain>[_<qualifier>]`) across every user
	 * and role. Idempotent + version-flagged via `ffc_taxonomy_caps_renamed_v1`.
	 *
	 * Separate flag from the 4.5.0 rename migration: one of its pairs
	 * (`ffc_view_own_appointments` → `ffc_view_own_appointments`) reverses that
	 * historical rename, so the two must never share a completion flag.
	 *
	 * @since 6.9.0
	 */
	private function ensure_taxonomy_renamed(): void {
		$flag = 'ffc_taxonomy_caps_renamed_v1';
		if ( '1' === get_option( $flag, '' ) ) {
			return;
		}
		if ( class_exists( '\FreeFormCertificate\UserDashboard\CapabilityManager' ) ) {
			\FreeFormCertificate\UserDashboard\CapabilityMigrator::migrate_taxonomy_renames();
		}
		update_option( $flag, '1', true );
	}

	/**
	 * One-time migration that seeds the destructive `ffc_delete_<domain>` caps
	 * (GAP E) onto every user/role already holding the matching
	 * `ffc_manage_<domain>` cap, preserving current delete behavior when the
	 * delete tier is split out of `manage`. Idempotent + version-flagged via
	 * `ffc_delete_caps_granted_v1`.
	 *
	 * @since 6.9.0
	 */
	private function ensure_delete_caps_granted(): void {
		$flag = 'ffc_delete_caps_granted_v1';
		if ( '1' === get_option( $flag, '' ) ) {
			return;
		}
		if ( class_exists( '\FreeFormCertificate\UserDashboard\CapabilityManager' ) ) {
			\FreeFormCertificate\UserDashboard\CapabilityMigrator::migrate_delete_caps_grant();
		}
		update_option( $flag, '1', true );
	}

	/**
	 * One-time migration that seeds the settings sub-caps (#711) —
	 * `ffc_manage_settings_smtp` + `ffc_manage_settings_dangerzone` — onto every
	 * user/role already holding `ffc_manage_settings`, preserving current SMTP /
	 * danger-zone behavior when those surfaces are split out of the blanket cap.
	 * Idempotent + version-flagged via `ffc_settings_split_caps_v1`.
	 *
	 * @since 6.15.0
	 */
	private function ensure_settings_split_caps_granted(): void {
		$flag = 'ffc_settings_split_caps_v1';
		if ( '1' === get_option( $flag, '' ) ) {
			return;
		}
		if ( class_exists( '\FreeFormCertificate\UserDashboard\CapabilityManager' ) ) {
			\FreeFormCertificate\UserDashboard\CapabilityMigrator::migrate_settings_split_caps_grant();
		}
		update_option( $flag, '1', true );
	}

	/**
	 * One-time migration that seeds the granular `ffc_export_<domain>` caps
	 * (GAP G) onto every user/role already holding the matching
	 * `ffc_manage_<domain>` cap, preserving current bulk-export behavior when the
	 * export tier is split out of `manage`. Idempotent + version-flagged via
	 * `ffc_export_caps_granted_v1`.
	 *
	 * @since 6.9.0
	 */
	private function ensure_export_caps_granted(): void {
		$flag = 'ffc_export_caps_granted_v1';
		if ( '1' === get_option( $flag, '' ) ) {
			return;
		}
		if ( class_exists( '\FreeFormCertificate\UserDashboard\CapabilityManager' ) ) {
			\FreeFormCertificate\UserDashboard\CapabilityMigrator::migrate_export_caps_grant();
		}
		update_option( $flag, '1', true );
	}

	/**
	 * One-time migration that seeds the granular import caps (GAP H) onto every
	 * user/role already holding the matching `manage` cap, preserving current
	 * bulk-import behavior when the import tier is enforced strictly (new
	 * `ffc_import_audiences`, and `ffc_import_recruitment` losing its umbrella
	 * fallback). Idempotent + version-flagged via `ffc_import_caps_granted_v1`.
	 *
	 * @since 6.9.0
	 */
	private function ensure_import_caps_granted(): void {
		$flag = 'ffc_import_caps_granted_v1';
		if ( '1' === get_option( $flag, '' ) ) {
			return;
		}
		if ( class_exists( '\FreeFormCertificate\UserDashboard\CapabilityManager' ) ) {
			\FreeFormCertificate\UserDashboard\CapabilityMigrator::migrate_import_caps_grant();
		}
		update_option( $flag, '1', true );
	}

	/**
	 * One-time migration that seeds the recruitment-reasons caps (GAP I) onto
	 * every user/role that already holds the matching source cap, so nobody
	 * loses read or edit access when the Reasons tab is carved onto its own
	 * strict 3-state tier. Idempotent + version-flagged via
	 * `ffc_reasons_caps_wired_v1`.
	 *
	 * @since 6.9.0
	 */
	private function ensure_reasons_caps_wired(): void {
		$flag = 'ffc_reasons_caps_wired_v1';
		if ( '1' === get_option( $flag, '' ) ) {
			return;
		}
		if ( class_exists( '\FreeFormCertificate\UserDashboard\CapabilityManager' ) ) {
			\FreeFormCertificate\UserDashboard\CapabilityMigrator::migrate_reasons_caps_grant();
		}
		update_option( $flag, '1', true );
	}

	/**
	 * Initialize REST API
	 *
	 * @since 3.0.0
	 */
	private function init_rest_api(): void {
		if ( class_exists( RestController::class ) ) {
			new RestController();
		}
	}

	/**
	 * Define activation hooks.
	 */
	private function define_activation_hooks(): void {
		// Autoloader handles class loading.
		register_activation_hook( FFC_PLUGIN_DIR . 'ffcertificate.php', array( '\\FreeFormCertificate\Activator', 'activate' ) );
		register_deactivation_hook( FFC_PLUGIN_DIR . 'ffcertificate.php', array( '\\FreeFormCertificate\Deactivator', 'deactivate' ) );
	}

	/**
	 * Ensure admin-level FFC capabilities are granted to the administrator role.
	 *
	 * Capabilities added in updates (e.g. ffc_manage_reregistration) are only
	 * granted during plugin activation.  For sites that update without
	 * reactivating, this one-time check fills the gap.
	 *
	 * @since 4.11.1
	 */
	private function ensure_admin_capabilities(): void {
		// v2: added cleanup of user-level false overrides for admin users.
		// v4: added the GAP E destructive `ffc_delete_*` caps — bumping the key
		// forces the administrator role to pick them up once even on installs
		// (e.g. the testes site) that don't change FFC_VERSION per batch.
		// v5: added the settings sub-caps `ffc_manage_settings_smtp` +
		// `ffc_manage_settings_dangerzone` (#711) — same one-time re-grant.
		$version_key = 'ffc_admin_caps_version_v5';
		$current     = get_option( $version_key, '' );

		if ( FFC_VERSION === $current ) {
			return;
		}

		$admin_role = get_role( 'administrator' );
		if ( $admin_role && class_exists( '\FreeFormCertificate\UserDashboard\UserManager' ) ) {
			$all_ffc_caps = \FreeFormCertificate\UserDashboard\CapabilityManager::get_all_capabilities();

			// 1. Grant admin-level capabilities to the administrator role.
			foreach ( \FreeFormCertificate\UserDashboard\CapabilityManager::ADMIN_CAPABILITIES as $cap ) {
				if ( ! $admin_role->has_cap( $cap ) ) {
					$admin_role->add_cap( $cap, true );
				}
			}

			// 2. Clean up user-level overrides for admin users.
			// A previous bug in save_capability_fields() used add_cap(false)
			// which stored explicit denials in user_meta, overriding the role.
			$admins = get_users(
				array(
					'role'   => 'administrator',
					'fields' => 'ID',
				)
			);
			foreach ( $admins as $admin_id ) {
				$user = get_userdata( (int) $admin_id );
				if ( ! $user ) {
					continue;
				}
				foreach ( $all_ffc_caps as $cap ) {
					// Remove only explicit false values (user-level denials).
					if ( isset( $user->caps[ $cap ] ) && ! $user->caps[ $cap ] ) {
						$user->remove_cap( $cap );
					}
				}
			}

			// 3. Strip legacy `=> false` cap entries from the `ffc_user` role
			// itself. Pre-6.0.3 the role was registered with every FFC cap as
			// `=> false`, which broke multi-role users (admin + ffc_user) via
			// `array_merge()` capability resolution. Issue #86. Idempotent:
			// only removes caps that exist with the `false` value; per-user
			// `add_cap($cap, true)` user-meta grants are unaffected.
			$ffc_user_role = get_role( 'ffc_user' );
			if ( $ffc_user_role ) {
				foreach ( $all_ffc_caps as $cap ) {
					if ( isset( $ffc_user_role->capabilities[ $cap ] ) && false === $ffc_user_role->capabilities[ $cap ] ) {
						$ffc_user_role->remove_cap( $cap );
					}
				}
			}
		}

		update_option( $version_key, FFC_VERSION );
	}

	/**
	 * Define admin hooks.
	 */
	private function define_admin_hooks(): void {
		add_action(
			'ffcertificate_daily_cleanup_hook',
			function () {
				$this->submission_handler->run_data_cleanup();
			}
		);
		// 6.5.0: scrub stale CSV-export temp files + transient rows
		// left behind by users who abandoned an export mid-flight (#144 S6).
		// Wrapped in a void closure because cleanup_stale_export_jobs()
		// returns an int (count of reclaimed jobs) — useful for logging
		// or programmatic invocation, but action callbacks must return
		// void per PHPStan's `return.void` rule.
		add_action(
			'ffcertificate_daily_cleanup_hook',
			static function (): void {
				\FreeFormCertificate\Admin\CsvExporter::cleanup_stale_export_jobs();
			}
		);
		// #Item11: reap spent schedule-exception jti markers whose 30-min
		// expiry has lapsed. Returns the reaped count; wrapped void per
		// PHPStan's return.void rule on action callbacks.
		add_action(
			'ffcertificate_daily_cleanup_hook',
			static function (): void {
				\FreeFormCertificate\Frontend\ScheduleExceptionSession::cleanup_expired_consumed();
			}
		);
		add_action( 'ffcertificate_reregistration_expire_hook', array( ReregistrationRepository::class, 'expire_overdue' ) );
		add_action( 'ffcertificate_reregistration_expire_hook', array( ReregistrationEmailHandler::class, 'run_automated_reminders' ) );
		add_action( \FreeFormCertificate\SelfScheduling\AppointmentReminderScanner::CRON_HOOK, array( \FreeFormCertificate\SelfScheduling\AppointmentReminderScanner::class, 'run' ) );
	}

	/**
	 * Register frontend assets (scripts used as dependencies by shortcodes).
	 * Only registers -- actual enqueue happens when shortcodes load their dependencies.
	 */
	public function register_frontend_assets(): void {
		$s = \FreeFormCertificate\Core\AssetHelper::asset_suffix();
		wp_register_script( 'ffc-rate-limit', FFC_PLUGIN_URL . "assets/js/ffc-frontend-helpers{$s}.js", array( 'jquery' ), FFC_VERSION, true );

		// ffc-core ships `window.FFC.request()` (the AJAX helper used by the
		// JS files migrated in #277). The admin enqueues ffc-core directly;
		// public shortcodes (CSV download, etc.) need it registered here so
		// their own enqueue can list it as a dependency without re-declaring
		// the source path.
		wp_register_script( 'ffc-core', FFC_PLUGIN_URL . "assets/js/ffc-core{$s}.js", array( 'jquery' ), FFC_VERSION, true );
		wp_localize_script(
			'ffc-core',
			'ffcCoreConfig',
			array(
				'version' => FFC_VERSION,
			)
		);

		// Dynamic fragments: refresh captcha + nonces on cached pages (v4.12.0).
		wp_register_script( 'ffc-dynamic-fragments', FFC_PLUGIN_URL . "assets/js/ffc-dynamic-fragments{$s}.js", array(), FFC_VERSION, true );
		wp_localize_script(
			'ffc-dynamic-fragments',
			'ffcDynamic',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			)
		);
	}

	/**
	 * Register `ffc-core` on every admin request.
	 *
	 * Sibling of register_frontend_assets() for the admin side. Other
	 * admin enqueues (URL Shortener metabox, etc.) declare `ffc-core` as
	 * a dependency — when this script is not registered, WP silently
	 * drops the dep and `window.FFC.request()` becomes undefined at
	 * runtime. AdminAssetsManager already enqueues ffc-core on FFC
	 * pages (which also registers it); this hook covers the gap on
	 * regular post/page edit screens where the URL Shortener metabox
	 * is enabled for the post type but is_ffc_page() returns false.
	 *
	 * Hook priority 1 so the registration runs before any module's
	 * enqueue callback (default priority 10).
	 *
	 * @since 6.6.9
	 */
	public function register_admin_core_assets(): void {
		if ( wp_script_is( 'ffc-core', 'registered' ) ) {
			return;
		}

		$s = \FreeFormCertificate\Core\AssetHelper::asset_suffix();
		wp_register_script(
			'ffc-core',
			FFC_PLUGIN_URL . "assets/js/ffc-core{$s}.js",
			array( 'jquery' ),
			FFC_VERSION,
			true
		);
		wp_localize_script(
			'ffc-core',
			'ffcCoreConfig',
			array(
				'version' => FFC_VERSION,
			)
		);
	}
}
