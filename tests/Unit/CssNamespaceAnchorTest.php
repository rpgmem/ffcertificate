<?php
/**
 * Todo seletor que o plugin publica precisa nomear algo que o plugin possui.
 *
 * @package FreeFormCertificate\Tests
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use FreeFormCertificate\Tests\Support\CssSelectors;
use PHPUnit\Framework\TestCase;

/**
 * Catraca de namespace do CSS (#1152, sub-issue da #1148).
 *
 * Uma regra como `.button::before { content: '\f123' }` alcança QUALQUER botão
 * da tela, não só os nossos. Hoje o raio é pequeno porque a folha só carrega
 * em duas páginas — mas isso é **sorte de enfileiramento, não desenho**, e
 * enfileiramento muda: `AudienceAdminPage::print_menu_separator_css()` existe
 * exatamente porque uma regra precisou sair de `ffc-audience-admin.css` quando
 * o alvo dela passou a aparecer em telas onde a folha não carrega.
 *
 * A guarda congela os 60 seletores sem âncora que existem hoje, por folha e
 * por texto, e **só encolhe**: um seletor novo sem âncora falha, e um seletor
 * da linha de base que ganhou âncora também falha (tranque o ganho removendo-o
 * daqui). A lista é um registro de dívida, não um alvo a crescer.
 *
 * **Nada está quebrado hoje** — nenhuma colisão observada. Isto a distingue de
 * todas as outras guardas do arco do tema, onde cada uma nasceu de um defeito
 * já entregue. É prevenção, e por isso ela congela em vez de exigir a correção
 * agora: as correções são unidades próprias (renomear `.status-active`, dar
 * prefixo aos seis ids `#tab-*`, ancorar as `.column-*` numa classe de página)
 * e cada uma mexe em emissor, JS e teste de módulos diferentes.
 *
 * Duas armadilhas de medição, ambas caídas antes de acertar:
 *
 * 1. **Contar classe a classe reporta falso positivo.** Em
 *    `.appointment-status.status-pending`, a classe `.status-pending` nunca
 *    aparece sozinha — o composto expõe UM nome, não dois. Varra seletor, não
 *    classe. É o que `test_a_compound_selector_counts_once()` fixa.
 *
 * 2. **"Tem `ffc` em algum lugar" não é o mesmo que "está ancorado", e a
 *    fronteira do token importa.** `a[href^="#ffc-separator-"]` está ancorado
 *    (não pode casar nada que não seja nosso), mas o caractere antes de `ffc`
 *    ali é `#`, não `-`: um padrão `(?:^|[-_])ffc[-_]` deixa sete seletores de
 *    fora e a contagem sobe de 60 para 67. A regra precisa ser decidida ANTES
 *    de medir, ou o número descreve o padrão em vez do CSS.
 *
 * O que ela NÃO vê: CSS inline impresso por PHP (`print_menu_separator_css()`,
 * o recibo de agendamento) e o `style=""` de atributo. Varre só as folhas de
 * `assets/css/`. Também não vê duplicação — a mesma varredura achou 19 classes
 * `ffc-*` declaradas cruas em mais de uma folha (`.ffc-status-badge` em cinco),
 * que é problema de componente sem dono único, não de namespace, e está na
 * #1162.
 */
class CssNamespaceAnchorTest extends TestCase {

	/**
	 * Seletores sem âncora que ficam, com o motivo.
	 *
	 * Diferente da linha de base abaixo: aqui não há dívida a pagar.
	 *
	 * @var array<string, array<string, string>>
	 */
	private const ALLOWED = array(
		'ffc-common.css' => array(
			// A paleta. `:root` é COMO se declara custom property -- não há
			// variante ancorada, e toda propriedade declarada ali é `--ffc-*`.
			// O bloco escuro, `:root.ffc-dark-mode`, já é ancorado.
			':root' => 'declaração da paleta; as propriedades são todas --ffc-*',
		),
	);

	/**
	 * Linha de base: seletor sem âncora => quantas vezes aparece na folha.
	 *
	 * Registro de dívida. Só encolhe.
	 *
	 * @var array<string, array<string, int>>
	 */
	private const BASELINE = array(
		// Os seis ids que `DashboardShortcode` publica sem prefixo. Um id é
		// único no documento: um tema com `#tab-profile` não repinta -- quebra
		// `getElementById`, o `aria-controls` das abas e a delegação de evento.
		//
		// Únicos sobreviventes depois da #1184: aqui a correção NÃO é âncora,
		// é renomear o id -- e isso é PHP, JS e `aria-controls` juntos, uma
		// unidade própria. Ancorar num contêiner deixaria o id genérico no
		// documento, que é justamente o risco.
		'ffc-user-dashboard.css' => array(
			'#tab-appointments h3'    => 1,
			'#tab-audience h3'        => 1,
			'#tab-reregistrations h3' => 1,
		),
	);

	/**
	 * Um seletor está ancorado quando nomeia algo que o plugin possui.
	 *
	 * Classe, id ou VALOR DE ATRIBUTO contendo o token `ffc` seguido de `-` ou
	 * `_`, precedido de qualquer coisa que não seja letra ou dígito. Cobre as
	 * cinco formas que a base usa: `.ffc-x`, `#ffc_x`, `.post-type-ffc_form`,
	 * `.cm-s-ffc-dark` (o tema do CodeMirror, nosso) e
	 * `a[href^="#ffc-separator-"]`.
	 *
	 * @param string $selector Um seletor único, já separado da lista.
	 * @return bool
	 */
	private function is_anchored( string $selector ): bool {
		return 1 === preg_match( '/(?:^|[^A-Za-z0-9])ffc[-_]/', $selector );
	}

	/**
	 * Extrai os seletores de uma folha, um por entrada da lista.
	 *
	 * Delega ao parser compartilhado: a guarda de posse de componente (#1162)
	 * mede sobre as MESMAS folhas, e duas varreduras que discordassem sobre o
	 * que é um seletor mediriam conjuntos diferentes -- o motivo pelo qual
	 * `.github/scripts/ffc-create-statements.php` também é compartilhado.
	 *
	 * @param string $css Conteúdo da folha.
	 * @return array<int, string>
	 */
	private function selectors( string $css ): array {
		return CssSelectors::of( $css );
	}

	/**
	 * Varre `assets/css/*.css` e agrupa os seletores sem âncora.
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
	 * Nada de novo sem âncora.
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
					$new[] = "{$sheet}: `{$selector}` aparece {$count}x, linha de base {$known}.";
				}
			}
		}

		$this->assertSame(
			array(),
			$new,
			"Seletor sem âncora `ffc`. Ancore numa classe nossa, numa classe de página ou "
				. "no `body.post-type-*`; se a regra precisa mesmo alcançar um nome de terceiro, "
				. "acrescente em ALLOWED com o motivo:\n" . implode( "\n", $new )
		);
	}

	/**
	 * O que foi ancorado sai da linha de base.
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
					$stale[] = "{$sheet}: `{$selector}` aparece {$now}x, linha de base ainda {$count}.";
				}
			}
		}

		$this->assertSame(
			array(),
			$stale,
			"Um seletor ganhou âncora e a linha de base não acompanhou — baixe-a para trancar o ganho:\n"
				. implode( "\n", $stale )
		);
	}

	/**
	 * Toda entrada de ALLOWED ainda existe e carrega motivo.
	 *
	 * @return void
	 */
	public function test_every_allowed_selector_still_exists_and_carries_a_reason(): void {
		$scan     = $this->scan()['anchorless'];
		$problems = array();

		foreach ( self::ALLOWED as $sheet => $selectors ) {
			foreach ( $selectors as $selector => $reason ) {
				if ( strlen( trim( $reason ) ) < 15 ) {
					$problems[] = "{$sheet}: `{$selector}` sem motivo escrito.";
				}
				if ( ! isset( $scan[ $sheet ][ $selector ] ) ) {
					$problems[] = "{$sheet}: `{$selector}` não existe mais — remova de ALLOWED.";
				}
			}
		}

		$this->assertSame( array(), $problems, implode( "\n", $problems ) );
	}

	/**
	 * A regra de âncora reconhece as cinco formas que a base usa.
	 *
	 * Isto é o que impede a contagem de descrever o padrão em vez do CSS. Os
	 * negativos importam tanto quanto os positivos: `.buffalo` contém as
	 * letras `ff` e não é nosso.
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
			$this->assertTrue( $this->is_anchored( $selector ), "`{$selector}` deveria contar como ancorado." );
		}

		foreach ( $anchorless as $selector ) {
			$this->assertFalse( $this->is_anchored( $selector ), "`{$selector}` não deveria contar como ancorado." );
		}
	}

	/**
	 * Um composto expõe um nome, não um por classe.
	 *
	 * A armadilha que inflou a medição original: `.appointment-status.status-
	 * pending` foi contado como dois nomes sem dono, e a folha reportou onze
	 * onde havia um. Uma lista separada por vírgula, ao contrário, é uma
	 * entrada por seletor.
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
	 * A varredura não colapsou.
	 *
	 * Um resultado vazio nunca pode ler como "limpo" — é a lição do #1071 /
	 * #1094. Mede as três formas de colapso: parar de achar folha, parar de
	 * achar seletor, e passar a classificar tudo como sem âncora (ou tudo como
	 * ancorado, que é o silencioso).
	 *
	 * @return void
	 */
	public function test_the_scan_still_reads_every_stylesheet(): void {
		$scan = $this->scan();

		$this->assertGreaterThanOrEqual( 25, $scan['sheets'], 'A varredura perdeu folhas.' );
		$this->assertGreaterThanOrEqual( 2000, $scan['total'], 'A varredura perdeu seletores.' );

		$anchorless = 0;
		foreach ( $scan['anchorless'] as $selectors ) {
			$anchorless += array_sum( $selectors );
		}

		$this->assertGreaterThan( 0, $anchorless, 'Zero sem âncora: a regra de âncora está casando tudo.' );
		$this->assertLessThan(
			(int) ( $scan['total'] * 0.1 ),
			$anchorless,
			'Mais de 10% sem âncora: a regra de âncora parou de casar.'
		);
	}

	/**
	 * O `@keyframes` não entra na conta.
	 *
	 * `0%` e `from` são passos, não seletores — e nenhum tem âncora, então uma
	 * varredura que os lesse reportaria dívida em toda folha animada.
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
	 * Um `;` dentro de string não corta o seletor.
	 *
	 * `img[src^="data:image/png;base64"]` existe em `ffc-pdf-core.css`, e lido
	 * fora de contexto o `;` deixava metade do seletor virar uma entrada
	 * fantasma chamada `base64"]`.
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
