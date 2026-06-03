<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\UserDashboard\CapabilityCatalog;
use FreeFormCertificate\UserDashboard\CapabilityManager;

/**
 * Tests for CapabilityCatalog — the human-facing metadata catalog that
 * drives the per-user capability UI.
 *
 * The load-bearing test is the invariant that the catalog covers exactly
 * the registry ({@see CapabilityManager::get_all_capabilities()}): the
 * render path and the save path both derive from the catalog, so any drift
 * would silently drop a capability from the UI (and, before the fix, from
 * the save loop's grant set).
 *
 * @covers \FreeFormCertificate\UserDashboard\CapabilityCatalog
 */
class CapabilityCatalogTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_catalog_covers_exactly_the_registry(): void {
		$catalog  = CapabilityCatalog::all_slugs();
		$registry = CapabilityManager::get_all_capabilities();

		sort( $catalog );
		sort( $registry );

		// Surfaces both directions: a registry cap missing from the catalog
		// (would vanish from the UI) and a catalog entry with no registry
		// backing (a typo / stale slug).
		$this->assertSame(
			$registry,
			$catalog,
			'CapabilityCatalog must list exactly the capabilities in CapabilityManager::get_all_capabilities().'
		);
	}

	public function test_no_duplicate_slugs(): void {
		$slugs = CapabilityCatalog::all_slugs();
		$this->assertSame( array_values( array_unique( $slugs ) ), $slugs );
	}

	public function test_every_cap_has_label_and_description(): void {
		foreach ( CapabilityCatalog::groups() as $group ) {
			foreach ( $group['caps'] as $slug => $meta ) {
				$this->assertNotEmpty( $meta['label'], "Missing label for {$slug}" );
				$this->assertNotEmpty( $meta['description'], "Missing description for {$slug}" );
			}
		}
	}

	public function test_groups_have_valid_level(): void {
		foreach ( CapabilityCatalog::groups() as $group ) {
			$this->assertContains( $group['level'], array( 'user', 'admin' ), "Bad level for group {$group['key']}" );
			$this->assertNotEmpty( $group['label'] );
			$this->assertNotEmpty( $group['caps'] );
		}
	}

	public function test_get_returns_metadata_with_group_for_known_slug(): void {
		$meta = CapabilityCatalog::get( 'ffc_view_recruitment_pii' );

		$this->assertNotNull( $meta );
		$this->assertSame( CapabilityCatalog::GROUP_ADMIN_RECRUITMENT, $meta['group'] );
		$this->assertNotEmpty( $meta['label'] );
		$this->assertNotEmpty( $meta['description'] );
	}

	public function test_get_returns_null_for_unknown_slug(): void {
		$this->assertNull( CapabilityCatalog::get( 'ffc_not_a_real_cap' ) );
	}
}
