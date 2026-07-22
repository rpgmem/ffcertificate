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
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
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

	// ------------------------------------------------------------------
	// Per-module grouping
	// ------------------------------------------------------------------

	public function test_admin_caps_are_split_into_per_module_groups(): void {
		// The old single "Administration — Modules" bucket is gone; each admin
		// domain is now its own group.
		$this->assertSame( CapabilityCatalog::GROUP_ADMIN_CERTIFICATES, CapabilityCatalog::get( 'ffc_delete_certificates' )['group'] );
		$this->assertSame( CapabilityCatalog::GROUP_ADMIN_APPOINTMENTS, CapabilityCatalog::get( 'ffc_manage_appointments' )['group'] );
		$this->assertSame( CapabilityCatalog::GROUP_ADMIN_AUDIENCES, CapabilityCatalog::get( 'ffc_import_audiences' )['group'] );
		$this->assertSame( CapabilityCatalog::GROUP_ADMIN_REREGISTRATION, CapabilityCatalog::get( 'ffc_delete_reregistration' )['group'] );
		$this->assertSame( CapabilityCatalog::GROUP_ADMIN_CUSTOM_FIELDS, CapabilityCatalog::get( 'ffc_manage_custom_fields' )['group'] );
		$this->assertSame( CapabilityCatalog::GROUP_ADMIN_URL_SHORTENER, CapabilityCatalog::get( 'ffc_delete_url_shortener' )['group'] );
		$this->assertSame( CapabilityCatalog::GROUP_ADMIN_SETTINGS, CapabilityCatalog::get( 'ffc_manage_settings' )['group'] );
		$this->assertSame( CapabilityCatalog::GROUP_ADMIN_SYSTEM, CapabilityCatalog::get( 'ffc_view_activity_log' )['group'] );
	}

	public function test_self_service_groups_render_before_admin_groups(): void {
		$levels = array_map(
			static fn( $g ) => $g['level'],
			CapabilityCatalog::groups()
		);
		// All 'user' groups precede every 'admin' group (drives the section
		// divider + the self-service-first layout).
		$first_admin = array_search( 'admin', $levels, true );
		$last_user   = array_keys( $levels, 'user', true );
		$this->assertNotEmpty( $last_user );
		$this->assertLessThan( $first_admin, max( $last_user ) );
	}

	// ------------------------------------------------------------------
	// Surface tags + display helpers
	// ------------------------------------------------------------------

	public function test_surface_tags_only_on_the_exception_caps(): void {
		$tagged = array();
		foreach ( CapabilityCatalog::groups() as $group ) {
			foreach ( $group['caps'] as $slug => $meta ) {
				if ( ! empty( $meta['surface'] ) ) {
					$tagged[ $slug ] = $meta['surface'];
				}
			}
		}
		$this->assertSame(
			array(
				'ffc_bypass_appointments' => 'frontend',
				'ffc_view_forms_api'    => 'api',
			),
			$tagged
		);
	}

	public function test_surface_badge_html_renders_only_for_tagged_caps(): void {
		$api = CapabilityCatalog::surface_badge_html( array( 'surface' => 'api' ) );
		$this->assertStringContainsString( 'ffc-cap-badge-surface--api', $api );
		$this->assertStringContainsString( 'API', $api );

		// No surface key → empty string (no badge).
		$this->assertSame( '', CapabilityCatalog::surface_badge_html( array( 'label' => 'x' ) ) );
		// Unknown surface → empty string.
		$this->assertSame( '', CapabilityCatalog::surface_badge_html( array( 'surface' => 'bogus' ) ) );
	}

	public function test_level_section_label_maps_user_and_admin(): void {
		$this->assertSame( 'Self-service', CapabilityCatalog::level_section_label( 'user' ) );
		$this->assertSame( 'Administration', CapabilityCatalog::level_section_label( 'admin' ) );
	}
}
