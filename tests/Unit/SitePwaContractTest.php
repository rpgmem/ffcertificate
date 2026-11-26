<?php
/**
 * This side of the contract with Site PWA (rpgmem/site-pwa) (#2871).
 *
 * Site PWA reads this plugin defensively, through `class_exists()` and
 * `is_callable()`, and this plugin reaches Site PWA through hooks that do
 * nothing when the other plugin is absent. So a rename here breaks nothing
 * visibly: the app falls back to its own defaults, and no test in either
 * repository fails. This test pins the names Site PWA depends on, read from
 * the source as text, so it loads no class and stubs nothing.
 *
 * This plugin offers its look through Site PWA's source filters
 * (`Shortcodes\AppLook`, #2873), and the source id `ffc` is a value Site PWA
 * stores, so it is pinned too. Site PWA no longer reads any class of this
 * plugin by name (rpgmem/site-pwa#49, #2877).
 *
 * It pins this side only and cannot see the other repository; the two run
 * together in `.github/scripts/site-pwa-integration-check.php` (#2875). The contract is written down in
 * `.claude/rules/pwa-contract.md`; a red test here means the contract moved,
 * so change both sides together, never just the expectation.
 *
 * @package FreeFormCertificate\Tests\Unit
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class SitePwaContractTest extends TestCase {

	/**
	 * Reads a repository file.
	 *
	 * @param string $path Path relative to the root.
	 * @return string
	 */
	private static function source( string $path ): string {
		$file = dirname( __DIR__, 2 ) . '/' . $path;
		self::assertFileExists( $file, $path . ' moved: update the contract in .claude/rules/pwa-contract.md and Site PWA.' );
		return (string) file_get_contents( $file );
	}

	/**
	 * Every Dark Mode value the settings accept is one `AppLook` maps to the
	 * app's words, and the other way round. A value added to one list and not
	 * the other reaches the app as null, and the app falls back to `auto` in
	 * silence.
	 */
	public function test_dark_mode_values_and_the_look_map_agree(): void {
		$save = self::source( 'includes/admin/class-ffc-settings-save-handler.php' );
		$this->assertSame( 1, preg_match( '/\$allowed_modes\s*=\s*array\(([^)]*)\)/', $save, $m ), 'The save handler no longer lists the Dark Mode values.' );
		preg_match_all( "/'([a-z]+)'/", $m[1], $allowed );

		$look = self::source( 'includes/shortcodes/class-ffc-app-look.php' );
		$this->assertSame( 1, preg_match( '/private const SCHEMES = array\((.*?)\);/s', $look, $n ), 'AppLook no longer maps the Dark Mode values.' );
		preg_match_all( "/'([a-z]+)'\s*=>/", $n[1], $mapped );

		$this->assertSame( $allowed[1], $mapped[1], 'AppLook::SCHEMES must map exactly the Dark Mode values the settings accept.' );
	}

	/**
	 * The root class `AppLook` offers the app is the one this plugin's dark palette hangs off.
	 */
	public function test_the_dark_palette_hangs_off_the_class_the_look_offers(): void {
		$this->assertMatchesRegularExpression( "/public const DARK_ROOT_CLASS = 'ffc-dark-mode';/", self::source( 'includes/shortcodes/class-ffc-app-look.php' ) );
		$this->assertStringContainsString( "classList.add('ffc-dark-mode')", self::source( 'assets/js/ffc-dark-mode.js' ) );
		$this->assertMatchesRegularExpression( '/:root\.ffc-dark-mode\s*\{/', self::source( 'assets/css/ffc-common.css' ) );
	}

	/**
	 * The hooks this plugin registers on Site PWA, each with the arity Site PWA passes.
	 *
	 * @return array<string, array{0: string, 1: string, 2: int}>
	 */
	public static function hooks(): array {
		return array(
			'register screens, dashboard' => array( 'includes/shortcodes/class-ffc-dashboard-app-screens.php', 'site_pwa_register_screens', 2 ),
			'register screens, audience'  => array( 'includes/audience/class-ffc-audience-app-screens.php', 'site_pwa_register_screens', 2 ),
			'profile line'                => array( 'includes/shortcodes/class-ffc-dashboard-app-screens.php', 'site_pwa_profile_line', 2 ),
			'details screen'              => array( 'includes/shortcodes/class-ffc-dashboard-app-screens.php', 'site_pwa_details_screen', 1 ),
			'home'                        => array( 'includes/shortcodes/class-ffc-app-home.php', 'site_pwa_home', 3 ),
			'lock required'               => array( 'includes/audience/class-ffc-audience-app-screens.php', 'site_pwa_lock_required', 2 ),
			'identity sources'            => array( 'includes/shortcodes/class-ffc-app-look.php', 'site_pwa_identity_sources', 1 ),
			'scheme sources'              => array( 'includes/shortcodes/class-ffc-app-look.php', 'site_pwa_scheme_sources', 1 ),
			'dark root classes'           => array( 'includes/shortcodes/class-ffc-app-look.php', 'site_pwa_dark_root_classes', 1 ),
		);
	}

	/**
	 * Each consumer still registers its hook with the arguments Site PWA passes.
	 *
	 * @dataProvider hooks
	 *
	 * @param string $path  The consumer.
	 * @param string $hook  The Site PWA hook.
	 * @param int    $arity The arguments Site PWA passes.
	 */
	public function test_each_consumer_registers_its_hook( string $path, string $hook, int $arity ): void {
		$tail = 1 === $arity ? '(?:,\s*10\s*,\s*1\s*)?\)\s*;' : ',\s*10\s*,\s*' . $arity . '\s*\)';
		$this->assertMatchesRegularExpression(
			'/add_(?:action|filter)\(\s*\'' . preg_quote( $hook, '/' ) . '\'\s*,\s*array\(\s*self::class\s*,\s*\'[a-z_]+\'\s*\)\s*' . $tail . '/',
			self::source( $path ),
			$hook . ' is no longer registered with ' . $arity . ' arguments in ' . $path . '.'
		);
	}

	/**
	 * The source id is stored in Site PWA's settings, and the root class names this plugin's palette.
	 */
	public function test_the_look_keeps_its_stored_source_id_and_root_class(): void {
		$src = self::source( 'includes/shortcodes/class-ffc-app-look.php' );
		$this->assertMatchesRegularExpression( "/public const SOURCE_ID = 'ffc';/", $src, 'Site PWA stores this id as the chosen source; renaming it drops every site back to the app defaults.' );
		$this->assertMatchesRegularExpression( "/public const DARK_ROOT_CLASS = 'ffc-dark-mode';/", $src );
		foreach ( array( "'accent'", "'radius'", "'control_radius'", "'light'", "'dark'", "'auto'" ) as $key ) {
			$this->assertStringContainsString( $key, $src, 'AppLook answers in Site PWA\'s shapes; ' . $key . ' is one of their keys or values.' );
		}
	}

	/**
	 * The URL filter is applied, not registered: this plugin asks Site PWA for the in-app URL.
	 */
	public function test_the_agenda_asks_the_app_for_its_url(): void {
		$this->assertStringContainsString( "apply_filters( 'site_pwa_app_url'", self::source( 'includes/shortcodes/class-ffc-agenda-app-screen.php' ) );
	}

	/**
	 * Every consumer speaks the same contract version, and it is the one Site PWA ships.
	 */
	public function test_every_consumer_speaks_api_version_one(): void {
		foreach ( array(
			'includes/shortcodes/class-ffc-dashboard-app-screens.php',
			'includes/audience/class-ffc-audience-app-screens.php',
			'includes/shortcodes/class-ffc-app-home.php',
		) as $path ) {
			$this->assertMatchesRegularExpression(
				'/public\s+const\s+API_VERSION\s*=\s*1\s*;/',
				self::source( $path ),
				$path . ' moved off contract version 1; Site PWA raises it only for a breaking change, so adapt both sides together.'
			);
		}
	}
}
