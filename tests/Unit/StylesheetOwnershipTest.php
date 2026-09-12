<?php
/**
 * Duas folhas que declaram a mesma classe precisam concordar sobre quem vence.
 *
 * @package FreeFormCertificate\Tests
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use FreeFormCertificate\Tests\Support\CssSelectors;
use PHPUnit\Framework\TestCase;

/**
 * Guarda de posse de componente (#1162, sub-issue da #1148).
 *
 * Quando duas folhas declaram `.ffc-x` **cruas** — sem ancestral, sem segunda
 * classe — e as duas carregam na mesma tela, quem vence é a ordem em que o
 * WordPress imprime os `<link>`. Essa ordem é determinística **só** quando uma
 * declara a outra como dependência no `wp_enqueue_style`; sem a aresta ela é a
 * ordem de enfileiramento, que por sua vez é a ordem em que os módulos são
 * ligados no `Loader`. Trocar duas linhas de bootstrap repinta uma tela.
 *
 * A guarda mede exatamente isso: classe declarada crua em duas folhas **sem
 * aresta de dependência entre elas**, em qualquer direção, direta ou
 * transitiva. Pares que não podem coexistir numa tela (admin × frontend, telas
 * de admin distintas) ficam em `ALLOWED` com o motivo — a impossibilidade é
 * uma propriedade dos *gates* de enfileiramento, que esta varredura não lê.
 *
 * **Isto não era prevenção: o defeito estava no ar.** `.ffc-status-cancelled`
 * era declarado por `ffc-calendar-admin.css` (vermelho, `--ffc-danger-*`) e por
 * `ffc-audience-admin.css` (âmbar, `--ffc-warning-*`). Na tela de Agendamentos
 * as duas carregam — Appointments é submenu de `ffc-scheduling`, então o gate
 * da folha de audiência (`strpos( $hook, 'ffc-scheduling' )`) casa lá também — e
 * como o `AudienceLoader` é ligado depois do `SelfSchedulingLoader`, a âmbar
 * vencia. Resultado: na coluna Status, "Cancelled" saía âmbar enquanto os seus
 * quatro irmãos saíam como a folha do próprio módulo pedia. As duas famílias
 * ganharam nome próprio (`ffc-appointment-status-*` e `ffc-audience-status-*`),
 * no molde do #1151 e do #1154.
 *
 * Três coisas que a medição ensinou, nenhuma adivinhável:
 *
 * 1. **`ffc-admin-submissions.css` carrega em TODA página `?page=ffc-*`**, não
 *    só na de submissões: o gate é `is_ffc_page()`, que casa qualquer menu
 *    `ffc-`. O docblock do enfileirador diz "submissions page". Era por isso
 *    que o `.ffc-status-badge` dela era a base acidental do selo de
 *    recrutamento e do de recadastramento -- os dois ganharam nome próprio no
 *    #1183 e a linha de base ficou VAZIA. A folha segue carregando em toda
 *    tela `ffc-*`, então o portão continua sendo o mecanismo a vigiar.
 * 2. **O resultado não é "a de baixo vence": é uma FUSÃO.** O selo da audiência
 *    renderizava `text-transform: uppercase` e `letter-spacing` que só a folha
 *    de submissões declara, e `white-space: nowrap` que só `ffc-common.css`
 *    declara. Nenhuma das três propriedades estava escrita na folha do
 *    componente. Nem "a última vence" nem "a primeira vale" descreve isso.
 * 3. **Um mesmo handle pode ser enfileirado com listas de dependência
 *    diferentes em sites diferentes** (`ffc-admin-settings` é um), e o WordPress
 *    guarda a primeira que registrar. A varredura une as listas de propósito:
 *    para esta guarda importa se a aresta é *declarada em algum lugar*, não
 *    qual site ganhou a corrida.
 *
 * O que ela NÃO vê: se as duas folhas realmente coexistem numa tela (isso são
 * os gates), CSS inline impresso por PHP, e colisão por seletor composto — só
 * a declaração crua, que é a forma que alcança qualquer componente.
 */
class StylesheetOwnershipTest extends TestCase {

	/**
	 * Pares que ficam, porque não podem coexistir numa tela.
	 *
	 * Chave: `classe|folhaA|folhaB` (folhas em ordem alfabética).
	 *
	 * @var array<string, string>
	 */
	private const ALLOWED = array(
		// `ffc-admin.css` é admin (`users.php` e telas `?page=ffc-*`);
		// `ffc-frontend.css` só sai no frontend. O componente de preview do
		// certificado é o mesmo dos dois lados, e é candidato a folha
		// compartilhada -- mas isso é decisão de arquitetura, não de ordem.
		'ffc-preview-backdrop|ffc-admin.css|ffc-frontend.css'  => 'admin × frontend: nunca coexistem numa tela',
		'ffc-preview-container|ffc-admin.css|ffc-frontend.css' => 'admin × frontend: nunca coexistem numa tela',
		'ffc-preview-stage|ffc-admin.css|ffc-frontend.css'     => 'admin × frontend: nunca coexistem numa tela',
		'ffc-checkbox-label|ffc-admin.css|ffc-reregistration-frontend.css' => 'admin × frontend: nunca coexistem numa tela',

		// `ffc-calendar-editor.css` só carrega na edição do CPT
		// `ffc_self_scheduling`, onde `is_ffc_page()` é falso (o post type não
		// é `ffc_form`) -- então `ffc-admin.css` não entra por lá, e o handle
		// `ffc-admin` de `users.php` também não.
		'ffc-shortcode-display|ffc-admin.css|ffc-calendar-editor.css' => 'telas distintas: a folha do editor só sai no CPT ffc_self_scheduling',

		// Telas de admin distintas: `?page=ffc-audience*`/`ffc-scheduling*`
		// contra `?page=ffc-reregistration*`/`ffc-custom-fields`.
		'column-actions|ffc-audience-admin.css|ffc-reregistration-admin.css' => 'telas de admin distintas; nomes sem prefixo já na base do #1152',
		'column-status|ffc-audience-admin.css|ffc-reregistration-admin.css'  => 'telas de admin distintas; nomes sem prefixo já na base do #1152',

		// `ffc-custom-fields-admin.css` sai no perfil de usuário e nas telas de
		// audiência; `ffc-reregistration-admin.css`, nas de recadastramento.
		'ffc-color-dot|ffc-custom-fields-admin.css|ffc-reregistration-admin.css' => 'telas distintas: perfil/audiência × recadastramento',

		// `ffc-working-hours.css` sai no perfil de usuário e no painel do
		// frontend; `ffc-audience-admin.css`, nas telas de audiência.
		'ffc-working-hours|ffc-audience-admin.css|ffc-reregistration-frontend.css' => 'admin × frontend: nunca coexistem numa tela',
		'ffc-working-hours|ffc-audience-admin.css|ffc-working-hours.css' => 'telas distintas: audiência × perfil de usuário e painel',
		// O selo de status do edital existe nas duas superfícies do
		// recrutamento, e elas não podem coexistir: a folha de admin tem
		// portão `is_recruitment_screen( $hook_suffix )`, que é um hook do
		// wp-admin, e a pública é enfileirada no render do shortcode.
		'ffc-recruitment-status-badge|ffc-recruitment-admin.css|ffc-recruitment-public.css' => 'admin × frontend: nunca coexistem numa tela',
	);

	/**
	 * Pares que coexistem e não têm aresta. Registro de dívida; só encolhe.
	 *
	 * @var array<string, string>
	 */
	private const BASELINE = array();

	/**
	 * Folhas que não passam por `wp_enqueue_style`, com o motivo.
	 *
	 * @var array<string, string>
	 */
	private const NO_HANDLE = array(
		// O cancelamento de agendamento monta um documento próprio e imprime os
		// `<link>` na mão, em ordem explícita (paleta primeiro) -- justamente
		// para que a ordem seja um valor testável em vez de um `echo` atrás de
		// um `exit()`. Não há fila do WordPress para ter aresta.
		'ffc-appointment-cancellation.css' => 'documento próprio do handler de cancelamento; ordem impressa na mão',
	);

	/**
	 * Handle => folha, e handle => dependências declaradas.
	 *
	 * @return array{files: array<string, string>, deps: array<string, array<int, string>>}
	 */
	private function graph(): array {
		$files = array();
		$deps  = array();

		foreach ( $this->php_sources() as $source ) {
			preg_match_all( "/const\s+([A-Z_][A-Z0-9_]*)\s*=\s*'([^']+)'/", $source, $cm, PREG_SET_ORDER );
			$consts = array();
			foreach ( $cm as $c ) {
				$consts[ $c[1] ] = $c[2];
			}

			$offset = 0;
			while ( preg_match( '/wp_(?:enqueue|register)_style\s*\(/', $source, $m, PREG_OFFSET_CAPTURE, $offset ) ) {
				$open   = (int) $m[0][1] + strlen( $m[0][0] ) - 1;
				$args   = $this->arguments( $source, $open );
				$offset = (int) $m[0][1] + 1;

				if ( count( $args ) < 2 ) {
					continue;
				}
				if ( ! preg_match( '#assets/css/([a-z0-9-]+)(?:\{\$s(?:uffix)?\}|\$s)?(?:\.min)?\.css#', $args[1], $sm ) ) {
					continue;
				}

				$handle = null;
				if ( preg_match( "/'([^']+)'/", $args[0], $hm ) ) {
					$handle = $hm[1];
				} elseif ( preg_match( '/self::([A-Z_][A-Z0-9_]*)/', $args[0], $cn ) ) {
					$handle = $consts[ $cn[1] ] ?? null;
				}
				if ( null === $handle ) {
					continue;
				}

				$files[ $handle ] = $sm[1] . '.css';
				$deps[ $handle ]  = $deps[ $handle ] ?? array();
				if ( isset( $args[2] ) ) {
					preg_match_all( "/'([^']+)'/", $args[2], $dm );
					$deps[ $handle ] = array_values( array_unique( array_merge( $deps[ $handle ], $dm[1] ) ) );
				}
			}
		}

		return array(
			'files' => $files,
			'deps'  => $deps,
		);
	}

	/**
	 * Argumentos de nível zero de uma chamada, dado o índice do `(`.
	 *
	 * Um `explode( ',', … )` não serve: o segundo argumento costuma ser uma
	 * concatenação com `plugins_url( …, dirname( __DIR__, 1 ) )`, cuja vírgula
	 * interna cortaria a lista no lugar errado e faria a folha sumir da conta.
	 *
	 * @param string $source Conteúdo do arquivo.
	 * @param int    $open   Índice do parêntese de abertura.
	 * @return array<int, string>
	 */
	private function arguments( string $source, int $open ): array {
		$args  = array();
		$cur   = '';
		$depth = 0;
		$quote = '';
		$len   = strlen( $source );

		for ( $i = $open; $i < $len; $i++ ) {
			$ch = $source[ $i ];

			if ( '' !== $quote ) {
				$cur .= $ch;
				if ( '\\' === $ch && $i + 1 < $len ) {
					$cur .= $source[ ++$i ];
					continue;
				}
				if ( $ch === $quote ) {
					$quote = '';
				}
				continue;
			}
			if ( '"' === $ch || "'" === $ch ) {
				$quote = $ch;
				$cur  .= $ch;
				continue;
			}
			if ( '(' === $ch ) {
				++$depth;
				if ( 1 === $depth ) {
					continue;
				}
			} elseif ( ')' === $ch ) {
				--$depth;
				if ( 0 === $depth ) {
					$args[] = $cur;
					return $args;
				}
			}
			if ( ',' === $ch && 1 === $depth ) {
				$args[] = $cur;
				$cur    = '';
				continue;
			}
			$cur .= $ch;
		}

		return $args;
	}

	/**
	 * Todo `.php` de `includes/`.
	 *
	 * @return array<int, string>
	 */
	private function php_sources(): array {
		$root  = dirname( __DIR__, 2 ) . '/includes';
		$files = array();

		$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root ) );
		foreach ( $it as $file ) {
			if ( $file instanceof \SplFileInfo && 'php' === $file->getExtension() ) {
				$files[] = (string) file_get_contents( $file->getPathname() );
			}
		}

		return $files;
	}

	/**
	 * `$from` alcança `$to` pela cadeia de dependências?
	 *
	 * @param string                          $from Handle de origem.
	 * @param string                          $to   Handle de destino.
	 * @param array<string, array<int,string>> $deps Grafo.
	 * @param array<string, bool>             $seen Visitados.
	 * @return bool
	 */
	private function reaches( string $from, string $to, array $deps, array &$seen ): bool {
		if ( isset( $seen[ $from ] ) ) {
			return false;
		}
		$seen[ $from ] = true;

		foreach ( $deps[ $from ] ?? array() as $dep ) {
			if ( $dep === $to || $this->reaches( $dep, $to, $deps, $seen ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Existe aresta entre duas folhas, em qualquer direção?
	 *
	 * @param string                          $a     Folha A.
	 * @param string                          $b     Folha B.
	 * @param array<string, string>           $files Handle => folha.
	 * @param array<string, array<int,string>> $deps  Grafo.
	 * @return bool
	 */
	private function linked( string $a, string $b, array $files, array $deps ): bool {
		$ha = array_keys( $files, $a, true );
		$hb = array_keys( $files, $b, true );

		foreach ( $ha as $x ) {
			foreach ( $hb as $y ) {
				$seen = array();
				if ( $this->reaches( $x, $y, $deps, $seen ) ) {
					return true;
				}
				$seen = array();
				if ( $this->reaches( $y, $x, $deps, $seen ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Pares (classe, folhaA, folhaB) declarados cruas sem aresta.
	 *
	 * @return array<int, string> Chaves `classe|folhaA|folhaB`.
	 */
	private function unlinked_pairs(): array {
		$graph = $this->graph();
		$bare  = array();

		foreach ( CssSelectors::sheets() as $path ) {
			$name = basename( $path );
			foreach ( CssSelectors::of( (string) file_get_contents( $path ) ) as $selector ) {
				$class = CssSelectors::bare_class( $selector );
				if ( null !== $class ) {
					$bare[ $class ][ $name ] = true;
				}
			}
		}

		$pairs = array();
		foreach ( $bare as $class => $sheets ) {
			$names = array_keys( $sheets );
			sort( $names );
			$count = count( $names );
			for ( $i = 0; $i < $count; $i++ ) {
				for ( $j = $i + 1; $j < $count; $j++ ) {
					if ( ! $this->linked( $names[ $i ], $names[ $j ], $graph['files'], $graph['deps'] ) ) {
						$pairs[] = "{$class}|{$names[ $i ]}|{$names[ $j ]}";
					}
				}
			}
		}

		sort( $pairs );

		return $pairs;
	}

	/**
	 * Nada de novo sem aresta.
	 *
	 * @return void
	 */
	public function test_no_new_class_is_declared_bare_in_two_unlinked_sheets(): void {
		$new = array();
		foreach ( $this->unlinked_pairs() as $pair ) {
			if ( ! isset( self::ALLOWED[ $pair ] ) && ! isset( self::BASELINE[ $pair ] ) ) {
				$new[] = $pair;
			}
		}

		$this->assertSame(
			array(),
			$new,
			"Classe declarada crua em duas folhas sem aresta de dependência entre elas — "
				. "quem vence é a ordem de enfileiramento. Declare a dependência no "
				. "`wp_enqueue_style`, dê nome próprio ao componente, ou registre em ALLOWED "
				. "com o motivo pelo qual as duas nunca carregam juntas:\n" . implode( "\n", $new )
		);
	}

	/**
	 * O que ganhou aresta (ou nome próprio) sai das listas.
	 *
	 * @return void
	 */
	public function test_the_lists_shrink_when_a_pair_is_resolved(): void {
		$live  = $this->unlinked_pairs();
		$stale = array();

		foreach ( array_keys( self::BASELINE ) as $pair ) {
			if ( ! in_array( $pair, $live, true ) ) {
				$stale[] = "BASELINE: {$pair}";
			}
		}
		foreach ( array_keys( self::ALLOWED ) as $pair ) {
			if ( ! in_array( $pair, $live, true ) ) {
				$stale[] = "ALLOWED: {$pair}";
			}
		}

		$this->assertSame(
			array(),
			$stale,
			"Um par foi resolvido e a lista não acompanhou — remova para trancar o ganho:\n"
				. implode( "\n", $stale )
		);
	}

	/**
	 * Toda entrada das duas listas carrega motivo.
	 *
	 * @return void
	 */
	public function test_every_listed_pair_carries_a_reason(): void {
		$thin = array();
		foreach ( array_merge( self::ALLOWED, self::BASELINE, self::NO_HANDLE ) as $key => $reason ) {
			if ( strlen( trim( $reason ) ) < 15 ) {
				$thin[] = $key;
			}
		}

		$this->assertSame( array(), $thin, 'Entrada sem motivo escrito: ' . implode( ', ', $thin ) );
	}

	/**
	 * Toda folha tem um handle, ou está em NO_HANDLE com o motivo.
	 *
	 * É a metade que impede a guarda de passar por ignorância: uma folha que o
	 * extrator não achasse teria grafo vazio, e todo par dela contaria como
	 * "sem aresta" — ou, pior, uma mudança na forma da chamada faria uma folha
	 * inteira sumir da conta sem nada ficar vermelho.
	 *
	 * @return void
	 */
	public function test_every_stylesheet_is_reachable_through_an_enqueue(): void {
		$graph   = $this->graph();
		$known   = array_values( $graph['files'] );
		$missing = array();

		foreach ( CssSelectors::sheets() as $path ) {
			$name = basename( $path );
			if ( ! in_array( $name, $known, true ) && ! isset( self::NO_HANDLE[ $name ] ) ) {
				$missing[] = $name;
			}
		}

		$this->assertSame(
			array(),
			$missing,
			"Folha sem `wp_enqueue_style` que o extrator reconheça. Se ela realmente não "
				. "passa pela fila do WordPress, registre em NO_HANDLE com o motivo:\n"
				. implode( "\n", $missing )
		);

		foreach ( array_keys( self::NO_HANDLE ) as $name ) {
			$this->assertNotContains( $name, $known, "{$name} ganhou handle — remova de NO_HANDLE." );
		}
	}

	/**
	 * A leitura do grafo não colapsou.
	 *
	 * @return void
	 */
	public function test_the_dependency_graph_is_still_being_read(): void {
		$graph = $this->graph();

		$this->assertGreaterThanOrEqual( 25, count( $graph['files'] ), 'O extrator perdeu handles.' );
		$this->assertSame( 'ffc-admin.css', $graph['files']['ffc-admin-css'] ?? null );
		$this->assertContains( 'ffc-admin-utilities', $graph['deps']['ffc-admin-css'] ?? array() );

		// `ffc-calendar-admin` só é alcançável porque o extrator lê o argumento
		// de nível zero: a chamada usa `plugins_url( "…", dirname( __DIR__, 1 ) )`,
		// cuja vírgula interna quebraria um `explode`.
		$this->assertSame( 'ffc-calendar-admin.css', $graph['files']['ffc-calendar-admin'] ?? null );

		// `ffc-recruitment-admin` só é alcançável porque o extrator resolve
		// `self::HANDLE_CSS` contra as constantes do próprio arquivo.
		$this->assertSame( 'ffc-recruitment-admin.css', $graph['files']['ffc-recruitment-admin'] ?? null );
	}

	/**
	 * Uma aresta transitiva conta como aresta.
	 *
	 * `ffc-admin-settings` → `ffc-admin-css` → `ffc-admin-utilities`: as folhas
	 * das pontas têm ordem determinística sem se citarem.
	 *
	 * @return void
	 */
	public function test_a_transitive_edge_counts_as_linked(): void {
		$graph = $this->graph();

		$this->assertTrue(
			$this->linked( 'ffc-admin-settings.css', 'ffc-admin-utilities.css', $graph['files'], $graph['deps'] )
		);
		$this->assertFalse(
			$this->linked( 'ffc-admin-settings.css', 'ffc-frontend.css', $graph['files'], $graph['deps'] )
		);
	}

	/**
	 * Só a declaração crua conta.
	 *
	 * Um composto ou um descendente já nomeia o dono; é a declaração solitária
	 * que alcança qualquer componente que carregue a classe.
	 *
	 * @return void
	 */
	public function test_only_a_bare_declaration_counts(): void {
		$this->assertSame( 'ffc-x', CssSelectors::bare_class( '.ffc-x' ) );
		$this->assertSame( 'ffc-x', CssSelectors::bare_class( '.ffc-x:hover' ) );
		$this->assertSame( 'ffc-x', CssSelectors::bare_class( '.ffc-x::after' ) );

		$this->assertNull( CssSelectors::bare_class( '.ffc-y .ffc-x' ) );
		$this->assertNull( CssSelectors::bare_class( '.ffc-x.is-open' ) );
		$this->assertNull( CssSelectors::bare_class( 'a.ffc-x' ) );
	}
}
