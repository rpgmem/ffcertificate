<?php
/**
 * Ids que o JavaScript procura, e quem os emite.
 *
 * @package FreeFormCertificate\Tests
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Support;

/**
 * Cruza o id que o JS PROCURA com o id que alguém EMITE (#1220).
 *
 * A classe de defeito: uma fixture escrita à mão envelhece em silêncio. Quando
 * o produto muda, ela segue verde descrevendo um mundo que não existe mais --
 * foi assim que o painel do usuário passou a não carregar painel nenhum
 * (#1204, `.ffc-tab.active` após a renomeação do #1170) e que o grupo de
 * acúmulo nunca se ocultou (#1219, `#ffc_rereg_acumulo` que nenhum PHP emite).
 *
 * **Por que não basta varrer PHP.** Medido: dos ids que o JS procura, 42 não
 * aparecem em PHP nenhum -- e a maioria é DOM que o **próprio JS cria**
 * (`#ffc-pdf-overlay`, `#ffc-template-modal`, `#ffc-migrations-overlay`…).
 * Uma guarda que só olhasse PHP reportaria dezenas de falsos e seria desligada
 * na primeira semana. A varredura de emissão cobre PHP **e** JS, exatamente
 * como o `CssClassEmitters` já faz para classes.
 *
 * **Quatro formas de emissão que a primeira versão não conhecia**, cada uma
 * encontrada por um falso positivo que ela produziu:
 *
 * 1. `wp_nonce_field( $acao, 'nome' )` emite `id="nome"` -- o id está no
 *    SEGUNDO argumento, e o primeiro é a ação, que tem outro valor. Três
 *    campos de nonce apareceram como órfãos por isso.
 * 2. Um literal guardado numa variável antes de virar id
 *    (`var inputId = 'x'; input.id = inputId`). Seguir a variável exigiria
 *    fluxo de dados; a varredura aceita o literal nu em JS como emissão
 *    possível, que é o mesmo lado para o qual o `CssClassEmitters` erra.
 * 3. Marcação do próprio WordPress (`#wpbody-content`, `#title`) -- exceção
 *    legítima, do mesmo tipo que as `VENDOR_CLASSES` do `ClassNamingIdiomTest`.
 * 4. Id montado em runtime, do lado da EMISSÃO (`id="linha-<?php echo ...`)
 *    e do lado do CONSUMO (`'#ffc-tabpanel-' + aba`). Um nome que nunca
 *    existe como literal só pode ser reconhecido por prefixo, a mesma
 *    limitação que o `CssClassEmitters` registra.
 *
 * **O que ela não vê**, e vale estar escrito: que a fixture de um teste
 * corresponda à marcação real. Nos três casos que a originaram o defeito
 * estava no produto E na fixture; isto enxerga só a metade do produto.
 */
final class JsIdSelectors {

	/** Diretórios varridos em busca de quem EMITE um id. */
	private const EMITTER_ROOTS = array( 'includes', 'templates', 'assets/js', 'libs/js' );

	/** Diretório varrido em busca de quem PROCURA um id. */
	private const CONSUMER_ROOT = 'assets/js';

	/**
	 * Um literal INTEIRO por vez, com escapes.
	 *
	 * Casar por alternância frouxa perde a paridade das aspas na primeira
	 * apóstrofe dentro de uma string com aspas duplas e lê o resto do arquivo
	 * deslocado -- a mesma razão pela qual `CssSelectors` precisa ser
	 * consciente de aspas.
	 */
	private const STRING_LITERAL = '/"((?:[^"\\\\]|\\\\.)*)"|\'((?:[^\'\\\\]|\\\\.)*)\'/';

	/** Chamadas que recebem um seletor como primeiro argumento. */
	private const LOOKUP = '/(?:\$\(|jQuery\(|\.find\(|\.closest\(|\.is\(|\.filter\(|\.not\(|\.parents\(|\.siblings\(|\.children\(|\.has\(|querySelector\(|querySelectorAll\()\s*/';

	/**
	 * @var array<string, array<int, string>>|null Consumo: id => arquivos.
	 */
	private static ?array $consumers = null;

	/**
	 * @var array{ids: array<string, bool>, prefixes: array<int, string>}|null
	 */
	private static ?array $emitters = null;

	private static function root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * @param array<int, string> $dirs
	 * @param string             $ext  Fragmento de regex da extensão.
	 * @return array<string, string> Caminho relativo => conteúdo.
	 */
	private static function files( array $dirs, string $ext ): array {
		$out = array();
		foreach ( $dirs as $dir ) {
			$base = self::root() . '/' . $dir;
			if ( ! is_dir( $base ) ) {
				continue;
			}
			$walk = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $base, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $walk as $file ) {
				$path = $file->getPathname();
				if ( ! preg_match( '/\.' . $ext . '$/', $path ) || str_contains( $path, '.min.' ) ) {
					continue;
				}
				$out[ str_replace( self::root() . '/', '', $path ) ] = (string) file_get_contents( $path );
			}
		}
		ksort( $out );

		return $out;
	}

	/**
	 * Os ids que o JS procura, por id.
	 *
	 * @return array<string, array<int, string>> Id (sem '#') => arquivos.
	 */
	public static function consumers(): array {
		if ( null !== self::$consumers ) {
			return self::$consumers;
		}

		$found = array();
		foreach ( self::files( array( self::CONSUMER_ROOT ), 'js' ) as $path => $src ) {
			// Um exemplo de uso dentro de docblock NÃO é uma busca. O
			// cabeçalho de `ffc-admin-autosave.js` documenta
			// `FFC.Admin.autoSaveField($('#admin_bypass_geo'), …)`, e sem
			// remover comentários a varredura o lê como consumo e cobra um
			// emissor de um id que nenhuma tela procura. É a mesma distinção
			// que o `CLAUDE.md` já faz para as anotações de supressão: prosa
			// que menciona o token não é o token.
			$src = self::strip_comments( $src );

			foreach ( self::selector_literals( $src ) as $selector ) {
				$id = self::simple_id( $selector );
				if ( null !== $id ) {
					$found[ $id ][] = $path;
				}
			}
			// `getElementById()` recebe o id SEM '#'; sem este ramo, metade
			// do consumo do painel não seria vista.
			if ( preg_match_all( '/getElementById\(\s*(["\'])([^"\']*)\1/', $src, $m, PREG_SET_ORDER ) ) {
				foreach ( $m as $one ) {
					$id = self::simple_id( '#' . $one[2] );
					if ( null !== $id ) {
						$found[ $id ][] = $path;
					}
				}
			}
		}

		foreach ( $found as $id => $paths ) {
			$found[ $id ] = array_values( array_unique( $paths ) );
		}
		ksort( $found );
		self::$consumers = $found;

		return $found;
	}

	/**
	 * Remove comentários de um fonte JS, preservando strings.
	 *
	 * Precisa ser consciente de aspas: o `//` de `'https://exemplo'` não abre
	 * comentário, e uma varredura ingênua apagaria o resto da linha -- e com
	 * ela qualquer busca que viesse depois.
	 */
	private static function strip_comments( string $src ): string {
		$out    = '';
		$len    = strlen( $src );
		$quote  = '';
		$i      = 0;

		while ( $i < $len ) {
			$ch   = $src[ $i ];
			$next = $i + 1 < $len ? $src[ $i + 1 ] : '';

			if ( '' !== $quote ) {
				$out .= $ch;
				if ( '\\' === $ch ) {
					$out .= $next;
					$i   += 2;
					continue;
				}
				if ( $ch === $quote ) {
					$quote = '';
				}
				++$i;
				continue;
			}

			if ( '"' === $ch || "'" === $ch || '`' === $ch ) {
				$quote = $ch;
				$out  .= $ch;
				++$i;
				continue;
			}

			if ( '/' === $ch && '/' === $next ) {
				while ( $i < $len && "\n" !== $src[ $i ] ) {
					++$i;
				}
				continue;
			}

			if ( '/' === $ch && '*' === $next ) {
				$end = strpos( $src, '*/', $i + 2 );
				$i   = false === $end ? $len : $end + 2;
				continue;
			}

			$out .= $ch;
			++$i;
		}

		return $out;
	}

	/**
	 * Literais que abrem imediatamente depois de uma chamada de busca.
	 *
	 * @return array<int, string>
	 */
	private static function selector_literals( string $src ): array {
		if ( ! preg_match_all( self::LOOKUP, $src, $m, PREG_OFFSET_CAPTURE ) ) {
			return array();
		}

		$out = array();
		foreach ( $m[0] as $hit ) {
			$tail = substr( $src, $hit[1] + strlen( $hit[0] ), 300 );
			if ( ! preg_match( self::STRING_LITERAL, $tail, $lm, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}
			// O literal tem de ser o PRIMEIRO argumento. Sem esta checagem,
			// `$( el ).attr( 'foo' )` contaria `'foo'` como seletor.
			if ( $lm[0][1] > 0 ) {
				continue;
			}
			$out[] = '' !== $lm[1][0] ? $lm[1][0] : $lm[2][0];
		}

		return $out;
	}

	/**
	 * O id de um seletor que é SÓ um id simples, ou null.
	 *
	 * Um literal terminado em `-` ou `_` é o começo de um nome montado em
	 * runtime (`'#ffc-tabpanel-' + aba`), não um id inteiro: cobrar emissor
	 * dele reportaria um órfão que nunca existiu como nome.
	 */
	private static function simple_id( string $selector ): ?string {
		if ( ! preg_match( '/^#([A-Za-z][A-Za-z0-9_-]*)$/', $selector, $m ) ) {
			return null;
		}
		if ( str_ends_with( $m[1], '-' ) || str_ends_with( $m[1], '_' ) ) {
			return null;
		}

		return $m[1];
	}

	/**
	 * Tudo que pode emitir um id.
	 *
	 * @return array{ids: array<string, bool>, prefixes: array<int, string>}
	 */
	private static function emitters(): array {
		if ( null !== self::$emitters ) {
			return self::$emitters;
		}

		$ids      = array();
		$prefixes = array();

		foreach ( self::files( self::EMITTER_ROOTS, '(php|js)' ) as $path => $src ) {
			$is_js = str_ends_with( $path, '.js' );

			// `id="foo"` na marcação.
			self::collect( '/\bid\s*=\s*(["\'])([A-Za-z][A-Za-z0-9_-]*)\1/', $src, 2, $ids );
			// `'id' => 'foo'` e `id: 'foo'`.
			self::collect( '/[\'"]?id[\'"]?\s*(?:=>|:)\s*(["\'])([A-Za-z][A-Za-z0-9_-]*)\1/', $src, 2, $ids );
			// `.attr( 'id', 'foo' )`.
			self::collect( '/\.attr\(\s*(["\'])id\1\s*,\s*(["\'])([A-Za-z][A-Za-z0-9_-]*)\2/', $src, 3, $ids );
			// `el.id = 'foo'`.
			self::collect( '/\.id\s*=\s*(["\'])([A-Za-z][A-Za-z0-9_-]*)\1/', $src, 2, $ids );
			// `wp_nonce_field( $acao, 'nome' )` -- o id é o SEGUNDO argumento.
			self::collect( '/wp_nonce_field\(\s*[^,]+,\s*(["\'])([A-Za-z][A-Za-z0-9_-]*)\1/', $src, 2, $ids );

			// Prefixo: nome seguido de eco PHP ou de concatenação.
			if ( preg_match_all( '/\bid\s*=\s*["\']([A-Za-z][A-Za-z0-9_-]*[-_])(?=\s*(?:\.|\+|<)|\{|\$)/', $src, $m, PREG_SET_ORDER ) ) {
				foreach ( $m as $one ) {
					$prefixes[] = $one[1];
				}
			}

			// Em JS um literal vira id por uma variável:
			// `var inputId = 'x'; input.id = inputId`. Aceitar QUALQUER
			// literal nu resolveria o caso, e foi o que a primeira versão
			// fez -- ao custo de inflar o conjunto de emissores de 578 para
			// 2.082 nomes e de engolir um achado real (`#admin_bypass_geo`,
			// cujo id emitido é `ffc_admin_bypass_geo`). A variável é
			// seguida por UM salto, que é o que o caso exige e nada mais.
			if ( $is_js ) {
				self::collect_via_variable( $src, $ids );
			}
		}

		self::$emitters = array(
			'ids'      => $ids,
			'prefixes' => array_values( array_unique( $prefixes ) ),
		);

		return self::$emitters;
	}

	/**
	 * Literal que chega a um id por uma variável, um salto.
	 *
	 * @param array<string, bool> $into
	 */
	private static function collect_via_variable( string $src, array &$into ): void {
		// Nomes que a seguir aparecem como id. Duas formas, e a segunda é a
		// que o caso real usa: `'<input id="' + inputId + '"'` -- o atributo
		// é ABERTO num literal e o nome chega pela variável, que é o espelho
		// exato da forma que o `CssClassEmitters` registra para classes.
		$as_id = array();
		$forms = array(
			'/(?:\.id\s*=\s*|\.attr\(\s*["\']id["\']\s*,\s*)([A-Za-z_$][A-Za-z0-9_$]*)\s*[;),]/',
			// Sem `\\?` aqui: numa string PHP entre aspas simples `\\?` colapsa
			// para `\?`, que o regex lê como um `?` LITERAL -- e aí o padrão
			// nunca casa. Custou uma medição.
			'/\bid\s*=\s*["\']["\']\s*\+\s*([A-Za-z_$][A-Za-z0-9_$]*)/',
		);
		foreach ( $forms as $form ) {
			if ( preg_match_all( $form, $src, $m, PREG_SET_ORDER ) ) {
				foreach ( $m as $one ) {
					$as_id[ $one[1] ] = true;
				}
			}
		}
		if ( empty( $as_id ) ) {
			return;
		}

		// E o literal que cada uma dessas variáveis recebeu.
		if ( preg_match_all( '/(?:var|let|const)\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*=\s*(["\'])([A-Za-z][A-Za-z0-9_-]*)\2/', $src, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $one ) {
				if ( isset( $as_id[ $one[1] ] ) ) {
					$into[ $one[3] ] = true;
				}
			}
		}
	}

	/**
	 * @param array<string, bool> $into
	 */
	private static function collect( string $pattern, string $src, int $group, array &$into ): void {
		if ( preg_match_all( $pattern, $src, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $one ) {
				$into[ $one[ $group ] ] = true;
			}
		}
	}

	/**
	 * Whether anything in the repository emits this id.
	 */
	public static function is_emitted( string $id ): bool {
		$e = self::emitters();
		if ( isset( $e['ids'][ $id ] ) ) {
			return true;
		}
		foreach ( $e['prefixes'] as $prefix ) {
			if ( str_starts_with( $id, $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Quantos ids distintos a varredura de emissão conhece.
	 *
	 * Existe para a autoverificação: uma varredura que voltou vazia não pode
	 * ser lida como "limpa" (a lição do #1071 / #1094).
	 */
	public static function emitted_count(): int {
		return count( self::emitters()['ids'] );
	}
}
