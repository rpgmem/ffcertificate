<?php
/**
 * Wiring smoke for the plugin composition root.
 *
 * The unit `LoaderTest` deliberately excludes `Loader::init_plugin()` because
 * it "orchestrates dozens of concrete classes … it would require mocking the
 * entire plugin graph". This integration test covers exactly that gap: it boots
 * the REAL graph (every module loader newing-up its real runtime classes) over a
 * stubbed WordPress boundary, so a cross-module wiring break — a renamed class, a
 * changed constructor signature, a bad `use`, a loader that fatals on
 * construction — fails here instead of only in production. It is the first entry
 * in the previously-empty `tests/Integration/` layer (#809 Fase 3).
 *
 * @package FreeFormCertificate\Tests\Integration
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Integration;

use FreeFormCertificate\Loader;

/**
 * @coversNothing
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class PluginBootstrapSmokeTest extends IntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();
		// Steady-state boot: the one-shot cap/role migrations are covered by
		// their own unit tests; this smoke asserts bootstrap wiring only.
		$this->seed_completed_migration_flags();
	}

	/**
	 * Booting the whole graph (frontend context, every module enabled) composes
	 * without a fatal and lands its bootstrap-level hooks, crons and roles wiring.
	 */
	public function test_full_bootstrap_composes_and_wires(): void {
		// Empty options ⇒ SettingsReader::module_enabled() defaults to true for
		// every module, so all module loaders boot (maximum wiring coverage).
		$loader = new Loader();
		$loader->init_plugin();

		// The graph is large: booting it should register many hooks. A low
		// floor here just proves the fan-out happened at all (the specific
		// assertions below prove the important ones landed).
		$this->assertGreaterThan(
			15,
			count( $this->actions ) + count( $this->filters ),
			'Booting the full graph should register a substantial number of hooks.'
		);

		// Role registration is deferred to `init:1` (WP 6.7 too-early-translation
		// guard) — the bootstrap must register that hook, not run it inline.
		$this->assertActionRegistered(
			'init',
			'register_ffc_roles_safe',
			'Bootstrap must defer FFC role registration to the init hook.'
		);

		// The three always-on maintenance crons are scheduled when none exists yet.
		$this->assertContains(
			'ffcertificate_daily_cleanup_hook',
			$this->scheduled,
			'Daily cleanup cron should be scheduled on boot.'
		);
		$this->assertContains(
			'ffcertificate_reregistration_expire_hook',
			$this->scheduled,
			'Reregistration expiry cron should be scheduled on boot.'
		);
		$this->assertContains(
			\FreeFormCertificate\SelfScheduling\AppointmentReminderScanner::CRON_HOOK,
			$this->scheduled,
			'Appointment-reminder scan cron should be scheduled on boot.'
		);
	}

	/**
	 * Booting in the admin context additionally wires the always-on admin
	 * surface (Settings + AdminLoader newing-up its ~20 admin classes) without a
	 * fatal, and still lands the shared bootstrap hooks. This is the half of the
	 * graph the frontend boot skips.
	 */
	public function test_admin_context_bootstrap_composes_and_wires(): void {
		\Brain\Monkey\Functions\when( 'is_admin' )->justReturn( true );

		$loader = new Loader();
		$loader->init_plugin();

		$this->assertActionRegistered(
			'init',
			'register_ffc_roles_safe',
			'Admin bootstrap must still defer FFC role registration to init.'
		);
		$this->assertContains(
			'ffcertificate_daily_cleanup_hook',
			$this->scheduled,
			'Admin bootstrap still schedules the daily cleanup cron.'
		);

		// The admin surface registers at least one admin_menu hook (Settings and
		// the admin module both hang menus off it) — proof the admin-only branch
		// composed and reached its menu wiring.
		$this->assertActionRegistered(
			'admin_menu',
			null,
			'Admin bootstrap should register at least one admin_menu action.'
		);
	}

	/**
	 * Re-running the bootstrap after the crons already exist must not
	 * double-schedule them — the `wp_next_scheduled()` guard is respected.
	 */
	public function test_bootstrap_does_not_reschedule_existing_crons(): void {
		\Brain\Monkey\Functions\when( 'wp_next_scheduled' )->justReturn( 123456789 );

		$loader = new Loader();
		$loader->init_plugin();

		$this->assertSame(
			array(),
			$this->scheduled,
			'No cron should be scheduled when wp_next_scheduled() already reports one.'
		);
	}
}
