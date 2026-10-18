<?php
/**
 * Ratchet on WordPress core button classes in the plugin's own markup (#1904).
 *
 * An action is drawn by the `.ffc-btn` family (CLAUDE.md "Buttons"). The admin
 * screens predate it and still print WordPress's `button` / `button-primary`
 * / `page-title-action` and call `submit_button()`, which ignore the family's
 * categories, its icons and the dark theme. #1904 moves them screen by screen;
 * this freezes what is left, PER FILE and per token, in
 * `tests/fixtures/core-button-classes-baseline.php`, a ratchet both ways:
 *
 * - a file that gains one fails -- draw it with `Core\Button::html()`,
 *   `AdminUI::render_button()` / `render_save_button()` or `FFC.button()`;
 * - a file that loses some fails too -- regenerate the register so the win
 *   is locked in, and a file at zero leaves it.
 *
 * Regenerate after an intentional change and review the diff:
 * `FFC_UPDATE_CORE_BUTTON_BASELINE=1 vendor/bin/phpunit --filter CoreButtonClassRatchet`.
 *
 * What counts and what does not, and the few places core buttons stay on
 * purpose, are written once in `Tests\Support\CoreButtonClasses`. It sees
 * presence, never truth: a family button with the wrong category passes.
 *
 * @package FreeFormCertificate\Tests\Unit
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use FreeFormCertificate\Tests\Support\CoreButtonClasses;
use FreeFormCertificate\Tests\Support\InlineStyles;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class CoreButtonClassRatchetTest extends TestCase {

	private const BASELINE_FILE = __DIR__ . '/../fixtures/core-button-classes-baseline.php';

	/**
	 * The frozen register.
	 *
	 * @return array<string, array<string, int>>
	 */
	private static function baseline(): array {
		/** @var array<string, array<string, int>> $baseline */
		$baseline = require self::BASELINE_FILE;

		return $baseline;
	}

	/**
	 * Nothing new: no file gains a core button class, and no file joins.
	 */
	public function test_no_file_gains_a_core_button_class(): void {
		$found = CoreButtonClasses::found();

		if ( getenv( 'FFC_UPDATE_CORE_BUTTON_BASELINE' ) ) {
			$body = "<?php\n/**\n * Core-button-class register (#1904) — generated.\n"
				. " * Regenerate: FFC_UPDATE_CORE_BUTTON_BASELINE=1 vendor/bin/phpunit --filter CoreButtonClassRatchet\n"
				. " *\n * File => WordPress core button class (or `submit_button()` call) => how many\n"
				. " * times it still appears. A debt register that only shrinks: a file moves\n"
				. " * to the .ffc-btn family and leaves it (CLAUDE.md \"Buttons\").\n */\n\nreturn array(\n";
			foreach ( $found as $file => $tokens ) {
				$body .= "\t'" . $file . "' => array(\n";
				foreach ( $tokens as $token => $count ) {
					$body .= "\t\t'" . $token . "' => " . $count . ",\n";
				}
				$body .= "\t),\n";
			}
			$body .= ");\n";
			file_put_contents( self::BASELINE_FILE, $body );
			$this->markTestSkipped( 'Core-button-class register regenerated (' . count( $found ) . ' files).' );
		}

		$baseline = self::baseline();
		$grown    = array();
		foreach ( $found as $file => $tokens ) {
			foreach ( $tokens as $token => $count ) {
				$frozen = $baseline[ $file ][ $token ] ?? 0;
				if ( $count > $frozen ) {
					$grown[] = sprintf( '%s: %s (%d frozen, %d found)', $file, $token, $frozen, $count );
				}
			}
		}

		$this->assertSame(
			array(),
			$grown,
			"A WordPress core button class appeared in plugin markup:\n  " . implode( "\n  ", $grown )
			. "\n\nDraw the action with the .ffc-btn family -- Core\\Button::html(), AdminUI::render_button() /"
			. "\nrender_save_button() in PHP, FFC.button() / FFC.buttonHtml() in JS -- with the category that"
			. "\nsays what it does and a Core\\Icons class (CLAUDE.md \"Buttons\")."
		);
	}

	/**
	 * A file that lost some, or all, leaves the register at its new count.
	 */
	public function test_the_register_only_shrinks(): void {
		$found = CoreButtonClasses::found();
		$stale = array();

		foreach ( self::baseline() as $file => $tokens ) {
			foreach ( $tokens as $token => $frozen ) {
				$count = $found[ $file ][ $token ] ?? 0;
				if ( $count < $frozen ) {
					$stale[] = sprintf( '%s: %s (%d frozen, %d found)', $file, $token, $frozen, $count );
				}
			}
		}

		$this->assertSame(
			array(),
			$stale,
			"These shrank or no longer exist -- regenerate the register to lock the win in\n"
			. "(FFC_UPDATE_CORE_BUTTON_BASELINE=1 vendor/bin/phpunit --filter CoreButtonClassRatchet):\n  "
			. implode( "\n  ", $stale )
		);
	}

	/**
	 * The register holds counts, never a zero: a file at zero is not in it.
	 */
	public function test_the_register_holds_no_zero(): void {
		$zeros = array();
		foreach ( self::baseline() as $file => $tokens ) {
			foreach ( $tokens as $token => $count ) {
				if ( $count < 1 ) {
					$zeros[] = $file . ': ' . $token;
				}
			}
			if ( array() === $tokens ) {
				$zeros[] = $file;
			}
		}

		$this->assertSame( array(), $zeros, "Drop these entries from the register:\n  " . implode( "\n  ", $zeros ) );
	}

	/**
	 * Every exclusion names a real file that still has a core button, and
	 * says why.
	 *
	 * An exclusion whose file was renamed or migrated would stop excluding
	 * anything while still reading as a decision; failing makes it leave.
	 */
	public function test_every_exclusion_is_live_and_says_why(): void {
		$root = dirname( __DIR__, 2 );
		$live = CoreButtonClasses::found_in_excluded();

		foreach ( CoreButtonClasses::EXCLUDED as $path => $reason ) {
			$this->assertFileExists( $root . '/' . $path, $path . ' is excluded by name and no longer exists.' );
			$this->assertGreaterThanOrEqual( 20, strlen( $reason ), $path . ' is excluded without a reason.' );
			$this->assertGreaterThan( 0, $live[ $path ], $path . ' is excluded but prints no core button any more -- drop the exclusion.' );
		}
	}

	/**
	 * The shapes on synthetic source: what counts, and what must not.
	 *
	 * Synthetic, so the proof does not depend on what the tree contains today
	 * -- the `DeprecationDueTest` canary shape.
	 */
	public function test_the_shapes_on_synthetic_source(): void {
		$php = '<a class="button button-primary ffc-x">a</a>'
			. "<?php \$a = array( 'class' => 'button', 'type' => 'button' );"
			. " \$btn_class = \$f ? 'button button-small' : 'button';"
			. " printf( '<a class=\"button%s\">', \$p ? ' button-primary' : '' );"
			. " submit_button( 'Save' ); \$h = get_submit_button( 'Go' ); function submit_button() {}"
			. " echo '<button type=\"button\" role=\"button\" class=\"ffc-btn ffc-btn-neutral\">';"
			. " echo Button::html( array( 'type' => 'button' ) ); \$button = 'x'; \$o->button( 1 );"
			. " echo '<a class=\"page-title-action\">'; echo __( 'Click the button below.' ); ?>"
			. "<span class=\"x <?php echo \$y ? 'button-secondary' : ''; ?>\">b</span>"
			. '<p data-button="Choose">Press the button.</p>';

		$this->assertSame(
			array(
				'button'            => 5,
				'button-primary'    => 2,
				'button-secondary'  => 1,
				'button-small'      => 1,
				'page-title-action' => 1,
				'submit_button()'   => 2,
			),
			CoreButtonClasses::in_code( $php, true ),
			'The PHP shapes no longer read what they exist for -- or a type, a role, a helper or prose started counting.'
		);

		$js = "var s = text.replace(/'/g, '&#39;');\n"
			. "// FFC.button() and \$el.addClass('button-primary') in a comment\n"
			. "/* a.button-small in a block comment */\n"
			. "\$el.addClass('button button-primary');\n"
			. "\$(document).on('click', 'a.button-small', fn);\n"
			. "el.className = 'button ' + (x ? 'button-primary ' : '') + 'ffc-x';\n"
			. "FFC.button({ type: 'button', label: 'x' }); var b = event.button || config.button;\n"
			. "html += '<a data-button=\"x\" class=\"ffc-btn ffc-btn-neutral\" role=\"button\">';\n"
			. "html += '<button type=\"button\" class=\"button ffc-remove\">';\n";

		$this->assertSame(
			array(
				'button'         => 3,
				'button-primary' => 2,
				'button-small'   => 1,
			),
			CoreButtonClasses::in_code( CoreButtonClasses::strip_js_comments( $js ), false ),
			'The JS shapes no longer read what they exist for -- or a comment, a type, FFC.button() or a property started counting.'
		);
	}

	/**
	 * The scan must not collapse in silence, and a second reading agrees.
	 *
	 * The register is non-empty and spans dozens of files, so a scan that
	 * loses files already loses entries and the shrink test fires -- the
	 * exact comparison CLAUDE.md "The guards" prefers. That stops working the
	 * day the register reaches zero, so this adds the independent recount
	 * as well: the files listed by a different traversal, and the occurrences
	 * counted on source whose comments a different stripper removed.
	 */
	public function test_the_scan_cannot_collapse_in_silence(): void {
		$root = dirname( __DIR__, 2 );

		$php_by_glob = array();
		foreach ( array( 'includes', 'templates' ) as $dir ) {
			foreach ( InlineStyles::glob_recursive( $root . '/' . $dir, '*.php' ) as $path ) {
				$relative = str_replace( $root . '/', '', $path );
				if ( ! CoreButtonClasses::is_excluded( $relative ) ) {
					$php_by_glob[] = $relative;
				}
			}
		}
		sort( $php_by_glob );
		$this->assertSame( $php_by_glob, CoreButtonClasses::php_files(), 'The PHP file list disagrees with a glob() walk of the same roots.' );

		$js_by_scandir = array();
		foreach ( scandir( $root . '/assets/js' ) ?: array() as $name ) {
			if ( '.js' === substr( $name, -3 ) && ! str_contains( $name, '.min.' ) ) {
				$js_by_scandir[] = 'assets/js/' . $name;
			}
		}
		$this->assertSame( $js_by_scandir, CoreButtonClasses::js_files(), 'The JS file list disagrees with a scandir() of assets/js.' );

		$total = 0;
		foreach ( CoreButtonClasses::found() as $tokens ) {
			$total += array_sum( $tokens );
		}
		$this->assertSame(
			CoreButtonClasses::total_by_second_reading(),
			$total,
			'php_strip_whitespace() and a regex comment stripper count a different number of core button classes than the scan.'
		);
	}
}
