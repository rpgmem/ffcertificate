<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Dashicons stay where they are WordPress chrome, and nowhere else (#1613).
 *
 * The plugin draws its own components from `Core\Icons`. A dashicon is right
 * only where WordPress draws the icon itself: the `menu_icon` of a post type
 * and the icon argument of `add_menu_page()`, both shown in the admin menu
 * beside core's. Everything else was replaced, screen by screen, and this
 * freezes the result: a file that gains a dashicon fails, and so does one on
 * the list that loses its last (drop it from the list to lock the win in).
 *
 * The count is per file and exact, so a second dashicon in an allowed file
 * fails too; the reason says what the allowed ones are.
 *
 * @coversNothing
 */
class DashiconsChromeOnlyTest extends TestCase {

	/**
	 * Files allowed to name dashicons: how many times, and why.
	 *
	 * @var array<string, array{0: int, 1: string}>
	 */
	private const ALLOWED = array(
		'includes/admin/class-ffc-cpt.php'                            => array( 1, 'the post type menu_icon, drawn by WordPress in the admin menu' ),
		'includes/admin/class-ffc-settings.php'                       => array( 1, 'the add_menu_page() icon' ),
		'includes/audience/class-ffc-audience-admin-page.php'         => array( 4, 'the add_menu_page() icon, and the admin-menu separator labels printed into #adminmenu, which is WordPress chrome' ),
		'includes/core/class-ffc-icons.php'                           => array( 2, 'Icons::tab_class(): a filter-contributed tab that still names a dashicon keeps drawing as one' ),
		'includes/date-messages/class-ffc-date-messages-admin-page.php' => array( 1, 'the add_menu_page() icon' ),
		'includes/recruitment/class-ffc-recruitment-admin-page.php'   => array( 1, 'the add_menu_page() icon' ),
		'includes/reregistration/class-ffc-reregistration-admin.php'  => array( 1, 'the add_menu_page() icon' ),
		'includes/url-shortener/class-ffc-url-shortener-admin-page.php' => array( 1, 'the add_menu_page() icon' ),
	);

	private static function root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * Dashicon references per file, under the trees that ship markup.
	 *
	 * @return array<string, int>
	 */
	private static function scan(): array {
		$found = array();
		foreach ( array( 'includes', 'templates', 'assets/js' ) as $dir ) {
			$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( self::root() . '/' . $dir, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $it as $file ) {
				$name = $file->getFilename();
				if ( ! preg_match( '/\.(php|js)$/', $name ) || str_ends_with( $name, '.min.js' ) ) {
					continue;
				}
				$count = substr_count( (string) file_get_contents( $file->getPathname() ), 'dashicons' );
				if ( $count > 0 ) {
					$found[ substr( $file->getPathname(), strlen( self::root() ) + 1 ) ] = $count;
				}
			}
		}
		ksort( $found );
		return $found;
	}

	public function test_dashicons_appear_only_as_wordpress_chrome(): void {
		$expected = array_map( static fn( array $entry ): int => $entry[0], self::ALLOWED );
		ksort( $expected );
		$this->assertSame( $expected, self::scan(), 'draw our own components from Core\Icons; a dashicon belongs only to the admin menu (CLAUDE.md "Icons")' );
	}

	public function test_every_allowance_carries_a_reason(): void {
		foreach ( self::ALLOWED as $file => $entry ) {
			$this->assertGreaterThan( 0, $entry[0], $file );
			$this->assertNotSame( '', $entry[1], $file );
		}
	}

	public function test_the_scan_counts_what_it_reads(): void {
		$this->assertSame( 0, substr_count( "<span class=\"ffc-icon-copy\"></span>", 'dashicons' ) );
		$this->assertArrayHasKey( 'includes/audience/class-ffc-audience-admin-page.php', self::scan(), 'the scan did not reach a nested module directory' );
	}
}
