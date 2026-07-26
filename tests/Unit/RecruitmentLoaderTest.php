<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Brain\Monkey\Actions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Recruitment\RecruitmentLoader;

/**
 * Tests for RecruitmentLoader's bootstrap wiring.
 *
 * The schema self-heal (`create_tables` / `maybe_migrate`) and the
 * recruitment-manager role registration are orchestrator-level lifecycle and
 * were relocated to `Loader::init_plugin()` / `Loader::register_ffc_roles_safe()`
 * so the Modules-tab toggle can skip this feature bootstrap without dropping the
 * recruitment tables or role. This test pins that relocation — the loader must
 * NOT re-register those lifecycle hooks — and confirms the remaining feature
 * bootstrap (rest_api_init, admin/init hooks) still wires.
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentLoader
 */
class RecruitmentLoaderTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_loader_does_not_reregister_relocated_lifecycle_hooks(): void {
		// Capture every add_action call so we can assert the relocated lifecycle
		// (create_tables@plugins_loaded:9, maybe_migrate@plugins_loaded:11,
		// role@init:1) is NO LONGER wired by the loader — it now lives in the
		// orchestrator so a disabled recruitment module keeps its schema + role.
		$plugins_loaded = array();
		$init_hooks     = array();
		Functions\when( 'add_action' )->alias(
			static function ( $hook, $cb, $priority = 10, $accepted = 1 ) use ( &$plugins_loaded, &$init_hooks ): bool {
				if ( 'plugins_loaded' === $hook ) {
					$plugins_loaded[] = array(
						'priority' => $priority,
						'callback' => $cb,
					);
				}
				if ( 'init' === $hook ) {
					$init_hooks[] = array(
						'priority' => $priority,
						'callback' => $cb,
					);
				}
				return true;
			}
		);

		( new RecruitmentLoader() )->init();

		// No plugins_loaded activator hooks remain on the loader.
		$this->assertSame( array(), $plugins_loaded, 'Loader must not hook create_tables/maybe_migrate on plugins_loaded — relocated to the orchestrator.' );

		// The role self-heal (init:1) is gone from the loader; only the feature
		// bootstrap init hooks (shortcode + dashboard, priority 10) remain.
		$init_priorities = array_column( $init_hooks, 'priority' );
		$this->assertNotContains( 1, $init_priorities, 'Role self-heal (init:1) must not be re-registered by the loader.' );
		$this->assertContains( 10, $init_priorities, 'Feature bootstrap init hooks (shortcode/dashboard) must still wire.' );
	}
}
