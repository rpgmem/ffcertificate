<?php
/**
 * Scope of `ffc-admin-utilities.css` (#1171).
 *
 * The sheet's docblock says what it accepts; this test is what turns that into
 * a rule rather than a request. A utility names a **property** or a generic
 * visual shape — `.ffc-mt-20`, `.ffc-w100`, `.ffc-monospace` — and resolves to
 * **one rule**, with a single class selector: no descendant, no pseudo-class,
 * no element sub-selector.
 *
 * Nine screen components lived here and left in #1171. What brought them is the
 * failure mode `CLAUDE.md` already names for `services/` and `integrations/`:
 * **a generic name invites drift**, and fixing it later is expensive. The
 * defence is not a future rename, it is the scope written down — and written in
 * a way that can fail.
 *
 * **Why moving costs verification rather than being tidy-up.** This sheet is a
 * DEPENDENCY of `ffc-admin-css` and is enqueued in exactly one place
 * (`AdminAssetsManager::enqueue_admin_base_styles()`, which enqueues the two in
 * consecutive statements), so everything here reaches every FFC admin screen.
 * Moving a component to its owner module's sheet **narrows** that reach: if
 * that sheet is not enqueued on the screen rendering the component, the styles
 * vanish with nothing to report it. That is why #1171 measured screen by screen
 * before moving anything.
 *
 * No dependency: it reads the sheet as text.
 *
 * @package FreeFormCertificate\Tests\Unit
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use FreeFormCertificate\Tests\Support\CssSelectors;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class UtilityScopeTest extends TestCase {

	/**
	 * Absolute path of the utilities sheet.
	 */
	private static function sheet(): string {
		return dirname( __DIR__, 2 ) . '/assets/css/ffc-admin-utilities.css';
	}

	/**
	 * The selectors the sheet declares, one per list entry.
	 *
	 * @return array<int, string>
	 */
	private static function selectors(): array {
		$out = array();

		foreach ( CssSelectors::rules( (string) file_get_contents( self::sheet() ) ) as $rule ) {
			foreach ( CssSelectors::split_list( $rule['selector'] ) as $selector ) {
				$out[] = trim( (string) preg_replace( '/\s+/', ' ', $selector ) );
			}
		}

		return $out;
	}

	/**
	 * Every selector is a bare class — no components.
	 *
	 * A descendant (`.ffc-x .ffc-y`), a pseudo-class (`:hover`) or an element
	 * sub-selector (`.ffc-x strong`) is structure, and structure is a
	 * component: it belongs to the sheet of the module that renders it.
	 */
	public function test_every_selector_is_a_single_bare_class(): void {
		$offenders = array();

		foreach ( self::selectors() as $selector ) {
			if ( ! preg_match( '/^\.[A-Za-z_][A-Za-z0-9_-]*$/', $selector ) ) {
				$offenders[] = $selector;
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			"Selector that is not a utility in `ffc-admin-utilities.css`:\n  " . implode( "\n  ", $offenders )
			. "\n\nA descendant, pseudo-class or element sub-selector is STRUCTURE, and"
			. "\nstructure is a component: it goes to the sheet of the module that renders it."
			. "\nBefore moving one, confirm that sheet is enqueued on the screen that"
			. "\nrenders the component — this sheet reaches EVERY FFC admin screen, and"
			. "\nmoving narrows that reach with nothing to report the loss."
		);
	}

	/**
	 * Each class resolves to exactly one rule.
	 *
	 * Two rules for the same name mean it has states or variants — a component
	 * again.
	 */
	public function test_every_class_is_declared_exactly_once(): void {
		$counts = array_count_values( self::selectors() );
		$repeat = array();

		foreach ( $counts as $selector => $times ) {
			if ( 1 < $times ) {
				$repeat[] = sprintf( '%s (%dx)', $selector, $times );
			}
		}

		$this->assertSame(
			array(),
			$repeat,
			"Class declared more than once in the utilities sheet:\n  " . implode( "\n  ", $repeat )
			. "\n\nMore than one rule for a name is a state or a variant, i.e. a component."
		);
	}

	/**
	 * Every name carries the house prefix.
	 *
	 * Redundant with `ClassNamingIdiomTest` for this sheet, and deliberately so:
	 * this is where the question "can I put a generic class here?" is born.
	 */
	public function test_every_utility_carries_the_prefix(): void {
		foreach ( self::selectors() as $selector ) {
			$this->assertStringStartsWith(
				'.ffc-',
				$selector,
				"Utility without the prefix: {$selector}"
			);
		}
	}

	/**
	 * The scan must not collapse in silence.
	 *
	 * An empty selector list satisfies the three tests above just as well as a
	 * correct sheet — the #1071 / #1094 shape.
	 */
	public function test_the_scan_cannot_collapse_in_silence(): void {
		$selectors = self::selectors();

		$this->assertFileExists( self::sheet() );
		$this->assertGreaterThan( 15, count( $selectors ), 'Reading the sheet collapsed.' );

		// And it has to be able to say NO: a component selector is refused.
		$this->assertSame(
			0,
			preg_match( '/^\.[A-Za-z_][A-Za-z0-9_-]*$/', '.ffc-preflight-badge a:hover' ),
			'The pattern accepts a component selector — the net is catching the ocean.'
		);
	}
}
