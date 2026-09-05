<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards against a `var(--ffc-…)` that names a token nothing defines.
 *
 * CSS resolves an undefined custom property to the guaranteed-invalid value,
 * which makes the whole declaration invalid at computed-value time — and does
 * so in silence. Nothing appears in the console, the rule simply never
 * applies, and downstream the consumer falls through to its own fallback.
 *
 * That is not hypothetical: the ALTCHA widget was given
 * `--altcha-border-radius: var(--ffc-radius)`, a token defined only in the
 * recruitment and user-dashboard sheets, which do not load on the pages the
 * widget renders on. The widget's `var(--altcha-border-radius, 0)` therefore
 * used its fallback and the box had square corners, with nothing to see
 * anywhere but the rendered page.
 *
 * The rule enforced here is the project's actual contract: `ffc-common.css`
 * is the shared token sheet, and any other sheet may declare tokens of its
 * own. A reference must resolve in one of those two places — or carry an
 * explicit fallback, which is a deliberate default rather than an accident.
 */
class CssTokenReferenceTest extends TestCase {

	/**
	 * Tokens supplied at runtime through a `style` attribute.
	 *
	 * These have no stylesheet definition by design: the value is per-element
	 * (an audience colour, a reason's swatch) and is written inline by the
	 * renderer, so a stylesheet default would be a value nothing ever uses.
	 *
	 * @var array<string, string>
	 */
	private const RUNTIME_TOKENS = array(
		'--ffc-color' => 'Set inline per element as a colour swatch (audiences, recruitment reasons).',
	);

	/**
	 * Every `var(--ffc-…)` without a fallback resolves to a declared token.
	 *
	 * @return void
	 */
	public function test_every_ffc_token_reference_resolves(): void {
		$root   = dirname( __DIR__, 2 ) . '/assets/css';
		$common = (string) file_get_contents( $root . '/ffc-common.css' );

		$shared = $this->declared_in( $common );
		$this->assertNotEmpty( $shared, 'ffc-common.css declares no tokens — the scan is looking at the wrong file.' );

		$unresolved = array();

		foreach ( glob( $root . '/*.css' ) as $file ) {
			if ( str_ends_with( $file, '.min.css' ) ) {
				continue;
			}

			$css   = (string) file_get_contents( $file );
			$local = $this->declared_in( $css );
			$name  = basename( $file );

			// A reference followed by a comma carries its own fallback, so an
			// undefined token there is a stated default, not an accident.
			preg_match_all( '/var\(\s*(--ffc-[\w-]+)\s*\)/', $css, $matches, PREG_OFFSET_CAPTURE );

			foreach ( $matches[1] as $match ) {
				$token = $match[0];

				if ( isset( self::RUNTIME_TOKENS[ $token ] ) ) {
					continue;
				}

				if ( in_array( $token, $local, true ) || in_array( $token, $shared, true ) ) {
					continue;
				}

				$line         = substr_count( substr( $css, 0, (int) $match[1] ), "\n" ) + 1;
				$unresolved[] = "{$name}:{$line} references {$token}, which neither that file nor ffc-common.css declares";
			}
		}

		$this->assertSame(
			array(),
			$unresolved,
			"A custom property that resolves to nothing silently voids its whole declaration:\n" . implode( "\n", $unresolved )
		);
	}

	/**
	 * The `--ffc-*` tokens a stylesheet declares.
	 *
	 * @param string $css Stylesheet source.
	 * @return list<string>
	 */
	private function declared_in( string $css ): array {
		preg_match_all( '/(--ffc-[\w-]+)\s*:/', $css, $matches );

		return array_values( array_unique( $matches[1] ) );
	}
}
