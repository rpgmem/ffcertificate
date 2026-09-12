<?php
/**
 * Escopo de `ffc-admin-utilities.css` (#1171).
 *
 * O docblock da folha diz o que ela aceita; este teste é o que torna isso uma
 * regra em vez de um pedido. Um utilitário nomeia uma **propriedade** ou uma
 * forma visual genérica — `.ffc-mt-20`, `.ffc-w100`, `.ffc-monospace` — e se
 * resolve em **uma regra**, com um seletor de classe só: sem descendente, sem
 * pseudo-classe, sem sub-seletor de elemento.
 *
 * Nove componentes de tela moravam aqui e saíram na #1171. O que os trouxe é o
 * modo de falha que o `CLAUDE.md` já nomeia para `services/` e `integrations/`:
 * **um nome genérico convida drift**, e a correção depois é cara. A defesa não
 * é um rename futuro, é o escopo escrito — e escrito de um jeito que falha.
 *
 * **Por que mover custa verificação, e não é arrumação.** Esta folha é
 * DEPENDÊNCIA de `ffc-admin-css` e é enfileirada num lugar só
 * (`AdminAssetsManager::enqueue_admin_base_styles()`, que enfileira as duas em
 * seguida), então tudo aqui alcança toda tela FFC do admin. Levar um componente
 * para a folha do módulo dono **estreita** o alcance: se aquela folha não
 * carregar na tela que renderiza o componente, o estilo some sem que nada
 * acuse. Foi por isso que a #1171 mediu tela a tela antes de mover.
 *
 * Sem dependência: lê o texto da folha.
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
final class UtilityScopeTest extends TestCase {

	/**
	 * Caminho absoluto da folha de utilitários.
	 */
	private static function sheet(): string {
		return dirname( __DIR__, 2 ) . '/assets/css/ffc-admin-utilities.css';
	}

	/**
	 * Os seletores declarados na folha, um por entrada da lista.
	 *
	 * @return array<int, string>
	 */
	private static function selectors(): array {
		$out = array();

		foreach ( CssSelectors::rules( (string) file_get_contents( self::sheet() ) ) as $rule ) {
			foreach ( CssSelectors::split_list( $rule['selector'] ) as $selector ) {
				$out[] = trim( (string) preg_replace( '/\s+/', ' ', $selector ) );
			}
		}

		return $out;
	}

	/**
	 * Todo seletor é uma classe crua — nada de componente.
	 *
	 * Um descendente (`.ffc-x .ffc-y`), uma pseudo-classe (`:hover`) ou um
	 * sub-seletor de elemento (`.ffc-x strong`) é estrutura, e estrutura é
	 * componente: pertence à folha do módulo que o renderiza.
	 */
	public function test_every_selector_is_a_single_bare_class(): void {
		$offenders = array();

		foreach ( self::selectors() as $selector ) {
			if ( ! preg_match( '/^\.[A-Za-z_][A-Za-z0-9_-]*$/', $selector ) ) {
				$offenders[] = $selector;
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			"Seletor que não é utilitário em `ffc-admin-utilities.css`:\n  " . implode( "\n  ", $offenders )
			. "\n\nDescendente, pseudo-classe ou sub-seletor de elemento é ESTRUTURA, e"
			. "\nestrutura é componente: vai para a folha do módulo que o renderiza."
			. "\nAntes de mover, confira que aquela folha é enfileirada na tela que"
			. "\nrenderiza o componente — esta folha alcança TODA tela FFC do admin, e"
			. "\nmover estreita o alcance sem que nada acuse a perda."
		);
	}

	/**
	 * Cada classe se resolve em uma regra só.
	 *
	 * Duas regras para o mesmo nome querem dizer que ele tem estados ou
	 * variantes — de novo, componente.
	 */
	public function test_every_class_is_declared_exactly_once(): void {
		$counts = array_count_values( self::selectors() );
		$repeat = array();

		foreach ( $counts as $selector => $times ) {
			if ( 1 < $times ) {
				$repeat[] = sprintf( '%s (%dx)', $selector, $times );
			}
		}

		$this->assertSame(
			array(),
			$repeat,
			"Classe declarada mais de uma vez na folha de utilitários:\n  " . implode( "\n  ", $repeat )
			. "\n\nMais de uma regra para um nome é estado ou variante, ou seja, componente."
		);
	}

	/**
	 * Todo nome carrega o prefixo da casa.
	 *
	 * Redundante com o `ClassNamingIdiomTest` para esta folha, e de propósito:
	 * é aqui que a pergunta "posso pôr uma classe genérica?" nasce.
	 */
	public function test_every_utility_carries_the_prefix(): void {
		foreach ( self::selectors() as $selector ) {
			$this->assertStringStartsWith(
				'.ffc-',
				$selector,
				"Utilitário sem prefixo: {$selector}"
			);
		}
	}

	/**
	 * A varredura não pode colapsar em silêncio.
	 *
	 * Um seletor vazio satisfaz os três testes acima tão bem quanto uma folha
	 * correta — a forma do #1071 / #1094.
	 */
	public function test_the_scan_cannot_collapse_in_silence(): void {
		$selectors = self::selectors();

		$this->assertFileExists( self::sheet() );
		$this->assertGreaterThan( 15, count( $selectors ), 'A leitura da folha colapsou.' );

		// E precisa saber dizer NÃO: um seletor de componente é recusado.
		$this->assertSame(
			0,
			preg_match( '/^\.[A-Za-z_][A-Za-z0-9_-]*$/', '.ffc-preflight-badge a:hover' ),
			'O padrão aceita um seletor de componente — a rede está pegando o oceano.'
		);
	}
}
