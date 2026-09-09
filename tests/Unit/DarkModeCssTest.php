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
}
