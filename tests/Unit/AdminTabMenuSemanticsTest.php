<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The page-link tab menus are a navigation, not a tablist (#2344).
 *
 * Each entry of these menus loads its own page. Marked up as `role="tab"`
 * with `tabindex="-1"` on the inactive ones and no arrow-key handler, only
 * the active entry was reachable by keyboard, and `aria-controls` named
 * panels that do not exist. A `<nav>` of plain links with `aria-current`
 * on the page shown is the honest shape. The in-page tabs of
 * `AdminUI::render_vertical_tabs()` and the user dashboard are real tabs
 * and are not read here.
 *
 * @coversNothing
 */
class AdminTabMenuSemanticsTest extends TestCase {

	/** The files that print a page-link tab menu. */
	private const MENUS = array(
		'includes/admin/class-ffc-settings.php',
		'includes/audience/class-ffc-audience-admin-settings.php',
	);

	/** The files that print the panel those menus sit beside. */
	private const PANELS = array(
		'includes/admin/class-ffc-settings.php',
		'includes/audience/class-ffc-audience-admin-settings.php',
	);

	/**
	 * @return array<string, array{0: string}>
	 */
	public function provide_menus(): array {
		$out = array();
		foreach ( self::MENUS as $file ) {
			$out[ $file ] = array( $file );
		}
		return $out;
	}

	/**
	 * @dataProvider provide_menus
	 */
	public function test_a_page_link_menu_is_a_nav_with_aria_current( string $file ): void {
		$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/' . $file );

		$this->assertStringContainsString( '<nav class="ffc-settings-tabs__nav"', $src );
		$this->assertStringContainsString( 'aria-current="page"', $src );
		$this->assertStringNotContainsString( 'role="tablist"', $src );
		$this->assertDoesNotMatchRegularExpression( '/role="tab"|role=\\\\"tab|aria-selected|aria-controls/', $src );
	}

	public function test_no_panel_claims_to_be_a_tabpanel(): void {
		foreach ( self::PANELS as $file ) {
			$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/' . $file );
			$this->assertDoesNotMatchRegularExpression( '/ffc-settings-tabs__panel"[^>]*role="tabpanel"/', $src, $file );
			$this->assertStringContainsString( 'ffc-settings-tabs__panel', $src, $file . ' still prints the panel.' );
		}
	}
}
