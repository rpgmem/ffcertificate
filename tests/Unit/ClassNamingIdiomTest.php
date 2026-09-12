<?php
/**
 * Guarda do idioma de nome de classe (#1170).
 *
 * A #1167 mediu três idiomas vivos dizendo a MESMA coisa — modificador com
 * prefixo (`.ffc-day.ffc-selected`), modificador sem prefixo
 * (`.ffc-consent-status.consent-given`) e estado SMACSS (`.ffc-cap-role.is-on`)
 * — e a #1170 os converge em dois, por função:
 *
 *  - **estado transitório**, o que a interação liga e desliga (aberto, ativo,
 *    colapsado, selecionado, copiado), fica SEM prefixo, como `is-` / `has-`.
 *    É a convenção SMACSS, é legível, e o nome só aparece composto — uma
 *    `.is-open` crua não tem âncora e falha no `CssNamespaceAnchorTest`.
 *  - **tudo o mais nosso** — variante que vem do dado, e elemento do
 *    componente — leva `ffc-`.
 *
 * Esta guarda recusa o terceiro idioma: uma classe nossa sem prefixo e sem
 * `is-`/`has-`. Os 38 nomes que existiam foram convertidos na #1170; o que
 * sobra sem prefixo é emitido pelo WordPress ou pelo CodeMirror, e está aqui
 * com a razão.
 *
 * **Não é sobre colisão** — todos os 38 apareciam compostos com uma classe
 * `ffc-`, que é por isso que a catraca de âncora do #1152 nunca os viu. É sobre
 * LEITURA: `.value`, `.top`, `.remove`, `.label`, `.open` não dizem de quem
 * são, e quem lê a folha reconstrói o contexto pelo seletor inteiro. Pela regra
 * de prioridade do `CLAUDE.md`, isso é inconsistência recorrente e voltada ao
 * leitor, não cosmética.
 *
 * E há um ganho que não é de leitura, medido na passada: uma variante montada
 * em runtime a partir de palavra solta (`$progress_color = 'complete'`) é
 * INVISÍVEL para `CssClassEmitters` por construção — a varredura só registra
 * prefixo `ffc-`. Com prefixo, a variável passa a guardar um literal e a
 * varredura a acha. Foi assim que três regras mortas apareceram.
 *
 * Sem dependência: lê o texto das folhas.
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
final class ClassNamingIdiomTest extends TestCase {

	/**
	 * Famílias inteiras que o WordPress (ou o CodeMirror) emite.
	 *
	 * Prefixo => razão. Estilizá-las é legítimo: são o markup que o core
	 * entrega e que o plugin decora. Prefixá-las é impossível — quem emite
	 * não é nosso.
	 */
	private const VENDOR_PREFIXES = array(
		'cm-'         => 'o CodeMirror emite; o tema `cm-s-ffc-dark` é nosso e já é ancorado',
		'CodeMirror'  => 'idem — a raiz do editor',
		'column-'     => 'coluna de list table do WordPress; o slug vem do core ou da nossa CPT',
		'wp-'         => 'classe do core (`wp-list-table`, `wp-admin`, `wp-submenu`, …)',
		'post-type-'  => 'classe de body do core; a nossa já carrega `ffc_` e é ancorada',
		'nav-tab'     => 'abas do core (`nav-tab`, `nav-tab-active`, `nav-tab-wrapper`)',
		'postbox'     => 'metabox do core (`postbox`, `postbox-header`)',
		'tablenav'    => 'barra de navegação de list table do core',
		'button'      => 'botão do core (`button`, `button-primary`, `button-secondary`)',
	);

	/**
	 * Nomes avulsos que o WordPress emite, com a razão de cada um.
	 *
	 * Catraca nos dois sentidos: um nome novo aqui falha, e um que sumiu das
	 * folhas também — a entrada morta sai para travar o ganho.
	 */
	private const VENDOR_CLASSES = array(
		'actions'          => 'célula de ações da list table do core (`.tablenav .actions`)',
		'alternate'        => 'zebra de linha da list table do core',
		'card'             => 'cartão do admin do core (about.php, cartões de plugin)',
		'current'          => 'item corrente de paginação / subsubsub do core',
		'dashicons'        => 'fonte de ícone do core',
		'description'      => 'texto de apoio de campo do core',
		'disabled'         => 'estado de botão do core (`.button.disabled`), escrito pelo próprio core',
		'displaying-num'   => 'contagem de itens da list table do core',
		'error'            => 'aviso do core (`div.error`) — não confundir com estado nosso, que é `has-error`',
		'form-table'       => 'tabela de formulário do admin do core',
		'hndle'            => 'título de metabox do core',
		'howto'            => 'texto de instrução do core',
		'inside'           => 'corpo de metabox do core',
		'large-text'       => 'modificador de largura de input do core',
		'misc-pub-section' => 'seção da caixa de publicação do core',
		'notice'           => 'aviso do admin do core',
		'publish'          => 'status de post do core, usado como classe de linha',
		'spinner'          => 'indicador de carregamento do core',
		'striped'          => 'modificador de list table do core',
		'subsubsub'        => 'filtros de topo de list table do core',
		'tablenav-pages'   => 'paginação da list table do core',
		'top'              => 'metade de cima da `tablenav` do core (o par é `bottom`)',
		'trash'            => 'status de post do core, usado como classe de linha',
		'updated'          => 'aviso do admin do core',
		'widefat'          => 'modificador de tabela do core',
		'wrap'             => 'invólucro de página do admin do core',
	);

	/**
	 * Toda classe declarada nas folhas, com as folhas onde aparece.
	 *
	 * @return array<string, array<int, string>>
	 */
	private static function declared(): array {
		$out = array();

		foreach ( CssSelectors::sheets() as $path ) {
			$sheet = basename( $path );

			foreach ( CssSelectors::rules( (string) file_get_contents( $path ) ) as $rule ) {
				foreach ( CssSelectors::split_list( $rule['selector'] ) as $selector ) {
					if ( ! preg_match_all( '/\.(-?[A-Za-z_][A-Za-z0-9_-]*)/', $selector, $m ) ) {
						continue;
					}
					foreach ( array_unique( $m[1] ) as $class ) {
						if ( ! isset( $out[ $class ] ) ) {
							$out[ $class ] = array();
						}
						if ( ! in_array( $sheet, $out[ $class ], true ) ) {
							$out[ $class ][] = $sheet;
						}
					}
				}
			}
		}

		ksort( $out );

		return $out;
	}

	/**
	 * A classe segue um dos dois idiomas, ou é de terceiro.
	 *
	 * @param string $class Nome da classe, sem o ponto.
	 */
	private static function is_allowed( string $class ): bool {
		if ( str_starts_with( $class, 'ffc-' ) || str_starts_with( $class, 'ffc_' ) ) {
			return true;
		}
		if ( str_starts_with( $class, 'is-' ) || str_starts_with( $class, 'has-' ) ) {
			return true;
		}
		if ( isset( self::VENDOR_CLASSES[ $class ] ) ) {
			return true;
		}
		foreach ( array_keys( self::VENDOR_PREFIXES ) as $prefix ) {
			if ( str_starts_with( $class, $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Nenhuma classe nossa fica sem prefixo e sem `is-`/`has-`.
	 */
	public function test_no_class_uses_the_third_idiom(): void {
		$offenders = array();

		foreach ( self::declared() as $class => $sheets ) {
			if ( ! self::is_allowed( $class ) ) {
				$offenders[] = sprintf( '.%s (%s)', $class, implode( ', ', $sheets ) );
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			"Classe sem prefixo e sem `is-`/`has-`:\n  " . implode( "\n  ", $offenders )
			. "\n\nDecida pela FUNÇÃO, não pela palavra:"
			. "\n  estado que a interação liga e desliga  → `is-` / `has-`"
			. "\n  variante que vem do dado, ou elemento  → `ffc-`"
			. "\nSe quem emite for o WordPress, acrescente a VENDOR_CLASSES com a razão."
		);
	}

	/**
	 * Uma entrada de terceiro que sumiu das folhas sai da lista.
	 *
	 * A direção que trava o ganho, como nas outras catracas.
	 */
	public function test_the_vendor_list_only_shrinks(): void {
		$declared = self::declared();
		$stale    = array();

		foreach ( array_keys( self::VENDOR_CLASSES ) as $class ) {
			if ( ! isset( $declared[ $class ] ) ) {
				$stale[] = $class;
			}
		}

		$this->assertSame(
			array(),
			$stale,
			"Nenhuma folha declara mais estas — tire-as de VENDOR_CLASSES:\n  ." . implode( "\n  .", $stale )
		);
	}

	/**
	 * O estado SMACSS nunca é declarado cru.
	 *
	 * `.is-open` sozinha alcança qualquer elemento da tela, inclusive os do
	 * tema. O `CssNamespaceAnchorTest` já recusaria o seletor por falta de
	 * âncora; isto é a mesma regra dita do lado do idioma, para que a resposta
	 * a "então posso usar `is-` em qualquer lugar?" esteja escrita onde a
	 * pergunta nasce.
	 */
	public function test_state_classes_are_never_declared_bare(): void {
		$bare = array();

		foreach ( CssSelectors::sheets() as $path ) {
			foreach ( CssSelectors::rules( (string) file_get_contents( $path ) ) as $rule ) {
				foreach ( CssSelectors::split_list( $rule['selector'] ) as $selector ) {
					$class = CssSelectors::bare_class( $selector );
					if ( null === $class ) {
						continue;
					}
					if ( str_starts_with( $class, 'is-' ) || str_starts_with( $class, 'has-' ) ) {
						$bare[] = sprintf( '%s: .%s', basename( $path ), $class );
					}
				}
			}
		}

		$this->assertSame(
			array(),
			$bare,
			"Estado declarado cru — componha com a classe do componente:\n  " . implode( "\n  ", $bare )
		);
	}

	/**
	 * A varredura não pode colapsar em silêncio.
	 *
	 * Um mapa vazio satisfaz os três testes acima tão bem quanto um mapa
	 * correto — a forma do #1071 / #1094.
	 */
	public function test_the_scan_cannot_collapse_in_silence(): void {
		$declared = self::declared();

		$this->assertGreaterThan( 1000, count( $declared ), 'A leitura das folhas colapsou.' );

		$states = array_filter(
			array_keys( $declared ),
			static fn ( string $c ): bool => str_starts_with( $c, 'is-' ) || str_starts_with( $c, 'has-' )
		);
		$this->assertGreaterThan( 10, count( $states ), 'O idioma de estado sumiu das folhas.' );

		$this->assertFalse(
			self::is_allowed( 'consent-given' ),
			'A guarda aceita qualquer nome — foi exatamente `consent-given` que a #1170 converteu.'
		);
		$this->assertTrue( self::is_allowed( 'is-open' ) );
		$this->assertTrue( self::is_allowed( 'ffc-detail-value' ) );
	}
}
