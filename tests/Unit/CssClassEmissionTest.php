<?php
/**
 * Guarda de emissão de classe CSS (#1170).
 *
 * Renomear uma classe é seguro exatamente na medida em que se consegue achar
 * quem a emite — e um grep não consegue, porque o nome costuma ser montado em
 * runtime. `tests/Support/CssClassEmitters.php` é a varredura; este arquivo é
 * o que a mantém honesta.
 *
 * **Os testes de forma são o conteúdo, não cerimônia.** Cada um deles nasceu de
 * uma classe que a varredura NÃO achava, e cada uma dessas seria uma renomeação
 * quebrando em silêncio. Eles estão aqui para que a próxima mudança na
 * varredura não desfaça nenhuma sem ninguém notar.
 *
 * @package FreeFormCertificate\Tests\Unit
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use FreeFormCertificate\Tests\Support\CssClassEmitters;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class CssClassEmissionTest extends TestCase {

	/**
	 * Classes declaradas nas folhas para as quais a varredura não acha emissor.
	 *
	 * Uma catraca que só encolhe. Não é uma lista de código morto — é uma lista
	 * de **perguntas em aberto**, e cada entrada tem uma dessas três respostas:
	 *
	 *  - morta de verdade (`ffc-txt-center` e as três irmãs não aparecem em
	 *    NENHUM lugar do repositório, nem como string nem como seletor);
	 *  - emitida por uma forma que a varredura ainda não conhece — e aí a
	 *    correção é ensinar a varredura, não baixar a guarda;
	 *  - aplicada por algo fora do nosso código (uma biblioteca, o WordPress).
	 *
	 * Responder cada uma é o trabalho que esta lista existe para tornar
	 * possível. Antes dela a pergunta não tinha como ser feita.
	 */
	private const WITHOUT_EMITTER = array(
		'ffc-appointments-table',
		'ffc-audience-bookings-table',
		'ffc-audience-join-item',
		'ffc-cap-chip--color',
		'ffc-flex',
		'ffc-full-width',
		'ffc-full-width-img',
		'ffc-has-event-list',
		'ffc-has-geofence',
		'ffc-hierarchy-child',
		'ffc-pdf-bg-img',
		'ffc-pdf-progress-overlay',
		'ffc-pdf-stage',
		'ffc-pdf-temp-wrapper',
		'ffc-pdf-user-content',
		'ffc-progress-spinner',
		'ffc-reregistrations-table',
		'ffc-responsive-logo',
		'ffc-sortable-placeholder',
		'ffc-transfer-child',
		'ffc-txt-center',
		'ffc-txt-justify',
		'ffc-txt-left',
		'ffc-txt-right',
	);

	/**
	 * As formas que a varredura precisa conhecer, com um caso real de cada.
	 *
	 * A coluna do meio é a classe; a última é a forma que a emite. Toda entrada
	 * aqui já falhou uma vez.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function provider_shapes(): array {
		return array(
			'atributo literal'            => array( 'ffc-status-badge', 'literal' ),
			'API de classe do jQuery'     => array( 'ffc-collapsed', 'literal' ),
			'seletor dentro de string'    => array( 'ffc-timeslot-full', 'literal' ),
			'eco embutido no atributo'    => array( 'ffc-audience-status-active', 'prefixo' ),
			'placeholder de printf'       => array( 'ffc-cap-origin--user', 'prefixo' ),
			'concatenação no fim da str'  => array( 'ffc-dashboard-status-confirmed', 'prefixo' ),
			'interpolação do PHP'         => array( 'ffc-verification-status-cancelled', 'prefixo' ),
		);
	}

	/**
	 * Cada forma conhecida continua sendo achada.
	 *
	 * @dataProvider provider_shapes
	 * @param string $class Classe real declarada nas folhas.
	 * @param string $how   `literal` ou `prefixo`.
	 */
	public function test_every_known_shape_is_still_found( string $class, string $how ): void {
		$found = CssClassEmitters::of( $class );

		$this->assertSame(
			$how,
			$found['how'],
			"A varredura deixou de achar `{$class}` por `{$how}`.\n"
			. "Essa forma já falhou uma vez e cada falha dela é uma renomeação que quebra\n"
			. 'em silêncio — ensine a varredura de volta, não relaxe o teste.'
		);
		$this->assertNotEmpty( $found['files'], "Achou `{$class}` mas não sabe dizer onde." );
	}

	/**
	 * Nenhuma classe nova entra sem emissor conhecido.
	 */
	public function test_no_new_class_lacks_an_emitter(): void {
		$new = array();

		foreach ( CssClassEmitters::declared_ffc_classes() as $class ) {
			if ( 'nenhum' !== CssClassEmitters::of( $class )['how'] ) {
				continue;
			}
			if ( ! in_array( $class, self::WITHOUT_EMITTER, true ) ) {
				$new[] = $class;
			}
		}

		$this->assertSame(
			array(),
			$new,
			"Classe declarada que ninguém emite:\n  ." . implode( "\n  .", $new )
			. "\n\nOu ela é morta, ou é emitida por uma forma que a varredura não conhece."
			. "\nSe for o segundo caso, ensine `CssClassEmitters` — acrescentar à lista"
			. "\nesconde exatamente o que ela existe para mostrar."
		);
	}

	/**
	 * Uma entrada da lista que ganhou emissor sai da lista.
	 *
	 * A direção que trava o ganho, como nas outras catracas.
	 */
	public function test_the_list_only_shrinks(): void {
		$resolved = array();

		foreach ( self::WITHOUT_EMITTER as $class ) {
			$found = CssClassEmitters::of( $class );
			if ( 'nenhum' !== $found['how'] ) {
				$resolved[] = sprintf( '%s (agora por %s)', $class, $found['how'] );
			}
		}

		$this->assertSame(
			array(),
			$resolved,
			"Estas ganharam emissor — tire-as de WITHOUT_EMITTER:\n  " . implode( "\n  ", $resolved )
		);
	}

	/**
	 * Toda entrada da lista ainda é uma classe declarada.
	 */
	public function test_every_listed_class_is_still_declared(): void {
		$declared = CssClassEmitters::declared_ffc_classes();

		foreach ( self::WITHOUT_EMITTER as $class ) {
			$this->assertContains(
				$class,
				$declared,
				"`{$class}` não é mais declarada em folha nenhuma. Tire-a da lista."
			);
		}
	}

	/**
	 * A varredura não pode colapsar em silêncio.
	 *
	 * Um mapa vazio satisfaz `assertSame( array(), $new )` tão bem quanto um
	 * mapa correto — a forma do #1071 / #1094. Os pisos são folgados de
	 * propósito: dizem "a varredura funcionou", não o tamanho do código.
	 */
	public function test_the_scan_cannot_collapse_in_silence(): void {
		$this->assertGreaterThan( 800, count( CssClassEmitters::declared_ffc_classes() ), 'A leitura das folhas colapsou.' );
		$this->assertGreaterThan( 500, count( CssClassEmitters::literals() ), 'O mapa de literais colapsou.' );
		$this->assertGreaterThan( 10, count( CssClassEmitters::prefixes() ), 'O mapa de prefixos colapsou.' );

		// E a varredura precisa saber dizer NÃO: um nome inventado não tem emissor.
		$this->assertSame(
			'nenhum',
			CssClassEmitters::of( 'ffc-classe-que-nao-existe-em-lugar-nenhum' )['how'],
			'A varredura acha emissor para qualquer coisa — a rede está pegando o oceano.'
		);
	}
}
