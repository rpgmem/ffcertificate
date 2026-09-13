<?php
/**
 * Um controle ou selo que só tinha fundo precisa de contorno no alto contraste.
 *
 * @package FreeFormCertificate\Tests
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use FreeFormCertificate\Tests\Support\CssSelectors;
use PHPUnit\Framework\TestCase;

/**
 * Guarda de contorno sob `forced-colors` (#1165, sub-issue da #1148).
 *
 * No modo de alto contraste o agente de usuário **força** `color`,
 * `background-color` e `border-color` para as cores do sistema e descarta
 * `box-shadow`. Um componente cujo limite era só o fundo deixa de ter limite.
 *
 * **Não era hipótese.** Medido em Chromium sobre a paleta e a folha pública
 * reais: os cinco selos de estado do agendamento colapsavam em **um só**
 * (mesmo branco, mesmo preto, zero bordas), e o botão de envio do formulário
 * público — a ação primária da tela — virava uma palavra sem contorno.
 *
 * A guarda **bloqueia em zero**: todo seletor de controle ou selo que declara
 * fundo e nenhum contorno precisa estar coberto por uma regra
 * `@media (forced-colors: active)`. Exceções vão em `ALLOWED` com o motivo.
 *
 * **Por que só controle e selo, e não todo fundo.** A varredura acha 274
 * seletores com fundo e sem contorno; a maioria é decorativa (listra de
 * tabela, fundo de página, véu de modal) e perder o fundo ali não custa nada.
 * Exigir borda de todos seria ruído, e ruído é como uma guarda vira algo que
 * se aprende a ignorar. Os outros 187 estão medidos e registrados na #1165 —
 * entre eles há casos que carregam significado (dia selecionado do calendário,
 * linha cancelada) e que precisam de julgamento caso a caso, não de regra.
 *
 * Três defeitos da própria medição, todos corrigidos e nenhum adivinhável:
 *
 * 1. **O anel de foco não é contorno permanente.** A primeira varredura contou
 *    `outline` de `:focus-visible` como se o componente tivesse limite, e por
 *    isso excluiu justamente `.ffc-submit-btn` — o pior caso. Contorno só
 *    conta no estado neutro.
 * 2. **Ler só `border-top-width` mente.** O probe reportou
 *    `.ffc-form-info-block` como "some", quando ele tem `border-left: 4px` e
 *    nunca esteve quebrado. Um componente pode ter limite em um lado só.
 * 3. **`border-radius` não é borda**, e um seletor como `.ffc-pdf-stage` casa
 *    "tag" por substring. A categoria precisa de fronteira de segmento.
 *
 * O que ela NÃO vê: se o contorno é *bonito*, se a cor do sistema escolhida é
 * a certa para o papel, e o que acontece numa máquina Windows de verdade —
 * isto mede declaração, e a evidência de render veio do Chromium em emulação.
 */
class ForcedColorsContourTest extends TestCase {

	/**
	 * Seletores que ficam sem regra de contorno, com o motivo.
	 *
	 * @var array<string, string>
	 */
	private const ALLOWED = array(
		// O papel do PDF, não um selo — casou a categoria por substring
		// ("s-tag-e"). Impressão e PDF são claros por definição (CLAUDE.md).
		'.ffc-pdf-stage' => 'papel do PDF; impressão é clara por definição, e a categoria casou por substring',
	);

	/**
	 * Um token de categoria precisa ser um segmento inteiro, não um pedaço.
	 */
	private const CONTROL = '/(?:^|[-.\s])(?:btn|button)(?:[-.\s]|$)/';
	private const BADGE   = '/(?:^|[-.\s])(?:status|badge|pill|tag|chip)(?:[-.\s]|$)/';

	/**
	 * Estados: o que eles declaram não descreve o componente em repouso.
	 */
	private const STATE = '/:(hover|focus|focus-visible|focus-within|active|visited|disabled|checked|target)\b/';

	/**
	 * Reduz um seletor ao componente: sem pseudo-elemento, sem estado.
	 *
	 * @param string $selector Seletor único.
	 * @return string
	 */
	private function component( string $selector ): string {
		$s = (string) preg_replace( '/::[\w-]+(\([^)]*\))?/', '', $selector );
		$s = (string) preg_replace( self::STATE . 'u', '', $s );

		return trim( (string) preg_replace( '/\s+/', ' ', $s ) );
	}

	/**
	 * Varre as folhas: quem tem fundo, quem tem contorno, quem tem regra forçada.
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

			// Os seletores que vivem dentro de um bloco `forced-colors`.
			foreach ( $this->forced_blocks( $css ) as $block ) {
				foreach ( CssSelectors::of( '@media x {' . $block . '}' ) as $one ) {
					$forced[ $this->component( $one ) ] = true;
				}
			}

			// …e o resto é lido SEM esses blocos. Sem isto a guarda se
			// autossabota: a própria borda que ela exige passa a contar como
			// contorno, o componente deixa de parecer necessitado, e apagar a
			// regra depois não falharia mais.
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
	 * A folha sem os blocos `forced-colors`.
	 *
	 * @param string $css Conteúdo da folha.
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
	 * Corpos dos blocos `@media (forced-colors: active)` de uma folha.
	 *
	 * @param string $css Conteúdo da folha.
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
	 * Todo controle e selo sem contorno tem regra de alto contraste.
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
			"Controle ou selo que declara fundo, não declara contorno e não tem regra "
				. "`@media (forced-colors: active)`. No alto contraste ele perde o limite e "
				. "deixa de ser um componente. Acrescente a regra na mesma folha "
				. "(`ButtonText` para o que se clica, `CanvasText` para o que se lê), ou "
				. "registre em ALLOWED com o motivo:\n" . implode( "\n", $missing )
		);
	}

	/**
	 * Toda entrada de ALLOWED ainda existe e carrega motivo.
	 *
	 * @return void
	 */
	public function test_every_allowed_selector_still_exists_and_carries_a_reason(): void {
		$scan     = $this->scan();
		$problems = array();

		foreach ( self::ALLOWED as $component => $reason ) {
			if ( strlen( trim( $reason ) ) < 15 ) {
				$problems[] = "{$component}: sem motivo escrito.";
			}
			if ( ! isset( $scan['background'][ $component ] ) ) {
				$problems[] = "{$component}: não declara mais fundo — remova de ALLOWED.";
			}
		}

		$this->assertSame( array(), $problems, implode( "\n", $problems ) );
	}

	/**
	 * O anel de foco não conta como contorno permanente.
	 *
	 * É o defeito que escondeu `.ffc-submit-btn` da primeira medição: ele tem
	 * `outline` no `:focus-visible`, e a varredura leu isso como "tem limite".
	 *
	 * @return void
	 */
	public function test_a_focus_ring_is_not_a_permanent_contour(): void {
		$scan = $this->scan();

		$this->assertArrayHasKey(
			'.ffc-shortcode .ffc-submit-btn',
			$scan['background'],
			'O botão de envio precisa continuar sendo visto como componente com fundo.'
		);
		$this->assertArrayNotHasKey(
			'.ffc-shortcode .ffc-submit-btn',
			$scan['contour'],
			'O botão de envio não tem borda em repouso — só anel de foco, que não conta.'
		);
		$this->assertArrayHasKey(
			'.ffc-shortcode .ffc-submit-btn',
			$scan['forced'],
			'…e por isso ele precisa da regra de alto contraste.'
		);
	}

	/**
	 * Uma borda de um lado só é contorno.
	 *
	 * `.ffc-form-info-block` foi reportado como quebrado por um probe que lia
	 * só `border-top-width`. Ele tem `border-left` e nunca esteve quebrado.
	 *
	 * @return void
	 */
	public function test_a_single_side_border_counts_as_a_contour(): void {
		$scan = $this->scan();

		$this->assertArrayHasKey(
			'.ffc-shortcode .ffc-form-info-block',
			$scan['contour'],
			'`border-left` é contorno: o componente não perde a forma no alto contraste.'
		);
	}

	/**
	 * A varredura não colapsou.
	 *
	 * @return void
	 */
	public function test_the_scan_still_reads_the_stylesheets(): void {
		$scan = $this->scan();

		$this->assertGreaterThanOrEqual( 2000, $scan['rules'], 'A varredura perdeu regras — vê dentro de @media?' );
		$this->assertGreaterThanOrEqual( 400, count( $scan['background'] ), 'A varredura perdeu seletores com fundo.' );
		$this->assertGreaterThanOrEqual( 80, count( $scan['forced'] ), 'A varredura perdeu os blocos forced-colors.' );
	}

	/**
	 * A categoria casa segmento, não pedaço de palavra.
	 *
	 * @return void
	 */
	public function test_the_category_matches_a_whole_segment(): void {
		$this->assertSame( 1, preg_match( self::BADGE, '.ffc-status-pending' ) );
		$this->assertSame( 1, preg_match( self::BADGE, '.ffc-cap-chip--muted' ) );
		$this->assertSame( 1, preg_match( self::CONTROL, '.ffc-shortcode .ffc-submit-btn' ) );

		// "s-tag-e" não é uma tag, e "debutante" não é um botão.
		$this->assertSame( 0, preg_match( self::BADGE, '.ffc-pdf-stage' ) );
		$this->assertSame( 0, preg_match( self::CONTROL, '.ffc-debutante' ) );
	}
}
