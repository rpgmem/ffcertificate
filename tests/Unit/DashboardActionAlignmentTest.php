<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use FreeFormCertificate\Tests\Support\CssSelectors;
use PHPUnit\Framework\TestCase;

/**
 * Os controles da coluna AÇÕES do painel declaram o seu alinhamento (#1215).
 *
 * A metade barata, no molde do #1184: prova que a DECLARAÇÃO está lá, nunca
 * que o render sai alinhado. O que provou o render foi o harness em Chromium
 * do #1215 -- 4 abas x 2 temas x 2 larguras, par (elemento x propriedade) --,
 * e ele não cabe no CI, que não tem navegador.
 *
 * O defeito que a originou já tinha embarcado, e não era o que parecia. Os
 * três controles da aba de agendamentos tinham a MESMA altura (26,8px) e topos
 * em 100,00 / 103,39 / 103,55: cada caixa entrava na linha pela sua PRÓPRIA
 * linha de base, e as três derivam a linha de base de formas diferentes -- um
 * `inline-flex` a herda do primeiro item flex (aqui o `::before` do ícone, uma
 * caixa de 14px sem texto), um `inline-block` a tira da última linha de texto.
 * Só a aba de agendamentos mistura as três construções, que é por isso que era
 * a única fora do lugar.
 *
 * Duas coisas que a medição decidiu e que não vale redescobrir:
 *
 * 1. **`display: flex` na célula NÃO serve.** É a resposta convencional e ela
 *    quebra a tabela: um `<td>` com `display: flex` sai do contexto de
 *    formatação da tabela e encolhe até o conteúdo. Medido, a última célula
 *    caía de 162,59px para 45,13px (certificados) e de 242,83px para 72,56px
 *    (públicos), enquanto o `<th>` seguia na largura cheia. Passava nas outras
 *    duas abas só porque o conteúdo delas já enchia a coluna.
 * 2. **O alinhamento pertence ao INVÓLUCRO do exportador, não ao botão de
 *    dentro** -- e o `vertical-align: middle` que estava no botão não era
 *    inerte, era a causa: deslocava o botão dentro do invólucro, o que movia a
 *    linha de base do próprio invólucro. Removê-lo sozinho já subia o
 *    invólucro de 103,55 para 100,00.
 *
 * @covers \FreeFormCertificate\Tests\Support\CssSelectors
 */
class DashboardActionAlignmentTest extends TestCase {

	private const SHEET = 'assets/css/ffc-user-dashboard.css';

	/**
	 * Os controles que dividem uma linha na célula de ações, e por quê.
	 *
	 * Um controle novo na coluna AÇÕES entra aqui. Não é lista decorativa: é
	 * o conjunto cujo alinhamento precisa ser DECLARADO, porque o padrão
	 * (`baseline`) depende da construção de cada caixa e as construções aqui
	 * são diferentes de propósito.
	 *
	 * @var array<string, string>
	 */
	private const CONTROLS = array(
		'.ffc-btn-pdf'      => 'Baixar PDF / Baixar Ficha -- `inline-flex` com ícone `::before` (certificados e recadastramentos).',
		'.ffc-btn-receipt'  => 'Ver Comprovante -- `inline-flex` com ícone `::before` (agendamentos).',
		'.ffc-btn-edit'     => 'Editar -- `inline-flex` com ícone `::before` (recadastramentos).',
		'.ffc-appointments-table .ffc-cancel-appointment' => 'Cancelar -- `inline-block` sem ícone, cuja linha de base vem do texto.',
		'.ffc-cal-export-wrap' => 'Exportar Calendário -- `inline-block` que envolve o botão; é ELE que participa da linha da célula.',
	);

	/**
	 * O botão interno do exportador não declara alinhamento.
	 *
	 * Ver o item 2 do docblock da classe: ali a declaração não era inerte, era
	 * a causa de 3,55px de desalinhamento.
	 */
	private const MUST_NOT_DECLARE = '.ffc-cal-export-btn';

	private function sheet(): string {
		$path = dirname( __DIR__, 2 ) . '/' . self::SHEET;
		$css  = file_get_contents( $path );
		$this->assertIsString( $css, self::SHEET . ' não pôde ser lida.' );

		return $css;
	}

	/**
	 * @return array<string, string> Seletor => corpo concatenado das regras que o declaram.
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

		// Autoverificação: uma varredura que não achou nada não pode passar
		// como "limpa" (a lição do #1071 / #1094).
		$this->assertGreaterThan(
			100,
			count( $bodies ),
			'A varredura da folha voltou quase vazia — o parser quebrou, e um verde aqui não significaria nada.'
		);

		foreach ( self::CONTROLS as $selector => $why ) {
			$this->assertArrayHasKey(
				$selector,
				$bodies,
				"O seletor `{$selector}` não existe mais em " . self::SHEET . ". Se o controle foi renomeado, atualize o registro; se saiu da célula de ações, remova-o. {$why}"
			);

			$this->assertMatchesRegularExpression(
				'/vertical-align\s*:\s*middle\s*(!important)?\s*;/',
				$bodies[ $selector ],
				"`{$selector}` divide uma linha na coluna AÇÕES e não declara `vertical-align: middle`. Sem isso ele entra na linha pela sua própria linha de base, que depende da construção da caixa. {$why}"
			);
		}
	}

	public function test_the_export_button_leaves_the_alignment_to_its_wrapper(): void {
		$bodies = $this->bodies_by_selector( $this->sheet() );

		$this->assertArrayHasKey(
			self::MUST_NOT_DECLARE,
			$bodies,
			'`' . self::MUST_NOT_DECLARE . '` sumiu da folha; se o exportador foi reescrito, revise este registro.'
		);

		$this->assertDoesNotMatchRegularExpression(
			'/vertical-align\s*:/',
			$bodies[ self::MUST_NOT_DECLARE ],
			'`' . self::MUST_NOT_DECLARE . '` voltou a declarar `vertical-align`. Quem participa da linha da célula é `.ffc-cal-export-wrap`; alinhar o botão de dentro desloca a linha de base do invólucro (#1215).'
		);
	}
}
