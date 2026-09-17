<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\UserDashboard\CapabilityManager;

/**
 * What `uninstall.php` must know about capabilities and roles (#1290).
 *
 * The defect this replaces: capability removal intersected a hand-written list
 * with whatever a user held, and the list named **31 of the 60** live
 * capabilities, so 40 survived uninstall on every install. The sharpest case
 * was `ffc_import_recruitment`, live and unremoved, sitting beside
 * `ffc_import_recruitment_csv` — its own pre-rename slug, dead since
 * `CapabilityMigrator::taxonomy_cap_renames()` retired it — which *was* listed.
 * The dead name was cleaned up and the live one was not.
 *
 * **The fix deleted the list rather than guarding it.** Removal is now a prefix
 * sweep, which cannot fall behind a registry, so there is nothing left to keep
 * in sync — and a guard comparing a 60-name declaration against the registry
 * that defines it would have been circular: the list's only remaining consumer
 * would have been the test asserting it equals its own source.
 *
 * What a sweep does need is its **precondition**, and that is what this file
 * asserts: every capability the plugin creates starts with `ffc_`, so the
 * sweep reaches it. That is one assertion instead of sixty names, it catches a
 * mistyped prefix, and it cannot go stale.
 *
 * Roles are the other half and they are NOT swept — `remove_role()` deletes a
 * role wholesale, so sweeping by prefix would delete an `ffc_`-named role this
 * plugin did not create. They are removed by name, which means the list IS
 * load-bearing there, which means it can fall behind exactly the way the
 * capability list did. Hence the last test.
 *
 * What none of this sees is whether the sweep RUNS: these are lists compared
 * with lists. The behaviour is proven where a database exists — the
 * `fresh-install` job grants a live capability, a retired one and a synthetic
 * `ffc_` name no list has ever contained, then asserts the footprint is zero
 * after a real uninstall. The synthetic one is the mutation: only a prefix
 * sweep can remove it.
 *
 * @covers \FreeFormCertificate\UserDashboard\CapabilityManager
 */
class UninstallCapabilitySweepTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// pcov attribution preload (CLAUDE.md pcov gotcha).
		class_exists( '\FreeFormCertificate\UserDashboard\CapabilityManager' );

		require_once dirname( __DIR__, 2 ) . '/.github/scripts/ffc-uninstall-manifest.php';

		// `LabelSorter::locale()` is guarded by `function_exists( 'get_locale' )`,
		// so the branch it takes depends on whether an earlier test in the
		// process defined that function. Pin it, as the sibling catalogue test
		// does.
		Functions\when( 'get_locale' )->justReturn( 'en_US' );
		Functions\when( '__' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function uninstall_file(): string {
		return dirname( __DIR__, 2 ) . '/uninstall.php';
	}

	private function uninstall_source(): string {
		return (string) file_get_contents( $this->uninstall_file() );
	}

	/**
	 * Role slugs `uninstall.php` deletes.
	 *
	 * Read out of the `remove_role()` loop rather than out of the whole file:
	 * the file also names roles in prose, and a comment mentioning one would
	 * otherwise read as a role that is removed.
	 *
	 * @return list<string>
	 */
	private function removed_roles(): array {
		if ( ! preg_match( '/foreach \(\s*array\((.*?)\n\t\) as \$ffc_legacy_role/s', $this->uninstall_source(), $block ) ) {
			return array();
		}
		if ( ! preg_match_all( "/'(ffc_[a-z0-9_]+)'/", $block[1], $m ) ) {
			return array();
		}

		return array_values( array_unique( $m[1] ) );
	}

	/**
	 * Self-check: an empty scan must fail rather than read as clean.
	 *
	 * The #1071 / #1094 rule every guard here carries. Without it, a renamed
	 * variable in `uninstall.php` would turn this whole file green.
	 */
	public function test_the_scans_find_something_at_all(): void {
		$this->assertFileExists( $this->uninstall_file() );

		$this->assertNotEmpty(
			CapabilityManager::get_all_capabilities(),
			'The registry resolved to nothing, so every comparison below would be vacuous.'
		);

		$this->assertNotEmpty(
			ffc_manifest_legacy_capabilities( $this->uninstall_file() ),
			'The unprefixed-capability list did not parse — fix the parser, do not delete the list.'
		);

		$this->assertGreaterThan(
			10,
			count( $this->removed_roles() ),
			'The role-removal loop did not parse.'
		);
	}

	/**
	 * The sweep's precondition, and the whole reason the 60-name list could go.
	 */
	public function test_every_live_capability_carries_the_prefix_the_sweep_removes(): void {
		$unreachable = array();

		foreach ( CapabilityManager::get_all_capabilities() as $cap ) {
			if ( 0 !== strpos( $cap, 'ffc_' ) ) {
				$unreachable[] = $cap;
			}
		}

		$this->assertSame(
			array(),
			$unreachable,
			'uninstall.php removes capabilities by the `ffc_` prefix, so one named without it would outlive the plugin on every install. Rename it — the naming grammar in CLAUDE.md requires the prefix anyway — or add it to $ffcertificate_legacy_caps with the reason.'
		);
	}

	/**
	 * The unprefixed list is for what the sweep cannot reach, and nothing else.
	 *
	 * A prefixed name here is dead weight the sweep already removes, and a LIVE
	 * name here would say the plugin still creates a capability it has retired.
	 */
	public function test_the_unprefixed_list_holds_only_what_the_sweep_cannot_reach(): void {
		$live    = CapabilityManager::get_all_capabilities();
		$legacy  = ffc_manifest_legacy_capabilities( $this->uninstall_file() );
		$strays  = array();

		foreach ( $legacy as $cap ) {
			if ( 0 === strpos( $cap, 'ffc_' ) ) {
				$strays[] = $cap . ' (prefixed — the sweep already removes it)';
			}
			if ( in_array( $cap, $live, true ) ) {
				$strays[] = $cap . ' (still live — this list is for retired names)';
			}
		}

		$this->assertSame( array(), $strays );
	}

	/**
	 * Each retired name says what replaced it.
	 *
	 * Without this the list is three words nobody can date or justify, and the
	 * first reader who cannot find them in the codebase deletes them — which is
	 * precisely the install these entries exist for.
	 */
	public function test_each_retired_name_names_its_replacement(): void {
		$source    = $this->uninstall_source();
		$unexplained = array();

		foreach ( ffc_manifest_legacy_capabilities( $this->uninstall_file() ) as $cap ) {
			if ( ! preg_match( "/'" . preg_quote( $cap, '/' ) . "',\s*\/\/\s*(ffc_[a-z0-9_]+)/", $source ) ) {
				$unexplained[] = $cap;
			}
		}

		$this->assertSame(
			array(),
			$unexplained,
			'Follow each entry with `// <the capability that replaced it>`.'
		);
	}

	/**
	 * Roles are removed BY NAME, so that list can fall behind — the same defect
	 * the capability sweep was written to make impossible.
	 *
	 * It is checked in one direction only. A role in `uninstall.php` that the
	 * plugin no longer registers is a deliberate cleanup for installs upgraded
	 * through a rename (`ffc_operator`, `ffc_certificate_manager`, …) and must
	 * stay; a role the plugin registers and never removes is a leftover
	 * definition in `wp_user_roles` on every install.
	 */
	public function test_every_role_the_plugin_registers_is_removed_on_uninstall(): void {
		$registered = array_keys( CapabilityManager::module_roles_definition() );
		$registered[] = 'ffc_end_user';
		$registered[] = CapabilityManager::RECRUITMENT_MANAGER_ROLE;

		$missing = array_values( array_diff( $registered, $this->removed_roles() ) );

		$this->assertSame(
			array(),
			$missing,
			'These roles survive uninstall as definitions in wp_user_roles. Add them to the remove_role() loop in uninstall.php.'
		);
	}
}
