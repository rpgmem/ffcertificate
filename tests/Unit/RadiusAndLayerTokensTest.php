<?php
/**
 * Radius and stacking-layer guard (#1171).
 *
 * Two small scales that the #1167 audit found in opposite states. `--ffc-radius-*`
 * was **half adopted** — 82 declarations read it against 186 literals, and 71 of
 * those literals were the `4px` the token already was. `z-index` had **no scale at
 * all**: `1`, `2`, `10`, `100`, `1000`, `9999`, `100000`, `100050`, `100100`,
 * `999999`, `2147483647`.
 *
 * Adoption moved nothing — the 149 radius and 16 layer values converted are all
 * identities, proved by resolving the 294 declarations back to their literal
 * before and after and comparing.
 *
 * **The four escalation values were deliberately NOT collapsed.** `9999`,
 * `100001`, `999999` and the `2147483647` in the PDF sheet are four different
 * answers to one question — "be above the wp-admin bar, which is 99999" — and
 * that is the signature of a stacking war, not a scale waiting to be applied.
 * Collapsing them changes the ORDER between elements on the screens where they
 * coexist, which is a behaviour change rather than a conversion, and it needs
 * screen-by-screen verification. They stay literal, in the budget, visible.
 *
 * Dependency-free on purpose — it reads source text only.
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
final class RadiusAndLayerTokensTest extends TestCase {

	/**
	 * Sheets that must NOT read any `var(--ffc-*)`.
	 *
	 * Both are enqueued **without** `ffc-common` on purpose — print is light by
	 * definition, and the editor theme is dark in both themes. A token there is
	 * undeclared, and an undeclared custom property invalidates the WHOLE
	 * declaration rather than falling back (#1126). The radius/layer conversion
	 * hit both and `AdminStylesheetTokensTest` direction B is what caught it, so
	 * this list exists to keep the next conversion from rediscovering it.
	 */
	private const NO_PALETTE = array( 'ffc-pdf-core.css', 'ffc-code-editor-dark.css' );

	/**
	 * The declared steps of each scale.
	 */
	private const RADIUS_STEPS = array( 'xs', 'sm', 'md', 'lg', 'full' );
	private const LAYER_STEPS  = array( 'raised', 'sticky', 'dropdown', 'overlay', 'modal' );

	/**
	 * Radius literals still allowed per stylesheet. `0` is not a radius.
	 *
	 * 40 remain of 186. The frequent ones are `10px` (10), `5px` (6), `2px` (5)
	 * and `11px` (5) — near-misses that a step would have to be invented for, and
	 * inventing a step per value is how a scale becomes a dictionary.
	 */
	private const RADIUS_BUDGET = array(
		'ffc-admin-settings.css'         => 2,
		'ffc-admin.css'                  => 2,
		'ffc-audience.css'               => 2,
		'ffc-certificates-dashboard.css' => 2,
		'ffc-code-editor-dark.css'       => 2,
		'ffc-common.css'                 => 5,
		'ffc-custom-fields-admin.css'    => 1,
		'ffc-frontend.css'               => 7,
		'ffc-pdf-core.css'               => 2,
		'ffc-recruitment-admin.css'      => 2,
		'ffc-recruitment-public.css'     => 1,
		'ffc-user-dashboard.css'         => 2,
		'ffc-user-permissions.css'       => 6,
	);

	/**
	 * `z-index` literals still allowed per stylesheet.
	 *
	 * Ten remain, and they are the debt this guard exists to keep visible:
	 * `ffc-pdf-core.css` carries five (it cannot read tokens at all), and the
	 * other five are the escalation values described in the class docblock.
	 */
	private const LAYER_BUDGET = array(
		'ffc-admin-settings.css'      => 1,
		'ffc-admin.css'               => 1,
		'ffc-frontend.css'            => 1,
		'ffc-pdf-core.css'            => 5,
		'ffc-url-shortener-admin.css' => 2,
	);

	/**
	 * Literal values a stylesheet writes for one property.
	 *
	 * @param string $path     Absolute path.
	 * @param string $property `border-radius` or `z-index`.
	 * @return array<int, string>
	 */
	private static function literals( string $path, string $property ): array {
		$out = array();

		foreach ( CssSelectors::rules( (string) file_get_contents( $path ) ) as $rule ) {
			$body = (string) preg_replace( '~/\*.*?\*/~s', '', $rule['body'] );

			foreach ( explode( ';', $body ) as $declaration ) {
				if ( ! str_contains( $declaration, ':' ) ) {
					continue;
				}
				list( $name, $value ) = explode( ':', $declaration, 2 );
				$name  = strtolower( trim( $name ) );
				$value = trim( (string) preg_replace( '/\s+/', ' ', $value ) );

				if ( $name !== $property || str_contains( $value, 'var(' ) ) {
					continue;
				}
				// `border-radius: 0` is "no radius", not a value on the scale.
				if ( 'border-radius' === $property && '0' === $value ) {
					continue;
				}
				$out[] = $name . ': ' . $value;
			}
		}

		return $out;
	}

	/**
	 * @return array<string, string> Step => declared value.
	 */
	private static function declared( string $prefix ): array {
		$css = (string) preg_replace( '#/\*.*?\*/#s', '', (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/css/ffc-common.css' ) );
		$out = array();

		if ( preg_match( '/:root\s*\{(.*?)\}/s', $css, $block ) ) {
			preg_match_all( '/--ffc-' . preg_quote( $prefix, '/' ) . '-([a-z0-9]+)\s*:\s*([^;]+);/i', $block[1], $found, PREG_SET_ORDER );
			foreach ( $found as $m ) {
				$out[ $m[1] ] = trim( $m[2] );
			}
		}

		return $out;
	}

	/**
	 * @return array<string, array{0: string, 1: array<string,int>, 2: string}>
	 */
	public static function provider_scales(): array {
		return array(
			'raio'   => array( 'border-radius', self::RADIUS_BUDGET, 'radius' ),
			'camada' => array( 'z-index', self::LAYER_BUDGET, 'z' ),
		);
	}

	/**
	 * No stylesheet writes more literals than its budget, on either scale.
	 *
	 * @dataProvider provider_scales
	 * @param string             $property CSS property.
	 * @param array<string, int> $budget   Per-sheet allowance.
	 */
	public function test_no_stylesheet_exceeds_its_budget( string $property, array $budget ): void {
		$over = array();

		foreach ( CssSelectors::sheets() as $path ) {
			$sheet = basename( $path );
			$found = self::literals( $path, $property );

			if ( count( $found ) > ( $budget[ $sheet ] ?? 0 ) ) {
				$over[] = sprintf(
					"%s: %d, orçamento %d\n      %s",
					$sheet,
					count( $found ),
					$budget[ $sheet ] ?? 0,
					implode( "\n      ", array_slice( array_unique( $found ), 0, 5 ) )
				);
			}
		}

		$this->assertSame(
			array(),
			$over,
			"Literal de `{$property}` acima do orçamento:\n\n  " . implode( "\n\n  ", $over )
			. "\n\nLeia a escala. Se o valor não estiver nela, deixe literal E suba o"
			. "\norçamento com a razão — inventar um degrau por valor é como uma escala"
			. "\nvira dicionário."
		);
	}

	/**
	 * A sheet that fell below its budget ratchets the budget down.
	 *
	 * @dataProvider provider_scales
	 * @param string             $property CSS property.
	 * @param array<string, int> $budget   Per-sheet allowance.
	 */
	public function test_budgets_ratchet_down( string $property, array $budget ): void {
		$stale = array();

		foreach ( CssSelectors::sheets() as $path ) {
			$sheet = basename( $path );
			$count = count( self::literals( $path, $property ) );

			if ( $count < ( $budget[ $sheet ] ?? 0 ) ) {
				$stale[] = sprintf( '%s: %d, orçamento ainda %d', $sheet, $count, $budget[ $sheet ] ?? 0 );
			}
		}

		$this->assertSame( array(), $stale, "Orçamento folgado — baixe e trave o ganho:\n  " . implode( "\n  ", $stale ) );
	}

	/**
	 * Both scales declare exactly the steps this guard knows about.
	 */
	public function test_both_scales_declare_exactly_their_steps(): void {
		$radius = array_keys( self::declared( 'radius' ) );
		$layer  = array_keys( self::declared( 'z' ) );
		sort( $radius );
		sort( $layer );

		$expected_radius = self::RADIUS_STEPS;
		$expected_layer  = self::LAYER_STEPS;
		sort( $expected_radius );
		sort( $expected_layer );

		$this->assertSame( $expected_radius, $radius, 'A escala de raio mudou de forma.' );
		$this->assertSame( $expected_layer, $layer, 'A escala de camada mudou de forma.' );
	}

	/**
	 * The two palette-less sheets read no token at all.
	 *
	 * Not a style preference: without `ffc-common` on the page the property does
	 * not exist, and an undeclared custom property invalidates the declaration
	 * rather than falling back — the radius would not change, it would VANISH.
	 */
	public function test_the_palette_less_sheets_read_no_token(): void {
		foreach ( self::NO_PALETTE as $sheet ) {
			$path = dirname( __DIR__, 2 ) . '/assets/css/' . $sheet;
			$this->assertFileExists( $path );
			$this->assertDoesNotMatchRegularExpression(
				'/var\(\s*--ffc-/i',
				(string) file_get_contents( $path ),
				"`{$sheet}` é enfileirada sem `ffc-common`, então um `var(--ffc-*)` ali "
				. 'invalida a declaração inteira. Converta de volta para literal.'
			);
		}
	}

	/**
	 * The scan cannot collapse in silence.
	 */
	public function test_the_scan_cannot_collapse_in_silence(): void {
		$radius = 0;
		$layer  = 0;

		foreach ( CssSelectors::sheets() as $path ) {
			$css     = (string) file_get_contents( $path );
			$radius += preg_match_all( '/var\(\s*--ffc-radius-/i', $css );
			$layer  += preg_match_all( '/var\(\s*--ffc-z-/i', $css );
		}

		$this->assertGreaterThan( 200, $radius, 'Quase ninguém lê a escala de raio — era esse o estado que a #1171 encontrou.' );
		$this->assertGreaterThan( 12, $layer, 'Quase ninguém lê a escala de camada.' );
		$this->assertGreaterThan( 20, count( CssSelectors::sheets() ), 'A varredura não achou as folhas.' );
	}
}
