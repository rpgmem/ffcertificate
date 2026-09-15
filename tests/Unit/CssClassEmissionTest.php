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
	 * Classes que o HTML do PRÓPRIO ADMINISTRADOR emite, não o nosso código.
	 *
	 * A `ffc-pdf-core.css` tem uma seção intitulada **"UTILITY CLASSES FOR
	 * CERTIFICATE TEMPLATES"** e dois comentários que dizem, literalmente, *"Add
	 * class `ffc-responsive-logo` to img tag to enable"*. São uma API para quem
	 * monta o corpo do certificado — que vive no banco, não no repositório —, e
	 * por isso nenhuma varredura de código pode achar emissor para elas.
	 *
	 * Não são pergunta em aberto e não são dívida: estão na terceira categoria
	 * que o docblock de `WITHOUT_EMITTER` sempre previu ("aplicada por algo fora
	 * do nosso código") e que, até esta medição, nunca tinha tido ocupante.
	 *
	 * **A #1170 as classificou como mortas** — "não aparecem em NENHUM lugar do
	 * repositório" — e isso estava certo sobre o repositório e errado sobre o
	 * mundo. Apagá-las quebraria silenciosamente todo certificado que já use
	 * `class="ffc-txt-center"`, que é o uso para o qual foram publicadas.
	 *
	 * @var array<string, string>
	 */
	private const TEMPLATE_API = array(
		'ffc-txt-center'      => 'seção 9 da folha: alinhamento para o corpo do certificado',
		'ffc-txt-left'        => 'seção 9 da folha: alinhamento para o corpo do certificado',
		'ffc-txt-right'       => 'seção 9 da folha: alinhamento para o corpo do certificado',
		'ffc-txt-justify'     => 'seção 9 da folha: alinhamento para o corpo do certificado',
		'ffc-full-width'      => 'seção 9 da folha: largura total para o corpo do certificado',
		'ffc-full-width-img'  => 'comentário na folha: "Add class ffc-full-width-img to img tag to enable"',
		'ffc-responsive-logo' => 'comentário na folha: "Add class ffc-responsive-logo to img tag to enable"',
	);

	/**
	 * Shim de compatibilidade que a folha declara como tal.
	 *
	 * A `ffc-pdf-core.css` tem uma seção **13. LEGACY CLASSES (Backward
	 * compatibility)**. Nenhuma delas foi emitida pelo nosso código em NENHUM
	 * ponto da história do repositório (`git log -S` sobre `assets/js`,
	 * `includes` e `templates` devolve zero), então aquilo com que elas mantêm
	 * compatibilidade está fora daqui — corpo de certificado salvo no banco, do
	 * mesmo jeito que a API acima.
	 *
	 * Cada uma tem a irmã viva que o JS emite hoje, e o par conta a renomeação:
	 * `stage`→`wrapper`, `bg-img`→`bg`, `user-content`→`content`,
	 * `temp-wrapper`→`temp-container`.
	 *
	 * **Ficam listadas aqui, e não em `WITHOUT_EMITTER`, porque não são pergunta
	 * em aberto: são shim, e o `CLAUDE.md` §5 exige evidência de instalação —
	 * nunca varredura de código — para retirar um.** O inventário de §5 passa a
	 * registrá-las com a condição de saída.
	 *
	 * @var array<string, string>
	 */
	private const LEGACY_SHIM = array(
		'ffc-pdf-stage'        => 'seção 13 da folha; irmã viva `ffc-pdf-wrapper`',
		'ffc-pdf-bg-img'       => 'seção 13 da folha; irmã viva `ffc-pdf-bg`',
		'ffc-pdf-user-content' => 'seção 13 da folha; irmã viva `ffc-pdf-content`',
		'ffc-pdf-temp-wrapper' => 'pareada com a viva `ffc-pdf-temp-container` na regra do wp-admin',
	);

	/**
	 * Classes declaradas nas folhas para as quais a varredura não acha emissor.
	 *
	 * Uma catraca que só encolhe. Não é uma lista de código morto — é uma lista
	 * de **perguntas em aberto**, e cada entrada tem uma dessas três respostas:
	 *
	 *  - morta de verdade;
	 *  - emitida por uma forma que a varredura ainda não conhece — e aí a
	 *    correção é ensinar a varredura, não baixar a guarda;
	 *  - aplicada por algo fora do nosso código.
	 *
	 * **Das 24 entradas originais, nove eram a segunda resposta** — tinham
	 * emissor no repositório e a varredura é que não lia a forma. Ensiná-la
	 * respondeu as nove de uma vez, e as três formas novas estão no
	 * `provider_shapes()`. Outras onze eram a terceira: viraram `TEMPLATE_API` e
	 * `LEGACY_SHIM` acima, cada uma com a evidência que a tirou daqui.
	 *
	 * Restam quatro, e todas as quatro são candidatas à PRIMEIRA resposta —
	 * nunca emitidas na história, sem seção da folha que as reivindique. Ficam
	 * como pergunta porque apagar CSS que o autor de template pode estar usando
	 * é decisão de produto, não de varredura.
	 *
	 * @var array<int, string>
	 */
	private const WITHOUT_EMITTER = array(
		'ffc-cap-chip--color',
		'ffc-flex',
		'ffc-pdf-progress-overlay',
		'ffc-progress-spinner',
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
			'atributo aberto e não fechado' => array( 'ffc-appointments-table', 'literal' ),
			'várias classes numa string'  => array( 'ffc-has-geofence', 'literal' ),
			'opção de biblioteca'         => array( 'ffc-sortable-placeholder', 'literal' ),
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
			if (
				! in_array( $class, self::WITHOUT_EMITTER, true )
				&& ! isset( self::TEMPLATE_API[ $class ] )
				&& ! isset( self::LEGACY_SHIM[ $class ] )
			) {
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

		foreach ( self::listed_classes() as $class ) {
			$found = CssClassEmitters::of( $class );
			if ( 'nenhum' !== $found['how'] ) {
				$resolved[] = sprintf( '%s (agora por %s)', $class, $found['how'] );
			}
		}

		$this->assertSame(
			array(),
			$resolved,
			"Estas ganharam emissor — tire-as da lista em que estão:\n  " . implode( "\n  ", $resolved )
		);
	}

	/**
	 * Toda entrada da lista ainda é uma classe declarada.
	 */
	public function test_every_listed_class_is_still_declared(): void {
		$declared = CssClassEmitters::declared_ffc_classes();

		foreach ( self::listed_classes() as $class ) {
			$this->assertContains(
				$class,
				$declared,
				"`{$class}` não é mais declarada em folha nenhuma. Tire-a da lista."
			);
		}
	}

	/**
	 * As três listas juntas — nenhuma classe pode estar em duas.
	 *
	 * @return array<int, string>
	 */
	private static function listed_classes(): array {
		return array_merge(
			self::WITHOUT_EMITTER,
			array_keys( self::TEMPLATE_API ),
			array_keys( self::LEGACY_SHIM )
		);
	}

	/**
	 * Uma classe pertence a exatamente uma das três listas.
	 *
	 * As três dizem coisas diferentes — pergunta em aberto, API publicada,
	 * shim com condição de saída —, então uma classe em duas delas é uma
	 * afirmação contraditória sobre o que fazer com ela.
	 */
	public function test_no_class_is_listed_twice(): void {
		$all  = self::listed_classes();
		$dupe = array_keys( array_filter( array_count_values( $all ), static fn ( int $n ): bool => $n > 1 ) );

		$this->assertSame( array(), $dupe, 'Classe em mais de uma lista: ' . implode( ', ', $dupe ) );
	}

	/**
	 * Toda razão diz alguma coisa.
	 *
	 * O piso de 20 caracteres é contra "legado" e "não usada" — não é medida
	 * de qualidade, é o mesmo piso que as outras guardas de supressão usam.
	 */
	public function test_every_reason_says_something(): void {
		foreach ( array( 'TEMPLATE_API' => self::TEMPLATE_API, 'LEGACY_SHIM' => self::LEGACY_SHIM ) as $list => $entries ) {
			foreach ( $entries as $class => $reason ) {
				$this->assertGreaterThan(
					20,
					strlen( $reason ),
					"A razão de `{$class}` em {$list} não diz o bastante: quem lê precisa saber POR QUE não há emissor."
				);
			}
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
