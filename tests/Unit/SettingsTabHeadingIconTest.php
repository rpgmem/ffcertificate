<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\Icons;

/**
 * Every Settings tab draws its own icon on its own screen (#1613).
 *
 * The tab menu and the screen's main heading are the same place to a reader,
 * so they carry the same icon. They had drifted apart: the menu was redrawn in
 * #1616 while the headings kept what the old emoji classes happened to be — a
 * phone on the QR Code tab, a palette on a rate-limit section, no icon at all
 * on three tabs. Nothing compared the two, because they live in different
 * files: the tab class names the icon, a view or a template draws the heading.
 *
 * This reads both, statically. It proves the icon is THERE, not that it sits
 * on the first heading; the render audit that found the drift (every tab
 * screenshot in a real WordPress) is what proves the rest, and it is a one-off
 * because it needs a database.
 *
 * @coversNothing
 */
class SettingsTabHeadingIconTest extends TestCase {

	/**
	 * Tabs whose screen is drawn outside the tab class and its view.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const RENDERED_ELSEWHERE = array(
		'activity-log' => array( 'includes/admin/class-ffc-admin-activity-log-page.php' ),
		'email-model'  => array( 'templates/admin/settings/email-model-box.php' ),
		'email-texts'  => array( 'templates/admin/settings/email-body-hub.php' ),
	);

	/**
	 * Tabs with no heading of their own, each with the reason.
	 *
	 * @var array<string, string>
	 */
	private const NO_TAB_HEADING = array(
		'advanced' => 'a set of unrelated sections (activity log, editor, debug, keys, danger zone) with no tab-level heading; each section carries the icon of its own subject, and the tab icon on "Activity Log" would mislabel it',
	);

	private static function root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * The tab icon each tab class declares, by slug.
	 *
	 * @return array<string, string>
	 */
	private static function tab_icons(): array {
		$icons = array();
		foreach ( (array) glob( self::root() . '/includes/settings/tabs/class-ffc-tab-*.php' ) as $file ) {
			if ( preg_match( "/\\\$this->tab_icon\s*=\s*'(ffc-icon-[a-z-]+)'/", (string) file_get_contents( (string) $file ), $m ) ) {
				$icons[ substr( basename( (string) $file, '.php' ), strlen( 'class-ffc-tab-' ) ) ] = $m[1];
			}
		}
		ksort( $icons );
		return $icons;
	}

	public function test_the_scan_reads_every_tab(): void {
		$recount = count(
			array_filter(
				(array) scandir( self::root() . '/includes/settings/tabs' ),
				static fn( $f ): bool => 1 === preg_match( '/^class-ffc-tab-[a-z-]+\.php$/', (string) $f )
					&& str_contains( (string) file_get_contents( self::root() . '/includes/settings/tabs/' . $f ), '$this->tab_icon' )
			)
		);
		$this->assertSame( $recount, count( self::tab_icons() ), 'a tab declares its icon in a shape the scan does not read' );
	}

	public function test_every_tab_icon_is_a_registered_class(): void {
		foreach ( self::tab_icons() as $slug => $icon ) {
			$this->assertArrayHasKey( substr( $icon, strlen( 'ffc-icon-' ) ), Icons::classes(), $slug . ' uses ' . $icon );
		}
	}

	public function test_every_tab_screen_draws_its_tab_icon(): void {
		$missing = array();
		foreach ( self::tab_icons() as $slug => $icon ) {
			if ( isset( self::NO_TAB_HEADING[ $slug ] ) ) {
				continue;
			}
			$files = array_merge(
				array( 'includes/settings/tabs/class-ffc-tab-' . $slug . '.php', 'includes/settings/views/ffc-tab-' . $slug . '.php' ),
				self::RENDERED_ELSEWHERE[ $slug ] ?? array()
			);
			$source = '';
			foreach ( $files as $file ) {
				if ( is_file( self::root() . '/' . $file ) ) {
					$source .= (string) file_get_contents( self::root() . '/' . $file );
				}
			}
			$source = (string) preg_replace( "/\\\$this->tab_icon\s*=\s*'[^']*';/", '', $source );
			if ( ! preg_match( '/\b' . preg_quote( $icon, '/' ) . '\b/', $source ) ) {
				$missing[] = $slug . ' (' . $icon . ')';
			}
		}
		$this->assertSame( array(), $missing, 'the main heading of these tabs does not draw the tab\'s own icon' );
	}

	public function test_every_rendered_elsewhere_entry_is_a_real_tab_and_file(): void {
		$icons = self::tab_icons();
		foreach ( self::NO_TAB_HEADING as $slug => $reason ) {
			$this->assertArrayHasKey( $slug, $icons, $slug );
			$this->assertNotSame( '', $reason, $slug );
		}
		foreach ( self::RENDERED_ELSEWHERE as $slug => $files ) {
			$this->assertArrayHasKey( $slug, $icons, $slug );
			foreach ( $files as $file ) {
				$this->assertFileExists( self::root() . '/' . $file );
			}
		}
	}
}
