<?php
/**
 * Wiring smoke for the newer cross-cutting modules + the admin-ajax boot
 * context (#911, #809 Fase 3).
 *
 * {@see PluginBootstrapSmokeTest} proves the graph composes in the frontend and
 * admin steady-state contexts and lands its bootstrap-level hooks/crons/roles.
 * This test pins the specific wiring of the modules added most recently —
 * whose registration is easy to break silently — and the admin-ajax context
 * the bootstrap smoke doesn't exercise:
 *
 *   - CSV export dispatcher (#772): the unified `ffc_export_*` AJAX endpoints.
 *   - Client-IP resolver bridge (#899): the ClientIpResolver config filters.
 *   - Cloudflare CIDR refresh (#899/#901): its daily cron + hook.
 *   - Certificate-template pool (#865): the reusable-template CPT registration.
 *
 * Wiring only: real graph over the stubbed WordPress boundary, asserting the
 * recorded hooks/filters/crons, not behaviour (unit-tested per class).
 *
 * @package FreeFormCertificate\Tests\Integration
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Integration;

use Brain\Monkey\Functions;
use FreeFormCertificate\Loader;

/**
 * @coversNothing
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class ModuleWiringSmokeTest extends IntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();
		// Steady-state boot (the one-shot cap/role migrations are unit-tested).
		$this->seed_completed_migration_flags();
	}

	/** True if any recorded add_filter() targets $hook. */
	private function assertFilterRegistered( string $hook, string $message = '' ): void {
		$this->assertContains(
			$hook,
			array_column( $this->filters, 'hook' ),
			'' !== $message ? $message : "Expected an add_filter() for '{$hook}'."
		);
	}

	/**
	 * The unified CSV export dispatcher (#772) registers its `ffc_export_*` AJAX
	 * endpoints unconditionally so both priv and nopriv admin-ajax reach it.
	 */
	public function test_bootstrap_wires_csv_export_dispatcher(): void {
		( new Loader() )->init_plugin();

		$hooks = array_column( $this->actions, 'hook' );
		$this->assertContains( 'wp_ajax_ffc_export_start', $hooks );
		$this->assertContains( 'wp_ajax_ffc_export_batch', $hooks );
		$this->assertContains( 'wp_ajax_ffc_export_download', $hooks );
		// Anonymous public export uses the nopriv side too.
		$this->assertContains( 'wp_ajax_nopriv_ffc_export_start', $hooks );
	}

	/**
	 * The client-IP resolver bridge (#899) feeds ClientIpResolver's filters from
	 * stored config — registered unconditionally (IP resolution runs outside
	 * is_admin()).
	 */
	public function test_bootstrap_wires_ip_resolver_bridge(): void {
		( new Loader() )->init_plugin();

		$this->assertFilterRegistered( 'ffc_ip_resolver_mode' );
		$this->assertFilterRegistered( 'ffc_trusted_proxies' );
		$this->assertFilterRegistered( 'ffc_cloudflare_ip_ranges' );
		$this->assertFilterRegistered( 'ffc_ip_shadow_logging' );
	}

	/**
	 * The Cloudflare CIDR refresh (#899/#901) wires its cron hook and schedules
	 * the daily event when none exists yet.
	 */
	public function test_bootstrap_wires_and_schedules_cloudflare_cidr_refresh(): void {
		( new Loader() )->init_plugin();

		$cron_hook = \FreeFormCertificate\Integrations\CloudflareCidrRefresh::CRON_HOOK;
		$this->assertContains( $cron_hook, array_column( $this->actions, 'hook' ),
			'CloudflareCidrRefresh must register its cron hook.' );
		$this->assertContains( $cron_hook, $this->scheduled,
			'CloudflareCidrRefresh must schedule its daily event on boot.' );
	}

	/**
	 * The certificate-template pool (#865) registers its reusable-template CPT
	 * on init (the CPT itself is context-agnostic; only its seeder is admin-only).
	 */
	public function test_bootstrap_registers_cert_template_cpt(): void {
		( new Loader() )->init_plugin();

		$registered = false;
		foreach ( $this->actions as $action ) {
			if (
				'init' === $action['hook']
				&& is_array( $action['callback'] )
				&& isset( $action['callback'][0] )
				&& is_object( $action['callback'][0] )
				&& $action['callback'][0] instanceof \FreeFormCertificate\Admin\CertTemplateCpt
			) {
				$registered = true;
				break;
			}
		}
		$this->assertTrue( $registered, 'CertTemplateCpt must register its post type on init.' );
	}

	/**
	 * The admin-ajax context (is_admin + wp_doing_ajax) composes the full graph
	 * without a fatal and still reaches the export-dispatcher AJAX wiring — the
	 * surface an export request actually hits. This is the boot context the
	 * bootstrap smoke (frontend + admin steady-state) doesn't cover.
	 */
	public function test_admin_ajax_context_composes_and_wires_export_endpoints(): void {
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'wp_doing_ajax' )->justReturn( true );

		( new Loader() )->init_plugin();

		// The bootstrap still defers role registration to init…
		$this->assertActionRegistered( 'init', 'register_ffc_roles_safe' );
		// …and the export dispatcher's AJAX endpoints are wired for the request.
		$this->assertContains( 'wp_ajax_ffc_export_start', array_column( $this->actions, 'hook' ),
			'Admin-ajax boot must reach the CSV export dispatcher wiring.' );
	}
}
