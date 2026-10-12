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
		$this->assertMatchesRegularExpression( '/^\d+\.\d+\.\d+(\.\d+)?$/', $version, 'The header carries a version.' );

		$this->assertSame( 1, preg_match( "/^define\\( 'FFC_VERSION', '([^']+)' \\);/m", $this->file( 'ffcertificate.php' ), $m ), 'The main file defines FFC_VERSION once.' );
		$this->assertSame( $version, $m[1], 'FFC_VERSION beside the header.' );
		$this->assertSame( $version, $this->readme( 'Stable tag' ), 'readme.txt Stable tag.' );
	}

	public function test_the_released_version_has_its_changelog_section(): void {
		$version = $this->header( 'Version' );
		$this->assertMatchesRegularExpression(
			'/^## \[' . preg_quote( $version, '/' ) . '\] \(\d{4}-\d{2}-\d{2}\)/m',
			$this->file( 'CHANGELOG.md' ),
			'The version the header declares is released, so CHANGELOG.md has its section; release.yml publishes that section as the notes.'
		);
	}

	public function test_the_php_floor_agrees_everywhere(): void {
		$floor = $this->header( 'Requires PHP' );
		$this->assertSame( $floor, $this->readme( 'Requires PHP' ), 'readme.txt.' );

		$composer = json_decode( $this->file( 'composer.json' ), true );
		$this->assertSame( '>=' . $floor, $composer['require']['php'], 'composer.json require.' );
		$this->assertSame( $floor, $composer['config']['platform']['php'], 'composer.json platform: dependencies resolve for the floor.' );

		$this->assertStringContainsString( '<config name="testVersion" value="' . $floor . '-"/>', $this->file( 'phpcs.xml.dist' ), 'PHPCompatibility judges against the floor.' );
	}

	public function test_the_wordpress_floor_agrees_everywhere(): void {
		$floor = $this->header( 'Requires at least' );
		$this->assertSame( $floor, $this->readme( 'Requires at least' ), 'readme.txt.' );
		$this->assertStringContainsString( '<config name="minimum_wp_version" value="' . $floor . '"/>', $this->file( 'phpcs.xml.dist' ), 'WPCS judges deprecations against the floor.' );
	}

	/**
	 * GitHub reads LICENSE, WordPress the header and readme.txt, Composer and
	 * npm their manifests. This repository had no LICENSE at all, so GitHub
	 * showed no licence while the header said GPLv3; in Institutional Email
	 * LICENSE carried GPLv2 while every declaration said GPLv3.
	 */
	public function test_the_licence_agrees_everywhere(): void {
		$name = 'AGPLv3 or later';
		$uri  = 'https://www.gnu.org/licenses/agpl-3.0.html';
		$spdx = 'AGPL-3.0-or-later';

		$field = static function ( string $text, string $prefix, string $key ): ?string {
			return 1 === preg_match( '/^' . $prefix . preg_quote( $key, '/' ) . ':\s*(.+?)\s*$/m', $text, $m ) ? $m[1] : null;
		};

		$this->assertSame( $name, $field( $this->file( 'ffcertificate.php' ), '\s*\*\s*', 'License' ), 'Plugin header License.' );
		$this->assertSame( $uri, $field( $this->file( 'ffcertificate.php' ), '\s*\*\s*', 'License URI' ), 'Plugin header License URI.' );
		$this->assertSame( $name, $field( $this->file( 'readme.txt' ), '', 'License' ), 'readme.txt License.' );
		$this->assertSame( $uri, $field( $this->file( 'readme.txt' ), '', 'License URI' ), 'readme.txt License URI.' );

		foreach ( array( 'composer.json', 'package.json' ) as $manifest ) {
			$json = json_decode( $this->file( $manifest ), true );
			$this->assertSame( $spdx, $json['license'] ?? null, "{$manifest} license." );
		}

		$this->assertStringContainsString( '**License** ' . $name, $this->file( 'README.md' ), 'README.md.' );

		$licence = $this->file( 'LICENSE' );
		$this->assertStringStartsWith( 'GNU AFFERO GENERAL PUBLIC LICENSE', ltrim( $licence ), 'LICENSE is the AGPL text.' );
		$this->assertStringContainsString( 'Version 3, 19 November 2007', $licence, 'LICENSE is version 3.' );
	}

	/**
	 * A plugin header field.
	 *
	 * @param string $name Header name.
	 */
	private function header( string $name ): string {
		$this->assertSame( 1, preg_match( '/^\s*\*\s*' . preg_quote( $name, '/' ) . ':\s*(\S+)\s*$/m', $this->file( 'ffcertificate.php' ), $m ), "The plugin header declares {$name}." );
		return $m[1];
	}

	/**
	 * A readme.txt header field.
	 *
	 * @param string $name Field name.
	 */
	private function readme( string $name ): string {
		$this->assertSame( 1, preg_match( '/^' . preg_quote( $name, '/' ) . ':\s*(\S+)\s*$/m', $this->file( 'readme.txt' ), $m ), "readme.txt declares {$name}." );
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
