<?php
/**
 * Ratchet on `opacity` over text (#1126, #1170).
 *
 * `DarkModeCssTest` measures colour pairs and is blind to this defect class
 * **by construction**: `opacity` fades the text AND the ground together, so it
 * multiplies down whatever contrast the tokens guaranteed while the tokens
 * stay correct. It is lesson 5 of the theme arc — "say state with colour, not
 * with fading" — and it was measured again here: the past row of the
 * reregistration table gave **3.11:1** in the light theme and **4.23:1** in
 * dark at `opacity: 0.75`; without it, 5.15:1 and 6.37:1.
 *
 * The guard freezes every `opacity` declaration below 1 in the sheets, per
 * sheet and per selector, each with its reason. A ratchet both ways: a new
 * declaration fails (justify it or use colour), and one that vanished fails
 * too (the win gets locked in).
 *
 * **It cannot read contrast** — it has no way to: what `opacity` does depends
 * on what is BEHIND the element, which is DOM, not stylesheet. What it
 * guarantees is that none enters without somebody having looked. Three
 * categories are legitimate:
 *
 *  - **inactive component** (`:disabled`, `[disabled]`, a disabled row):
 *    SC 1.4.3 exempts text that is part of an inactive component, the same
 *    reason `DarkModeCssTest::DERIVED_EXCEPTIONS` already uses;
 *  - **transient state** (loading, `:hover`): not the resting state anyone
 *    reads;
 *  - **`opacity: 0`**: the element is not painted — hiding is not fading.
 *
 * Outside those, `opacity` over resting text is the defect.
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
final class OpacityContrastTest extends TestCase {

	/**
	 * Every `opacity` declaration below 1, with its reason.
	 *
	 * Sheet => selector => reason. The selector is the rule's first selector,
	 * normalised to single spaces.
	 */
	private const ALLOWED = array(
		'ffc-admin-settings.css'          => array(
			'.ffc-settings-tabs__external' => 'external-link icon, decorative glyph beside the label',
			'.ffc-settings-back-to-top'    => 'floating back-to-top button; its label is a glyph',
		),
		'ffc-admin-submission-edit.css'   => array(
			'.ffc-consent-header:hover' => '`:hover` feedback, a transient state',
		),
		'ffc-admin.css'                   => array(
			'#ffc-preview-modal' => 'opacity: 0 — the closed modal is not painted',
			'.ffc-page-identities .ffc-identity-panel-step[aria-disabled="true"]' => 'the step control at either end of a panel: an INACTIVE component, which SC 1.4.3 exempts — and inactive in fact, not only in wording, since the same rule sets `pointer-events: none` and the attribute says so to a screen reader',
		),
		'ffc-audience-admin.css'          => array(
			'.ffc-selected-user .ffc-selected-user-remove' => 'the remove ×, a decorative glyph',
		),
		'ffc-audience.css'                => array(
			'.ffc-shortcode .ffc-day.ffc-other-month'  => 'neighbouring-month day: inactive component, not clickable — measured in #1185, 5.74:1 light and 5.55:1 dark with the fade included',
			'.ffc-shortcode .ffc-booking-cancelled'    => 'cancelled booking: inactive component, exempt under SC 1.4.3 — and measured in #1185, 8.45:1 light and 7.04:1 dark WITH the fade included',
		),
		'ffc-calendar-frontend.css'       => array(
			'.ffc-shortcode .ffc-timeslot-available' => 'seats-left count: measured in #1185, 12.63:1 light and 7.33:1 dark with the fade included — the fade here is visual hierarchy, not state',
		),
		'ffc-certificates-dashboard.css'  => array(
			'.ffc-certificates-submissions-link'        => 'secondary card link, glyph plus a count',
			'.ffc-calendar-core .ffc-day.ffc-other-month' => 'neighbouring-month day: inactive component — measured in #1185 against the real DOM (the dashboard is an admin screen, so under `body.wp-admin`): 5.74:1 light and 4.87:1 dark with the fade included',
		),
		'ffc-common.css'                  => array(
			'.ffc-loading'                                            => 'loading state, transient',
			'.ffc-form button[type="submit"]:disabled'                => 'inactive component, exempt under SC 1.4.3',
			'.ffc-btn:disabled'                                       => 'inactive component, exempt under SC 1.4.3',
			'.ffc-toggle input[type="checkbox"]'                      => 'opacity: 0 — the real input is invisible under the drawn track',
			'.ffc-toggle input[type="checkbox"]:disabled + .ffc-toggle-track' => 'inactive component, exempt under SC 1.4.3',
			'#ffc-activity-log-table.ffc-loading'                     => 'loading state, transient',
		),
		'ffc-custom-fields-admin.css'     => array(
			'.ffc-custom-field-row.ffc-field-inactive' => 'disabled field: inactive component, exempt under SC 1.4.3',
		),
		'ffc-frontend.css'                => array(
			'.ffc-submit-btn.ffc-btn-loading' => 'loading state, transient',
			'.ffc-public-csv-download .ffc-info-btn-primary[disabled], .ffc-open-early-modal .ffc-info-btn-primary[disabled], .ffc-extend-end-modal .ffc-info-btn-primary[disabled]'   => 'inactive component, exempt under SC 1.4.3',
			'.ffc-public-csv-download .ffc-info-btn-secondary[disabled], .ffc-open-early-modal .ffc-info-btn-secondary[disabled], .ffc-extend-end-modal .ffc-info-btn-secondary[disabled]' => 'inactive component, exempt under SC 1.4.3',
			'.ffc-public-csv-download .ffc-info-btn-warning[disabled], .ffc-open-early-modal .ffc-info-btn-warning[disabled], .ffc-extend-end-modal .ffc-info-btn-warning[disabled]'   => 'inactive component, exempt under SC 1.4.3',
			'#ffc-preview-modal' => 'opacity: 0 — the closed modal is not painted',
		),
		'ffc-pdf-core.css'                => array(
			'.ffc-btn-loading' => 'loading state, transient',
		),
		'ffc-reregistration-frontend.css' => array(
			'.ffc-rereg-header-subtitle' => 'header subtitle: measured in #1185, 17.58:1 light and 10.10:1 dark with the fade included',
		),
		'ffc-url-shortener-admin.css'     => array(
			'.ffc-shorturl-toast' => 'opacity: 0 — the toast only appears through animation',
		),
		'ffc-user-dashboard.css'          => array(
			'.ffc-audience-join-item .button.button-primary:disabled' => 'inactive component, exempt under SC 1.4.3',
		),
		'ffc-user-permissions.css'        => array(
			'.ffc-cap-role[disabled]' => 'inactive component, exempt under SC 1.4.3',
		),
	);

	/**
	 * Each `opacity` declaration below 1, per sheet.
	 *
	 * @return array<string, array<string, string>> Sheet => selector => value.
	 */
	private static function found(): array {
		$out = array();

		foreach ( CssSelectors::sheets() as $path ) {
			$sheet = basename( $path );

			foreach ( CssSelectors::rules( (string) file_get_contents( $path ) ) as $rule ) {
				$body = (string) preg_replace( '~/\*.*?\*/~s', '', $rule['body'] );

				if ( ! preg_match( '/(?<![-\w])opacity\s*:\s*(0(?:\.\d+)?)\s*(?:;|$)/', $body, $m ) ) {
					continue;
				}
				if ( 1.0 <= (float) $m[1] ) {
					continue;
				}

				$selector = trim( (string) preg_replace( '/\s+/', ' ', $rule['selector'] ) );
				$out[ $sheet ][ $selector ] = $m[1];
			}
		}

		return $out;
	}

	/**
	 * No new `opacity` declaration enters without a written reason.
	 */
	public function test_no_new_opacity_fades_text(): void {
		$new = array();

		foreach ( self::found() as $sheet => $rules ) {
			foreach ( $rules as $selector => $value ) {
				if ( ! isset( self::ALLOWED[ $sheet ][ $selector ] ) ) {
					$new[] = sprintf( '%s: %s { opacity: %s }', $sheet, $selector, $value );
				}
			}
		}

		$this->assertSame(
			array(),
			$new,
			"New `opacity`, with no written reason:\n  " . implode( "\n  ", $new )
			. "\n\nThe pair meter does NOT see this: fading multiplies the contrast down"
			. "\nwhile the tokens stay correct. Say state with COLOUR."
			. "\nIf it is an inactive component, a transient state or `opacity: 0`, add it"
			. "\nto ALLOWED with the reason."
		);
	}

	/**
	 * An entry that vanished from the sheets leaves the list.
	 */
	public function test_the_list_only_shrinks(): void {
		$found = self::found();
		$stale = array();

		foreach ( self::ALLOWED as $sheet => $rules ) {
			foreach ( array_keys( $rules ) as $selector ) {
				if ( ! isset( $found[ $sheet ][ $selector ] ) ) {
					$stale[] = $sheet . ': ' . $selector;
				}
			}
		}

		$this->assertSame(
			array(),
			$stale,
			"These no longer exist — drop them from ALLOWED to lock the win in:\n  " . implode( "\n  ", $stale )
		);
	}

	/**
	 * Every reason says something.
	 *
	 * A floor against "ok" / "see above" — never a quality bar, the same
	 * criterion the suppression guards use (#1027 / #1035).
	 */
	public function test_every_reason_says_something(): void {
		$short = array();

		foreach ( self::ALLOWED as $sheet => $rules ) {
			foreach ( $rules as $selector => $reason ) {
				if ( 20 > strlen( $reason ) ) {
					$short[] = $sheet . ': ' . $selector;
				}
			}
		}

		$this->assertSame( array(), $short, "Reason too short:\n  " . implode( "\n  ", $short ) );
	}

	/**
	 * The scan must not collapse in silence.
	 *
	 * An empty map satisfies the first test just as well as a correct one —
	 * the #1071 / #1094 shape.
	 */
	public function test_the_scan_cannot_collapse_in_silence(): void {
		$found = self::found();
		$total = 0;
		foreach ( $found as $rules ) {
			$total += count( $rules );
		}

		$this->assertGreaterThan( 20, $total, 'The `opacity` scan collapsed.' );
		$this->assertGreaterThan( 20, count( CssSelectors::sheets() ), 'Reading the sheets collapsed.' );

		// And the table that motivated the guard must not fade again.
		$this->assertArrayNotHasKey(
			'.ffc-reregistrations-table.ffc-table-past',
			$found['ffc-user-dashboard.css'] ?? array(),
			'The reregistration past row went back to `opacity` — it was 3.11:1 in the light theme.'
		);
	}
}
