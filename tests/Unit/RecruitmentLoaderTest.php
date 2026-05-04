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

	public function test_plugins_loaded_hooks_fire_in_priority_order_9_10_11(): void {
		// Capture every add_action call so we can pin the safety-net set
		// (create_tables@9, role@10, maybe_migrate@11) without coupling to
		// brain/monkey's expectation argument-matching quirks.
		$plugins_loaded = array();
		Functions\when( 'add_action' )->alias(
			static function ( $hook, $cb, $priority = 10, $accepted = 1 ) use ( &$plugins_loaded ): bool {
				if ( 'plugins_loaded' === $hook ) {
					$plugins_loaded[] = array(
						'priority' => $priority,
						'callback' => $cb,
					);
				}
				return true;
			}
		);

		( new RecruitmentLoader() )->init();

		$priorities = array_column( $plugins_loaded, 'priority' );

		// All three priority slots are occupied.
		$this->assertContains( 9, $priorities, 'create_tables must hook at priority 9' );
		$this->assertContains( 10, $priorities, 'role self-heal must hook at priority 10' );
		$this->assertContains( 11, $priorities, 'maybe_migrate must hook at priority 11' );

		// Verify create_tables + maybe_migrate are wired to the right callables
		// (the role registration is a closure so we just verify a closure is
		// hooked at priority 10).
		$by_priority = array();
		foreach ( $plugins_loaded as $entry ) {
			$by_priority[ $entry['priority'] ] = $entry['callback'];
		}
		$this->assertSame( array( RecruitmentActivator::class, 'create_tables' ), $by_priority[9] );
		$this->assertSame( array( RecruitmentActivator::class, 'maybe_migrate' ), $by_priority[11] );
		$this->assertInstanceOf( \Closure::class, $by_priority[10] );
	}
}
