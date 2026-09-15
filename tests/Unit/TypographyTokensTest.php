<?php
/**
 * Every `font-size` paints from the scale, or says why it does not.
 *
 * @package FreeFormCertificate\Tests
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Per-sheet typography ratchet (#1148 item 4).
 *
 * The scale had existed in `ffc-common.css` all along, was semantic and was
 * right — and had **zero** consumers: 415 `font-size` declarations and not one
 * read a token. This is the adoption, sheet by sheet, in the mould of the
 * colour ratchet: the budget can only shrink. Going over the budget fails
 * (tokenize it); coming in BELOW it fails too (a sheet was converted — lock the
 * win in).
 *
 * Three things the measurement taught that are not guessable:
 *
 * 1. **12px was not drift, it was a missing step.** 69 uses, the third most
 *    frequent value in the codebase, and no token — the scale jumped from 11 to
 *    13. In four sheets it is 13px's responsive step down. The `xs` step became
 *    12px and 11px became `2xs`; the rename cost nothing, because there was not
 *    a single call site to update.
 *
 * 2. **Seventeen of the eighteen "same selector, two sizes" cases are
 *    `@media`**, not drift — the sheet steps down on phones. The opposite
 *    hypothesis (drift) was the intuitive one and was wrong; measuring cost one
 *    script.
 *
 * 3. **An icon sized by `font-size` is not typography.** It is a glyph box
 *    (dashicons, `&times;`, the stat card's icon), and the text scale does not
 *    govern it. Those are exceptions with the reason written beside them in the
 *    CSS, not silent debt.
 *
 * What this guard does NOT see: whether the chosen step is the right one.
 * `var(--ffc-font-size-2xl)` on an 11px label passes — it measures
 * tokenization, never correctness.
 *
 * One tooling trap, because it already bit during the conversion itself:
 * counting literals with `font-size\s*:\s*(?!var\()` **does not work**. `\s*`
 * is greedy and backtracks until the lookahead succeeds, so every tokenized
 * declaration is counted as a literal. Capture the value and test its start.
 */
class TypographyTokensTest extends TestCase {

	/**
	 * Maximum literal `font-size` declarations per sheet. It can only shrink.
	 *
	 * `0` is the normal state. Every non-zero entry is a decision with the
	 * reason INLINE in the CSS, or a sheet not yet converted — and the two are
	 * distinguished here, because "not converted" goes away when the second
	 * stage arrives and "decision" stays.
	 *
	 * @var array<string, int>
	 */
	private const BUDGET = array(
		// Zero is the normal state: the whole sheet paints from the scale.
		'ffc-admin-move-submissions.css'   => 0,
		'ffc-admin-submission-edit.css'    => 0,
		'ffc-admin-submissions.css'        => 0,
		'ffc-admin-utilities.css'          => 0,
		'ffc-appointment-cancellation.css' => 0,
		'ffc-calendar-admin.css'           => 0,
		'ffc-calendar-editor.css'          => 0,
		'ffc-certificates-dashboard.css'   => 0,
		'ffc-custom-fields-admin.css'      => 0,
		'ffc-email-model.css'              => 0,
		'ffc-progress-overlay.css'         => 0,
		'ffc-recruitment-admin.css'        => 0,
		'ffc-recruitment-public.css'       => 0,
		'ffc-reregistration-frontend.css'  => 0,
		'ffc-url-shortener-admin.css'      => 0,
		'ffc-user-permissions.css'         => 0,
		'ffc-working-hours.css'            => 0,

		// Glyphs: an icon (dashicon, `&times;`, a success mark) sized by
		// font-size is a glyph box, not text.
		'ffc-admin-settings.css'           => 1,
		'ffc-calendar-frontend.css'        => 1,
		'ffc-reregistration-admin.css'     => 1,
		'ffc-admin.css'                    => 3,
		'ffc-frontend.css'                 => 2,

		// Card hero numbers, deliberately above the text scale — plus the phone
		// step, which would make no sense if it rose to the floor.
		'ffc-audience-admin.css'           => 2,
		'ffc-user-dashboard.css'           => 2,

		// A badge that has to fit inside the day cell, with its own responsive step.
		'ffc-audience.css'                 => 2,

		// Two `em` values relative to the parent on purpose: the component is
		// dropped into contexts of different sizes and follows each one.
		'ffc-common.css'                   => 2,

		// `ffc-pdf-core.css` stays entirely literal, for two distinct reasons.
		// The six h1-h6 rules redeclare the user agent's default sizes
		// (2em … 0.75em) in order to PRESERVE them inside the wrapper: they are
		// relative to the parent on purpose, because the certificate body sets
		// its own base size and the headings follow it. And the wrapper's
		// `font-size` is literal because this sheet is the BASE of the chain —
		// enqueued with `array()`, with no declared dependency on `ffc-common`,
		// and with a second enqueue on the frontend. Reading a token the page
		// may not have invalidates the whole declaration.
		'ffc-pdf-core.css'                 => 7,
	);

	/**
	 * The seven declared steps, in order.
	 *
	 * @var array<int, string>
	 */
	private const STEPS = array( '2xs', 'xs', 'sm', 'base', 'lg', 'xl', '2xl' );

	/**
	 * Counts `font-size` declarations per sheet.
	 *
	 * @return array<string, array{literal: int, token: int}>
	 */
	private function scan(): array {
		$root  = dirname( __DIR__, 2 ) . '/assets/css/';
		$found = array();

		foreach ( glob( $root . '*.css' ) ?: array() as $path ) {
			if ( str_ends_with( $path, '.min.css' ) ) {
				continue;
			}

			$css = (string) preg_replace( '#/\*.*?\*/#s', '', (string) file_get_contents( $path ) );
			preg_match_all( '/font-size\s*:\s*([^;}\n]+)/', $css, $m );

			$literal = 0;
			$token   = 0;
			foreach ( $m[1] as $value ) {
				if ( str_starts_with( trim( $value ), 'var(' ) ) {
					++$token;
				} else {
					++$literal;
				}
			}

			if ( $literal || $token ) {
				$found[ basename( $path ) ] = array(
					'literal' => $literal,
					'token'   => $token,
				);
			}
		}

		return $found;
	}

	public function test_no_stylesheet_exceeds_its_literal_budget(): void {
		$over = array();
		foreach ( $this->scan() as $name => $counts ) {
			$budget = self::BUDGET[ $name ] ?? null;
			if ( null === $budget ) {
				$over[] = "{$name} is not in BUDGET — add it with the measured count (or 0).";
				continue;
			}
			if ( $counts['literal'] > $budget ) {
				$over[] = "{$name}: {$counts['literal']} literals, budget {$budget}.";
			}
		}

		$this->assertSame(
			array(),
			$over,
			"Literal `font-size` over budget. Use `var(--ffc-font-size-*)`; if the value "
				. "is a decision (an icon glyph, a hero number), leave it literal WITH the "
				. "reason beside it and raise the budget:\n" . implode( "\n", $over )
		);
	}

	public function test_budgets_are_ratcheted_down_when_a_sheet_is_converted(): void {
		$slack = array();
		foreach ( $this->scan() as $name => $counts ) {
			$budget = self::BUDGET[ $name ] ?? null;
			if ( null !== $budget && $counts['literal'] < $budget ) {
				$slack[] = "{$name}: {$counts['literal']} literals, budget still {$budget}.";
			}
		}

		$this->assertSame(
			array(),
			$slack,
			"A sheet improved and the budget did not follow — lower it to lock the win in:\n"
				. implode( "\n", $slack )
		);
	}

	/**
	 * Each step is `max(<px floor>, <rem>)`, and the two halves agree at 16px.
	 *
	 * `rem` on its own resolves against the DOCUMENT root, which on the frontend
	 * belongs to the site's theme: under `html { font-size: 62.5% }` — a common
	 * idiom — the whole scale shrinks, and the `sm` step measures 8.1px instead
	 * of 13px. Measured in Chromium (#1157), not estimated. The floor removes
	 * that; the `rem` on the other side of the `max()` preserves the browser's
	 * font-size preference, which is why the scale is in `rem` in the first
	 * place.
	 *
	 * The equality at 16px is the most specific thing this guard measures:
	 * `max(13px, 0.8125rem)` is an IDENTITY at that point, not a range. A
	 * `max(13px, 0.75rem)` typed by mistake would go unnoticed forever — both
	 * halves are plausible in isolation, and the difference only shows up
	 * rendered, in a theme nobody here runs.
	 *
	 * What it does not see: whether the floor is the right size for that role.
	 * It measures the scale's coherence, never design correctness.
	 *
	 * @return void
	 */
	public function test_every_step_floors_in_px_and_scales_in_rem(): void {
		$css = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/css/ffc-common.css' );

		$broken = array();
		$seen   = 0;

		foreach ( self::STEPS as $step ) {
			$found = preg_match(
				'/--ffc-font-size-' . preg_quote( $step, '/' ) . '\s*:\s*([^;]+);/',
				$css,
				$m
			);

			if ( ! $found ) {
				$broken[] = "{$step}: not declared.";
				continue;
			}

			$value = trim( $m[1] );
			if ( ! preg_match( '/^max\(\s*([\d.]+)px\s*,\s*([\d.]+)rem\s*\)$/', $value, $parts ) ) {
				$broken[] = "{$step}: `{$value}` — the form must be `max(<px>, <rem>)`.";
				continue;
			}

			++$seen;
			$px  = (float) $parts[1];
			$rem = (float) $parts[2] * 16.0;
			if ( abs( $px - $rem ) > 0.001 ) {
				$broken[] = "{$step}: floor {$px}px, but the rem is {$rem}px at a 16px root — the halves diverge.";
			}
		}

		$this->assertSame(
			array(),
			$broken,
			"The scale lost the form that makes it safe under the site's theme (#1157):\n" . implode( "\n", $broken )
		);
		$this->assertSame( count( self::STEPS ), $seen, 'The scale scan collapsed — no step matched the form.' );
	}

	public function test_the_scale_declares_exactly_the_seven_steps(): void {
		$css = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/css/ffc-common.css' );

		preg_match_all( '/--ffc-font-size-([\w]+)\s*:/', $css, $m );
		$declared = array_values( array_unique( $m[1] ) );

		$this->assertSame(
			self::STEPS,
			$declared,
			'The scale changed shape. A step that disappears takes every declaration reading it — '
				. 'an undeclared custom property INVALIDATES the whole declaration, it does not fall back to the previous value.'
		);
	}

	public function test_every_token_read_names_a_declared_step(): void {
		$root    = dirname( __DIR__, 2 ) . '/assets/css/';
		$unknown = array();

		foreach ( glob( $root . '*.css' ) ?: array() as $path ) {
			if ( str_ends_with( $path, '.min.css' ) ) {
				continue;
			}
			$css = (string) preg_replace( '#/\*.*?\*/#s', '', (string) file_get_contents( $path ) );
			preg_match_all( '/var\(\s*--ffc-font-size-([\w]+)\s*[),]/', $css, $m );
			foreach ( $m[1] as $step ) {
				if ( ! in_array( $step, self::STEPS, true ) ) {
					$unknown[] = basename( $path ) . ": --ffc-font-size-{$step}";
				}
			}
		}

		$this->assertSame( array(), $unknown, 'A typography token nobody declares — the whole declaration is invalidated.' );
	}

	/**
	 * The self-check: a scan that collapses passes everything above by finding
	 * nothing.
	 *
	 * @return void
	 */
	public function test_the_scan_still_sees_the_stylesheets(): void {
		$found = $this->scan();

		$this->assertGreaterThan( 20, count( $found ), 'The scan stopped seeing sheets.' );

		$tokens = array_sum( array_column( $found, 'token' ) );
		$this->assertGreaterThan(
			100,
			$tokens,
			'The scan no longer finds tokenized declarations — the regex stopped matching, '
				. 'and the budgets would pass vacuously.'
		);
	}

	public function test_every_budget_entry_names_a_real_stylesheet(): void {
		$root = dirname( __DIR__, 2 ) . '/assets/css/';
		foreach ( array_keys( self::BUDGET ) as $name ) {
			$this->assertFileExists( $root . $name, "BUDGET names {$name}, which does not exist." );
		}
	}
}
