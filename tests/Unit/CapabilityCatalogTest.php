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

		// `LabelSorter::locale()` is guarded by `function_exists( 'get_locale' )`,
		// so this used to take the no-WordPress branch only because nothing in
		// the process had defined that function yet — an accident of file
		// ordering, not a property this test asserts. Pin it to the same locale
		// the fallback produced, so the behaviour under test is chosen.
		Functions\when( 'get_locale' )->justReturn( 'en_US' );
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

	public function test_groups_are_alphabetical_by_label_within_each_section(): void {
		$by_level = array( 'user' => array(), 'admin' => array() );
		foreach ( CapabilityCatalog::groups() as $group ) {
			$by_level[ $group['level'] ][] = $group['label'];
		}

		foreach ( $by_level as $level => $labels ) {
			$sorted = $labels;
			usort( $sorted, 'strcasecmp' );
			$this->assertSame(
				$sorted,
				$labels,
				"The {$level} groups must render alphabetically by label."
			);
		}
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
				// Groups render by label: Audience Calendars sorts before
				// Personal Calendars - Bookings.
				'ffc_bypass_audiences'            => 'frontend',
				'ffc_bypass_appointments'         => 'frontend',
				'ffc_bypass_appointment_capacity' => 'frontend',
				'ffc_view_forms_api'              => 'api',
				'ffc_submit_forms_api'            => 'api',
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

	public function test_group_hue_shares_domain_across_user_and_admin(): void {
		// The end-user and admin groups of one domain resolve to one hue.
		$this->assertSame(
			CapabilityCatalog::group_hue( CapabilityCatalog::GROUP_CERTIFICATE ),
			CapabilityCatalog::group_hue( CapabilityCatalog::GROUP_ADMIN_CERTIFICATES )
		);
		$this->assertSame( 'certificates', CapabilityCatalog::group_hue( CapabilityCatalog::GROUP_ADMIN_CERTIFICATES ) );
		$this->assertSame( 'appointments', CapabilityCatalog::group_hue( CapabilityCatalog::GROUP_APPOINTMENT ) );
		$this->assertSame( 'forms', CapabilityCatalog::group_hue( CapabilityCatalog::GROUP_ADMIN_FORMS ) );
		$this->assertSame( 'custom_fields', CapabilityCatalog::group_hue( CapabilityCatalog::GROUP_ADMIN_CUSTOM_FIELDS ) );
		$this->assertSame( 'neutral', CapabilityCatalog::group_hue( 'not_a_group' ) );
	}

	public function test_every_group_resolves_a_non_empty_hue(): void {
		foreach ( CapabilityCatalog::groups() as $group ) {
			$hue = CapabilityCatalog::group_hue( (string) $group['key'] );
			$this->assertNotSame( '', $hue, 'Group ' . $group['key'] . ' has no hue.' );
			$this->assertNotSame( 'neutral', $hue, 'Cataloged group ' . $group['key'] . ' fell back to neutral.' );
		}
	}

	public function test_every_group_hue_is_painted_by_the_stylesheet(): void {
		// A hue the sheet does not declare renders no stripe at all: the
		// date-messages group shipped that way, since the check above only
		// proves the token is not `neutral`, never that a rule reads it.
		$css = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/css/ffc-user-permissions.css' );
		preg_match_all( '/\.ffc-cap-group\[data-ffc-hue="([a-z_]+)"\]\s*\{\s*--ffc-cap-hue:\s*(#[0-9a-f]{6})\s*;/', $css, $m );
		$declared = array_combine( $m[1], $m[2] );
		$this->assertArrayHasKey( 'neutral', $declared, 'The hue scan read nothing; the selector shape changed.' );

		$used = array();
		foreach ( CapabilityCatalog::groups() as $group ) {
			$hue = CapabilityCatalog::group_hue( (string) $group['key'] );
			$this->assertArrayHasKey( $hue, $declared, 'Group ' . $group['key'] . ' has hue "' . $hue . '" but ffc-user-permissions.css paints none.' );
			$used[ $hue ] = $declared[ $hue ];
		}
		// Categorical: two domains sharing a colour defeats the scale.
		$this->assertSame( count( $declared ), count( array_unique( $declared ) ), 'Two hues share one colour.' );
		$this->assertNotEmpty( $used );
	}

	public function test_cap_tier_derives_action_and_promotes_pii(): void {
		$this->assertSame( 'view', CapabilityCatalog::cap_tier( 'ffc_view_certificates' ) );
		$this->assertSame( 'manage', CapabilityCatalog::cap_tier( 'ffc_manage_settings_smtp' ) );
		$this->assertSame( 'delete', CapabilityCatalog::cap_tier( 'ffc_delete_certificates' ) );
		$this->assertSame( 'bypass', CapabilityCatalog::cap_tier( 'ffc_bypass_appointments' ) );
		// A write cap must not fall back to the faintest tier (#1861).
		$this->assertSame( 'submit', CapabilityCatalog::cap_tier( 'ffc_submit_forms_api' ) );
		// `test` is a flow verb of its own (#1860).
		$this->assertSame( 'test', CapabilityCatalog::cap_tier( 'ffc_test_date_messages' ) );
		$this->assertSame( 'test', CapabilityCatalog::cap_tier( 'ffc_test_settings_smtp' ) );
		// The _pii qualifier is promoted to its own reveal tier.
		$this->assertSame( 'reveal', CapabilityCatalog::cap_tier( 'ffc_view_certificates_pii' ) );
		$this->assertSame( 'reveal', CapabilityCatalog::cap_tier( 'ffc_view_appointments_pii' ) );
		// Unknown action → the faintest, safest tier.
		$this->assertSame( 'view', CapabilityCatalog::cap_tier( 'ffc_frobnicate_widgets' ) );
	}

	public function test_every_cataloged_cap_resolves_a_known_tier(): void {
		$known = array( 'view', 'reveal', 'book', 'submit', 'test', 'cancel', 'download', 'call', 'checkin', 'grade', 'send', 'export', 'import', 'edit', 'manage', 'bypass', 'delete', 'purge', 'merge', 'split' );
		foreach ( CapabilityCatalog::all_slugs() as $slug ) {
			$this->assertContains( CapabilityCatalog::cap_tier( $slug ), $known, $slug . ' resolved an unknown tier.' );
		}
	}

	public function test_every_catalog_verb_has_a_tier_of_its_own(): void {
		// A verb the catalog uses must not fall back to `view`, the faintest
		// tier: `ffc_purge_appointments` read as a viewer's cap (#2340).
		foreach ( CapabilityCatalog::all_slugs() as $slug ) {
			$verb = explode( '_', $slug )[1] ?? '';
			if ( 'view' === $verb ) {
				continue;
			}
			$this->assertNotSame( 'view', CapabilityCatalog::cap_tier( $slug ), $slug . ' falls back to the view tier.' );
		}
		foreach ( array( 'purge', 'merge', 'split', 'send', 'checkin', 'grade' ) as $verb ) {
			$this->assertSame( $verb, CapabilityCatalog::cap_tier( 'ffc_' . $verb . '_things' ) );
		}
	}

	public function test_granting_asks_first_for_personal_data_destruction_and_the_activity_log(): void {
		// An export sits in the Personal data band: it takes records out in bulk.
		foreach ( array( 'ffc_view_certificates_pii', 'ffc_export_certificates', 'ffc_delete_certificates', 'ffc_purge_appointments', 'ffc_merge_identities', 'ffc_view_activity_log', 'ffc_export_activity_log' ) as $slug ) {
			$this->assertTrue( CapabilityCatalog::needs_grant_confirmation( $slug ), $slug );
		}
		foreach ( array( 'ffc_view_certificates', 'ffc_manage_certificates', 'ffc_bypass_appointments', 'ffc_book_appointments' ) as $slug ) {
			$this->assertFalse( CapabilityCatalog::needs_grant_confirmation( $slug ), $slug );
		}
	}

	public function test_the_tier_is_written_out_beside_the_name(): void {
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		$this->assertSame( '<span class="ffc-cap-tier ffc-cap-tier-destroy">Destructive</span>', CapabilityCatalog::tier_chip( 'ffc_purge_appointments' ) );
		$this->assertSame( '<span class="ffc-cap-tier ffc-cap-tier-data">Personal data</span>', CapabilityCatalog::tier_chip( 'ffc_view_certificates_pii' ) );
		$this->assertSame( '<span class="ffc-cap-tier ffc-cap-tier-read">Read</span>', CapabilityCatalog::tier_chip( 'ffc_view_forms' ) );
		$this->assertSame( 'override', CapabilityCatalog::tier_band( 'bypass' )['band'] );
		$this->assertSame( 'act', CapabilityCatalog::tier_band( 'checkin' )['band'] );
		$this->assertSame( 'change', CapabilityCatalog::tier_band( 'manage' )['band'] );
		$this->assertSame( 'read', CapabilityCatalog::tier_band( 'unknown' )['band'], 'An unknown tier reads as the safest band.' );
	}
}
