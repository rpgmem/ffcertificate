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
 * It pins this side only. It cannot see the other repository, so a name that
 * still exists here but that Site PWA stopped reading passes, and so does a
 * change made in Site PWA alone. The contract is written down in
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
	 * The look Site PWA reads: `PublicDesign::from_settings()` and four public properties.
	 */
	public function test_public_design_keeps_what_the_app_reads(): void {
		$src = self::source( 'includes/core/class-ffc-public-design.php' );

		$this->assertMatchesRegularExpression( '/namespace\s+FreeFormCertificate\\\\Core\s*;/', $src );
		$this->assertMatchesRegularExpression( '/\bclass\s+PublicDesign\b/', $src );
		$this->assertMatchesRegularExpression( '/public\s+static\s+function\s+from_settings\s*\(\s*\)/', $src );
		foreach ( array( 'enabled', 'accent', 'radius', 'card_radius' ) as $property ) {
			$this->assertMatchesRegularExpression(
				'/public\s+(?:readonly\s+)?[a-z?]+\s+\$' . $property . '\s*;/',
				$src,
				'Site PWA reads PublicDesign::$' . $property . ' for the app\'s look.'
			);
		}
	}

	/**
	 * The scheme Site PWA reads: `SettingsReader::get( 'dark_mode' )` and its three values.
	 */
	public function test_dark_mode_keeps_its_reader_and_its_three_values(): void {
		$reader = self::source( 'includes/settings/class-ffc-settings-reader.php' );
		$this->assertMatchesRegularExpression( '/namespace\s+FreeFormCertificate\\\\Settings\s*;/', $reader );
		$this->assertMatchesRegularExpression( '/\bclass\s+SettingsReader\b/', $reader );
		$this->assertMatchesRegularExpression( '/public\s+static\s+function\s+get\s*\(\s*string\s+\$key/', $reader );

		$save = self::source( 'includes/admin/class-ffc-settings-save-handler.php' );
		$this->assertSame(
			1,
			preg_match( '/\$allowed_modes\s*=\s*array\(\s*\'off\',\s*\'on\',\s*\'auto\'\s*\)/', $save ),
			'Site PWA maps dark_mode off / on / auto to its own light / dark / auto; a value it does not know is ignored there.'
		);
	}

	/**
	 * The root class the app's frame sets so this plugin's dark palette applies.
	 */
	public function test_the_dark_palette_still_hangs_off_ffc_dark_mode_on_the_root(): void {
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
			'home'                        => array( 'includes/shortcodes/class-ffc-app-home.php', 'site_pwa_home', 3 ),
			'lock required'               => array( 'includes/audience/class-ffc-audience-app-screens.php', 'site_pwa_lock_required', 2 ),
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
		$this->assertMatchesRegularExpression(
			'/add_(?:action|filter)\(\s*\'' . preg_quote( $hook, '/' ) . '\'\s*,[^;]*,\s*10\s*,\s*' . $arity . '\s*\)/',
			self::source( $path ),
			$hook . ' is no longer registered with ' . $arity . ' arguments in ' . $path . '.'
		);
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
