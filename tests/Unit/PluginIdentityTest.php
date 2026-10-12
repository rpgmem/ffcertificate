<?php
/**
 * The version and the two floors, read where WordPress, Composer and PHPCS
 * read them.
 *
 * WHY THIS GUARD EXISTS
 *
 * Each of these is declared in several files that other tools parse before
 * any PHP runs, so nothing at runtime compares them. "Versioning" in
 * CLAUDE.md asked for the three version sites to move in one commit, by
 * hand, and nothing checked it. The floors had already drifted when this was
 * written: `phpcs.xml.dist` still declared PHP 8.1 and WordPress 6.2, two
 * releases after the plugin required 8.3 and 6.8, so PHPCompatibility and
 * WPCS were judging the code against platforms it no longer supports.
 *
 * The plugin header is the source each comparison starts from, because it is
 * what WordPress reads first; the fresh-install job already resolves its WP
 * floor from the same header.
 *
 * @package FreeFormCertificate\Tests\Unit
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class PluginIdentityTest extends TestCase {

	private const ROOT = __DIR__ . '/../..';

	public function test_the_version_agrees_everywhere(): void {
		$version = $this->header( 'Version' );
		self::assertMatchesRegularExpression( '/^\d+\.\d+\.\d+(\.\d+)?$/', $version, 'The header carries a version.' );

		self::assertSame( 1, preg_match( "/^define\\( 'FFC_VERSION', '([^']+)' \\);/m", $this->file( 'ffcertificate.php' ), $m ), 'The main file defines FFC_VERSION once.' );
		self::assertSame( $version, $m[1], 'FFC_VERSION beside the header.' );
		self::assertSame( $version, $this->readme( 'Stable tag' ), 'readme.txt Stable tag.' );
	}

	public function test_the_released_version_has_its_changelog_section(): void {
		$version = $this->header( 'Version' );
		self::assertMatchesRegularExpression(
			'/^## \[' . preg_quote( $version, '/' ) . '\] \(\d{4}-\d{2}-\d{2}\)/m',
			$this->file( 'CHANGELOG.md' ),
			'The version the header declares is released, so CHANGELOG.md has its section; release.yml publishes that section as the notes.'
		);
	}

	public function test_the_php_floor_agrees_everywhere(): void {
		$floor = $this->header( 'Requires PHP' );
		self::assertSame( $floor, $this->readme( 'Requires PHP' ), 'readme.txt.' );

		$composer = json_decode( $this->file( 'composer.json' ), true );
		self::assertSame( '>=' . $floor, $composer['require']['php'], 'composer.json require.' );
		self::assertSame( $floor, $composer['config']['platform']['php'], 'composer.json platform: dependencies resolve for the floor.' );

		self::assertStringContainsString( '<config name="testVersion" value="' . $floor . '-"/>', $this->file( 'phpcs.xml.dist' ), 'PHPCompatibility judges against the floor.' );
	}

	public function test_the_wordpress_floor_agrees_everywhere(): void {
		$floor = $this->header( 'Requires at least' );
		self::assertSame( $floor, $this->readme( 'Requires at least' ), 'readme.txt.' );
		self::assertStringContainsString( '<config name="minimum_wp_version" value="' . $floor . '"/>', $this->file( 'phpcs.xml.dist' ), 'WPCS judges deprecations against the floor.' );
	}

	/**
	 * A plugin header field.
	 *
	 * @param string $name Header name.
	 */
	private function header( string $name ): string {
		self::assertSame( 1, preg_match( '/^\s*\*\s*' . preg_quote( $name, '/' ) . ':\s*(\S+)\s*$/m', $this->file( 'ffcertificate.php' ), $m ), "The plugin header declares {$name}." );
		return $m[1];
	}

	/**
	 * A readme.txt header field.
	 *
	 * @param string $name Field name.
	 */
	private function readme( string $name ): string {
		self::assertSame( 1, preg_match( '/^' . preg_quote( $name, '/' ) . ':\s*(\S+)\s*$/m', $this->file( 'readme.txt' ), $m ), "readme.txt declares {$name}." );
		return $m[1];
	}

	/**
	 * A file of the repository.
	 *
	 * @param string $path Path from the root.
	 */
	private function file( string $path ): string {
		return (string) file_get_contents( self::ROOT . '/' . $path );
	}
}
