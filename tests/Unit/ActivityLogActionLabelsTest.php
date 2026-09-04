<?php
/**
 * Activity-log action label guard (#1024).
 *
 * `AdminActivityLogPage::get_action_label()` maps an action key to a
 * translated label and falls back to `ucwords( str_replace( '_', ' ', $key ) )`
 * for anything it does not know. That fallback is silent and produces
 * untranslated English in every locale, so an action without a label looks
 * deliberate: nothing fails, nothing warns, the log just stops being
 * translatable. It had swallowed 49 of the 64 action keys in use — every
 * recruitment action, every privacy action, every migration — reported as
 * "Capabilities Granted" showing up untranslated on a Portuguese install.
 *
 * The label feeds three surfaces: the log table, the per-action summary, and
 * the CSV export.
 *
 * WHY THE FALLBACK STAYS. Rows written by an older version can carry a key
 * that no longer exists in the code, and a filter could inject one. Those must
 * still render something. The fallback is for them; this guard is what stops a
 * *current* action from relying on it.
 *
 * WHAT THIS CANNOT SEE. Only actions written as a string literal in the first
 * argument of `ActivityLog::log()`. An action assembled at runtime
 * (`'prefix_' . $x`) is invisible here, and so is one that only ever arrives
 * through a filter. It also cannot judge whether a label reads well or is
 * translated correctly — only that one exists.
 *
 * Dependency-free on purpose — no WordPress, no Brain\Monkey — like the module
 * boundary, AJAX wiring and settings-default guards. It reads source text only.
 *
 * @package FreeFormCertificate\Tests\Unit
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class ActivityLogActionLabelsTest extends TestCase {

	private const LOG_PAGE     = 'includes/admin/class-ffc-admin-activity-log-page.php';
	private const ACTIVITY_LOG = 'includes/core/class-ffc-activity-log.php';

	/**
	 * Absolute path to the repository root.
	 */
	private static function root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * Every PHP file under includes/.
	 *
	 * @return array<int, string>
	 */
	private static function sources(): array {
		$found = array();

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( self::root() . '/includes', \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( $file->isFile() && 'php' === $file->getExtension() ) {
				$found[] = $file->getPathname();
			}
		}

		return $found;
	}

	/**
	 * Action keys written as a literal first argument to `ActivityLog::log()`.
	 *
	 * Deliberately narrow about the receiver. An earlier, looser pass matched
	 * `::log(` on any class and swept in keys belonging to other loggers
	 * entirely; only `ActivityLog` — however it is spelled at the call site —
	 * and `self::` inside `ActivityLog` itself count.
	 *
	 * @return array<int, string>
	 */
	private static function written_actions(): array {
		$keys = array();

		foreach ( self::sources() as $file ) {
			$source = (string) file_get_contents( $file );

			preg_match_all( "/ActivityLog::log\(\s*'([a-z0-9_]+)'/", $source, $m );
			$keys = array_merge( $keys, $m[1] );

			if ( self::root() . '/' . self::ACTIVITY_LOG === $file ) {
				preg_match_all( "/self::log\(\s*'([a-z0-9_]+)'/", $source, $m );
				$keys = array_merge( $keys, $m[1] );
			}
		}

		return array_values( array_unique( $keys ) );
	}

	/**
	 * Action keys named in `ActivityLog::category_for_action()`'s map. An
	 * action explicitly categorised there is one the plugin knows about, even
	 * if this guard cannot see where it is written.
	 *
	 * @return array<int, string>
	 */
	private static function categorised_actions(): array {
		$source = (string) file_get_contents( self::root() . '/' . self::ACTIVITY_LOG );

		$block = explode( '$by_action = array(', $source );
		self::assertCount( 2, $block, 'ActivityLog::category_for_action() no longer declares $by_action.' );

		$block = explode( ');', $block[1] )[0];
		preg_match_all( "/'([a-z0-9_]+)'\s*=>/", $block, $m );

		return $m[1];
	}

	/**
	 * Action keys that have a translated label.
	 *
	 * @return array<int, string>
	 */
	private static function labelled_actions(): array {
		preg_match_all( "/'([a-z0-9_]+)'\s*=>\s*__\(/", self::label_map_body(), $m );

		return $m[1];
	}

	/**
	 * The body of `get_action_label()`'s `$labels` map.
	 *
	 * Anchored on the method, not on the first `$labels = array(` in the file:
	 * `get_preflight_reason_label()` declares an unrelated map of the same
	 * name further down, and slicing on the variable alone silently mixed the
	 * two.
	 */
	private static function label_map_body(): string {
		$source = (string) file_get_contents( self::root() . '/' . self::LOG_PAGE );

		$after = explode( 'function get_action_label(', $source );
		self::assertCount( 2, $after, 'AdminActivityLogPage no longer declares get_action_label().' );

		$map = explode( '$labels = array(', $after[1] );
		self::assertGreaterThan( 1, count( $map ), 'get_action_label() no longer declares $labels.' );

		return explode( "\t\t);", $map[1] )[0];
	}

	public function test_every_known_action_has_a_translated_label(): void {
		$known    = array_unique( array_merge( self::written_actions(), self::categorised_actions() ) );
		$labelled = self::labelled_actions();

		sort( $known );
		$missing = array_values( array_diff( $known, $labelled ) );

		$this->assertSame(
			array(),
			$missing,
			"These activity-log actions have no entry in AdminActivityLogPage::get_action_label():\n\n  "
			. implode( "\n  ", $missing )
			. "\n\nWithout one they render through ucwords(), which is untranslated English in every\n"
			. 'locale, across the log table, the per-action summary and the CSV export.'
		);
	}

	/**
	 * The extraction is only meaningful if it actually finds things. Were a
	 * refactor to change how logging is called, every regex above could return
	 * nothing and the test would pass while checking nothing at all.
	 */
	public function test_the_guard_can_see_both_sides(): void {
		$this->assertGreaterThan(
			40,
			count( self::written_actions() ),
			'Found suspiciously few logged actions — the call-site pattern probably no longer matches.'
		);

		$this->assertGreaterThan(
			40,
			count( self::labelled_actions() ),
			'Found suspiciously few labels — the $labels pattern probably no longer matches.'
		);
	}

	/**
	 * Every label must be wrapped in `__()` with the plugin text domain, or it
	 * is not translatable even though it is present. The extraction above only
	 * collects `__()`-wrapped entries, so a bare string would silently read as
	 * "missing" — this states the real reason.
	 */
	public function test_no_label_is_declared_without_a_translation_call(): void {
		$block = self::label_map_body();

		preg_match_all( "/'([a-z0-9_]+)'\s*=>\s*(.+?),\n/s", $block, $m, PREG_SET_ORDER );

		$bare = array();
		foreach ( $m as $entry ) {
			if ( ! str_contains( $entry[2], "__(" ) || ! str_contains( $entry[2], "'ffcertificate'" ) ) {
				$bare[] = $entry[1];
			}
		}

		$this->assertSame(
			array(),
			$bare,
			"These labels are not wrapped in __( …, 'ffcertificate' ) and so are never translated:\n  "
			. implode( "\n  ", $bare )
		);
	}
}
