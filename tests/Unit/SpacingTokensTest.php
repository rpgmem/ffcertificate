<?php
/**
 * Spacing-scale guard (#1169).
 *
 * `--ffc-spacing-*` existed for as long as the palette and had **3 consumers
 * out of 1,238** spacing declarations; 30 distinct px values were in use. That
 * is the typography story before #1148, with one aggravation: the ladder the
 * scale declared (5 · 10 · 15 · 20 · 30) did not contain the ladder the code
 * actually used (2 · 4 · 6 · 8 · 12 · 16 · 24), and neither dominated — 550
 * uses against 624.
 *
 * So the scale was rebuilt from the values the code really uses, and adoption
 * moved **nothing**: every substitution is `10px` → `var(--ffc-spacing-md)`
 * where that token is `10px`, which is an identity. It was proved rather than
 * asserted — the 1,238 declarations were resolved back to px before and after
 * and compared; the two lists are byte-identical.
 *
 * Dependency-free on purpose — no WordPress, no Brain\Monkey — like the module
 * boundary and typography guards. It reads source text only.
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
final class SpacingTokensTest extends TestCase {

	/**
	 * The properties this guard treats as spacing.
	 *
	 * `border-width` and `inset` are deliberately out: a hairline is not a
	 * distance between things, and a density theme would not scale it.
	 */
	private const PROPERTIES = array(
		'margin',
		'margin-top',
		'margin-bottom',
		'margin-left',
		'margin-right',
		'padding',
		'padding-top',
		'padding-bottom',
		'padding-left',
		'padding-right',
		'gap',
		'row-gap',
		'column-gap',
	);

	/**
	 * The nine steps of the scale, plus the two the second ladder left behind.
	 *
	 * There is **one ladder now**. `--ffc-spacing-5` and `--ffc-spacing-15`
	 * carried the second one under names that stated their value, so that they
	 * would leave by deletion rather than by a rename that hides the debt —
	 * and in #1176 they did: 182 declarations repointed to a neighbouring step,
	 * the two tokens deleted. The `14px` / `18px` literals went with them; they
	 * were the exact midpoints of the two widest gaps (12→16 and 16→20), and
	 * admitting them as steps would have made an eleven-rung dictionary out of
	 * a scale.
	 */
	private const STEPS = array( '3xs', '2xs', 'xs', 'sm', 'md', 'lg', 'xl', '2xl', '3xl' );

	/**
	 * Spacing literals still allowed per stylesheet, by basename.
	 *
	 * A ratchet that only shrinks. **162 before #1176, 92 after**, and what
	 * remains is two populations rather than one:
	 *
	 *  - **`1px` and `3px`** — an optical nudge, not a distance. They stay
	 *    literal for the same reason the typography scale keeps a glyph box.
	 *  - **`30px` and above** — one-off large distances, mostly a section break
	 *    or a fixed panel. A scale that reached them would be a dictionary.
	 *
	 * The third population is gone: `14px` and `18px` were the residue of a
	 * THIRD pattern, sitting on the exact midpoints of the two widest gaps
	 * (12→16 and 16→20). #1176 snapped them down rather than admit two more
	 * rungs, because an eleven-rung ladder is a dictionary too.
	 */
	private const BUDGET = array(
		'ffc-admin-move-submissions.css'   => 1,
		'ffc-admin-settings.css'           => 7,
		'ffc-admin-submissions.css'        => 1,
		'ffc-admin.css'                    => 2,
		'ffc-audience-admin.css'           => 8,
		'ffc-audience.css'                 => 7,
		'ffc-calendar-frontend.css'        => 4,
		'ffc-certificates-dashboard.css'   => 1,
		'ffc-code-editor-dark.css'         => 1,
		'ffc-common.css'                   => 1,
		'ffc-custom-fields-admin.css'      => 3,
		'ffc-frontend.css'                 => 19,
		'ffc-pdf-core.css'                 => 1,
		'ffc-progress-overlay.css'         => 1,
		'ffc-recruitment-admin.css'        => 2,
		'ffc-reregistration-admin.css'     => 5,
		'ffc-url-shortener-admin.css'      => 5,
		'ffc-user-dashboard.css'           => 8,
		'ffc-user-permissions.css'         => 15,
	);

	/**
	 * Absolute path to the stylesheet that declares the scale.
	 */
	private static function palette_sheet(): string {
		return dirname( __DIR__, 2 ) . '/assets/css/ffc-common.css';
	}

	/**
	 * Every bare `<n>px` a stylesheet writes in a spacing property.
	 *
	 * Reads declarations, never selectors, and skips `calc()` — a px inside an
	 * expression is an operand, not a distance the scale can name.
	 *
	 * @param string $path Absolute path.
	 * @return array<int, string> The offending declarations, in file order.
	 */
	private static function literals( string $path ): array {
		$out = array();

		foreach ( CssSelectors::rules( (string) file_get_contents( $path ) ) as $rule ) {
			$body = (string) preg_replace( '~/\*.*?\*/~s', '', $rule['body'] );

			foreach ( explode( ';', $body ) as $declaration ) {
				if ( ! str_contains( $declaration, ':' ) ) {
					continue;
				}
				list( $property, $value ) = explode( ':', $declaration, 2 );
				$property = strtolower( trim( $property ) );
				$value    = trim( (string) preg_replace( '/\s+/', ' ', $value ) );

				if ( ! in_array( $property, self::PROPERTIES, true ) || str_contains( $value, 'calc(' ) ) {
					continue;
				}

				foreach ( preg_split( '/\s+/', $value ) ?: array() as $part ) {
					if ( preg_match( '/^\d+px$/', $part ) ) {
						$out[] = $property . ': ' . $value;
					}
				}
			}
		}

		return $out;
	}

	/**
	 * Token => declared value, read from the `:root` block.
	 *
	 * @return array<string, string>
	 */
	private static function declared_steps(): array {
		$css = (string) preg_replace( '#/\*.*?\*/#s', '', (string) file_get_contents( self::palette_sheet() ) );
		$out = array();

		if ( preg_match( '/:root\s*\{(.*?)\}/s', $css, $block ) ) {
			preg_match_all( '/(--ffc-spacing-([a-z0-9]+))\s*:\s*([^;]+);/i', $block[1], $found, PREG_SET_ORDER );
			foreach ( $found as $m ) {
				$out[ $m[2] ] = trim( $m[3] );
			}
		}

		return $out;
	}

	// ==================================================================
	// A catraca
	// ==================================================================

	/**
	 * No stylesheet writes more spacing literals than its budget.
	 */
	public function test_no_stylesheet_exceeds_its_spacing_budget(): void {
		$over = array();

		foreach ( CssSelectors::sheets() as $path ) {
			$sheet   = basename( $path );
			$budget  = self::BUDGET[ $sheet ] ?? 0;
			$found   = self::literals( $path );
			$count   = count( $found );

			if ( $count > $budget ) {
				$over[] = sprintf(
					"%s: %d literais, orçamento %d\n      %s",
					$sheet,
					$count,
					$budget,
					implode( "\n      ", array_slice( array_unique( $found ), 0, 6 ) )
				);
			}
		}

		$this->assertSame(
			array(),
			$over,
			"Literal de espaçamento acima do orçamento:\n\n  " . implode( "\n\n  ", $over )
			. "\n\nLeia a escala: 2 · 4 · 6 · 8 · 10 · 12 · 16 · 20 · 24 são"
			. "\n`var(--ffc-spacing-3xs .. 3xl)` — é a escada inteira, não há outra."
			. "\nUm valor fora disso é ajuste ótico ou distância avulsa — deixe literal E"
			. "\naumente o orçamento nesta linha de base, com a razão."
		);
	}

	/**
	 * A sheet that fell below its budget must ratchet the budget down.
	 *
	 * The direction that locks a win in: without it a conversion silently
	 * leaves room for the next literal to creep back.
	 */
	public function test_budgets_ratchet_down_when_a_sheet_is_converted(): void {
		$stale = array();

		foreach ( CssSelectors::sheets() as $path ) {
			$sheet  = basename( $path );
			$budget = self::BUDGET[ $sheet ] ?? 0;
			$count  = count( self::literals( $path ) );

			if ( $count < $budget ) {
				$stale[] = sprintf( '%s: %d literais, orçamento ainda %d', $sheet, $count, $budget );
			}
		}

		$this->assertSame(
			array(),
			$stale,
			"Orçamento folgado — baixe-o para o valor medido e trave o ganho:\n  "
			. implode( "\n  ", $stale )
		);
	}

	// ==================================================================
	// A escala
	// ==================================================================

	/**
	 * The scale declares exactly the nine steps — no tenth, no second ladder.
	 */
	public function test_the_scale_declares_exactly_the_expected_steps(): void {
		// Um degrau nomeado por número viraria chave int; compara como string.
		$declared = array_map( 'strval', array_keys( self::declared_steps() ) );
		sort( $declared );

		$expected = self::STEPS;
		sort( $expected );

		$this->assertSame(
			$expected,
			$declared,
			'A escala mudou de forma. Se um degrau entrou ou saiu, atualize STEPS '
			. 'e diga por quê — um degrau a mais costuma ser um dicionário nascendo.'
		);
	}

	/**
	 * Every `var(--ffc-spacing-*)` read names a step the scale declares.
	 *
	 * An undeclared custom property invalidates the WHOLE declaration — it
	 * does not fall back to what was there before (the #1126 lesson), so a
	 * typo here removes the padding rather than mis-sizing it.
	 */
	public function test_every_token_read_names_a_declared_step(): void {
		$declared = self::declared_steps();
		$unknown  = array();

		foreach ( CssSelectors::sheets() as $path ) {
			$css = (string) file_get_contents( $path );
			preg_match_all( '/var\(\s*--ffc-spacing-([a-z0-9]+)\s*[,)]/i', $css, $found );
			foreach ( array_unique( $found[1] ) as $step ) {
				if ( ! isset( $declared[ $step ] ) ) {
					$unknown[] = basename( $path ) . ': --ffc-spacing-' . $step;
				}
			}
		}

		$this->assertSame( array(), $unknown, "Token de espaçamento que ninguém declara:\n  " . implode( "\n  ", $unknown ) );
	}

	// ==================================================================
	// Autoverificação
	// ==================================================================

	/**
	 * The scan cannot collapse in silence.
	 *
	 * `assertSame( array(), $over )` is also satisfied by a scan that read
	 * nothing — the #1071 / #1094 shape. The floors are loose on purpose: they
	 * say "the scan worked", not how big the codebase is.
	 */
	public function test_the_scan_still_sees_the_stylesheets(): void {
		$sheets = CssSelectors::sheets();
		$this->assertGreaterThan( 20, count( $sheets ), 'A varredura não achou as folhas.' );

		$reads = 0;
		foreach ( $sheets as $path ) {
			$reads += preg_match_all( '/var\(\s*--ffc-spacing-/i', (string) file_get_contents( $path ) );
		}

		$this->assertGreaterThan(
			900,
			$reads,
			'Quase ninguém lê a escala — foi exatamente esse o estado que a #1169 encontrou '
			. '(3 consumidores de 1.238) e que esta guarda existe para impedir de voltar.'
		);
	}

	/**
	 * Every budget entry names a stylesheet that exists.
	 */
	public function test_every_budget_entry_names_a_real_stylesheet(): void {
		$real = array_map( 'basename', CssSelectors::sheets() );

		foreach ( array_keys( self::BUDGET ) as $sheet ) {
			$this->assertContains( $sheet, $real, "O orçamento cita `{$sheet}`, que não existe." );
		}
	}
}
