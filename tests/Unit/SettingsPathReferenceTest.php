<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Core\PluginAreas;
use FreeFormCertificate\Tests\Support\I18nCalls;
use PHPUnit\Framework\TestCase;

/**
 * Every "Settings → Tab" a reader is sent to names a tab that exists (#1641).
 *
 * Help text, notices and the documentation send operators to a screen by its
 * path. A path is a name declared at both ends — the tab's own title and the
 * sentence that cites it — and renaming one end left the other behind: seven
 * strings still said "Settings → Migrations" for a tab titled "Data
 * Migrations", and "Settings → URL Shortener" would have outlived #1641. So
 * every path in a translatable string is read and its first step checked
 * against the real tab titles. A path under "Scheduling → Settings" is
 * checked against the Scheduling settings tabs instead.
 *
 * It sees the name, never the destination: a path to the right tab that
 * describes the wrong field passes.
 *
 * @coversNothing
 */
class SettingsPathReferenceTest extends TestCase {

	/**
	 * Destinations that are not a plugin Settings tab, with why.
	 *
	 * @var array<string, string>
	 */
	private const NOT_PLUGIN_TABS = array(
		'Privacy'       => 'WordPress core: Settings → Privacy → Policy Guide',
		'Permalinks'    => 'WordPress core: Settings → Permalinks',
		'Safari'        => 'iOS device settings, in the cookie-blocked help shown to visitors',
		'Site Settings' => 'Android Chrome settings, in the cookie-blocked help shown to visitors',
		'Location'      => 'a browser\'s site settings ("Site Settings → Location"), in the geolocation help shown to visitors',
		'Cookies'       => 'a browser\'s site settings, in the cookie-blocked help shown to visitors',
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
	 * The titles of the plugin's Settings tabs, read from the tab classes.
	 *
	 * @return array<int, string>
	 */
	private static function settings_tabs(): array {
		$titles = array();
		foreach ( (array) glob( dirname( __DIR__, 2 ) . '/includes/settings/tabs/*.php' ) as $file ) {
			$src = (string) file_get_contents( (string) $file );
			if ( 1 === preg_match( "/tab_title\\s*=\\s*__\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", $src, $m ) ) {
				$titles[] = stripslashes( $m[1] );
			} elseif ( 1 === preg_match( "/tab_title\\s*=\\s*\\\\?FreeFormCertificate\\\\Core\\\\PluginAreas::label\\(\\s*'([a-z_]+)'\\s*\\)/", $src, $m ) ) {
				$titles[] = PluginAreas::label( $m[1] );
			}
		}
		return $titles;
	}

	/**
	 * The titles of the Scheduling settings tabs.
	 *
	 * @return array<int, string>
	 */
	private static function scheduling_tabs(): array {
		$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/audience/class-ffc-audience-admin-settings.php' )
			. (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/admin/class-ffc-cert-template-receipt-settings.php' );
		preg_match_all( "/'label'\\s*=>\\s*__\\(\\s*'([^']+)'/", $src, $m );
		return $m[1];
	}

	/**
	 * Whether a path continues with one of the given names at a word boundary.
	 *
	 * @param string             $rest  Text after "Settings → ".
	 * @param array<int, string> $names Candidate names.
	 * @return bool
	 */
	private static function starts_with_one( string $rest, array $names ): bool {
		foreach ( $names as $name ) {
			if ( str_starts_with( $rest, $name ) && 1 !== preg_match( '/^[A-Za-z]/', substr( $rest, strlen( $name ) ) ) ) {
				return true;
			}
		}
		return false;
	}

	public function test_the_scan_reads_the_tabs_and_the_paths(): void {
		$tabs = self::settings_tabs();
		// Every tab class declares a title; one the scan cannot read would let a
		// path to it fail for the wrong reason, or pass against a shorter list.
		$this->assertCount( count( (array) glob( dirname( __DIR__, 2 ) . '/includes/settings/tabs/*.php' ) ), $tabs, 'a tab title the scan cannot read' );
		$this->assertContains( 'Data Migrations', $tabs );
		$this->assertContains( 'Short URLs', $tabs );
		$this->assertContains( 'Receipt', self::scheduling_tabs() );
	}

	public function test_every_settings_path_names_a_tab_that_exists(): void {
		$tabs       = self::settings_tabs();
		$scheduling = self::scheduling_tabs();
		$stale      = array();
		$read       = array();

		foreach ( I18nCalls::all() as $call ) {
			$text = (string) $call['msgid'];
			if ( ! preg_match_all( '/(\S+ → )?Settings → /u', $text, $m, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}
			foreach ( $m[0] as $i => $match ) {
				$rest   = substr( $text, $match[1] + strlen( $match[0] ) );
				$read[] = preg_replace( '/[.,;:()"].*$/su', '', $match[0] . $rest );
				$parent = $m[1][ $i ][0] ?? '';
				$names  = 'Scheduling → ' === $parent ? $scheduling : array_merge( $tabs, array_keys( self::NOT_PLUGIN_TABS ) );
				if ( ! self::starts_with_one( $rest, $names ) ) {
					$stale[] = mb_substr( $match[0] . $rest, 0, 60 ) . '  (' . implode( ', ', $call['refs'] ) . ')';
				}
			}
		}

		// Named, not counted: the notice that sends an administrator to the
		// html/ image migration is a path the scan can least afford to miss.
		$this->assertContains( 'Settings → Data Migrations → Rewrite html/ Image References', $read, 'the scan no longer reads the paths in translatable strings' );
		$this->assertSame( array(), $stale, 'a path names a Settings tab that does not exist — rename it to the tab\'s current title' );
	}
}
