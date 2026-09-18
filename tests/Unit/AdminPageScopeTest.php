<?php
/**
 * Every admin screen the plugin draws says so in its markup.
 *
 * @package FreeFormCertificate\Tests
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The page-scope anchor (#1184, a sub-issue of #1148).
 *
 * `CLAUDE.md` has called this *"genuinely worth doing"* since #1167, and what
 * is missing is always the same thing: #1152's ratchet freezes 41 selectors
 * that name nothing of ours, and the ones left after #1170 **are not badly
 * named classes of ours** -- they are `.tablenav`, `.column-*`, `.form-table`,
 * `.button`, `.card`, `code`: markup WordPress emits and that cannot be
 * prefixed. What they lack is an ancestor saying *this screen is ours*.
 *
 * The convention, as two classes on the `div.wrap`:
 *
 *  - **`ffc-admin-page`** -- every admin screen of ours. It is the anchor for a
 *    rule that holds on all of them; `ffc-admin.css` is enqueued twice under
 *    two handles -- `ffc-admin-css` on `is_ffc_page()` screens and `ffc-admin`
 *    on `users.php` (`AdminUserColumns`) -- so its `.tablenav` reaches a core
 *    screen today.
 *  - **`ffc-page-<slug>`** -- one screen, where `<slug>` is the screen's
 *    `?page=` minus the leading `ffc-`. It is the anchor for a rule that
 *    belongs to one screen and reaches the others through a wide gate:
 *    `ffc-admin-submissions.css` is gated on `is_ffc_page()`, which matches ANY
 *    `?page=ffc-*`, and its `.button[title]` builds a tooltip on every button
 *    with a `title` on every FFC screen.
 *
 * Both exist because both have consumers -- this is not one level held in
 * reserve.
 *
 * **This guard is the cheap half: it proves the anchor is in the markup, never
 * that a rule reads it.** What charges the reading is `CssNamespaceAnchorTest`,
 * and that is where the debt shrinks. Splitting the two is what makes this
 * delivery provable by construction: adding a class no rule reads cannot move a
 * pixel, and that is the strongest proof of zero movement there is.
 *
 * Three things #1184's measurement found, none of them guessable:
 *
 * 1. **`?page=` is not available as a derived source.** Two of the fourteen
 *    slugs -- `ffc-scheduling-dashboard` and `ffc-scheduling-environments` --
 *    exist as a literal nowhere in the repository: they are built by
 *    concatenation (`self::MENU_SLUG . '-dashboard'`). A check that required
 *    finding the slug in the source would report both as typos. It is the same
 *    finding `CssClassEmitters` records for a name assembled at runtime, and it
 *    is why the map below is a frozen register rather than a derivation.
 * 2. **`.ffc-settings-wrap` is NOT a competing page-scope name.** #1184 counted
 *    three precedents with no convention; measured, they are three different
 *    things. `ffc-settings-wrap` is on TWO screens (`ffc-settings` and
 *    `ffc-scheduling-settings`) -- it is a **layout** class, "this screen uses
 *    the vertical-tab design", and it stays. `ffc-recruitment-admin` is a
 *    genuine page anchor with exactly one rule reading it
 *    (`.wrap.ffc-recruitment-admin .card`), and it is the precedent this
 *    convention generalises.
 * 3. **Two `div.wrap` were NESTED inside the Settings wrap**, and so took no
 *    anchor: they are not screens. The nesting itself was debt -- core's
 *    `.wrap` carries its own margin, so nesting doubles it horizontally -- and
 *    one of them (`ffc-settings-page`) gave birth to seven duplicated selectors
 *    in `ffc-admin-settings.css`, writing `.ffc-settings-wrap .card,
 *    .ffc-settings-page .card` to reach the same element twice. Both were fixed
 *    in #1202 item 3; see NESTED below.
 */
class AdminPageScopeTest extends TestCase {

	/**
	 * The generic class, on every admin screen of ours.
	 */
	private const GENERIC = 'ffc-admin-page';

	/**
	 * Frozen map: screen class => the `?page=` that serves it.
	 *
	 * A register, not a derivation -- see item 1 of the class docblock.
	 *
	 * @var array<string, string>
	 */
	private const SCREENS = array(
		'ffc-page-certificates-dashboard' => 'ffc-certificates-dashboard',
		'ffc-page-submissions'            => 'ffc-submissions',
		'ffc-page-settings'               => 'ffc-settings',
		'ffc-page-scheduling-audiences'   => 'ffc-scheduling-audiences',
		'ffc-page-scheduling-bookings'    => 'ffc-scheduling-bookings',
		'ffc-page-scheduling-calendars'   => 'ffc-scheduling-calendars',
		// The parent menu `ffc-scheduling` and the submenu `ffc-scheduling-dashboard`
		// call the SAME `render_dashboard_page()`. A screen with two addresses is
		// still one screen: the class names the screen, by the submenu's slug.
		'ffc-page-scheduling-dashboard'   => 'ffc-scheduling-dashboard',
		'ffc-page-scheduling-environments' => 'ffc-scheduling-environments',
		'ffc-page-scheduling-settings'    => 'ffc-scheduling-settings',
		'ffc-page-recruitment'            => 'ffc-recruitment',
		'ffc-page-reregistration'         => 'ffc-reregistration',
		'ffc-page-custom-fields'          => 'ffc-custom-fields',
		'ffc-page-appointments'           => 'ffc-appointments',
		'ffc-page-short-urls'             => 'ffc-short-urls',
	);

	/**
	 * `div.wrap` left without an anchor, with the reason.
	 *
	 * EMPTY since #1202 item 3. The two entries were `wrap`s nested inside the
	 * Settings screen's `.ffc-settings-wrap`, and both stopped opening a
	 * `wrap`: the user-access tab became `<div class="ffc-settings-page">`, and
	 * the activity log's missing-view fallback now mirrors the tab's own real
	 * view (`ffc-settings-wrap` + `h2`, instead of `wrap` + `h1`).
	 *
	 * A new entry here is a `wrap` inside another one -- which costs core's
	 * `.wrap` horizontal margin twice (measured: 22px of width on the
	 * user-access tab). The vertical one does NOT double, it collapses.
	 *
	 * @var array<string, string>
	 */
	private const NESTED = array();

	/**
	 * Directories scanned, relative to the repository root.
	 *
	 * @var array<int, string>
	 */
	private const ROOTS = array( 'includes', 'templates' );

	/**
	 * Repository root.
	 *
	 * @return string
	 */
	private function root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * Every `class="wrap…"` emitted, with its file and line.
	 *
	 * @return array<int, array{file: string, line: int, classes: string}>
	 */
	private function wraps(): array {
		$found = array();
		$root  = $this->root();

		foreach ( self::ROOTS as $dir ) {
			$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( "{$root}/{$dir}" ) );
			foreach ( $it as $file ) {
				if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
					continue;
				}
				$rel   = str_replace( $root . '/', '', (string) $file->getPathname() );
				$lines = explode( "\n", (string) file_get_contents( (string) $file->getPathname() ) );
				foreach ( $lines as $i => $line ) {
					if ( 1 !== preg_match( '/class="(wrap(?:\s[^"]*)?)"/', $line, $m ) ) {
						continue;
					}
					$found[] = array(
						'file'    => $rel,
						'line'    => $i + 1,
						'classes' => $m[1],
					);
				}
			}
		}

		sort( $found );
		return $found;
	}

	/**
	 * The scan must not pass by being empty.
	 *
	 * An empty result must never read as "clean" -- the #1071 / #1094 lesson,
	 * which every guard in this arc carries.
	 *
	 * @return void
	 */
	public function test_the_scan_finds_the_admin_screens(): void {
		$this->assertGreaterThanOrEqual(
			20,
			count( $this->wraps() ),
			'The `class="wrap"` scan collapsed. An empty result is not "clean".'
		);
	}

	/**
	 * Every `div.wrap` of ours carries the generic anchor plus a screen one.
	 *
	 * @return void
	 */
	public function test_every_admin_wrap_declares_its_page_scope(): void {
		$missing = array();

		foreach ( $this->wraps() as $wrap ) {
			if ( isset( self::NESTED[ $wrap['file'] ] ) ) {
				continue;
			}

			$classes = preg_split( '/\s+/', $wrap['classes'] ) ?: array();
			$where   = "{$wrap['file']}:{$wrap['line']}";

			if ( ! in_array( self::GENERIC, $classes, true ) ) {
				$missing[] = "{$where}: missing `" . self::GENERIC . '`.';
			}

			$screen = array_values( array_intersect( $classes, array_keys( self::SCREENS ) ) );
			if ( 1 !== count( $screen ) ) {
				$missing[] = "{$where}: expected exactly one known `ffc-page-*` class, found "
					. ( array() === $screen ? 'none' : implode( ' + ', $screen ) ) . '.';
			}
		}

		$this->assertSame(
			array(),
			$missing,
			"Admin screen with no page-scope anchor. Add `" . self::GENERIC
				. ' ffc-page-<slug>` to the `div.wrap` and register the screen in SCREENS; if the'
				. " `wrap` is nested inside another one it is not a screen and goes to NESTED with the reason:\n"
				. implode( "\n", $missing )
		);
	}

	/**
	 * Every registered screen class is actually emitted.
	 *
	 * The ratchet holds both ways: a screen that left the plugin must not leave
	 * a dead name in the map, because a dead name in the map is what makes an
	 * orphaned CSS rule pass as anchored.
	 *
	 * @return void
	 */
	public function test_no_registered_screen_class_is_dead(): void {
		$emitted = array();
		foreach ( $this->wraps() as $wrap ) {
			foreach ( preg_split( '/\s+/', $wrap['classes'] ) ?: array() as $class ) {
				$emitted[ $class ] = true;
			}
		}

		$dead = array_values( array_diff( array_keys( self::SCREENS ), array_keys( $emitted ) ) );

		$this->assertSame(
			array(),
			$dead,
			"Registered screen class nobody emits. Drop it from SCREENS:\n" . implode( "\n", $dead )
		);
	}

	/**
	 * The nested-`wrap` exceptions still exist.
	 *
	 * @return void
	 */
	public function test_every_nested_exception_still_matches_a_real_wrap(): void {
		$files = array();
		foreach ( $this->wraps() as $wrap ) {
			$files[ $wrap['file'] ] = true;
		}

		$stale = array_values( array_diff( array_keys( self::NESTED ), array_keys( $files ) ) );

		$this->assertSame(
			array(),
			$stale,
			"NESTED exception that no longer matches any `class=\"wrap\"`. Drop it:\n" . implode( "\n", $stale )
		);
	}
}
