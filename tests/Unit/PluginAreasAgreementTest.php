<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Admin\AdminMenuIcons;
use FreeFormCertificate\Core\Icons;
use FreeFormCertificate\Core\PluginAreas;
use FreeFormCertificate\Settings\SettingsReader;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * A plugin area wears one icon and one name everywhere (#1640, #1641).
 *
 * The admin menu, the documentation tree and Settings → Modules each named
 * and drew the areas their own way until #1640 / #1641: the menu took
 * dashicons, and one area had up to fourteen names. `PluginAreas` is now the
 * one map, and this holds every consumer to it: the menus register with
 * `'none'` so the registry draws them and take their title from the map, the
 * menu ids match each module's own slug, and the documentation and Modules
 * tab read the map instead of naming a class or a label of their own.
 *
 * @covers \FreeFormCertificate\Core\PluginAreas
 * @covers \FreeFormCertificate\Core\Icons
 * @covers \FreeFormCertificate\Admin\AdminMenuIcons
 */
class PluginAreasAgreementTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Where each area's menu is registered, and the slug that file owns.
	 *
	 * @var array<string, array{0: string, 1: string}>
	 */
	private const REGISTRARS = array(
		'certificates'   => array( 'includes/admin/class-ffc-cpt.php', "register_post_type( 'ffc_form'" ),
		'scheduling'     => array( 'includes/audience/class-ffc-audience-admin-page.php', "MENU_SLUG = 'ffc-scheduling'" ),
		'reregistration' => array( 'includes/reregistration/class-ffc-reregistration-admin.php', "MENU_SLUG = 'ffc-reregistration'" ),
		'recruitment'    => array( 'includes/recruitment/class-ffc-recruitment-admin-page.php', "PAGE_SLUG = 'ffc-recruitment'" ),
		'url_shortener'  => array( 'includes/url-shortener/class-ffc-url-shortener-admin-page.php', "'ffc-short-urls'," ),
		'date_messages'  => array( 'includes/date-messages/class-ffc-date-messages-admin-page.php', "MENU_SLUG = 'ffc-date-messages'" ),
		'settings'       => array( 'includes/admin/class-ffc-settings.php', "'ffc-settings'," ),
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

	private static function read( string $path ): string {
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/' . $path );
	}

	public function test_every_area_draws_a_registry_class(): void {
		$this->assertSame( array_keys( self::REGISTRARS ), array_keys( PluginAreas::all() ), 'an area was added or removed; name where its menu is registered' );
		foreach ( PluginAreas::all() as $area => $entry ) {
			$this->assertArrayHasKey( $entry['icon'], Icons::classes(), $area . ' names a class the registry does not draw' );
			$this->assertSame( 'ffc-icon-' . $entry['icon'], PluginAreas::icon_class( $area ) );
			$this->assertNotSame( '', PluginAreas::label( $area ), $area . ' has no name' );
		}
		$this->assertSame( '', PluginAreas::icon_class( 'not_an_area' ) );
		$this->assertSame( '', PluginAreas::label( 'not_an_area' ) );
	}

	public function test_menu_ids_match_the_slugs_the_modules_register(): void {
		foreach ( PluginAreas::all() as $area => $entry ) {
			$source = self::read( self::REGISTRARS[ $area ][0] );
			$this->assertStringContainsString( self::REGISTRARS[ $area ][1], $source, $area . ': the registrar no longer owns that slug' );

			$slug = 'certificates' === $area ? 'ffc_form' : (string) preg_replace( "/^.*'([^']+)',?$/", '$1', self::REGISTRARS[ $area ][1] );
			$id   = 'certificates' === $area ? 'menu-posts-' . $slug : 'toplevel_page_' . $slug;
			$this->assertSame( $id, $entry['menu'], $area . ': the menu item id WordPress prints for that slug' );
		}
	}

	public function test_every_menu_lets_the_registry_draw_its_icon(): void {
		foreach ( self::REGISTRARS as $area => $registrar ) {
			$source = self::read( $registrar[0] );
			$this->assertMatchesRegularExpression( "/'none',? \\/\\/ Drawn from the icon registry by AdminMenuIcons/", $source, $area . ' registers its menu with an icon of its own' );
		}
	}

	public function test_every_menu_takes_its_title_from_the_map(): void {
		foreach ( self::REGISTRARS as $area => $registrar ) {
			$this->assertStringContainsString(
				"PluginAreas::label( '" . $area . "' )",
				self::read( $registrar[0] ),
				$area . ' names its menu with a string of its own'
			);
		}
	}

	public function test_documentation_and_modules_tab_name_the_areas_from_the_map(): void {
		$docs = self::read( 'includes/settings/views/ffc-tab-documentation.php' );
		$list = self::read( 'includes/settings/views/documentation/config-modules.php' );
		$tab  = self::read( 'includes/settings/views/ffc-tab-modulos.php' );
		foreach ( array_keys( PluginAreas::all() ) as $area ) {
			if ( 'settings' === $area ) {
				continue;
			}
			$this->assertStringContainsString( "'title'", $docs );
			$this->assertMatchesRegularExpression( "/PluginAreas::icon_class\\( '" . $area . "' \\),\\s*'title'\\s*=> \\\\FreeFormCertificate\\\\Core\\\\PluginAreas::label\\( '" . $area . "' \\)/", $docs, 'the documentation names ' . $area . ' with a title of its own' );
			if ( 'scheduling' !== $area ) {
				// Scheduling is two modules, Personal and Audience Calendars, each
				// named for its own half; every other module is its area.
				$this->assertStringContainsString( "'label' => PluginAreas::label( '" . $area . "' )", $tab, 'Settings → Modules names ' . $area . ' with a label of its own' );
				$this->assertStringContainsString( "PluginAreas::label( '" . $area . "' )", $list, 'the Modules documentation names ' . $area . ' with a label of its own' );
			}
		}
	}

	public function test_documentation_and_modules_tab_read_the_map(): void {
		$docs = self::read( 'includes/settings/views/ffc-tab-documentation.php' );
		foreach ( array_keys( PluginAreas::all() ) as $area ) {
			if ( 'settings' === $area ) {
				continue; // The documentation has no Settings area: its topics sit under each feature.
			}
			$this->assertStringContainsString( "PluginAreas::icon_class( '" . $area . "' )", $docs, 'the documentation tree draws ' . $area . ' with a class of its own' );
		}

		$modules = self::read( 'includes/settings/views/ffc-tab-modulos.php' );
		$this->assertStringContainsString( "PluginAreas::icon_class( \$ffc_meta['area'] ?? '' )", $modules );
		foreach ( SettingsReader::MODULE_SLUGS as $slug ) {
			$this->assertMatchesRegularExpression( "/'" . $slug . "'\\s*=> array\\(\\s*'area'\\s*=> '([a-z_]+)'/", $modules, $slug . ' has no area on the Modules tab' );
			preg_match( "/'" . $slug . "'\\s*=> array\\(\\s*'area'\\s*=> '([a-z_]+)'/", $modules, $m );
			$this->assertNotSame( '', PluginAreas::icon_class( $m[1] ), $slug . ' points at an area that does not exist' );
		}
	}

	public function test_areas_and_modules_follow_the_menu_order(): void {
		// The menus that set a position sit in the FFC block of the sidebar;
		// AREAS lists them in that order.
		$positions = array();
		foreach ( self::REGISTRARS as $area => $registrar ) {
			if ( 1 === preg_match( '/add_menu_page\([^;]*?\b(26\.\d+)\s*\);/s', self::read( $registrar[0] ), $m ) ) {
				$positions[ $area ] = (float) $m[1];
			}
		}
		$this->assertCount( 5, $positions, 'the FFC block lost or gained a positioned menu' );
		$sorted = $positions;
		asort( $sorted );
		$this->assertSame( array_keys( $sorted ), array_keys( $positions ), 'PluginAreas lists the areas out of menu order' );

		// The Modules tab lists modules in MODULE_SLUGS order; mapped to their
		// areas, that must be the menu order too.
		$modules = self::read( 'includes/settings/views/ffc-tab-modulos.php' );
		$order   = array();
		foreach ( SettingsReader::MODULE_SLUGS as $slug ) {
			preg_match( "/'" . $slug . "'\\s*=> array\\(\\s*'area'\\s*=> '([a-z_]+)'/", $modules, $m );
			$order[ $m[1] ] = true;
		}
		$areas = array_values( array_diff( array_keys( PluginAreas::all() ), array( 'settings' ) ) );
		$this->assertSame( $areas, array_keys( $order ), 'Settings → Modules lists the modules out of menu order' );
	}

	public function test_menu_stylesheet_paints_every_area_in_the_menu_colour(): void {
		$css = Icons::menu_stylesheet();
		foreach ( PluginAreas::all() as $area ) {
			$this->assertStringContainsString( '#adminmenu #' . $area['menu'] . ' div.wp-menu-image::before{-webkit-mask-image:url("data:image/svg+xml,', $css );
		}
		// The colour comes from WordPress's own rules for the scheme, hover and
		// current states; the stylesheet must not name one outside forced colours.
		$this->assertStringContainsString( 'background-color:currentColor', $css );
		$this->assertStringContainsString( '@media (forced-colors: active)', $css );
		$this->assertStringContainsString( 'forced-color-adjust:none;background-color:LinkText', $css );
		$this->assertDoesNotMatchRegularExpression( '/#[0-9a-f]{3,6}[;}]/i', $css );
	}

	public function test_admin_menu_icons_attaches_the_css_to_the_core_menu_sheet(): void {
		Functions\expect( 'add_action' )->once()->with( 'admin_enqueue_scripts', array( AdminMenuIcons::class, 'print_css' ) );
		AdminMenuIcons::init();

		Functions\expect( 'wp_add_inline_style' )->once()->with( 'admin-menu', Icons::menu_stylesheet() );
		AdminMenuIcons::print_css();
	}
}
