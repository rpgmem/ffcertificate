<?php

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Integrations\GithubUpdater;
use FreeFormCertificate\Integrations\ReadmeSections;

/**
 * What the "View details" modal points at must exist.
 *
 * The banner ships in the zip; the screenshots are served from GitHub at the
 * release tag, so `GithubUpdater::SCREENSHOTS` is a list the code cannot
 * check at runtime. This holds it to `docs/screenshots/` and to the readme's
 * captions, so a screenshot added, renamed or captioned without the other
 * fails here instead of showing a broken image or a caption with no picture.
 *
 * @coversNothing
 */
final class PluginDetailsAssetsTest extends TestCase {

	private static function root(): string {
		return dirname( __DIR__, 2 );
	}

	public function test_both_banners_ship_at_the_size_their_key_promises(): void {
		foreach ( array( 'banner-772x250.png' => array( 772, 250 ), 'banner-1544x500.png' => array( 1544, 500 ) ) as $name => $size ) {
			$file = self::root() . '/assets/img/brand/' . $name;
			$this->assertFileExists( $file );
			$info = getimagesize( $file );
			$this->assertIsArray( $info );
			$this->assertSame( $size, array( $info[0], $info[1] ), "{$name} is the wrong size." );
		}
	}

	public function test_the_screenshot_list_matches_the_folder(): void {
		$files = array_values( array_map( 'basename', glob( self::root() . '/docs/screenshots/*.png' ) ?: array() ) );
		sort( $files );

		$this->assertNotSame( array(), $files, 'docs/screenshots/ read as empty; the check below would pass on nothing.' );
		$this->assertSame( $files, GithubUpdater::SCREENSHOTS );
	}

	public function test_every_caption_has_a_screenshot_and_every_screenshot_a_caption(): void {
		$sections = ReadmeSections::split( (string) file_get_contents( self::root() . '/readme.txt' ) );
		$this->assertArrayHasKey( 'Screenshots', $sections );

		$captions = preg_match_all( '/^\s*\d+\.\s+\S/m', $sections['Screenshots'] );
		$this->assertSame( count( GithubUpdater::SCREENSHOTS ), $captions );
	}
}
