<?php
/**
 * The `== Upgrade Notice ==` section of `readme.txt` (#1340).
 *
 * WHY THIS GUARD EXISTS
 *
 * The section had been there for three years and reached nobody. `compat()`
 * reads `readme.txt` through `get_file_data()`, which sees HEADERS and not
 * sections, so nothing in the plugin ever opened the body -- an operator
 * deciding whether to apply an update saw a version number and nothing else.
 * It had also fallen three releases behind (last entry 6.23.0 against a
 * shipped 6.26.0), and the gaps before that -- 6.23.0, then 6.20.0, then
 * 6.11.0 -- say it was never systematic, only remembered.
 *
 * Writing the entry is now a step of the release PR. This is what stops that
 * step from being skipped in silence, the way it was skipped before.
 *
 * WHY EXACTLY ONE ENTRY
 *
 * `GithubUpdater::get_latest_release()` fetches `releases/latest`, so the only
 * version this plugin ever offers is the newest. An entry for an older release
 * is text no code path can display. Holding one entry also stops `readme.txt`
 * becoming a second changelog, which its own `== Changelog ==` section records
 * as a drift problem already solved once by deleting the mirror.
 *
 * IT CALLS THE RUNTIME PARSER RATHER THAN MIRRORING IT
 *
 * The sibling guard `GitHubUpdaterCompatTest` re-implements `get_file_data()`
 * closely enough for a header. That is safe for a one-line field and would not
 * be safe here: a guard with its own regex can approve a section shape the
 * updater cannot read, and the failure would be invisible -- the update screen
 * simply shows nothing. So the shape assertions below run against
 * `GithubUpdater::upgrade_notice()` itself, through reflection, which makes
 * "what CI approved" and "what WordPress will display" the same string by
 * construction. This is the reason `.github/scripts/ffc-create-statements.php`
 * and `tests/Support/CssSelectors.php` are shared, applied to a private method
 * instead of a helper.
 *
 * WHAT IT CANNOT SEE
 *
 * Whether the summary is TRUE. Nobody can assert from source text that the
 * release does what the sentence claims, the same limit `GitHubUpdaterCompatTest`
 * states about `Tested up to`. It checks the shape, the version agreement and
 * the length; a wrong summary of the right length passes.
 *
 * @package FreeFormCertificate\Tests\Unit
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * @coversNothing
 */
final class ReadmeUpgradeNoticeTest extends TestCase {

	/**
	 * The WordPress.org norm for this field.
	 *
	 * Not enforced on us by anybody -- this plugin updates from GitHub and is
	 * not in the directory -- and adopted anyway, so publishing there later
	 * never means rewriting entries. It is a target in the mould of the
	 * CHANGELOG's own 300-character bullet aim.
	 *
	 * @var int
	 */
	private const MAX_CHARS = 300;

	/**
	 * Absolute path to the repository root.
	 */
	private static function root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * The `readme.txt` source.
	 */
	private static function readme(): string {
		$contents = file_get_contents( self::root() . '/readme.txt' );

		return is_string( $contents ) ? $contents : '';
	}

	/**
	 * The section body, from the opening heading to the next `== ` heading.
	 */
	private static function section(): string {
		preg_match( '/^==\s*Upgrade Notice\s*==\s*$(.*?)(?=^==\s|\z)/ms', self::readme(), $m );

		return $m[1] ?? '';
	}

	/**
	 * The versions the section declares, in file order.
	 *
	 * @return array<int, string>
	 */
	private static function versions(): array {
		preg_match_all( '/^=\s*(\S+)\s*=\s*$/m', self::section(), $m );

		return $m[1] ?? array();
	}

	/**
	 * What the updater will actually hand WordPress.
	 */
	private static function runtime_notice(): string {
		$method = new ReflectionMethod( '\FreeFormCertificate\Integrations\GithubUpdater', 'upgrade_notice' );
		$method->setAccessible( true );

		return (string) $method->invoke( null );
	}

	/**
	 * Self-check: an empty scan must fail rather than read as clean.
	 *
	 * The #1071 / #1094 rule every guard here carries. A renamed section, a
	 * heading that stopped matching, or a `readme.txt` that moved would
	 * otherwise turn every assertion below vacuously true.
	 */
	public function test_the_scan_finds_the_section(): void {
		$this->assertNotSame( '', self::readme(), 'readme.txt did not parse -- the guard is reading nothing.' );
		$this->assertNotSame( '', trim( self::section() ), 'The `== Upgrade Notice ==` section is missing or empty.' );
		$this->assertNotEmpty( self::versions(), 'The section declares no version heading.' );
	}

	/**
	 * One entry, because the updater can never offer an older release.
	 */
	public function test_the_section_holds_exactly_one_entry(): void {
		$versions = self::versions();

		$this->assertCount(
			1,
			$versions,
			'The section must hold the offered version alone; `releases/latest` means an older entry can never be displayed. Found: ' . implode( ', ', $versions )
		);
	}

	/**
	 * The entry names the version this release actually ships.
	 *
	 * `Stable tag` is the version `readme.txt` itself declares, so comparing
	 * against it keeps both halves of one file in agreement without this test
	 * naming a number that goes stale every release.
	 */
	public function test_the_entry_names_the_stable_tag(): void {
		preg_match( '/^Stable tag:\s*(\S+)\s*$/m', self::readme(), $tag );

		$this->assertNotEmpty( $tag[1] ?? '', 'The `Stable tag` header did not parse.' );
		$this->assertSame(
			$tag[1],
			self::versions()[0] ?? '',
			'The upgrade notice names a different version from `Stable tag` -- the release PR bumped one and not the other.'
		);
	}

	/**
	 * The summary fits the norm.
	 */
	public function test_the_entry_fits_the_character_norm(): void {
		$notice = self::runtime_notice();

		$this->assertLessThanOrEqual(
			self::MAX_CHARS,
			mb_strlen( $notice ),
			sprintf( 'The upgrade notice is %d characters against a %d target. Trim it -- CHANGELOG.md carries the detail.', mb_strlen( $notice ), self::MAX_CHARS )
		);
	}

	/**
	 * The runtime parser reads the entry, and reads the entry alone.
	 *
	 * The assertion that makes the rest of this file mean anything: a section
	 * the guard approves and the updater cannot parse would leave the update
	 * screen blank with nothing failing.
	 */
	public function test_the_runtime_parser_returns_the_entry_body(): void {
		$notice = self::runtime_notice();

		$this->assertNotSame( '', $notice, 'The updater parsed nothing -- WordPress would show no notice at all.' );
		$this->assertStringNotContainsString( '=', substr( $notice, 0, 1 ), 'The parser kept the version heading in the body.' );
		$this->assertStringNotContainsString( 'CHANGELOG.md', $notice, 'The parser swallowed the section trailer into the entry body.' );
	}

	/**
	 * The section still tells the reader where the detail lives.
	 *
	 * The summary is deliberately short, so the pointer is what makes it
	 * honest rather than partial.
	 */
	public function test_the_section_points_at_the_changelog(): void {
		$this->assertStringContainsString(
			'CHANGELOG.md',
			self::section(),
			'The section must point at CHANGELOG.md for the per-release detail and the issues each change references.'
		);
	}
}
