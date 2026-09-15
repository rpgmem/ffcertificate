<?php
/**
 * Every selector the plugin publishes must name something the plugin owns.
 *
 * @package FreeFormCertificate\Tests
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use FreeFormCertificate\Tests\Support\CssSelectors;
use PHPUnit\Framework\TestCase;

/**
 * CSS namespace ratchet (#1152, a sub-issue of #1148).
 *
 * A rule like `.button::before { content: '\f123' }` reaches ANY button on the
 * screen, not only ours. The radius is small today because the sheet loads on
 * two pages only — but that is **enqueue luck, not design**, and enqueueing
 * changes: `AudienceAdminPage::print_menu_separator_css()` exists precisely
 * because one rule had to leave `ffc-audience-admin.css` when its target started
 * appearing on screens where that sheet does not load.
 *
 * The guard freezes the anchorless selectors that exist, per sheet and per text,
 * and **only shrinks**: a new anchorless selector fails, and a baselined
 * selector that gained an anchor also fails (lock the win in by dropping it from
 * here). The list is a debt register, not a target to grow. It opened at 60 and
 * is now **empty** — #1170 took it to 51 by prefixing the ones that were ours,
 * #1184 to 3 by giving the rest a page anchor, and #1202 item 1 to zero by
 * renaming the last three.
 *
 * **Nothing was broken** — no collision was ever observed. That sets it apart
 * from every other guard in the theme arc, each of which was born from a defect
 * that had already shipped. It is prevention, which is why it froze the debt
 * instead of demanding the fix at once: the fixes were units of their own
 * (renaming `.status-active`, prefixing the six `#tab-*` ids, anchoring the
 * `.column-*` rules on a page class) and each touched an emitter, JS and tests
 * in different modules.
 *
 * Two measurement traps, both fallen into before getting it right:
 *
 * 1. **Counting class by class reports a false positive.** In
 *    `.appointment-status.status-pending`, `.status-pending` never appears on
 *    its own — the compound exposes ONE name, not two. Scan selectors, not
 *    classes. That is what `test_a_compound_selector_counts_once()` pins down.
 *
 * 2. **"It has `ffc` somewhere" is not the same as "it is anchored", and the
 *    token boundary matters.** `a[href^="#ffc-separator-"]` is anchored (it
 *    cannot match anything that is not ours), but the character before `ffc`
 *    there is `#`, not `-`: a `(?:^|[-_])ffc[-_]` pattern drops seven selectors
 *    and the count rises from 60 to 67. The rule has to be decided BEFORE
 *    measuring, or the number describes the pattern instead of the CSS.
 *
 * What it does NOT see: inline CSS printed from PHP
 * (`print_menu_separator_css()`, the appointment receipt) and the `style=""`
 * attribute. It scans the sheets in `assets/css/` only. Nor does it see
 * duplication — the same scan found 19 `ffc-*` classes declared bare in more
 * than one sheet (`.ffc-status-badge` in five), which is a component without a
 * single owner rather than a namespace problem, and lives in #1162.
 */
class CssNamespaceAnchorTest extends TestCase {

	/**
	 * Anchorless selectors that stay, with the reason.
	 *
	 * Different from the baseline below: there is no debt to pay here.
	 *
	 * @var array<string, array<string, string>>
	 */
	private const ALLOWED = array(
		'ffc-common.css' => array(
			// The palette. `:root` is HOW a custom property is declared -- there
			// is no anchored variant, and every property declared there is
			// `--ffc-*`. The dark block, `:root.ffc-dark-mode`, is anchored.
			':root' => 'palette declaration; the properties are all --ffc-*',
		),
	);

	/**
	 * Baseline: anchorless selector => how many times it appears in the sheet.
	 *
	 * A debt register. It only shrinks.
	 *
	 * @var array<string, array<string, int>>
	 */
	private const BASELINE = array(
		// EMPTY since #1202 item 1. The last three entries were the `#tab-*` ids
		// `DashboardShortcode` published unprefixed, and there the fix was not an
		// anchor: an id is unique in the document, so anchoring inside a
		// container would leave the generic name exposed -- a theme declaring
		// `#tab-profile` does not merely repaint, it breaks `getElementById`, the
		// tabs' `aria-controls` and the event delegation. The six panels became
		// `ffc-tabpanel-<slug>`, which is the convention the form editor already
		// used (`ffc-tabnav-` / `ffc-tabpanel-`).
		//
		// Zero is not the end of the guard: what charges it is
		// `test_no_stylesheet_publishes_a_new_anchorless_selector()`, which scans
		// all 28 sheets every run. A new entry here is a decision to defend, not
		// a line to add.
	);

	/**
	 * A selector is anchored when it names something the plugin owns.
	 *
	 * A class, an id or an ATTRIBUTE VALUE containing the token `ffc` followed by
	 * `-` or `_`, preceded by anything that is not a letter or a digit. It covers
	 * the five shapes the codebase uses: `.ffc-x`, `#ffc_x`, `.post-type-ffc_form`,
	 * `.cm-s-ffc-dark` (CodeMirror's theme, which is ours) and
	 * `a[href^="#ffc-separator-"]`.
	 *
	 * @param string $selector A single selector, already split off the list.
	 * @return bool
	 */
	private function is_anchored( string $selector ): bool {
		return 1 === preg_match( '/(?:^|[^A-Za-z0-9])ffc[-_]/', $selector );
	}

	/**
	 * Extracts a sheet's selectors, one per list entry.
	 *
	 * It delegates to the shared parser: the component-ownership guard (#1162)
	 * measures over the SAME sheets, and two scans disagreeing about what a
	 * selector is would measure different sets -- the reason
	 * `.github/scripts/ffc-create-statements.php` is shared too.
	 *
	 * @param string $css Sheet contents.
	 * @return array<int, string>
	 */
	private function selectors( string $css ): array {
		return CssSelectors::of( $css );
	}

	/**
	 * Scans `assets/css/*.css` and groups the anchorless selectors.
	 *
	 * @return array{anchorless: array<string, array<string, int>>, total: int, sheets: int}
	 */
	private function scan(): array {
		$anchorless = array();
		$total      = 0;
		$sheets     = 0;

		foreach ( CssSelectors::sheets() as $path ) {
			++$sheets;
			$name = basename( $path );

			foreach ( $this->selectors( (string) file_get_contents( $path ) ) as $selector ) {
				++$total;
				if ( $this->is_anchored( $selector ) ) {
					continue;
				}
				$anchorless[ $name ][ $selector ] = ( $anchorless[ $name ][ $selector ] ?? 0 ) + 1;
			}
		}

		return array(
			'anchorless' => $anchorless,
			'total'      => $total,
			'sheets'     => $sheets,
		);
	}

	/**
	 * Nothing new without an anchor.
	 *
	 * @return void
	 */
	public function test_no_stylesheet_publishes_a_new_anchorless_selector(): void {
		$new = array();

		foreach ( $this->scan()['anchorless'] as $sheet => $selectors ) {
			foreach ( $selectors as $selector => $count ) {
				if ( isset( self::ALLOWED[ $sheet ][ $selector ] ) ) {
					continue;
				}
				$known = self::BASELINE[ $sheet ][ $selector ] ?? 0;
				if ( $count > $known ) {
					$new[] = "{$sheet}: `{$selector}` appears {$count}x, baseline {$known}.";
				}
			}
		}

		$this->assertSame(
			array(),
			$new,
			"Selector with no `ffc` anchor. Anchor it on a class of ours, on a page class or "
				. "on `body.post-type-*`; if the rule genuinely has to reach a third-party name, "
				. "add it to ALLOWED with the reason:\n" . implode( "\n", $new )
		);
	}

	/**
	 * What gained an anchor leaves the baseline.
	 *
	 * @return void
	 */
	public function test_the_baseline_shrinks_when_a_selector_gains_an_anchor(): void {
		$scan  = $this->scan()['anchorless'];
		$stale = array();

		foreach ( self::BASELINE as $sheet => $selectors ) {
			foreach ( $selectors as $selector => $count ) {
				$now = $scan[ $sheet ][ $selector ] ?? 0;
				if ( $now < $count ) {
					$stale[] = "{$sheet}: `{$selector}` appears {$now}x, baseline still {$count}.";
				}
			}
		}

		$this->assertSame(
			array(),
			$stale,
			"A selector gained an anchor and the baseline did not follow — lower it to lock the win in:\n"
				. implode( "\n", $stale )
		);
	}

	/**
	 * Every ALLOWED entry still exists and carries a reason.
	 *
	 * @return void
	 */
	public function test_every_allowed_selector_still_exists_and_carries_a_reason(): void {
		$scan     = $this->scan()['anchorless'];
		$problems = array();

		foreach ( self::ALLOWED as $sheet => $selectors ) {
			foreach ( $selectors as $selector => $reason ) {
				if ( strlen( trim( $reason ) ) < 15 ) {
					$problems[] = "{$sheet}: `{$selector}` has no written reason.";
				}
				if ( ! isset( $scan[ $sheet ][ $selector ] ) ) {
					$problems[] = "{$sheet}: `{$selector}` no longer exists — drop it from ALLOWED.";
				}
			}
		}

		$this->assertSame( array(), $problems, implode( "\n", $problems ) );
	}

	/**
	 * The anchor rule recognises the five shapes the codebase uses.
	 *
	 * This is what stops the count from describing the pattern instead of the
	 * CSS. The negatives matter as much as the positives: `.buffalo` contains the
	 * letters `ff` and is not ours.
	 *
	 * @return void
	 */
	public function test_the_anchor_rule_recognises_every_shape_the_codebase_uses(): void {
		$anchored = array(
			'.ffc-modal',
			'#ffc_pdf_layout',
			'.post-type-ffc_form .wrap',
			'.cm-s-ffc-dark .cm-tag',
			'#adminmenu .wp-submenu a[href^="#ffc-separator-"]',
			'.column-ffc_certificates',
			':root.ffc-dark-mode',
		);

		$anchorless = array(
			'.button::before',
			'.column-status',
			'#tab-profile h3',
			'.form-table th',
			'code',
			':root',
			'.ffcertificate',
			'.buffalo',
		);

		foreach ( $anchored as $selector ) {
			$this->assertTrue( $this->is_anchored( $selector ), "`{$selector}` should count as anchored." );
		}

		foreach ( $anchorless as $selector ) {
			$this->assertFalse( $this->is_anchored( $selector ), "`{$selector}` should not count as anchored." );
		}
	}

	/**
	 * A compound exposes one name, not one per class.
	 *
	 * The trap that inflated the original measurement:
	 * `.appointment-status.status-pending` was counted as two ownerless names,
	 * and the sheet reported eleven where there was one. A comma-separated list,
	 * by contrast, is one entry per selector.
	 *
	 * @return void
	 */
	public function test_a_compound_selector_counts_once(): void {
		$this->assertSame(
			array( '.appointment-status.status-pending' ),
			$this->selectors( '.appointment-status.status-pending { color: red; }' )
		);

		$this->assertSame(
			array( '.a', '.b .c' ),
			$this->selectors( ".a,\n.b .c { color: red; }" )
		);
	}

	/**
	 * The scan did not collapse.
	 *
	 * An empty result must never read as "clean" — the #1071 / #1094 lesson. It
	 * measures the three shapes of collapse: no longer finding sheets, no longer
	 * finding selectors, and classifying everything as anchorless (or everything
	 * as anchored, which is the silent one).
	 *
	 * @return void
	 */
	public function test_the_scan_still_reads_every_stylesheet(): void {
		$scan = $this->scan();

		$this->assertGreaterThanOrEqual( 25, $scan['sheets'], 'The scan lost sheets.' );
		$this->assertGreaterThanOrEqual( 2000, $scan['total'], 'The scan lost selectors.' );

		$anchorless = 0;
		foreach ( $scan['anchorless'] as $selectors ) {
			$anchorless += array_sum( $selectors );
		}

		$this->assertGreaterThan( 0, $anchorless, 'Zero anchorless: the anchor rule is matching everything.' );
		$this->assertLessThan(
			(int) ( $scan['total'] * 0.1 ),
			$anchorless,
			'More than 10% anchorless: the anchor rule stopped matching.'
		);
	}

	/**
	 * `@keyframes` does not count.
	 *
	 * `0%` and `from` are steps, not selectors — and neither has an anchor, so a
	 * scan that read them would report debt in every animated sheet.
	 *
	 * @return void
	 */
	public function test_keyframe_steps_are_not_selectors(): void {
		$this->assertSame(
			array( '.ffc-spinner' ),
			$this->selectors(
				'@keyframes ffc-spin { 0% { transform: rotate(0); } to { transform: rotate(1turn); } }'
					. '.ffc-spinner { animation: ffc-spin 1s; }'
			)
		);
	}

	/**
	 * A `;` inside a string does not split the selector.
	 *
	 * `img[src^="data:image/png;base64"]` exists in `ffc-pdf-core.css`, and read
	 * out of context that `;` turned half the selector into a phantom entry
	 * called `base64"]`.
	 *
	 * @return void
	 */
	public function test_a_semicolon_inside_a_string_does_not_split_a_selector(): void {
		$this->assertSame(
			array( '.ffc-pdf-wrapper img[src^="data:image/png;base64"]' ),
			$this->selectors( '.ffc-pdf-wrapper img[src^="data:image/png;base64"] { max-width: 100%; }' )
		);
	}
}
