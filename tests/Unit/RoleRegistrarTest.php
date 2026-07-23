<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\UserDashboard\RoleRegistrar;

/**
 * Tests for RoleRegistrar's managed-role label map and its canonical ordering.
 *
 * The order is consumed by both admin surfaces that list FFC roles (the
 * Blocked Roles checkboxes and the role → capability editor dropdown), so it is
 * pinned here: cross-cutting roles first, then module ladders alphabetically by
 * module name, each from least to most powerful.
 *
 * @covers \FreeFormCertificate\UserDashboard\RoleRegistrar
 */
class RoleRegistrarTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// pcov: preload so coverage attributes to the exercised classes.
		class_exists( '\FreeFormCertificate\UserDashboard\RoleRegistrar' );
		class_exists( '\FreeFormCertificate\UserDashboard\CapabilityManager' );
		// Translations resolve to the msgid in tests, so labels are English.
		Functions\when( '__' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_managed_role_labels_pins_cross_cutting_roles_first(): void {
		$slugs = array_keys( RoleRegistrar::ffc_managed_role_labels() );

		$this->assertSame(
			array( 'ffc_administrator', 'ffc_readonly', 'ffc_end_user' ),
			array_slice( $slugs, 0, 3 ),
			'Cross-cutting roles must lead: administrator → readonly → end_user.'
		);
	}

	public function test_managed_role_labels_sorts_modules_alphabetically_then_by_tier(): void {
		$labels = RoleRegistrar::ffc_managed_role_labels();
		$slugs  = array_keys( $labels );

		$tier_suffix = array(
			'_viewer'   => 0,
			'_operator' => 1,
			'_manager'  => 2,
			'_admin'    => 3,
		);

		$prev = null;
		foreach ( array_slice( $slugs, 3 ) as $slug ) {
			$module = (string) preg_replace( '/^FFC\s+| - .*$/u', '', $labels[ $slug ] );
			$tier   = 99;
			foreach ( $tier_suffix as $suffix => $rank ) {
				if ( str_ends_with( $slug, $suffix ) ) {
					$tier = $rank;
					break;
				}
			}
			$key = array( strtolower( $module ), $tier );
			if ( null !== $prev ) {
				$this->assertLessThanOrEqual(
					0,
					$prev <=> $key,
					"Role {$slug} breaks the (module, tier) ordering."
				);
			}
			$prev = $key;
		}
	}

	public function test_recruitment_manager_slots_into_the_recruitment_ladder(): void {
		$labels = RoleRegistrar::ffc_managed_role_labels();
		$slugs  = array_keys( $labels );

		$vi = (int) array_search( 'ffc_recruitment_viewer', $slugs, true );
		$op = (int) array_search( 'ffc_recruitment_operator', $slugs, true );
		$mg = (int) array_search( 'ffc_recruitment_manager', $slugs, true );
		$ad = (int) array_search( 'ffc_recruitment_admin', $slugs, true );

		$this->assertTrue(
			$vi < $op && $op < $mg && $mg < $ad,
			'Recruitment ladder order: viewer < operator < manager < admin.'
		);
		$this->assertSame( 'FFC Recruitment - Manager', $labels['ffc_recruitment_manager'] );
	}
}
