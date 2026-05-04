<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Brain\Monkey\Actions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Recruitment\RecruitmentLoader;
use FreeFormCertificate\Recruitment\RecruitmentActivator;

/**
 * Tests for RecruitmentLoader's plugins_loaded upgrade-safety wiring.
 *
 * In-place plugin updates DO NOT fire register_activation_hook, so the
 * recruitment module hooks `create_tables`, `register_recruitment_manager_role`,
 * and `maybe_migrate` on `plugins_loaded` to self-heal. This test pins those
 * hook registrations + their priority order (9 < 10 < 11) so future loader
 * refactors don't accidentally drop the safety net.
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

	public function test_plugins_loaded_hooks_create_tables_and_maybe_migrate(): void {
		// Capture every add_action call so we can pin the safety-net set
		// (create_tables@plugins_loaded:9, maybe_migrate@plugins_loaded:11,
		// role@init:1) without coupling to brain/monkey's expectation
		// argument-matching quirks.
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

		$plugins_loaded_priorities = array_column( $plugins_loaded, 'priority' );
		$this->assertContains( 9, $plugins_loaded_priorities, 'create_tables must hook plugins_loaded:9' );
		$this->assertContains( 11, $plugins_loaded_priorities, 'maybe_migrate must hook plugins_loaded:11' );

		$by_priority = array();
		foreach ( $plugins_loaded as $entry ) {
			$by_priority[ $entry['priority'] ] = $entry['callback'];
		}
		$this->assertSame( array( RecruitmentActivator::class, 'create_tables' ), $by_priority[9] );
		$this->assertSame( array( RecruitmentActivator::class, 'maybe_migrate' ), $by_priority[11] );

		// Role self-heal moved to init:1 in 6.2.x — the role label uses __()
		// so it can't run on plugins_loaded without tripping WP 6.7+'s
		// "translation loading … too early" notice.
		$init_priorities = array_column( $init_hooks, 'priority' );
		$this->assertContains( 1, $init_priorities, 'role self-heal must hook init:1' );
	}
}
