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

		$add = static function ( array &$bucket, string $key, string $file ): void {
			if ( '' === $key ) {
				return;
			}
			if ( ! isset( $bucket[ $key ] ) ) {
				$bucket[ $key ] = array();
			}
			if ( ! in_array( $file, $bucket[ $key ], true ) ) {
				$bucket[ $key ][] = $file;
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
						if ( preg_match( '/^[A-Za-z_][A-Za-z0-9_-]*$/', $token ) ) {
							$add( $literals, $token, $file );
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
									$add( $literals, $token, $file );
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
					$add( $literals, $token, $file );
				}
			}

			// ── Forma 4: `className = 'x'` e `className += ' x'`.
			if ( preg_match_all( '/className\s*\+?=\s*(["\'])([^"\']*)\1/', $src, $m, PREG_SET_ORDER ) ) {
				foreach ( $m as $hit ) {
					foreach ( preg_split( '/\s+/', trim( $hit[2] ) ) ?: array() as $token ) {
						if ( preg_match( '/^[A-Za-z_][A-Za-z0-9_-]*$/', $token ) ) {
							$add( $literals, $token, $file );
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
				if ( preg_match_all( '/(["\'])((?:ffc-)?[a-z][a-z0-9]*(?:-[a-z0-9]+)+)\1/i', $window, $m ) ) {
					foreach ( $m[2] as $token ) {
						$add( $literals, $token, $file );
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
						$add( $prefixes, $prefix, $file );
					}
				}
				if ( preg_match_all( '/(["\'])(ffc-[a-z0-9]+(?:-[a-z0-9]+)*-)\{?\$/i', $window, $m ) ) {
					foreach ( $m[2] as $prefix ) {
						$add( $prefixes, $prefix, $file );
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
						$add( $prefixes, $prefix, $file );
					}
				}
				if ( preg_match_all( '/(ffc-[a-z0-9]+(?:-{1,2}[a-z0-9]+)*-{1,2})%[0-9]*\$?[sd]/i', $window, $m ) ) {
					foreach ( $m[1] as $prefix ) {
						$add( $prefixes, $prefix, $file );
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
	 * batizada `rowClass`. A janela é generosa (240 caracteres para cada lado)
	 * porque o objetivo aqui é **não perder site**, não ser exato: quem lê a
	 * saída é uma pessoa prestes a renomear, e um falso positivo custa uma
	 * olhada enquanto um falso negativo custa um estilo que some.
	 *
	 * @param string $src Conteúdo do arquivo.
	 * @return array<int, string>
	 */
	private static function near_class_context( string $src ): array {
		$out = array();

		if ( ! preg_match_all( '/[A-Za-z]*class(?:List|Name)?[A-Za-z]*/i', $src, $m, PREG_OFFSET_CAPTURE ) ) {
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
