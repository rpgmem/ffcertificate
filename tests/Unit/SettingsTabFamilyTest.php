<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guard: every settings tab is a `SettingsTab`.
 *
 * WRITTEN ONCE OVER THE FAMILY, BECAUSE WRITTEN PER FILE IT MISSED TWO.
 *
 * Sixteen `Tab*Test` files each carried a one-line
 * `assertInstanceOf( SettingsTab::class, $this->tab )`. Measured against the
 * directory, there are EIGHTEEN tab classes: `TabCaptcha` and
 * `TabIpDiagnostics` had no such assertion at all, because a per-file
 * convention only covers what somebody remembered to write. This reads the
 * directory instead, so a tab added tomorrow is covered the moment its file
 * lands.
 *
 * IT IS NOT A COMPILE-TIME GUARANTEE, WHICH IS WHY IT IS WORTH ASSERTING.
 *
 * The first reading of those sixteen called them unfalsifiable -- if a class
 * did not extend its parent, surely PHP would refuse to load it. It would
 * not: `extends` only fatals when the named parent is MISSING, and
 * `SettingsTab` is an abstract class that nothing in `includes/` declares as
 * a parameter or return type. Tabs are consumed through
 * `$visible_tabs[ $active_tab ]->render()` -- an array, duck-typed. So a tab
 * rewritten to stand alone would keep working until it reached for something
 * the base provides, and nothing would have said so.
 *
 * @coversNothing A directory of classes is its subject, not one class.
 */
class SettingsTabFamilyTest extends TestCase {

	/**
	 * Where the tabs live.
	 *
	 * @var string
	 */
	private const TABS = __DIR__ . '/../../includes/settings/tabs';

	/**
	 * The parent every one of them must have.
	 *
	 * @var string
	 */
	private const PARENT_CLASS = 'SettingsTab';

	/**
	 * Every tab class in the directory, as `class name => parent it declares`.
	 *
	 * Read as text rather than by loading the files: including them would
	 * autoload the whole settings stack, and what this asks is a question
	 * about the source, not about a live object.
	 *
	 * @return array<string, string>
	 */
	private function declared(): array {
		$found = array();

		foreach ( (array) glob( self::TABS . '/*.php' ) as $file ) {
			$source = (string) file_get_contents( (string) $file );

			if ( preg_match( '/^\s*(?:final\s+|abstract\s+)?class\s+(\w+)\s+extends\s+\\\\?([\w\\\\]+)/m', $source, $m ) ) {
				$found[ $m[1] ] = $m[2];
				continue;
			}

			// A class declaring NO parent is the defect this exists for, so
			// it has to reach the assertion rather than be skipped here.
			if ( preg_match( '/^\s*(?:final\s+)?class\s+(\w+)/m', $source, $m ) ) {
				$found[ $m[1] ] = '';
			}
		}

		return $found;
	}

	/**
	 * Every tab extends the base.
	 */
	public function test_every_tab_extends_the_settings_tab_base(): void {
		$tabs = $this->declared();

		// The self-check the #1071 / #1094 rule asks of every scan: an empty
		// reading must never pass as a clean one.
		$this->assertNotEmpty( $tabs, 'No tab class was found at all, so this proves nothing.' );

		foreach ( $tabs as $class => $parent ) {
			$this->assertSame(
				self::PARENT_CLASS,
				$parent,
				sprintf(
					'%s must extend %s. Nothing type-hints the base, so a tab that stops extending it keeps working until it reaches for something the base provides.',
					$class,
					self::PARENT_CLASS
				)
			);
		}
	}

	/**
	 * The scan reaches every file in the directory.
	 *
	 * Named separately because the assertion above passes vacuously on a
	 * parse that resolved fewer classes than there are files -- the way it
	 * would if a tab were declared under a shape the regex does not know.
	 */
	public function test_the_scan_resolves_every_file_in_the_directory(): void {
		$files = (array) glob( self::TABS . '/*.php' );

		$this->assertNotEmpty( $files, 'The tabs directory is empty, so this proves nothing.' );
		$this->assertCount(
			count( $files ),
			$this->declared(),
			'A file in the tabs directory declares no class this scan can see; teach the scan rather than lowering it.'
		);
	}
}
