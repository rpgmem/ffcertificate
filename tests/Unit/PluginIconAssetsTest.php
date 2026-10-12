<?php
/**
 * Plugin icon guard (#1712).
 *
 * `GitHubUpdater::icons()` hands WordPress three URLs under the installed copy.
 * A URL whose file is missing, or excluded from the release zip, draws a broken
 * image on Dashboard → Updates, and nothing else would report it: the updater
 * builds the URL from a string and never looks at the disk. So this reads the
 * URLs out of the source and checks each against the tree, the PNG sizes
 * against the keys WordPress picks them by, and `.distignore` against the
 * directory.
 *
 * The four variants beside the colour icon (black and white lines, dark
 * screen, no background) are read by no code; they ship for whoever needs the
 * mark elsewhere, as SVG and as 128 px and 256 px PNG, so their only guard is
 * that every one of those files exists at its size.
 *
 * Dependency-free on purpose: it reads files, never runs WordPress.
 *
 * @package FreeFormCertificate\Tests\Unit
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
class PluginIconAssetsTest extends TestCase {

	private const DIR = 'assets/img/brand/';

	private const VARIANTS = array( 'icon.svg', 'icon-black.svg', 'icon-white.svg', 'icon-dark.svg', 'icon-outline.svg' );

	private static function root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * The `icons` map as the updater writes it, key => path under the plugin.
	 *
	 * @return array<string, string>
	 */
	private static function updater_icons(): array {
		$source = (string) file_get_contents( self::root() . '/includes/integrations/class-ffc-github-updater.php' );
		preg_match_all( "#'(1x|2x|svg)'\s*=>\s*\\\$base\s*\.\s*'([^']+)'#", $source, $m, PREG_SET_ORDER );
		preg_match( "#\\\$base\s*=\s*FFC_PLUGIN_URL\s*\.\s*'([^']+)'#", $source, $base );

		$icons = array();
		foreach ( $m as $row ) {
			$icons[ $row[1] ] = ( $base[1] ?? '' ) . $row[2];
		}
		return $icons;
	}

	public function test_the_scan_reads_all_three_keys(): void {
		$this->assertSame( array( '1x', '2x', 'svg' ), array_keys( self::updater_icons() ), 'The source scan lost the icons map; fix the scan before trusting the checks below.' );
	}

	public function test_every_icon_url_resolves_to_a_file_of_the_promised_size(): void {
		$sizes = array( '1x' => 128, '2x' => 256 );

		foreach ( self::updater_icons() as $key => $path ) {
			$file = self::root() . '/' . $path;
			$this->assertFileExists( $file, "The updater advertises {$path} for '{$key}', and there is no such file." );

			if ( isset( $sizes[ $key ] ) ) {
				$info = getimagesize( $file );
				$this->assertIsArray( $info );
				$this->assertSame( array( $sizes[ $key ], $sizes[ $key ] ), array( $info[0], $info[1] ), "{$path} is the '{$key}' icon and must be {$sizes[ $key ]}px square." );
			}
		}
	}

	public function test_every_variant_ships_as_svg(): void {
		foreach ( self::VARIANTS as $name ) {
			$file = self::root() . '/' . self::DIR . $name;
			$this->assertFileExists( $file );
			$this->assertStringStartsWith( '<svg ', (string) file_get_contents( $file ), "{$name} is not an SVG document." );
		}
	}

	public function test_every_variant_ships_as_png_in_both_sizes(): void {
		foreach ( self::VARIANTS as $name ) {
			foreach ( array( 128, 256 ) as $size ) {
				$png  = substr( $name, 0, -4 ) . '-' . $size . '.png';
				$file = self::root() . '/' . self::DIR . $png;
				$this->assertFileExists( $file, "{$png} is missing; every SVG variant ships as a 128 px and a 256 px PNG." );
				$info = getimagesize( $file );
				$this->assertIsArray( $info );
				$this->assertSame( array( $size, $size ), array( $info[0], $info[1] ), "{$png} must be {$size}px square." );
			}
		}
	}

	public function test_the_icons_are_not_excluded_from_the_distribution_zip(): void {
		$rules = array_filter( array_map( 'trim', file( self::root() . '/.distignore' ) ?: array() ) );

		foreach ( $rules as $rule ) {
			if ( '#' === $rule[0] ) {
				continue;
			}
			$pattern = trim( $rule, '/' );
			foreach ( array( 'assets', 'assets/img', rtrim( self::DIR, '/' ) ) as $dir ) {
				$this->assertNotSame( $dir, $pattern, ".distignore excludes {$dir}, so the released zip ships without the icon." );
			}
			$this->assertFalse( fnmatch( $pattern, 'icon-256.png' ) || fnmatch( $pattern, 'icon.svg' ), ".distignore rule '{$rule}' matches an icon file." );
		}
	}
}
