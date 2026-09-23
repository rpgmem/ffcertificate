<?php
/**
 * Dark-mode CSS guard (#1126).
 *
 * The dark mode is a token swap: `:root.ffc-dark-mode` redefines ~50 custom
 * properties and everything painted through `var(--ffc-*)` follows. A literal
 * colour inside a dark-mode rule opts that one declaration out of the swap and
 * is invisible in exactly one of the two themes — which is why the class keeps
 * coming back rather than being caught in review. It was found once in the
 * autosave badge (#1116, hardcoded hex so the badge ignored dark mode) and
 * measured as a population in #1126.
 *
 * This guard is deliberately narrow: it covers the dark-mode override block
 * this repository owns, not every admin stylesheet. The wider sweep — seven
 * admin stylesheets with zero tokens between them — is the second half of
 * #1126, and gets its own guard with a per-file allowlist when those are
 * converted. A guard that fails on work not yet done teaches people to skip it.
 *
 * Dependency-free on purpose — no WordPress, no Brain\Monkey — like the module
 * boundary and settings-defaults guards. It reads source text only.
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
final class DarkModeCssTest extends TestCase {

	/**
	 * Absolute path to the stylesheet that owns the dark mode.
	 */
	private static function stylesheet(): string {
		return dirname( __DIR__, 2 ) . '/assets/css/ffc-common.css';
	}

	/**
	 * Every rule whose selector mentions `.ffc-dark-mode`, as raw text.
	 *
	 * Returns the selector plus its declaration block, so a caller can report
	 * which rule is at fault rather than just that one exists.
	 *
	 * @return array<int, array{selector: string, body: string}>
	 */
	public static function dark_mode_rules(): array {
		$css = (string) file_get_contents( self::stylesheet() );

		// Strip comments first: the block above talks about `#1d2327` in prose,
		// and a scanner that reads its own documentation as a violation is
		// worse than no scanner.
		$css = (string) preg_replace( '#/\*.*?\*/#s', '', $css );

		if ( ! preg_match_all( '/([^{}]*\.ffc-dark-mode[^{}]*)\{([^}]*)\}/s', $css, $matches, PREG_SET_ORDER ) ) {
			return array();
		}

		$out = array();
		foreach ( $matches as $match ) {
			$out[] = array(
				'selector' => trim( (string) $match[1] ),
				'body'     => (string) $match[2],
			);
		}

		return $out;
	}

	/**
	 * A dark-mode rule may not name a colour literally.
	 *
	 * The one exception is the token block itself — `:root.ffc-dark-mode { … }`
	 * with nothing but custom-property declarations — which is where the
	 * literals are supposed to live. It is recognised by what it declares, not
	 * by its position in the file, so moving it does not silently exempt
	 * something else.
	 */
	public function test_no_dark_mode_rule_paints_with_a_literal_colour(): void {
		$offenders = array();

		foreach ( self::dark_mode_rules() as $rule ) {
			foreach ( explode( ';', $rule['body'] ) as $declaration ) {
				$declaration = trim( $declaration );
				if ( '' === $declaration ) {
					continue;
				}

				// The token definitions are the intended home for literals.
				if ( strpos( $declaration, '--ffc-' ) === 0 ) {
					continue;
				}

				if ( preg_match( '/#[0-9a-fA-F]{3,8}\b|\brgba?\(|\bhsla?\(/', $declaration ) ) {
					$offenders[] = $rule['selector'] . ' → ' . $declaration;
				}
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			"A dark-mode rule names a colour literally, so it will not follow the theme:\n  "
			. implode( "\n  ", $offenders )
			. "\n\nUse a var(--ffc-*) token. If the palette has no role for it, add the token"
			. "\nto BOTH the light and the dark block — never only to the dark one."
		);
	}

	/**
	 * The guard's own self-check.
	 *
	 * `assertSame( array(), $offenders )` is satisfied by a scan that found
	 * nothing to look at, which is the failure mode #1094 found in four guards
	 * at once. The override block is ~40 rules; the floor is set well under
	 * that so ordinary edits do not trip it, but a collapsed regex does.
	 */
	public function test_the_scan_sees_the_override_block(): void {
		$rules = self::dark_mode_rules();

		$this->assertGreaterThan(
			20,
			count( $rules ),
			'The dark-mode scan collapsed — check the regex and that the block still exists.'
		);

		$selectors = implode( ' ', array_column( $rules, 'selector' ) );

		// Two landmarks from the two halves: the token block, and the core
		// override that the 6.24.0 smoke reported as invisible.
		$this->assertStringContainsString( ':root.ffc-dark-mode', $selectors );
		$this->assertStringContainsString( '.form-table th', $selectors, 'The label override is what #1126 was opened for.' );
	}

	/**
	 * The token block must define both grounds it is asked for.
	 *
	 * Every override above resolves `--ffc-text` and `--ffc-bg-card`; a rename
	 * that dropped one would leave those declarations resolving to nothing,
	 * which renders as *inherited*, not as an error.
	 */
	public function test_the_tokens_the_overrides_depend_on_are_defined(): void {
		$css = (string) file_get_contents( self::stylesheet() );

		foreach ( array( '--ffc-text', '--ffc-text-muted', '--ffc-bg-alt', '--ffc-bg-card', '--ffc-bg-input', '--ffc-border' ) as $token ) {
			$this->assertMatchesRegularExpression(
				'/\.ffc-dark-mode\s*\{[^}]*' . preg_quote( $token, '/' ) . '\s*:/s',
				$css,
				"The dark palette does not define {$token}, which the core overrides read."
			);
		}
	}

	// ==================================================================
	// Contraste medido (#1132)
	// ==================================================================
	//
	// The guard above sees PRESENCE — whether the declaration uses a token or a
	// literal. It does not see whether the resulting pair is legible, and that
	// is how ten pairs reached the repository failing WCAG AA, the dark theme's
	// `on-primary` among them: white on the light primary, 2.52:1, since the day
	// the dark theme was written. Nothing measured it, so nothing reported it.
	//
	// This computes the ratio from the CSS itself. It blocks at zero.

	/**
	 * Pairs the CSS actually paints, each with its floor.
	 *
	 * The floor is not an opinion: 4.5:1 is WCAG AA's minimum for normal text,
	 * 3:1 for what identifies a component (SC 1.4.11) — and it is the same floor
	 * Material 3 adopts. A pair that does not appear on screen does not go here;
	 * the list describes real compositions, not the cartesian product of the
	 * tokens.
	 *
	 * @return array<int, array{0: string, 1: string, 2: float, 3: string}>
	 */
	private static function pairs(): array {
		return array(
			array( '--ffc-text', '--ffc-bg', 4.5, 'text on the background' ),
			array( '--ffc-text', '--ffc-bg-alt', 4.5, 'text on the alternate background' ),
			array( '--ffc-text', '--ffc-bg-card', 4.5, 'text on a card' ),
			array( '--ffc-text', '--ffc-bg-input', 4.5, 'text inside a field' ),
			array( '--ffc-text-secondary', '--ffc-bg-card', 4.5, 'secondary text on a card' ),
			array( '--ffc-text-muted', '--ffc-bg-card', 4.5, 'a description on a card' ),
			array( '--ffc-text-muted', '--ffc-bg-alt', 4.5, 'a description on the alternate background' ),
			array( '--ffc-text-light', '--ffc-bg-input', 4.5, 'a placeholder inside a field' ),
			array( '--ffc-link', '--ffc-bg-card', 4.5, 'a link on a card' ),
			array( '--ffc-text-on-primary', '--ffc-primary', 4.5, 'the primary button label' ),
			array( '--ffc-success-text', '--ffc-success-bg', 4.5, 'success text' ),
			array( '--ffc-warning-text', '--ffc-warning-bg', 4.5, 'warning text' ),
			array( '--ffc-danger-text', '--ffc-danger-bg', 4.5, 'danger text' ),
			array( '--ffc-info-text', '--ffc-info-bg', 4.5, 'informational text' ),
			// The fifth family (#1407). Not a severity — it exists so the
			// identity screen's five categories are five colours rather than
			// four, which is what a category colour is for.
			array( '--ffc-accent-text', '--ffc-accent-bg', 4.5, 'the fifth category' ),
			// Pairs the seven admin sheets started painting (#1126 B). Two of
			// them already failed before the conversion: --ffc-danger as text on
			// a card (4.29:1 in dark) and the white label of the .ffc-btn-success
			// button (3.35:1 in light). No guard measured that.
			array( '--ffc-text-secondary', '--ffc-bg-alt', 4.5, 'neutral status badge' ),
			array( '--ffc-text-muted', '--ffc-bg-alt', 4.5, 'closed status badge' ),
			array( '--ffc-text-secondary', '--ffc-bg-card', 4.5, 'a label inside the modal' ),
			array( '--ffc-primary-hover', '--ffc-primary-light', 4.5, '"sent" badge' ),
			array( '--ffc-success-text', '--ffc-bg-card', 4.5, 'a positive state as text' ),
			array( '--ffc-danger-text', '--ffc-bg-card', 4.5, 'a delete link' ),
			array( '--ffc-danger', '--ffc-bg-card', 4.5, 'danger as text on a card' ),
			array( '--ffc-text-light', '--ffc-bg-card', 4.5, 'an empty state inside the modal' ),
			array( '--ffc-primary', '--ffc-bg-card', 4.5, 'primary as text on a card' ),
			array( '--ffc-text-on-primary', '--ffc-primary-hover', 4.5, 'the primary button hovered' ),
			array( '--ffc-text-on-danger', '--ffc-danger', 4.5, 'the destructive button label' ),
			array( '--ffc-text-on-danger', '--ffc-danger-hover', 4.5, 'the destructive button hovered' ),
			array( '--ffc-text-on-success', '--ffc-success', 4.5, 'the success button label' ),
			array( '--ffc-text-on-success', '--ffc-success-hover', 4.5, 'the success button hovered' ),
			// The warning button's label was white on --ffc-warning: 3.04:1 in
			// the light theme, from the start, and nothing measured it (#1126,
			// 2nd pass).
			array( '--ffc-text-on-warning', '--ffc-warning', 4.5, 'the warning button label' ),
			// Pairs from the 6.24.0 smoke: the schedule's cancelled row and the
			// warning label used as TEXT (--ffc-warning is a signal colour, 3:1
			// floor, and gave 3.04:1 on white when used in .ffc-text-warning).
			array( '--ffc-text-muted', '--ffc-danger-bg', 4.5, 'the schedule\'s cancelled row' ),
			array( '--ffc-warning-text', '--ffc-bg', 4.5, 'warning as text' ),
			array( '--ffc-warning-text', '--ffc-bg-card', 4.5, 'warning as text on a card' ),
			array( '--ffc-inverse-on-surface', '--ffc-inverse-surface', 4.5, 'a toast bubble' ),
			// The base pair (#1126, 5th pass). Text that declares no colour
			// inherits from OUTSIDE here — from core's `body { color: #3c434a }`
			// in the admin, from the theme on a public page — and lands at
			// 1.28:1 on a dark ground. The base rule brings it to --ffc-text;
			// these are the backgrounds it has to cover, now measured like any
			// other pair instead of depending on inheritance.
			array( '--ffc-text', '--ffc-gray-100', 4.5, 'inherited text on the calendar header' ),
			array( '--ffc-text', '--ffc-gray-50', 4.5, 'inherited text on the shallowest surface' ),
			// Non-text: the contour that identifies the component, and the state
			// colours used as a signal (a badge's coloured dot).
			array( '--ffc-border', '--ffc-bg', 3.0, 'a contour on the background' ),
			array( '--ffc-border', '--ffc-bg-card', 3.0, 'a contour on a card' ),
			array( '--ffc-border', '--ffc-bg-input', 3.0, 'a field contour' ),
			array( '--ffc-border', '--ffc-bg-alt', 3.0, 'a contour on the alternate background' ),
			array( '--ffc-primary', '--ffc-bg', 3.0, 'primary as a signal' ),
			array( '--ffc-danger', '--ffc-bg', 3.0, 'danger as a signal' ),
			array( '--ffc-success', '--ffc-bg', 3.0, 'success as a signal' ),
			array( '--ffc-warning', '--ffc-bg', 3.0, 'warning as a signal' ),
			array( '--ffc-info', '--ffc-bg', 3.0, 'info as a signal' ),
		);
	}

	/**
	 * The colour tokens of one of the two themes.
	 *
	 * The dark theme is the light one **overridden**, not a set of its own: the
	 * `:root.ffc-dark-mode` block redefines only part of the tokens, and the rest
	 * still hold. Reading the dark block in isolation would measure a theme that
	 * does not exist — hence the merge.
	 *
	 * @param string $theme 'light' or 'dark'.
	 * @return array<string, string> Token => value.
	 */
	private static function palette( string $theme ): array {
		$css = (string) preg_replace( '#/\*.*?\*/#s', '', (string) file_get_contents( self::stylesheet() ) );

		$read = static function ( string $selector ) use ( $css ): array {
			if ( ! preg_match( '/' . preg_quote( $selector, '/' ) . '\s*\{(.*?)\n\}/s', $css, $m ) ) {
				return array();
			}
			$out = array();
			foreach ( explode( ';', $m[1] ) as $declaration ) {
				$parts = explode( ':', $declaration, 2 );
				if ( count( $parts ) === 2 && strpos( trim( $parts[0] ), '--ffc-' ) === 0 ) {
					$out[ trim( $parts[0] ) ] = trim( $parts[1] );
				}
			}
			return $out;
		};

		$light = $read( ':root' );
		return 'dark' === $theme ? array_merge( $light, $read( ':root.ffc-dark-mode' ) ) : $light;
	}

	/**
	 * `#rgb`, `#rrggbb` or `rgba()` to [r, g, b]; null for anything else.
	 *
	 * @param string $value CSS value.
	 * @return array{0: int, 1: int, 2: int}|null
	 */
	private static function to_rgb( string $value ): ?array {
		if ( preg_match( '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', trim( $value ), $m ) ) {
			$hex = $m[1];
			if ( strlen( $hex ) === 3 ) {
				$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
			}
			return array(
				(int) hexdec( substr( $hex, 0, 2 ) ),
				(int) hexdec( substr( $hex, 2, 2 ) ),
				(int) hexdec( substr( $hex, 4, 2 ) ),
			);
		}

		if ( preg_match( '/^rgba?\(\s*([0-9]+)\s*,\s*([0-9]+)\s*,\s*([0-9]+)/', trim( $value ), $m ) ) {
			return array( (int) $m[1], (int) $m[2], (int) $m[3] );
		}

		return null;
	}

	/**
	 * The WCAG 2.x contrast ratio between two colours.
	 *
	 * @param array{0: int, 1: int, 2: int} $a The first colour.
	 * @param array{0: int, 1: int, 2: int} $b The second colour.
	 */
	private static function contrast( array $a, array $b ): float {
		$luminance = static function ( array $c ): float {
			$channel = static function ( int $v ): float {
				$s = $v / 255;
				return $s <= 0.03928 ? $s / 12.92 : pow( ( $s + 0.055 ) / 1.055, 2.4 );
			};
			return 0.2126 * $channel( $c[0] ) + 0.7152 * $channel( $c[1] ) + 0.0722 * $channel( $c[2] );
		};

		$la = $luminance( $a );
		$lb = $luminance( $b );

		return ( max( $la, $lb ) + 0.05 ) / ( min( $la, $lb ) + 0.05 );
	}

	/**
	 * Every painted pair must reach its WCAG AA floor, in both themes.
	 *
	 * @dataProvider provider_themes
	 * @param string $theme The theme name.
	 */
	public function test_every_painted_pair_meets_its_contrast_floor( string $theme ): void {
		$palette  = self::palette( $theme );
		$failures = array();

		foreach ( self::pairs() as list( $fg, $bg, $floor, $what ) ) {
			$a = self::to_rgb( $palette[ $fg ] ?? '' );
			$b = self::to_rgb( $palette[ $bg ] ?? '' );

			$this->assertNotNull( $a, "Token {$fg} missing or unreadable in the {$theme} theme." );
			$this->assertNotNull( $b, "Token {$bg} missing or unreadable in the {$theme} theme." );

			$ratio = self::contrast( $a, $b );
			if ( $ratio < $floor ) {
				$failures[] = sprintf(
					'%s: %s on %s = %.2f:1, minimum %.1f:1  (%s / %s)',
					$what,
					$palette[ $fg ],
					$palette[ $bg ],
					$ratio,
					$floor,
					$fg,
					$bg
				);
			}
		}

		$this->assertSame(
			array(),
			$failures,
			"Pairs below the WCAG AA floor in the {$theme} theme:\n  " . implode( "\n  ", $failures )
			. "\n\nAdjust the token's LIGHTNESS while keeping its hue, and check it against ALL the"
			. "\nbackgrounds it appears on — a token is usually painted over more than one."
		);
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function provider_themes(): array {
		return array(
			'light' => array( 'light' ),
			'dark'  => array( 'dark' ),
		);
	}

	// ==================================================================
	// Pairs DERIVED from the scan (#1168)
	// ==================================================================
	//
	// `pairs()` above is a hand-written list. It measures what somebody
	// remembered to list, and CLAUDE.md described it as "every painted pair" —
	// which was not true. `--ffc-danger` over `--ffc-danger-bg` never made the
	// list and shipped at 4.25:1 in the LIGHT theme, below the AA floor, on a
	// warning in the public CSV flow.
	//
	// This derives the pairs from the CSS itself: every rule that declares
	// `color` AND a background in the SAME rule is a painted pair, measured in
	// both themes.
	//
	// The two lists are complementary, not substitutes. The scan only sees the
	// pair one rule declares together; the pair INHERITANCE creates — the base
	// text over `--ffc-gray-100`, a button label whose colour comes from its
	// container — is beyond any static scan, and that is what the curated list
	// covers. Deleting either in favour of the other loses coverage.

	/**
	 * Pairs below the floor that are exempt, with the reason.
	 *
	 * SC 1.4.3 exempts text that is part of an **inactive user interface
	 * component**. The two entries here are exactly that, and both say
	 * `cursor: not-allowed` in the rule itself. Any other entry needs a reason
	 * that survives being read aloud.
	 */
	private const DERIVED_EXCEPTIONS = array(
		'var(--ffc-gray-400) || var(--ffc-gray-100)'    => 'a full time slot in the public calendar: `cursor: not-allowed`, an inactive component exempt under SC 1.4.3',
		'var(--ffc-text-light) || var(--ffc-bg-alt)'    => 'a `readonly`/`disabled` reregistration field: an inactive component exempt under SC 1.4.3',
	);

	/**
	 * Resolves a CSS colour value down to [r, g, b], crossing `var()`.
	 *
	 * It has to accept three things `to_rgb()` alone does not, and all three
	 * appeared in the real CSS: the `!important` suffix, the `var()` chain (a
	 * token can point at another) and the NAMED colour.
	 *
	 * The named colour is the one that matters. The first version of this scan
	 * could not read it and reported four pairs as "unresolvable" — which were
	 * `color: white` over `--ffc-primary`, `--ffc-danger`, `--ffc-success` and
	 * `--ffc-inverse-surface`, measuring 2.52 · 2.68 · 2.78 and **1.23:1** in the
	 * dark theme. Passing silently over what cannot be read is how a meter lies;
	 * that is why `null` here FAILS the test, never skips.
	 *
	 * @param string               $value   The CSS value.
	 * @param array<string,string> $palette Token => value, for the measured theme.
	 * @param int                  $depth   Recursion depth.
	 * @return array{0: int, 1: int, 2: int}|null
	 */
	private static function resolve_colour( string $value, array $palette, int $depth = 0 ): ?array {
		if ( $depth > 8 ) {
			return null;
		}

		$value = trim( (string) preg_replace( '/\s*!important\s*$/i', '', trim( $value ) ) );

		$named = array( 'white' => '#ffffff', 'black' => '#000000' );
		if ( isset( $named[ strtolower( $value ) ] ) ) {
			$value = $named[ strtolower( $value ) ];
		}

		if ( preg_match( '/^var\(\s*(--ffc-[a-z0-9-]+)\s*(?:,\s*(.+))?\)$/i', $value, $m ) ) {
			if ( isset( $palette[ $m[1] ] ) ) {
				return self::resolve_colour( $palette[ $m[1] ], $palette, $depth + 1 );
			}
			return isset( $m[2] ) ? self::resolve_colour( $m[2], $palette, $depth + 1 ) : null;
		}

		return self::to_rgb( $value );
	}

	/**
	 * `color` + background pairs declared in the SAME rule, across all sheets.
	 *
	 * A shorthand background (`background: linear-gradient(...)`) is measured
	 * against the first colour it names — a declared approximation, not exactness:
	 * the one gradient that paints text today passes at both ends (5.17 and
	 * 6.84:1). A pair where neither side is a token does not enter: it is literal
	 * end to end, and the literal ratchet is what looks after it.
	 *
	 * @return array<string, array{fg: string, bg: string, sites: array<int, string>}>
	 */
	private static function derived_pairs(): array {
		$pairs = array();

		foreach ( CssSelectors::sheets() as $path ) {
			foreach ( CssSelectors::rules( (string) file_get_contents( $path ) ) as $rule ) {
				$body = (string) preg_replace( '#/\*.*?\*/#s', '', $rule['body'] );
				$fg   = null;
				$bg   = null;

				foreach ( explode( ';', $body ) as $declaration ) {
					if ( ! str_contains( $declaration, ':' ) ) {
						continue;
					}
					list( $property, $value ) = explode( ':', $declaration, 2 );
					$property = strtolower( trim( $property ) );
					$value    = trim( (string) preg_replace( '/\s+/', ' ', $value ) );

					if ( 'color' === $property ) {
						$fg = $value;
					}
					if ( 'background' === $property || 'background-color' === $property ) {
						if ( preg_match( '/(var\(\s*--ffc-[a-z0-9-]+\s*(?:,[^)]*)?\)|#[0-9a-fA-F]{3,8}|rgba?\([^)]*\))/', $value, $m ) ) {
							$bg = $m[1];
						}
					}
				}

				if ( null === $fg || null === $bg ) {
					continue;
				}
				if ( ! str_contains( $fg, 'var(--ffc-' ) && ! str_contains( $bg, 'var(--ffc-' ) ) {
					continue;
				}

				$key = $fg . ' || ' . $bg;
				$pairs[ $key ]['fg']      = $fg;
				$pairs[ $key ]['bg']      = $bg;
				$pairs[ $key ]['sites'][] = basename( $path ) . ' :: ' . trim( (string) preg_replace( '/\s+/', ' ', $rule['selector'] ) );
			}
		}

		return $pairs;
	}

	/**
	 * Every pair a rule declares together must reach 4.5:1.
	 *
	 * The floor is the TEXT one, with no exception by role: the rule declared
	 * `color`, so it is painting text. A contour is `border-color` and does not
	 * enter this scan.
	 *
	 * It blocks at zero. Whatever the scan finds is fixed by swapping the token
	 * for the pair the palette already guarantees (`--ffc-X` → `--ffc-X-text`
	 * over a `--ffc-X-bg` ground); there is no baseline to grow.
	 *
	 * @dataProvider provider_themes
	 * @param string $theme The theme name.
	 */
	public function test_every_derived_pair_meets_the_text_floor( string $theme ): void {
		$palette  = self::palette( $theme );
		$failures = array();

		foreach ( self::derived_pairs() as $key => $pair ) {
			$a = self::resolve_colour( $pair['fg'], $palette );
			$b = self::resolve_colour( $pair['bg'], $palette );

			// Unreadable is NOT "skip": it is a failure. See resolve_colour()'s docblock.
			$this->assertNotNull(
				$a,
				"Could not resolve the colour `{$pair['fg']}` in the {$theme} theme.\n"
				. "Teach `resolve_colour()` to read it — do not let it pass in silence.\n"
				. '  ' . implode( "\n  ", array_slice( $pair['sites'], 0, 3 ) )
			);
			$this->assertNotNull(
				$b,
				"Could not resolve the background `{$pair['bg']}` in the {$theme} theme.\n"
				. "Teach `resolve_colour()` to read it — do not let it pass in silence.\n"
				. '  ' . implode( "\n  ", array_slice( $pair['sites'], 0, 3 ) )
			);

			$ratio = self::contrast( $a, $b );
			if ( $ratio >= 4.5 ) {
				continue;
			}
			if ( isset( self::DERIVED_EXCEPTIONS[ $key ] ) ) {
				continue;
			}

			$failures[] = sprintf(
				'%.2f:1  %s' . "\n      " . '%s',
				$ratio,
				$key,
				implode( "\n      ", array_slice( $pair['sites'], 0, 4 ) )
			);
		}

		$this->assertSame(
			array(),
			$failures,
			"A pair declared in one rule below 4.5:1 in the {$theme} theme:\n\n  "
			. implode( "\n\n  ", $failures )
			. "\n\nSwap the text token for the pair the palette guarantees — `--ffc-X` over"
			. "\na `--ffc-X-bg`/`--ffc-X-light` ground means `--ffc-X-text`. If it is an"
			. "\nINACTIVE component (SC 1.4.3 exempts it), add it to DERIVED_EXCEPTIONS with the reason."
		);
	}

	/**
	 * Every declared exception must exist and carry a reason.
	 *
	 * An exception the CSS no longer produces is noise that outlives whoever
	 * wrote it — the same ratchet as the other guards, in the direction that only
	 * shrinks.
	 */
	public function test_the_derived_exceptions_are_all_live(): void {
		$keys = array_keys( self::derived_pairs() );

		foreach ( self::DERIVED_EXCEPTIONS as $key => $reason ) {
			$this->assertContains(
				$key,
				$keys,
				"The exception `{$key}` matches no painted pair. Drop it."
			);
			$this->assertGreaterThan(
				30,
				strlen( $reason ),
				"The exception `{$key}` needs a reason that holds up when read."
			);
		}
	}

	/**
	 * Self-check for the derived scan.
	 *
	 * A scan that returns zero pairs also satisfies `assertSame( array(),
	 * $failures )` — the #1071 / #1094 lesson. The floors are deliberately loose:
	 * they describe "the scan worked", not the exact size.
	 */
	public function test_the_derived_scan_cannot_collapse_in_silence(): void {
		$pairs = self::derived_pairs();
		$sites = array_sum( array_map( static fn( array $p ): int => count( $p['sites'] ), $pairs ) );

		$this->assertGreaterThan( 30, count( $pairs ), 'The derived-pair scan collapsed.' );
		$this->assertGreaterThan( 150, $sites, 'The scan found too many pairs from too few rules.' );

		// Resolution has to cross `var()` and the named colour, not just hex.
		$palette = self::palette( 'light' );
		$this->assertNotNull( self::resolve_colour( 'var(--ffc-text)', $palette ), '`var()` stopped resolving.' );
		$this->assertNotNull( self::resolve_colour( 'white !important', $palette ), 'A named colour stopped resolving.' );
		$this->assertSame( array( 255, 255, 255 ), self::resolve_colour( 'white', $palette ) );
	}

	/**
	 * Self-check for the meter.
	 *
	 * `assertSame( array(), $failures )` is also satisfied by a palette that was
	 * never read — the shape #1094 found in four guards at once. The check here
	 * is threefold: the palette has to have a plausible size, the dark theme has
	 * to genuinely differ from the light one, and the computation has to
	 * reproduce two known values.
	 */
	public function test_the_meter_cannot_collapse_in_silence(): void {
		$light = self::palette( 'light' );
		$dark  = self::palette( 'dark' );

		$this->assertGreaterThan( 30, count( $light ), 'The light palette was not read.' );
		$this->assertGreaterThan( 30, count( $dark ), 'The dark palette was not read.' );
		$this->assertNotSame( $light, $dark, 'The dark theme read the same as the light one — the merge broke.' );

		// Black on white is 21:1 and white on white is 1:1, by definition.
		$this->assertEqualsWithDelta( 21.0, self::contrast( array( 0, 0, 0 ), array( 255, 255, 255 ) ), 0.01 );
		$this->assertEqualsWithDelta( 1.0, self::contrast( array( 255, 255, 255 ), array( 255, 255, 255 ) ), 0.01 );
	}
}

