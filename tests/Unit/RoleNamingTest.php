<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\PluginAreas;
use FreeFormCertificate\UserDashboard\CapabilityManager;

/**
 * One naming pattern and four tiers in every module (#1860).
 *
 * The role labels named modules by internal terms ("Appointments",
 * "Audiences") instead of the menu, and the tiers differed per module: one
 * module had no Administrator, three had no roles at all. This holds every
 * role `CapabilityManager::module_roles_definition()` declares to:
 *
 * - a label `FFC <Module> [- <Part>] - <Tier>`, the module named as
 *   `PluginAreas` names it and the tier one of the four;
 * - the four tiers in every module, each holding the tier below it, except
 *   the two parts that keep Viewer and Manager only;
 * - the activity log and identity caps on `ffc_administrator` alone;
 * - no `_pii` cap on `ffc_readonly`.
 *
 * @covers \FreeFormCertificate\UserDashboard\CapabilityManager
 */
class RoleNamingTest extends TestCase {

	/**
	 * The two modules of the Scheduling area. `PluginAreas` names the AREA
	 * (one top-level menu); its roles are per module, and the menu calls the
	 * modules by these names (CLAUDE.md "Icons", the area's two modules).
	 */
	private const SCHEDULING_MODULES = array( 'Personal Calendars', 'Audience Calendars' );

	/**
	 * Modules whose screens split into parts, and those parts. A module named
	 * here names the part in every one of its roles.
	 */
	private const PARTS = array(
		'Certificates'       => array( 'Submissions', 'Forms' ),
		'Personal Calendars' => array( 'Bookings', 'Setup' ),
	);

	/**
	 * The two parts with no day-to-day or administrator action: Viewer and
	 * Manager only.
	 */
	private const TWO_TIER_PARTS = array( 'Certificates - Forms', 'Personal Calendars - Setup' );

	/**
	 * The roles outside the module pattern, with their labels.
	 */
	private const GLOBAL_ROLES = array(
		'ffc_administrator' => 'FFC Administrator',
		'ffc_readonly'      => 'FFC Read-only',
	);

	/**
	 * Tier label => slug suffix, lowest first.
	 */
	private const TIERS = array(
		'Viewer'        => 'viewer',
		'Operator'      => 'operator',
		'Manager'       => 'manager',
		'Administrator' => 'admin',
	);

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Every module name a role may carry: the `PluginAreas` names, with the
	 * Scheduling area read as its two modules.
	 *
	 * @return list<string>
	 */
	private function module_names(): array {
		$names = array();
		foreach ( array_keys( PluginAreas::all() ) as $area ) {
			if ( 'scheduling' === $area ) {
				foreach ( self::SCHEDULING_MODULES as $module ) {
					$names[] = $module;
				}
				continue;
			}
			$names[] = PluginAreas::label( $area );
		}
		return $names;
	}

	/**
	 * Parse a module role's label into its module, part and tier.
	 *
	 * @param string $label Role label.
	 * @return array{module: string, part: string, tier: string}|null Null when it fits no module.
	 */
	private function parse( string $label ): ?array {
		foreach ( $this->module_names() as $module ) {
			// "FFC Settings" already carries the prefix.
			$head = str_starts_with( $module, 'FFC ' ) ? $module : 'FFC ' . $module;
			if ( ! str_starts_with( $label, $head . ' - ' ) ) {
				continue;
			}
			$rest  = explode( ' - ', substr( $label, strlen( $head . ' - ' ) ) );
			$tier  = (string) array_pop( $rest );
			$part  = implode( ' - ', $rest );
			return array(
				'module' => $module,
				'part'   => $part,
				'tier'   => $tier,
			);
		}
		return null;
	}

	/**
	 * The module roles, grouped by `<Module>[ - <Part>]`, each group tier => slug.
	 *
	 * @return array<string, array<string, string>>
	 */
	private function groups(): array {
		$groups = array();
		foreach ( CapabilityManager::module_roles_definition() as $slug => $def ) {
			if ( isset( self::GLOBAL_ROLES[ $slug ] ) ) {
				continue;
			}
			$parsed = $this->parse( $def['label'] );
			$this->assertNotNull( $parsed, "{$slug}: \"{$def['label']}\" names no PluginAreas module." );
			$key = '' === $parsed['part'] ? $parsed['module'] : $parsed['module'] . ' - ' . $parsed['part'];
			$this->assertArrayNotHasKey( $parsed['tier'], $groups[ $key ] ?? array(), "Two roles are {$key} - {$parsed['tier']}." );
			$groups[ $key ][ $parsed['tier'] ] = $slug;
		}
		return $groups;
	}

	public function test_the_scan_reads_every_declared_role(): void {
		// An independent recount: the role labels as literals in the source,
		// against the definition the other tests read.
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/user-dashboard/class-ffc-capability-manager.php' );
		$this->assertGreaterThan( 0, preg_match_all( "/'label'\\s*=>\\s*__\\(\\s*'FFC /", $source ) );
		$this->assertSame(
			preg_match_all( "/'label'\\s*=>\\s*__\\(\\s*'FFC /", $source ),
			count( CapabilityManager::module_roles_definition() ),
			'The definition and the labels in its source disagree on how many roles there are.'
		);
		// And the modules the scan expects are the ones PluginAreas lists.
		$this->assertCount( count( PluginAreas::all() ) + count( self::SCHEDULING_MODULES ) - 1, $this->module_names() );
	}

	public function test_global_roles_keep_their_names(): void {
		$definition = CapabilityManager::module_roles_definition();
		foreach ( self::GLOBAL_ROLES as $slug => $label ) {
			$this->assertArrayHasKey( $slug, $definition );
			$this->assertSame( $label, $definition[ $slug ]['label'] );
		}
	}

	public function test_every_label_follows_the_pattern_and_its_slug(): void {
		foreach ( CapabilityManager::module_roles_definition() as $slug => $def ) {
			if ( isset( self::GLOBAL_ROLES[ $slug ] ) ) {
				continue;
			}
			$parsed = $this->parse( $def['label'] );
			$this->assertNotNull( $parsed, "{$slug}: \"{$def['label']}\" names no PluginAreas module." );
			$this->assertArrayHasKey( $parsed['tier'], self::TIERS, "{$slug}: \"{$parsed['tier']}\" is not one of the four tiers." );
			$this->assertStringEndsWith( '_' . self::TIERS[ $parsed['tier'] ], $slug, "{$slug} is labelled {$parsed['tier']}." );

			$parts = self::PARTS[ $parsed['module'] ] ?? array();
			if ( array() === $parts ) {
				$this->assertSame( '', $parsed['part'], "{$slug}: {$parsed['module']} has no parts." );
			} else {
				$this->assertContains( $parsed['part'], $parts, "{$slug}: every {$parsed['module']} role names its part." );
			}
		}
	}

	public function test_every_module_has_its_tiers(): void {
		$groups   = $this->groups();
		$expected = array();
		foreach ( $this->module_names() as $module ) {
			foreach ( self::PARTS[ $module ] ?? array( '' ) as $part ) {
				$expected[] = '' === $part ? $module : $module . ' - ' . $part;
			}
		}
		sort( $expected );
		$found = array_keys( $groups );
		sort( $found );
		$this->assertSame( $expected, $found, 'Every module (and part) has roles, and no role names another.' );

		foreach ( $groups as $key => $tiers ) {
			$want = in_array( $key, self::TWO_TIER_PARTS, true ) ? array( 'Viewer', 'Manager' ) : array_keys( self::TIERS );
			$have = array_keys( array_intersect_key( self::TIERS, $tiers ) );
			$this->assertSame( $want, $have, "{$key} has the wrong tiers." );
			// One slug stem per group: the tiers are one ladder.
			$stems = array_unique(
				array_map(
					static fn( string $slug ): string => (string) preg_replace( '/_(viewer|operator|manager|admin)$/', '', $slug ),
					$tiers
				)
			);
			$this->assertCount( 1, $stems, "{$key} mixes slug families: " . implode( ', ', $tiers ) );
		}
	}

	public function test_each_tier_holds_the_tier_below_it(): void {
		$definition = CapabilityManager::module_roles_definition();
		foreach ( $this->groups() as $key => $tiers ) {
			$below = null;
			foreach ( array_keys( self::TIERS ) as $tier ) {
				if ( ! isset( $tiers[ $tier ] ) ) {
					continue;
				}
				$caps = $definition[ $tiers[ $tier ] ]['caps'];
				if ( null !== $below ) {
					$this->assertSame( array(), array_values( array_diff( $below, $caps ) ), "{$key} - {$tier} lacks caps of the tier below it." );
				}
				$below = $caps;
			}
		}
	}

	public function test_activity_log_and_identities_are_the_administrators_alone(): void {
		foreach ( CapabilityManager::module_roles_definition() as $slug => $def ) {
			$held = array_values( array_intersect( CapabilityManager::ADMINISTRATOR_ONLY_CAPABILITIES, $def['caps'] ) );
			if ( 'ffc_administrator' === $slug ) {
				$this->assertSame( CapabilityManager::ADMINISTRATOR_ONLY_CAPABILITIES, $held );
				continue;
			}
			$this->assertSame( array(), $held, "{$slug} holds an FFC-Administrator-only capability." );
		}
		// The list is the caps it names, registered.
		foreach ( CapabilityManager::ADMINISTRATOR_ONLY_CAPABILITIES as $cap ) {
			$this->assertContains( $cap, CapabilityManager::get_all_capabilities() );
		}
	}

	public function test_readonly_sees_everything_masked(): void {
		$caps = CapabilityManager::module_roles_definition()['ffc_readonly']['caps'];
		$this->assertNotEmpty( $caps );
		foreach ( $caps as $cap ) {
			$this->assertStringStartsWith( 'ffc_view_', $cap, "ffc_readonly holds {$cap}, which is not a view capability." );
			$this->assertStringEndsNotWith( '_pii', $cap, "ffc_readonly holds {$cap}." );
		}
	}
}
