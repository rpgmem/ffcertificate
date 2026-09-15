<?php
/**
 * Onde cada classe CSS é EMITIDA, no PHP e no JS.
 *
 * Existe porque renomear uma classe é seguro exatamente na medida em que se
 * consegue achar quem a emite, e um grep não consegue. Medindo para a #1170,
 * três recortes diferentes deram três respostas, e duas estavam erradas:
 *
 *  1. Grep da palavra solta — `.error`, `.value`, `.top` casam em qualquer PHP
 *     por motivo nenhum.
 *  2. Grep com `\b` — pior, e em silêncio: em CSS **`-` é limite de palavra**,
 *     então `ffc-error` casa como `error` e infla justamente os nomes
 *     genéricos que se quer renomear.
 *  3. Token delimitado — o número real, e a base desta classe.
 *
 * **O caso que decide tudo é o nome montado em runtime.** `'ffc-dashboard-status-'
 * . $status` não existe como literal em lugar nenhum; procurar
 * `ffc-dashboard-status-confirmed` não acha nada, e renomeá-lo quebra sem aviso.
 * Por isso a varredura registra também os PREFIXOS dinâmicos, e uma classe conta
 * como emitida quando começa por um deles.
 *
 * É a mesma escolha que `AjaxWiringTest` já documenta para nome de ação — casar
 * as formas exatamente reportava todas como órfãs —, e pelo mesmo motivo:
 * **frouxo demais reporta pouco; restrito demais reporta o mundo.**
 *
 * @package FreeFormCertificate\Tests\Support
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Support;

/**
 * Varredura das fontes que emitem classe.
 */
final class CssClassEmitters {

	/**
	 * Diretórios varridos, relativos à raiz do repositório.
	 */
	private const ROOTS = array( 'includes', 'templates', 'assets/js', 'libs/js' );

	/**
	 * Um literal de string COMPLETO, de qualquer das duas aspas.
	 *
	 * Casar `(["\'])(.*?)\1` por alternância perde a paridade no primeiro
	 * apóstrofo dentro de aspas duplas e daí em diante lê o arquivo deslocado —
	 * o cabeçalho desta classe já registra o fato, e mesmo assim a primeira
	 * versão da forma 5b caiu nele: `ffc-has-geofence` ficava sem emissor
	 * porque, varrendo `class-ffc-shortcodes.php` do começo, a paridade já
	 * estava trocada quando a varredura chegava na linha 169.
	 *
	 * Cada alternativa aqui consome o literal INTEIRO, escapes inclusive, então
	 * uma aspa do outro tipo lá dentro é conteúdo e não delimitador.
	 */
	private const STRING_LITERAL = '/"((?:[^"\\\\]|\\\\.)*)"|\'((?:[^\'\\\\]|\\\\.)*)\'/';

	/**
	 * Cache da varredura — ela lê centenas de arquivos.
	 *
	 * @var array{literals: array<string, array<int, string>>, prefixes: array<string, array<int, string>>}|null
	 */
	private static ?array $cache = null;

	/**
	 * Caminho absoluto da raiz do repositório.
	 */
	private static function root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * Todo arquivo PHP/JS que pode emitir classe.
	 *
	 * @return array<string, string> Caminho relativo => conteúdo.
	 */
	private static function sources(): array {
		$out = array();

		foreach ( self::ROOTS as $dir ) {
			$base = self::root() . '/' . $dir;
			if ( ! is_dir( $base ) ) {
				continue;
			}
			$walk = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $base, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $walk as $file ) {
				$path = $file->getPathname();
				if ( ! preg_match( '/\.(php|js)$/', $path ) || str_contains( $path, '.min.' ) ) {
					continue;
				}
				$out[ str_replace( self::root() . '/', '', $path ) ] = (string) file_get_contents( $path );
			}
		}

		ksort( $out );

		return $out;
	}

	/**
	 * Varre uma vez e guarda literais e prefixos dinâmicos.
	 *
	 * @return array{literals: array<string, array<int, string>>, prefixes: array<string, array<int, string>>}
	 */
	private static function scan(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$literals = array();
		$prefixes = array();

		/*
		 * Um acumulador por mapa, capturando o array por referência.
		 *
		 * O óbvio seria UM fechamento recebendo `array &$bucket`, e ele quebra —
		 * mas só quando algum teste anterior no mesmo processo tiver registrado
		 * um patch. O Patchwork instrumenta a chamada dinâmica e a despacha por
		 * `call_user_func_array()`, que não passa por referência: a chamada morre
		 * com "Argument #1 (\$bucket) must be passed by reference".
		 *
		 * Rodar este arquivo sozinho passa, e rodá-lo depois de qualquer classe
		 * que use Brain\Monkey falha — foi assim que o verde local mentiu e a CI
		 * pegou. Variável capturada por `use ( &… )` não é parâmetro, então o
		 * despacho não a toca.
		 */
		$add_literal = static function ( string $key, string $file ) use ( &$literals ): void {
			if ( '' === $key ) {
				return;
			}
			if ( ! isset( $literals[ $key ] ) ) {
				$literals[ $key ] = array();
			}
			if ( ! in_array( $file, $literals[ $key ], true ) ) {
				$literals[ $key ][] = $file;
			}
		};

		$add_prefix = static function ( string $key, string $file ) use ( &$prefixes ): void {
			if ( '' === $key ) {
				return;
			}
			if ( ! isset( $prefixes[ $key ] ) ) {
				$prefixes[ $key ] = array();
			}
			if ( ! in_array( $file, $prefixes[ $key ], true ) ) {
				$prefixes[ $key ][] = $file;
			}
		};

		foreach ( self::sources() as $file => $src ) {
			/*
			 * ── Forma 1 e 2: o atributo `class="…"`.
			 *
			 * O valor pode carregar um bloco PHP no meio; os literais em volta
			 * dele ainda são tokens de classe, e o que estiver DENTRO é tratado
			 * pelas formas de string mais abaixo.
			 *
			 * Este comentário é de BLOCO por necessidade: um `//` termina na tag
			 * de fechamento do PHP, então citar uma aqui fecharia a tag no meio
			 * da função — o mesmo fato que o CLAUDE.md registra sobre anotações
			 * do PHPCS, e no qual eu tropecei ao escrever isto.
			 */
			if ( preg_match_all( '/class\s*=\s*(["\'])(.*?)\1/s', $src, $m, PREG_SET_ORDER ) ) {
				foreach ( $m as $hit ) {
					$value = (string) preg_replace( '/<\?(php|=).*?\?>/s', ' ', $hit[2] );
					foreach ( preg_split( '/\s+/', trim( $value ) ) ?: array() as $token ) {
						/*
						 * O token chega com a PONTUAÇÃO DE CONCATENAÇÃO colada
						 * quando o atributo é aberto e não fechado na mesma
						 * string -- `'<table class="ffc-appointments-table' +
						 * (past ? ' ffc-table-past' : '') + '">'`. A aspa que o
						 * casamento acima encontra é a do FIM da expressão, e
						 * o primeiro nome sai como `ffc-appointments-table'`,
						 * que não passa na validação e some.
						 *
						 * É a irmã da forma que a #1170 ensinou: lá o token do
						 * FIM chegava com o espaço separador, aqui o token do
						 * COMEÇO chega com a aspa. Consertar um sem o outro é
						 * por que quatro tabelas do painel ficaram anos na
						 * lista de órfãs.
						 */
						$token = trim( $token, "\"'+., \t\n" );

						if ( preg_match( '/^[A-Za-z_][A-Za-z0-9_-]*$/', $token ) ) {
							$add_literal( $token, $file );
						}
					}
				}
			}

			// ── Forma 3: API de classe, no DOM e no jQuery.
			// `classList.add('a','b')` e `addClass('a b')` — o argumento pode
			// trazer mais de um nome.
			if ( preg_match_all( '/(?:classList\.(?:add|remove|toggle|contains|replace)|(?:add|remove|toggle|has)Class)\s*\(([^)]*)\)/i', $src, $m ) ) {
				foreach ( $m[1] as $args ) {
					if ( preg_match_all( '/(["\'])([^"\']*)\1/', $args, $strings ) ) {
						foreach ( $strings[2] as $group ) {
							foreach ( preg_split( '/\s+/', trim( $group ) ) ?: array() as $token ) {
								if ( preg_match( '/^[A-Za-z_][A-Za-z0-9_-]*$/', $token ) ) {
									$add_literal( $token, $file );
								}
							}
						}
					}
				}
			}

			/*
			 * ── Forma 3b: a classe como SELETOR numa string.
			 *
			 * `$(document).on('click', '.ffc-timeslot:not(.ffc-timeslot-full)')`
			 * é um site de emissão para efeito de renomeação: mudar a classe sem
			 * mudar o seletor quebra o handler, e nada acusa. Não precisa de
			 * contexto de classe em volta — o ponto antes do nome já é o sinal.
			 *
			 * Procura no fonte inteiro em vez de dentro de strings casadas, e
			 * isso é deliberado: casar `"…"` e `'…'` por alternância PERDE A
			 * SINCRONIA no primeiro apóstrofo dentro de aspas duplas (`"don't"`),
			 * e daí em diante lê o arquivo com a paridade trocada. Foi assim que
			 * a primeira versão não achou `.ffc-timeslot-full`. É a mesma razão
			 * pela qual `CssSelectors` precisa ser ciente de aspas.
			 */
			if ( preg_match_all( '/\.(ffc-[a-z0-9]+(?:-{1,2}[a-z0-9]+)*)/i', $src, $m ) ) {
				foreach ( $m[1] as $token ) {
					$add_literal( $token, $file );
				}
			}

			/*
			 * ── Forma 7: opção de biblioteca cujo VALOR é uma classe.
			 *
			 * `$('#lista').sortable({ placeholder: 'ffc-sortable-placeholder' })`
			 * -- o jQuery UI aplica essa string como classe no elemento fantasma
			 * que ele insere. Não existe a palavra `class` em lugar nenhum ali,
			 * então nenhuma janela de contexto alcança, e a classe ficava na lista
			 * de órfãs parecendo morta.
			 *
			 * Casa só a forma de OPÇÃO (`placeholder:`), nunca o atributo HTML
			 * (`placeholder="Digite o nome"`), que é texto livre do usuário.
			 */
			if ( preg_match_all( '/placeholder\s*:\s*(["\'])([A-Za-z_][A-Za-z0-9_-]*)\1/', $src, $m ) ) {
				foreach ( $m[2] as $token ) {
					$add_literal( $token, $file );
				}
			}

			// ── Forma 4: `className = 'x'` e `className += ' x'`.
			if ( preg_match_all( '/className\s*\+?=\s*(["\'])([^"\']*)\1/', $src, $m, PREG_SET_ORDER ) ) {
				foreach ( $m as $hit ) {
					foreach ( preg_split( '/\s+/', trim( $hit[2] ) ) ?: array() as $token ) {
						if ( preg_match( '/^[A-Za-z_][A-Za-z0-9_-]*$/', $token ) ) {
							$add_literal( $token, $file );
						}
					}
				}
			}

			/*
			 * ── Forma 5: string solta num contexto de classe.
			 *
			 * É o que apanha o ternário dentro de `esc_attr()`, o
			 * `var rowClass = 'past-row'` e o `sprintf( '…class="%s"…', $c )`.
			 *
			 * A PROXIMIDADE é o que faz esta forma valer. Sem ela a varredura
			 * conta como classe todo `handle` de `wp_enqueue_style( 'ffc-…' )`
			 * e toda chave de opção — e passa a reportar que tudo tem emissor,
			 * que foi o primeiro resultado ao escrever isto: zero classe órfã,
			 * porque a rede pegava o oceano.
			 */
			foreach ( self::near_class_context( $src ) as $window ) {
				/*
				 * O `\s*` de cada lado não é folga: o token vem com o espaço
				 * SEPARADOR quando é concatenado a um atributo que já existe —
				 * `'<table class="ffc-appointments-table' + (past ? ' past-appointments' : '') + '">'`.
				 * Sem ele a varredura não achava emissor para as três classes
				 * `past-*` do painel, e a #1170 ia renomeá-las às cegas.
				 */
				if ( preg_match_all( '/(["\'])\s*((?:ffc-)?[a-z][a-z0-9]*(?:-[a-z0-9]+)+)\s*\1/i', $window, $m ) ) {
					foreach ( $m[2] as $token ) {
						$add_literal( $token, $file );
					}
				}

				/*
				 * ── Forma 5b: VÁRIAS classes numa string só.
				 *
				 * `'ffc-shortcode ffc-form-wrapper ffc-has-geofence'` e
				 * `'ffc-hierarchy-child ffc-hierarchy-level-' . $level`. A forma
				 * 5 ancora nas duas aspas, então enxerga só a string de um
				 * token e perde estas -- ficava o PREFIXO do último nome, que a
				 * forma 6 pega, e os nomes inteiros que vêm antes sumiam.
				 *
				 * O discriminante é ter DOIS OU MAIS tokens: a folga que a
				 * forma 5 não pode dar existe porque um `handle` de
				 * `wp_enqueue_style` é sempre um token só, e foi ele que fez a
				 * primeira versão desta varredura dizer que nada era órfão.
				 * Exigir o plural mantém a rede fechada para handles, chaves de
				 * opção e slugs de capacidade.
				 */
				if ( preg_match_all( self::STRING_LITERAL, $window, $m, PREG_SET_ORDER ) ) {
					foreach ( $m as $hit ) {
						$value  = ( isset( $hit[2] ) && '' !== $hit[2] ) ? $hit[2] : $hit[1];
						$tokens = preg_split( '/\s+/', trim( $value ) ) ?: array();

						if ( count( $tokens ) < 2 ) {
							continue;
						}

						foreach ( $tokens as $token ) {
							if ( preg_match( '/^(?:ffc-)?[a-z][a-z0-9]*(?:-[a-z0-9]+)+$/i', $token ) ) {
								$add_literal( $token, $file );
							}
						}
					}
				}

				/*
				 * ── Forma 6, a que decide: o PREFIXO de um nome montado em
				 * runtime. `'ffc-dashboard-status-' . $status` e
				 * `'ffc-status-' + item.status`. O nome completo não existe em
				 * lugar nenhum, então procurá-lo não acha nada — e renomeá-lo
				 * quebra sem aviso.
				 *
				 * Exige `ffc-` MAIS um segmento: o prefixo nu `ffc-` cobriria as
				 * 1.046 classes e diria que nenhuma é órfã. Ele apareceu de
				 * verdade, em `'ffc-' + Date.now()` — que monta o UID de um
				 * evento iCal, não uma classe.
				 */
				/*
				 * O prefixo pode estar no FIM de uma string maior, e não ser a
				 * string inteira — é a forma mais comum no JS que monta HTML:
				 * `'<td><span class="… ffc-dashboard-status-' + item.status`.
				 * Ancorar na abertura da aspa perdia justamente esses.
				 */
				if ( preg_match_all( '/(ffc-[a-z0-9]+(?:-{1,2}[a-z0-9]+)*-{1,2})(["\'])\s*[.+]/i', $window, $m ) ) {
					foreach ( $m[1] as $prefix ) {
						$add_prefix( $prefix, $file );
					}
				}
				if ( preg_match_all( '/(["\'])(ffc-[a-z0-9]+(?:-[a-z0-9]+)*-)\{?\$/i', $window, $m ) ) {
					foreach ( $m[2] as $prefix ) {
						$add_prefix( $prefix, $file );
					}
				}

				/*
				 * Duas formas de prefixo que NÃO são concatenação e por isso
				 * escaparam da primeira versão desta varredura. Cada uma
				 * corresponde a uma renomeação que teria quebrado em silêncio:
				 *
				 *  - o eco embutido no próprio atributo,
				 *    `class="ffc-audience-status-` seguido de um bloco PHP;
				 *  - o placeholder de `printf`,
				 *    `class="ffc-cap-origin--%7$s"`.
				 */
				if ( preg_match_all( '/(ffc-[a-z0-9]+(?:-{1,2}[a-z0-9]+)*-{1,2})<\?/i', $window, $m ) ) {
					foreach ( $m[1] as $prefix ) {
						$add_prefix( $prefix, $file );
					}
				}
				if ( preg_match_all( '/(ffc-[a-z0-9]+(?:-{1,2}[a-z0-9]+)*-{1,2})%[0-9]*\$?[sd]/i', $window, $m ) ) {
					foreach ( $m[1] as $prefix ) {
						$add_prefix( $prefix, $file );
					}
				}
			}
		}

		ksort( $literals );
		ksort( $prefixes );

		self::$cache = array(
			'literals' => $literals,
			'prefixes' => $prefixes,
		);

		return self::$cache;
	}


	/**
	 * Trechos do fonte em torno de algo que fala de classe.
	 *
	 * O sinal é a palavra `class` em qualquer das suas formas de uso —
	 * o atributo, `classList`, `addClass`, `className`, ou uma variável
	 * batizada `rowClass` — mais o nome de um AJUDANTE que recebe classes como
	 * argumentos posicionais. `BadgeHtml::render( 'ffc-recruitment-subscription-badge', … )`
	 * não tem a palavra `class` em lugar nenhum da chamada: ela está no nome do
	 * PARÂMETRO, que fica na definição e não no site. Duas classes ficaram sem
	 * emissor achável assim, e só apareceram quando o #1193 passou a declará-las
	 * numa folha — antes disso ninguém as procurava. A janela é generosa (240 caracteres para cada lado)
	 * porque o objetivo aqui é **não perder site**, não ser exato: quem lê a
	 * saída é uma pessoa prestes a renomear, e um falso positivo custa uma
	 * olhada enquanto um falso negativo custa um estilo que some.
	 *
	 * @param string $src Conteúdo do arquivo.
	 * @return array<int, string>
	 */
	private static function near_class_context( string $src ): array {
		$out = array();

		if ( ! preg_match_all( '/[A-Za-z]*(?:class(?:List|Name)?|cls)[A-Za-z]*|BadgeHtml/i', $src, $m, PREG_OFFSET_CAPTURE ) ) {
			return $out;
		}

		foreach ( $m[0] as $hit ) {
			$start = max( 0, $hit[1] - 240 );
			$out[] = substr( $src, $start, 480 + strlen( $hit[0] ) );
		}

		return $out;
	}

	/**
	 * Classe => arquivos que a emitem como literal.
	 *
	 * @return array<string, array<int, string>>
	 */
	public static function literals(): array {
		return self::scan()['literals'];
	}

	/**
	 * Prefixo dinâmico => arquivos que o concatenam.
	 *
	 * @return array<string, array<int, string>>
	 */
	public static function prefixes(): array {
		return self::scan()['prefixes'];
	}

	/**
	 * Onde uma classe é emitida — literalmente ou por prefixo.
	 *
	 * O segundo caso devolve o prefixo que a cobre, porque é essa a informação
	 * que interessa a quem vai renomear: o site de emissão nomeia o prefixo, não
	 * a classe, e é o prefixo que precisa mudar.
	 *
	 * @param string $class Nome da classe, sem o ponto.
	 * @return array{how: string, prefix: string, files: array<int, string>}
	 */
	public static function of( string $class ): array {
		$scan = self::scan();

		if ( isset( $scan['literals'][ $class ] ) ) {
			return array(
				'how'    => 'literal',
				'prefix' => '',
				'files'  => $scan['literals'][ $class ],
			);
		}

		// O prefixo mais LONGO que cobre — o mais específico é o que descreve
		// de verdade o site de emissão.
		$best = '';
		foreach ( array_keys( $scan['prefixes'] ) as $prefix ) {
			if ( str_starts_with( $class, $prefix ) && strlen( $prefix ) > strlen( $best ) ) {
				$best = $prefix;
			}
		}

		if ( '' !== $best ) {
			return array(
				'how'    => 'prefixo',
				'prefix' => $best,
				'files'  => $scan['prefixes'][ $best ],
			);
		}

		return array(
			'how'    => 'nenhum',
			'prefix' => '',
			'files'  => array(),
		);
	}

	/**
	 * Toda classe `ffc-*` declarada nas folhas.
	 *
	 * @return array<int, string>
	 */
	public static function declared_ffc_classes(): array {
		$out = array();

		foreach ( CssSelectors::sheets() as $path ) {
			foreach ( CssSelectors::of( (string) file_get_contents( $path ) ) as $selector ) {
				if ( preg_match_all( '/\.(ffc-[A-Za-z0-9_-]+)/', $selector, $m ) ) {
					foreach ( $m[1] as $class ) {
						$out[ $class ] = true;
					}
				}
			}
		}

		$names = array_keys( $out );
		sort( $names );

		return $names;
	}
}
