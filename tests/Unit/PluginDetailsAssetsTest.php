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
 * A translated readme (`languages/readme-<locale>.txt`) is held to the
 * English one: the same tabs, the same number of other sections and of
 * captions, and a banner in that language at both sizes. It cannot check
 * that the translation says the same thing, only that nothing was left out.
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
	/**
	 * @return array<string, string> Locale => translated readme path.
	 */
	private static function translations(): array {
		$out = array();
		foreach ( glob( self::root() . '/languages/readme-*.txt' ) ?: array() as $file ) {
			$out[ (string) preg_replace( '/^readme-(.+)\.txt$/', '$1', basename( $file ) ) ] = $file;
		}
		return $out;
	}

	/**
	 * Section titles WordPress shows as tabs, plus how many others there are.
	 *
	 * @return array{tabs: list<string>, others: int, captions: int}
	 */
	private static function shape( string $readme ): array {
		$tabs     = array();
		$others   = 0;
		$captions = 0;
		foreach ( ReadmeSections::split( $readme ) as $title => $body ) {
			$key = strtolower( $title );
			if ( in_array( $key, array( 'description', 'installation', 'frequently asked questions', 'screenshots' ), true ) ) {
				$tabs[] = $key;
			} elseif ( ! in_array( $key, array( 'changelog', 'upgrade notice' ), true ) ) {
				++$others;
			}
			if ( 'screenshots' === $key ) {
				$captions = (int) preg_match_all( '/^\s*\d+\.\s+\S/m', $body );
			}
		}
		sort( $tabs );
		return array(
			'tabs'     => $tabs,
			'others'   => $others,
			'captions' => $captions,
		);
	}

	public function test_a_translated_readme_has_every_tab_section_and_caption_of_the_english_one(): void {
		$translations = self::translations();
		$this->assertNotSame( array(), $translations, 'languages/ read as having no translated readme; the checks below would pass on nothing.' );

		$english = self::shape( (string) file_get_contents( self::root() . '/readme.txt' ) );
		foreach ( $translations as $locale => $file ) {
			$this->assertSame( $english, self::shape( (string) file_get_contents( $file ) ), "languages/readme-{$locale}.txt differs from readme.txt in its tabs, its other sections or its captions." );
		}
	}

	public function test_each_translated_readme_has_its_banner_at_both_sizes(): void {
		foreach ( array_keys( self::translations() ) as $locale ) {
			foreach ( array( 'banner-772x250' => array( 772, 250 ), 'banner-1544x500' => array( 1544, 500 ) ) as $name => $size ) {
				$file = self::root() . "/assets/img/brand/{$name}-{$locale}.png";
				$this->assertFileExists( $file );
				$info = getimagesize( $file );
				$this->assertIsArray( $info );
				$this->assertSame( $size, array( $info[0], $info[1] ), "{$name}-{$locale}.png is the wrong size." );
			}
		}
	}
}
