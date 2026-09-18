<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\UserDashboard\CapabilityCatalog;

/**
 * A documentation page that enumerates a capability group must enumerate all of
 * it (#1214 sprint 6).
 *
 * **The reference page is generated and cannot drift; the feature pages are
 * hand-written and did.** `reference-capabilities.php` renders
 * `CapabilityCatalog::groups()` at request time, so every capability is
 * documented there by construction. Ten other pages ALSO list slugs, in their
 * own words and against their own screen — "See the submissions list and the
 * PDF button (read-only)" is more use on that page than the catalogue's generic
 * sentence, which is why those lists are written by hand and why they stay.
 *
 * The cost of writing them by hand is that a new capability does not reach
 * them. Measured when this was written: three of the ten were missing one each
 * — `ffc_import_reregistration` (added in #1292), `ffc_view_appointments_pii`
 * and `ffc_view_certificates_pii` — and the last two are the ones deciding
 * whether a screen shows somebody's CPF, which is the wrong pair to leave
 * unmentioned.
 *
 * **Why the obvious guard was NOT written.** "Every slug in the catalogue
 * appears somewhere in the tab" is the rule that suggests itself, and it is
 * useless twice over: read against the rendered page it is vacuously true
 * forever, and read against the file text it fails today for twenty
 * capabilities whose only correct home is the generated page — so the way to
 * make it green would be to paste slugs into pages nobody asked to list them.
 * A guard whose satisfying move is worse than the defect is not a guard.
 *
 * What this checks instead is narrower and answerable: for the pages that DO
 * take on a group, the enumeration is complete. It sees presence, never
 * usefulness — a slug pasted with an empty description passes — so it is a
 * floor under the drift, not a substitute for writing the page.
 *
 * @covers \FreeFormCertificate\UserDashboard\CapabilityCatalog
 */
class DocumentationCapabilityCoverageTest extends TestCase {

	/**
	 * Documentation page => the catalogue groups it enumerates in full.
	 *
	 * A page is in this register because it presents itself as the list for
	 * those groups. Pages that merely MENTION a capability in passing are
	 * deliberately absent — `config-activity-log.php` names the two activity-log
	 * caps and has no business listing `ffc_view_as_user`, which shares their
	 * group; `developer-hooks-api.php` cites slugs as examples inside hook
	 * descriptions. Adding such a page here would force it to grow a list it
	 * was never for.
	 *
	 * The group keys come from `CapabilityCatalog`'s own constants rather than
	 * from a domain guessed out of the slug: an earlier version of this scan
	 * inferred the domain by string-matching and produced two false positives
	 * out of five findings, reporting end-user dashboard caps as missing from
	 * an admin screen's table.
	 *
	 * @var array<string, list<string>>
	 */
	private const PAGE_GROUPS = array(
		'submissions-list.php'         => array( CapabilityCatalog::GROUP_ADMIN_CERTIFICATES ),
		'feature-reregistration.php'   => array( CapabilityCatalog::GROUP_ADMIN_REREGISTRATION ),
		'feature-recruitment.php'      => array( CapabilityCatalog::GROUP_ADMIN_RECRUITMENT ),
		'feature-url-shortener.php'    => array( CapabilityCatalog::GROUP_ADMIN_URL_SHORTENER ),
		'feature-self-scheduling.php'  => array( CapabilityCatalog::GROUP_ADMIN_APPOINTMENTS, CapabilityCatalog::GROUP_APPOINTMENT ),
		'scheduling-audiences.php'     => array( CapabilityCatalog::GROUP_ADMIN_AUDIENCES, CapabilityCatalog::GROUP_AUDIENCE ),
	);

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// pcov attribution preload (CLAUDE.md pcov gotcha).
		class_exists( '\FreeFormCertificate\UserDashboard\CapabilityCatalog' );

		// `LabelSorter::locale()` is guarded by `function_exists( 'get_locale' )`,
		// so the branch it takes depends on whether an earlier test in the
		// process defined that function. Pin it, the way the sibling catalogue
		// test does.
		Functions\when( 'get_locale' )->justReturn( 'en_US' );
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Absolute path of the documentation directory.
	 *
	 * @return string
	 */
	private function docs_dir(): string {
		return dirname( __DIR__, 2 ) . '/includes/settings/views/documentation';
	}

	/**
	 * The capabilities of one catalogue group.
	 *
	 * @param string $group_key Group key.
	 * @return list<string>
	 */
	private function caps_of( string $group_key ): array {
		foreach ( CapabilityCatalog::groups() as $group ) {
			if ( ( $group['key'] ?? '' ) === $group_key ) {
				return array_keys( (array) ( $group['caps'] ?? array() ) );
			}
		}

		return array();
	}

	/**
	 * Self-check: an empty scan must fail rather than read as clean.
	 *
	 * The #1071 / #1094 rule every guard here carries. Without it, a renamed
	 * directory or a catalogue that stopped returning groups would turn this
	 * whole file green.
	 */
	public function test_the_scan_finds_the_pages_and_the_groups(): void {
		$this->assertDirectoryExists( $this->docs_dir() );

		foreach ( array_keys( self::PAGE_GROUPS ) as $page ) {
			$this->assertFileExists(
				$this->docs_dir() . '/' . $page,
				'A registered page is gone; move its entry or remove it deliberately.'
			);
		}

		foreach ( self::PAGE_GROUPS as $page => $groups ) {
			foreach ( $groups as $group_key ) {
				$this->assertNotEmpty(
					$this->caps_of( $group_key ),
					sprintf( 'Group %s resolved to no capabilities, so %s would be checked against nothing.', $group_key, $page )
				);
			}
		}
	}

	/**
	 * The guard itself.
	 *
	 * @dataProvider provide_pages
	 * @param string       $page   Documentation file name.
	 * @param list<string> $groups Groups it takes on.
	 */
	public function test_a_page_that_takes_on_a_group_lists_all_of_it( string $page, array $groups ): void {
		$source = (string) file_get_contents( $this->docs_dir() . '/' . $page );

		$expected = array();
		foreach ( $groups as $group_key ) {
			$expected = array_merge( $expected, $this->caps_of( $group_key ) );
		}

		$missing = array();
		foreach ( $expected as $cap ) {
			// Word-boundary on the right: `ffc_view_certificates` must not be
			// satisfied by `ffc_view_certificates_pii` sitting on the page.
			if ( 1 !== preg_match( '/' . preg_quote( $cap, '/' ) . '(?![a-z_])/', $source ) ) {
				$missing[] = $cap;
			}
		}

		$this->assertSame(
			array(),
			$missing,
			sprintf(
				'%s presents itself as the capability list for its screen, so a capability missing from it reads as not existing. Add it, or drop the page from PAGE_GROUPS if it is no longer that list.',
				$page
			)
		);
	}

	/** @return array<string, array{string, list<string>}> */
	public function provide_pages(): array {
		$cases = array();
		foreach ( self::PAGE_GROUPS as $page => $groups ) {
			$cases[ $page ] = array( $page, $groups );
		}

		return $cases;
	}
}
