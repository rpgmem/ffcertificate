<?php
/**
 * A control or badge whose only boundary was its background needs a contour in
 * forced-colors mode.
 *
 * @package FreeFormCertificate\Tests
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use FreeFormCertificate\Tests\Support\CssSelectors;
use PHPUnit\Framework\TestCase;

/**
 * Forced-colors contour guard (#1165, a sub-issue of #1148).
 *
 * In forced-colors mode the user agent **forces** `color`, `background-color`
 * and `border-color` to the system colours and discards `box-shadow`. A
 * component whose only boundary was its background stops having one.
 *
 * **It was not a hypothesis.** Measured in Chromium against the real palette
 * and the real public sheet: the five scheduling status badges collapsed into
 * **one** (same white, same black, zero borders), and the public form's submit
 * button — the screen's primary action — became a word with no outline.
 *
 * The guard **blocks at zero**: every control or badge selector that declares a
 * background and no contour must be covered by an
 * `@media (forced-colors: active)` rule. Exceptions go in `ALLOWED` with the
 * reason.
 *
 * **Why only controls and badges, and not every background.** The scan finds
 * 274 selectors with a background and no contour; most are decorative (table
 * striping, a page background, a modal veil) and losing the background there
 * costs nothing. Demanding a border from all of them would be noise, and noise
 * is how a guard becomes something people learn to skip. The other 187 are
 * measured and recorded in #1165 — among them are cases that carry meaning (a
 * selected calendar day, a cancelled row) and that need case-by-case judgement,
 * not a rule.
 *
 * Three defects in the measurement itself, all fixed and none guessable:
 *
 * 1. **The focus ring is not a permanent contour.** The first scan counted a
 *    `:focus-visible` `outline` as if the component had a boundary, and so
 *    excluded `.ffc-submit-btn` of all things — the worst case. A contour only
 *    counts in the resting state.
 * 2. **Reading only `border-top-width` lies.** The probe reported
 *    `.ffc-form-info-block` as broken when it has `border-left: 4px` and was
 *    never broken at all. A component can have its boundary on one side only.
 * 3. **`border-radius` is not a border**, and a selector like `.ffc-pdf-stage`
 *    matches "tag" by substring. The category needs a segment boundary.
 *
 * What it does NOT see: whether the contour is *good-looking*, whether the
 * chosen system colour is the right one for the role, and what happens on a
 * real Windows machine — this measures declarations, and the render evidence
 * came from Chromium under emulation.
 */
class ForcedColorsContourTest extends TestCase {

	/**
	 * Selectors left without a contour rule, each with its reason.
	 *
	 * @var array<string, string>
	 */
	private const ALLOWED = array(
		// The PDF's paper, not a badge — it matched the category by substring
		// ("s-tag-e"). Print and PDF are light by definition (CLAUDE.md).
		'.ffc-pdf-stage' => 'the PDF paper; print is light by definition, and the category matched by substring',
	);

	/**
	 * A category token must be a whole segment, not a fragment.
	 */
	private const CONTROL = '/(?:^|[-.\s])(?:btn|button)(?:[-.\s]|$)/';
	private const BADGE   = '/(?:^|[-.\s])(?:status|badge|pill|tag|chip)(?:[-.\s]|$)/';

	/**
	 * States: what they declare does not describe the component at rest.
	 */
	private const STATE = '/:(hover|focus|focus-visible|focus-within|active|visited|disabled|checked|target)\b/';

	/**
	 * Reduces a selector to the component: no pseudo-element, no state.
	 *
	 * @param string $selector A single selector.
	 * @return string
	 */
	private function component( string $selector ): string {
		$s = (string) preg_replace( '/::[\w-]+(\([^)]*\))?/', '', $selector );
		$s = (string) preg_replace( self::STATE . 'u', '', $s );

		return trim( (string) preg_replace( '/\s+/', ' ', $s ) );
	}

	/**
	 * Scans the sheets: who has a background, who has a contour, who has a
	 * forced-colors rule.
	 *
	 * @return array{background: array<string, true>, contour: array<string, true>, forced: array<string, true>, rules: int}
	 */
	private function scan(): array {
		$background = array();
		$contour    = array();
		$forced     = array();
		$count      = 0;

		foreach ( CssSelectors::sheets() as $path ) {
			$css = (string) file_get_contents( $path );

			// The selectors that live inside a `forced-colors` block.
			foreach ( $this->forced_blocks( $css ) as $block ) {
				foreach ( CssSelectors::of( '@media x {' . $block . '}' ) as $one ) {
					$forced[ $this->component( $one ) ] = true;
				}
			}

			// …and the rest is read WITHOUT those blocks. Without this the guard
			// sabotages itself: the very border it demands starts counting as a
			// contour, the component stops looking like it needs one, and
			// deleting the rule later would no longer fail.
			foreach ( CssSelectors::rules( $this->without_forced_blocks( $css ) ) as $rule ) {
				++$count;
				$declarations = array();
				foreach ( explode( ';', $rule['body'] ) as $declaration ) {
					if ( ! str_contains( $declaration, ':' ) ) {
						continue;
					}
					[ $property, $value ] = explode( ':', $declaration, 2 );
					$declarations[]       = array( strtolower( trim( $property ) ), trim( $value ) );
				}

				foreach ( CssSelectors::split_list( $rule['selector'] ) as $one ) {
					$is_state  = 1 === preg_match( self::STATE, $one );
					$component = $this->component( $one );
					if ( '' === $component || $is_state ) {
						continue;
					}

					foreach ( $declarations as [$property, $value] ) {
						$first = strtok( $value, ' ' ) ?: '';

						if ( in_array( $property, array( 'background', 'background-color' ), true )
							&& ! in_array( $first, array( 'none', 'transparent', 'inherit', 'initial', 'unset' ), true )
							&& ! str_starts_with( $first, 'url(' ) ) {
							$background[ $component ] = true;
						}

						$paints_edge = ( 'border' === $property && 'none' !== $first && '0' !== $first )
							|| ( 1 === preg_match( '/^border-(top|right|bottom|left)(-(width|style))?$/', $property )
								&& ! in_array( $value, array( '0', 'none', '0px' ), true ) )
							|| ( in_array( $property, array( 'border-width', 'border-style' ), true )
								&& ! in_array( $value, array( '0', 'none', '0px' ), true ) );

						if ( $paints_edge ) {
							$contour[ $component ] = true;
						}
					}
				}
			}
		}

		return array(
			'background' => $background,
			'contour'    => $contour,
			'forced'     => $forced,
			'rules'      => $count,
		);
	}

	/**
	 * The sheet with the `forced-colors` blocks removed.
	 *
	 * @param string $css Sheet contents.
	 * @return string
	 */
	private function without_forced_blocks( string $css ): string {
		$clean = (string) preg_replace( '#/\*.*?\*/#s', '', $css );

		while ( preg_match( '/@media[^{]*forced-colors[^{]*\{/', $clean, $m, PREG_OFFSET_CAPTURE ) ) {
			$start = (int) $m[0][1];
			$i     = $start + strlen( $m[0][0] );
			$depth = 1;
			$len   = strlen( $clean );
			while ( $i < $len && $depth > 0 ) {
				if ( '{' === $clean[ $i ] ) {
					++$depth;
				} elseif ( '}' === $clean[ $i ] ) {
					--$depth;
				}
				++$i;
			}
			$clean = substr( $clean, 0, $start ) . substr( $clean, $i );
		}

		return $clean;
	}

	/**
	 * Bodies of a sheet's `@media (forced-colors: active)` blocks.
	 *
	 * @param string $css Sheet contents.
	 * @return array<int, string>
	 */
	private function forced_blocks( string $css ): array {
		$css   = (string) preg_replace( '#/\*.*?\*/#s', '', $css );
		$found = array();
		$at    = 0;

		while ( preg_match( '/@media[^{]*forced-colors[^{]*\{/', $css, $m, PREG_OFFSET_CAPTURE, $at ) ) {
			$open  = (int) $m[0][1] + strlen( $m[0][0] );
			$depth = 1;
			$i     = $open;
			$len   = strlen( $css );
			while ( $i < $len && $depth > 0 ) {
				if ( '{' === $css[ $i ] ) {
					++$depth;
				} elseif ( '}' === $css[ $i ] ) {
					--$depth;
				}
				++$i;
			}
			$found[] = substr( $css, $open, $i - $open - 1 );
			$at      = $i;
		}

		return $found;
	}

	/**
	 * Every control and badge without a contour has a forced-colors rule.
	 *
	 * @return void
	 */
	public function test_every_control_and_badge_without_a_contour_has_a_forced_colors_rule(): void {
		$scan    = $this->scan();
		$missing = array();

		foreach ( array_keys( $scan['background'] ) as $component ) {
			if ( isset( $scan['contour'][ $component ] ) || isset( self::ALLOWED[ $component ] ) ) {
				continue;
			}
			$is_target = 1 === preg_match( self::CONTROL, $component )
				|| 1 === preg_match( self::BADGE, $component );
			if ( $is_target && ! isset( $scan['forced'][ $component ] ) ) {
				$missing[] = $component;
			}
		}

		sort( $missing );

		$this->assertSame(
			array(),
			$missing,
			"Control or badge that declares a background, declares no contour and has no "
				. "`@media (forced-colors: active)` rule. In forced-colors mode it loses its "
				. "boundary and stops being a component. Add the rule to the same sheet "
				. "(`ButtonText` for what is clicked, `CanvasText` for what is read), or "
				. "record it in ALLOWED with the reason:\n" . implode( "\n", $missing )
		);
	}

	/**
	 * Every ALLOWED entry still exists and carries a reason.
	 *
	 * @return void
	 */
	public function test_every_allowed_selector_still_exists_and_carries_a_reason(): void {
		$scan     = $this->scan();
		$problems = array();

		foreach ( self::ALLOWED as $component => $reason ) {
			if ( strlen( trim( $reason ) ) < 15 ) {
				$problems[] = "{$component}: no written reason.";
			}
			if ( ! isset( $scan['background'][ $component ] ) ) {
				$problems[] = "{$component}: no longer declares a background — drop it from ALLOWED.";
			}
		}

		$this->assertSame( array(), $problems, implode( "\n", $problems ) );
	}

	/**
	 * A focus ring does not count as a permanent contour.
	 *
	 * This is the defect that hid `.ffc-submit-btn` from the first measurement:
	 * it has an `outline` on `:focus-visible`, and the scan read that as "it has
	 * a boundary".
	 *
	 * @return void
	 */
	public function test_a_focus_ring_is_not_a_permanent_contour(): void {
		$scan = $this->scan();

		$this->assertArrayHasKey(
			'.ffc-shortcode .ffc-submit-btn',
			$scan['background'],
			'The submit button must keep being seen as a component with a background.'
		);
		$this->assertArrayNotHasKey(
			'.ffc-shortcode .ffc-submit-btn',
			$scan['contour'],
			'The submit button has no border at rest — only a focus ring, which does not count.'
		);
		$this->assertArrayHasKey(
			'.ffc-shortcode .ffc-submit-btn',
			$scan['forced'],
			'…and that is why it needs the forced-colors rule.'
		);
	}

	/**
	 * A border on one side only is a contour.
	 *
	 * `.ffc-form-info-block` was reported as broken by a probe that read only
	 * `border-top-width`. It has `border-left` and was never broken.
	 *
	 * @return void
	 */
	public function test_a_single_side_border_counts_as_a_contour(): void {
		$scan = $this->scan();

		$this->assertArrayHasKey(
			'.ffc-shortcode .ffc-form-info-block',
			$scan['contour'],
			'`border-left` is a contour: the component does not lose its shape in forced colors.'
		);
	}

	/**
	 * The scan did not collapse.
	 *
	 * @return void
	 */
	public function test_the_scan_still_reads_the_stylesheets(): void {
		$scan = $this->scan();

		$this->assertGreaterThanOrEqual( 2000, $scan['rules'], 'The scan lost rules — does it see inside @media?' );
		$this->assertGreaterThanOrEqual( 400, count( $scan['background'] ), 'The scan lost selectors with a background.' );
		$this->assertGreaterThanOrEqual( 80, count( $scan['forced'] ), 'The scan lost the forced-colors blocks.' );
	}

	/**
	 * The category matches a segment, not a fragment of a word.
	 *
	 * @return void
	 */
	public function test_the_category_matches_a_whole_segment(): void {
		$this->assertSame( 1, preg_match( self::BADGE, '.ffc-status-pending' ) );
		$this->assertSame( 1, preg_match( self::BADGE, '.ffc-cap-chip--muted' ) );
		$this->assertSame( 1, preg_match( self::CONTROL, '.ffc-shortcode .ffc-submit-btn' ) );

		// "s-tag-e" is not a tag, and "debutante" is not a button.
		$this->assertSame( 0, preg_match( self::BADGE, '.ffc-pdf-stage' ) );
		$this->assertSame( 0, preg_match( self::CONTROL, '.ffc-debutante' ) );
	}
}
