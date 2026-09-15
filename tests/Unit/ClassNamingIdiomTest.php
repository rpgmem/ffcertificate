<?php
/**
 * Class-naming idiom guard (#1170).
 *
 * #1167 measured three live idioms saying the SAME thing — a prefixed modifier
 * (`.ffc-day.ffc-selected`), an unprefixed one
 * (`.ffc-consent-status.consent-given`) and a SMACSS state
 * (`.ffc-cap-role.is-on`) — and #1170 converges them into two, by function:
 *
 *  - **transient state**, what the interaction turns on and off (open, active,
 *    collapsed, selected, copied), stays UNPREFIXED, as `is-` / `has-`.
 *    That is the SMACSS convention, it reads well, and the name only ever
 *    appears compounded — a bare `.is-open` has no anchor and fails
 *    `CssNamespaceAnchorTest`.
 *  - **everything else of ours** — a variant the data dictates, and an element
 *    of the component — takes `ffc-`.
 *
 * This guard refuses the third idiom: a class of ours with no prefix and no
 * `is-`/`has-`. The 38 names that existed were converted in #1170; what stays
 * unprefixed is emitted by WordPress or by CodeMirror, and is listed here with
 * its reason.
 *
 * **It is not about collision** — all 38 appeared compounded with an `ffc-`
 * class, which is why #1152's anchor ratchet never saw them. It is about
 * READING: `.value`, `.top`, `.remove`, `.label`, `.open` do not say whose they
 * are, and whoever reads the sheet reconstructs the context from the whole
 * selector. By `CLAUDE.md`'s priority rule, that is a recurring, reader-facing
 * inconsistency, not a cosmetic one.
 *
 * And there is a gain that is not about reading, measured in the same pass: a
 * variant assembled at runtime out of a bare word (`$progress_color =
 * 'complete'`) is INVISIBLE to `CssClassEmitters` by construction — the scan
 * only records an `ffc-` prefix. With the prefix the variable holds a literal
 * and the scan finds it. That is how three dead rules surfaced.
 *
 * No dependency: it reads the sheets as text.
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
final class ClassNamingIdiomTest extends TestCase {

	/**
	 * Whole families that WordPress (or CodeMirror) emits.
	 *
	 * Prefix => reason. Styling them is legitimate: they are the markup core
	 * hands over and the plugin decorates. Prefixing them is impossible — we
	 * are not the ones emitting them.
	 */
	private const VENDOR_PREFIXES = array(
		'cm-'         => 'CodeMirror emits it; the `cm-s-ffc-dark` theme is ours and is already anchored',
		'CodeMirror'  => 'likewise — the editor root',
		'column-'     => 'WordPress list-table column; the slug comes from core or from our CPT',
		'wp-'         => 'core class (`wp-list-table`, `wp-admin`, `wp-submenu`, …)',
		'post-type-'  => 'core body class; ours already carries `ffc_` and is anchored',
		'nav-tab'     => 'core tabs (`nav-tab`, `nav-tab-active`, `nav-tab-wrapper`)',
		'postbox'     => 'core metabox (`postbox`, `postbox-header`)',
		'tablenav'    => 'core list-table navigation bar',
		'button'      => 'core button (`button`, `button-primary`, `button-secondary`)',
	);

	/**
	 * Standalone names WordPress emits, each with its reason.
	 *
	 * A ratchet both ways: a new name here fails, and one that vanished from
	 * the sheets fails too — the dead entry leaves to lock the win in.
	 */
	private const VENDOR_CLASSES = array(
		'alternate'        => 'core list-table row striping',
		'card'             => 'core admin card (about.php, plugin cards)',
		'current'          => 'core current pagination / subsubsub item',
		'dashicons'        => 'core icon font',
		'description'      => 'core field helper text',
		'disabled'         => 'core button state (`.button.disabled`), written by core itself',
		'displaying-num'   => 'core list-table item count',
		'error'            => 'core admin notice (`div.error`) — not to be confused with a state of ours, which is `has-error`',
		'form-table'       => 'core admin form table',
		'hndle'            => 'core metabox title',
		'howto'            => 'core instruction text',
		'inside'           => 'core metabox body',
		'large-text'       => 'core input width modifier',
		'misc-pub-section' => 'core publish-box section',
		'notice'           => 'core admin notice',
		'spinner'          => 'core loading indicator',
		'striped'          => 'core list-table modifier',
		'subsubsub'        => 'core list-table top filters',
		'tablenav-pages'   => 'core list-table pagination',
		'top'              => 'the top half of core\'s `tablenav` (the pair is `bottom`)',
		'updated'          => 'core admin notice',
		'widefat'          => 'core table modifier',
		'wrap'             => 'core admin page wrapper',
	);

	/**
	 * Every class the sheets declare, with the sheets it appears in.
	 *
	 * @return array<string, array<int, string>>
	 */
	private static function declared(): array {
		$out = array();

		foreach ( CssSelectors::sheets() as $path ) {
			$sheet = basename( $path );

			foreach ( CssSelectors::rules( (string) file_get_contents( $path ) ) as $rule ) {
				foreach ( CssSelectors::split_list( $rule['selector'] ) as $selector ) {
					if ( ! preg_match_all( '/\.(-?[A-Za-z_][A-Za-z0-9_-]*)/', $selector, $m ) ) {
						continue;
					}
					foreach ( array_unique( $m[1] ) as $class ) {
						if ( ! isset( $out[ $class ] ) ) {
							$out[ $class ] = array();
						}
						if ( ! in_array( $sheet, $out[ $class ], true ) ) {
							$out[ $class ][] = $sheet;
						}
					}
				}
			}
		}

		ksort( $out );

		return $out;
	}

	/**
	 * The class follows one of the two idioms, or belongs to a third party.
	 *
	 * @param string $class Class name, without the dot.
	 */
	private static function is_allowed( string $class ): bool {
		if ( str_starts_with( $class, 'ffc-' ) || str_starts_with( $class, 'ffc_' ) ) {
			return true;
		}
		if ( str_starts_with( $class, 'is-' ) || str_starts_with( $class, 'has-' ) ) {
			return true;
		}
		if ( isset( self::VENDOR_CLASSES[ $class ] ) ) {
			return true;
		}
		foreach ( array_keys( self::VENDOR_PREFIXES ) as $prefix ) {
			if ( str_starts_with( $class, $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * No class of ours goes without a prefix and without `is-`/`has-`.
	 */
	public function test_no_class_uses_the_third_idiom(): void {
		$offenders = array();

		foreach ( self::declared() as $class => $sheets ) {
			if ( ! self::is_allowed( $class ) ) {
				$offenders[] = sprintf( '.%s (%s)', $class, implode( ', ', $sheets ) );
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			"Class with no prefix and no `is-`/`has-`:\n  " . implode( "\n  ", $offenders )
			. "\n\nDecide by FUNCTION, not by the word:"
			. "\n  state the interaction turns on and off  → `is-` / `has-`"
			. "\n  variant the data dictates, or element   → `ffc-`"
			. "\nIf WordPress is the one emitting it, add it to VENDOR_CLASSES with the reason."
		);
	}

	/**
	 * A third-party entry that vanished from the sheets leaves the list.
	 *
	 * The direction that locks the win in, as in the other ratchets.
	 */
	public function test_the_vendor_list_only_shrinks(): void {
		$declared = self::declared();
		$stale    = array();

		foreach ( array_keys( self::VENDOR_CLASSES ) as $class ) {
			if ( ! isset( $declared[ $class ] ) ) {
				$stale[] = $class;
			}
		}

		$this->assertSame(
			array(),
			$stale,
			"No sheet declares these any more — drop them from VENDOR_CLASSES:\n  ." . implode( "\n  .", $stale )
		);
	}

	/**
	 * A SMACSS state is never declared bare.
	 *
	 * `.is-open` on its own reaches any element on the screen, the theme's
	 * included. `CssNamespaceAnchorTest` would already refuse the selector for
	 * want of an anchor; this is the same rule stated from the idiom's side, so
	 * that the answer to "so can I use `is-` anywhere?" is written where the
	 * question is born.
	 */
	public function test_state_classes_are_never_declared_bare(): void {
		$bare = array();

		foreach ( CssSelectors::sheets() as $path ) {
			foreach ( CssSelectors::rules( (string) file_get_contents( $path ) ) as $rule ) {
				foreach ( CssSelectors::split_list( $rule['selector'] ) as $selector ) {
					$class = CssSelectors::bare_class( $selector );
					if ( null === $class ) {
						continue;
					}
					if ( str_starts_with( $class, 'is-' ) || str_starts_with( $class, 'has-' ) ) {
						$bare[] = sprintf( '%s: .%s', basename( $path ), $class );
					}
				}
			}
		}

		$this->assertSame(
			array(),
			$bare,
			"State declared bare — compound it with the component's class:\n  " . implode( "\n  ", $bare )
		);
	}

	/**
	 * The scan must not collapse in silence.
	 *
	 * An empty map satisfies the three tests above just as well as a correct
	 * one — the #1071 / #1094 shape.
	 */
	public function test_the_scan_cannot_collapse_in_silence(): void {
		$declared = self::declared();

		$this->assertGreaterThan( 1000, count( $declared ), 'Reading the sheets collapsed.' );

		$states = array_filter(
			array_keys( $declared ),
			static fn ( string $c ): bool => str_starts_with( $c, 'is-' ) || str_starts_with( $c, 'has-' )
		);
		$this->assertGreaterThan( 10, count( $states ), 'The state idiom vanished from the sheets.' );

		$this->assertFalse(
			self::is_allowed( 'consent-given' ),
			'The guard accepts any name — `consent-given` is exactly what #1170 converted.'
		);
		$this->assertTrue( self::is_allowed( 'is-open' ) );
		$this->assertTrue( self::is_allowed( 'ffc-detail-value' ) );
	}
}
