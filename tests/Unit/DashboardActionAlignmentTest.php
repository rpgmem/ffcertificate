<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use FreeFormCertificate\Tests\Support\CssSelectors;
use PHPUnit\Framework\TestCase;

/**
 * The dashboard's ACTIONS column controls declare their alignment (#1215).
 *
 * The cheap half, in the #1184 mould: it proves the DECLARATION is there, never
 * that the render comes out aligned. What proved the render was #1215's Chromium
 * harness -- 4 tabs x 2 themes x 2 widths, per (element x property) pair -- and
 * that does not fit in CI, which has no browser.
 *
 * The defect that produced it had already shipped, and was not what it looked
 * like. The appointments tab's three controls had the SAME height (26.8px) and
 * tops at 100.00 / 103.39 / 103.55: each box joined the line on its OWN
 * baseline, and the three derive their baseline differently -- an `inline-flex`
 * inherits it from the first flex item (here the icon's `::before`, a 14px box
 * with no text), an `inline-block` takes it from the last line of text. Only the
 * appointments tab mixes all three constructions, which is why it was the only
 * one out of place.
 *
 * Two things the measurement settled and that are not worth rediscovering:
 *
 * 1. **`display: flex` on the cell does NOT work.** It is the conventional
 *    answer and it breaks the table: a `<td>` with `display: flex` leaves the
 *    table formatting context and shrinks to its content. Measured, the last
 *    cell fell from 162.59px to 45.13px (certificates) and from 242.83px to
 *    72.56px (public forms), while the `<th>` stayed at full width. It passed on
 *    the other two tabs only because their content already filled the column.
 * 2. **The alignment belongs to the exporter's WRAPPER, not to the button
 *    inside** -- and the `vertical-align: middle` that sat on the button was not
 *    inert, it was the cause: it shifted the button inside the wrapper, which
 *    moved the wrapper's own baseline. Removing it alone already raised the
 *    wrapper from 103.55 to 100.00.
 *
 * @coversNothing Its subject is a STYLESHEET, not a class. The `@covers` here
 * used to name `Tests\Support\CssSelectors`, the helper it reads the sheet
 * with — but coverage is scoped to `./includes`, so that target attributed to
 * nothing while reading as though the test covered something. The honest
 * annotation is the one `SensitiveFieldPolicyTest` already uses for the same
 * reason.
 */
class DashboardActionAlignmentTest extends TestCase {

	private const SHEET = 'assets/css/ffc-user-dashboard.css';

	/**
	 * The controls that share a line in the actions cell, and why.
	 *
	 * A new control in the ACTIONS column goes here. It is not a decorative
	 * list: it is the set whose alignment has to be DECLARED, because the default
	 * (`baseline`) depends on each box's construction and the constructions here
	 * differ on purpose.
	 *
	 * @var array<string, string>
	 */
	private const CONTROLS = array(
		'.ffc-btn-pdf'      => 'Download PDF / Download Record -- `inline-flex` with an `::before` icon (certificates and reregistrations).',
		'.ffc-btn-receipt'  => 'View Receipt -- `inline-flex` with an `::before` icon (appointments).',
		'.ffc-btn-edit'     => 'Edit -- `inline-flex` with an `::before` icon (reregistrations).',
		'.ffc-appointments-table .ffc-cancel-appointment' => 'Cancel -- `inline-block` with no icon, whose baseline comes from the text.',
		'.ffc-cal-export-wrap' => 'Export Calendar -- an `inline-block` wrapping the button; IT is what joins the cell\'s line.',
	);

	/**
	 * The exporter's inner button declares no alignment.
	 *
	 * See item 2 of the class docblock: there the declaration was not inert, it
	 * was the cause of 3.55px of misalignment.
	 */
	private const MUST_NOT_DECLARE = '.ffc-cal-export-btn';

	private function sheet(): string {
		$path = dirname( __DIR__, 2 ) . '/' . self::SHEET;
		$css  = file_get_contents( $path );
		$this->assertIsString( $css, self::SHEET . ' could not be read.' );

		return $css;
	}

	/**
	 * @return array<string, string> Selector => concatenated body of the rules declaring it.
	 */
	private function bodies_by_selector( string $css ): array {
		$out = array();
		foreach ( CssSelectors::rules( $css ) as $rule ) {
			foreach ( CssSelectors::split_list( $rule['selector'] ) as $one ) {
				$one          = trim( $one );
				$out[ $one ]  = ( $out[ $one ] ?? '' ) . "\n" . $rule['body'];
			}
		}

		return $out;
	}

	public function test_every_action_control_declares_vertical_align_middle(): void {
		$bodies = $this->bodies_by_selector( $this->sheet() );

		// Self-check: a scan that found nothing must not pass as "clean" (the
		// #1071 / #1094 lesson).
		$this->assertGreaterThan(
			100,
			count( $bodies ),
			'The sheet scan came back almost empty — the parser broke, and a green here would mean nothing.'
		);

		foreach ( self::CONTROLS as $selector => $why ) {
			$this->assertArrayHasKey(
				$selector,
				$bodies,
				"Selector `{$selector}` no longer exists in " . self::SHEET . ". If the control was renamed, update the register; if it left the actions cell, drop it. {$why}"
			);

			$this->assertMatchesRegularExpression(
				'/vertical-align\s*:\s*middle\s*(!important)?\s*;/',
				$bodies[ $selector ],
				"`{$selector}` shares a line in the ACTIONS column and declares no `vertical-align: middle`. Without it, it joins the line on its own baseline, which depends on the box's construction. {$why}"
			);
		}
	}

	public function test_the_export_button_leaves_the_alignment_to_its_wrapper(): void {
		$bodies = $this->bodies_by_selector( $this->sheet() );

		$this->assertArrayHasKey(
			self::MUST_NOT_DECLARE,
			$bodies,
			'`' . self::MUST_NOT_DECLARE . '` vanished from the sheet; if the exporter was rewritten, revise this register.'
		);

		$this->assertDoesNotMatchRegularExpression(
			'/vertical-align\s*:/',
			$bodies[ self::MUST_NOT_DECLARE ],
			'`' . self::MUST_NOT_DECLARE . '` declares `vertical-align` again. What joins the cell\'s line is `.ffc-cal-export-wrap`; aligning the inner button shifts the wrapper\'s baseline (#1215).'
		);
	}
}
