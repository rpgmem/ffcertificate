<?php
/**
 * Updater compatibility-metadata guard (#1022).
 *
 * `GitHubUpdater::compat()` reads the three compatibility fields it puts into
 * the update-transient object out of the two files that own them:
 *
 *   requires      ← ffcertificate.php  "Requires at least"
 *   requires_php  ← ffcertificate.php  "Requires PHP"
 *   tested        ← readme.txt         "Tested up to"
 *
 * They used to be hard-coded constants and both WP values had drifted: `tested`
 * still said 7.0 after #984 raised `readme.txt` to 7.1, so the update screen
 * told every 7.1 site that a release verified against 7.1 was untested;
 * `requires` said 6.2 against an actual 6.4. WordPress reads these fields and
 * never `readme.txt`, because the plugin updates from GitHub rather than the
 * WordPress.org directory — so the stale copy was the one that counted.
 *
 * Reading at runtime removes that third copy, and moves the risk rather than
 * erasing it: `get_file_data()` returns an empty string for a header that is
 * missing or renamed, silently, so the failure mode becomes an empty `tested`
 * instead of a stale one. These tests pin the headers the read depends on.
 *
 * WHAT THIS CANNOT SEE. It proves the headers exist, are well-formed and agree
 * with each other — not that they are true. Nobody can assert from source text
 * that the plugin really was exercised against WordPress 7.1; raising "Tested
 * up to" stays a human claim.
 *
 * Dependency-free on purpose — no WordPress, no Brain\Monkey — like the module
 * boundary, AJAX wiring and settings-default guards. It reads source text only.
 *
 * @package FreeFormCertificate\Tests\Unit
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class GitHubUpdaterCompatTest extends TestCase {

	/**
	 * Absolute path to the repository root.
	 */
	private static function root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * Extract a `Field: value` header the way `get_file_data()` would.
	 *
	 * Mirrors core's behaviour closely enough for a guard: it scans the first
	 * 8 KB and takes the rest of the line, so a renamed or deleted header
	 * yields '' here exactly as it would at runtime.
	 *
	 * @param string $file  Path relative to the repository root.
	 * @param string $field Header name, e.g. 'Tested up to'.
	 * @return string Trimmed value, or '' when absent.
	 */
	private static function header( string $file, string $field ): string {
		$handle = fopen( self::root() . '/' . $file, 'r' );
		self::assertNotFalse( $handle, "Could not open {$file}." );

		$chunk = (string) fread( $handle, 8192 );
		fclose( $handle );

		// Tolerate the leading ` * ` of a PHP docblock, as core does.
		if ( 1 !== preg_match( '/^[ \t\/*#@]*' . preg_quote( $field, '/' ) . ':(.*)$/mi', $chunk, $m ) ) {
			return '';
		}

		return trim( preg_replace( '/\s*(?:\*\/|\?>).*/', '', $m[1] ) ?? '' );
	}

	/**
	 * The three headers `compat()` reads, as file => field.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function compat_headers(): array {
		return array(
			'requires'     => array( 'ffcertificate.php', 'Requires at least' ),
			'requires_php' => array( 'ffcertificate.php', 'Requires PHP' ),
			'tested'       => array( 'readme.txt', 'Tested up to' ),
		);
	}

	/**
	 * @dataProvider compat_headers
	 *
	 * @param string $file  File the updater reads.
	 * @param string $field Header it asks for.
	 */
	public function test_every_header_the_updater_reads_is_present_and_non_empty( string $file, string $field ): void {
		$value = self::header( $file, $field );

		$this->assertNotSame(
			'',
			$value,
			"{$file} has no usable '{$field}:' header.\n"
			. "GitHubUpdater::compat() reads it through get_file_data(), which returns '' rather than\n"
			. 'failing — so removing or renaming it ships an empty compatibility field to the update screen.'
		);

		$this->assertMatchesRegularExpression(
			'/^\d+(\.\d+)*$/',
			$value,
			"{$file}'s '{$field}: {$value}' is not a bare version number, so version_compare() in\n"
			. "WordPress's update screen cannot read it."
		);
	}

	/**
	 * `readme.txt` and the plugin header both carry the WP floor and the PHP
	 * floor. `compat()` takes both from the plugin header, so a disagreement
	 * means the readme is advertising something the updater will not send.
	 */
	public function test_readme_and_plugin_header_agree_with_each_other(): void {
		$this->assertSame(
			self::header( 'ffcertificate.php', 'Requires at least' ),
			self::header( 'readme.txt', 'Requires at least' ),
			"readme.txt and the plugin header disagree on 'Requires at least'."
		);

		$this->assertSame(
			self::header( 'ffcertificate.php', 'Requires PHP' ),
			self::header( 'readme.txt', 'Requires PHP' ),
			"readme.txt and the plugin header disagree on 'Requires PHP'."
		);
	}

	/**
	 * The anti-regression for #1022 itself: the values must stay derived. A
	 * reintroduced constant would pass every test above while shipping a stale
	 * number, which is precisely how the original bug survived.
	 */
	public function test_updater_does_not_hard_code_the_values_again(): void {
		$source = (string) file_get_contents(
			self::root() . '/includes/integrations/class-ffc-github-updater.php'
		);

		$this->assertDoesNotMatchRegularExpression(
			"/const\s+(WP_REQUIRES|WP_TESTED|PHP_REQUIRES)\s*=/",
			$source,
			"GitHubUpdater declares a hard-coded compatibility constant again.\n"
			. 'These are read from readme.txt and the plugin header for a reason — see #1022.'
		);

		$this->assertStringContainsString(
			"'tested' => 'Tested up to'",
			$source,
			'GitHubUpdater no longer reads "Tested up to" from readme.txt.'
		);
	}

	/**
	 * `readme.txt` must ship, or the runtime read finds nothing on an installed
	 * site even though it works from a git checkout.
	 */
	public function test_readme_is_not_excluded_from_the_distribution_zip(): void {
		$distignore = (string) file_get_contents( self::root() . '/.distignore' );

		$excluded = preg_grep(
			'#^/?readme\.txt$#i',
			array_map( 'trim', explode( "\n", $distignore ) )
		);

		$this->assertSame(
			array(),
			array_values( (array) $excluded ),
			".distignore excludes readme.txt, so the released zip would ship without it and\n"
			. "GitHubUpdater::compat() would read an empty 'tested' on every installed site."
		);
	}
}
