<?php
/**
 * Leitor de seletores das folhas de `assets/css/`.
 *
 * @package FreeFormCertificate\Tests
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Support;

/**
 * O parser de CSS compartilhado pelas guardas de folha de estilo.
 *
 * Existe pelo mesmo motivo que `.github/scripts/ffc-create-statements.php`:
 * duas guardas que medem a mesma coisa não podem discordar sobre o conjunto
 * medido. `CssNamespaceAnchorTest` (#1152) conta seletor sem âncora,
 * `StylesheetOwnershipTest` (#1162) conta classe declarada crua -- as duas
 * precisam do mesmo recorte de "o que é um seletor nesta folha".
 *
 * Três coisas que o parser precisa fazer e que um `([^{]+)\{` não faz:
 *
 * 1. **Pular prelúdio de at-rule e passo de `@keyframes`.** `0%` e `from` não
 *    são seletores; uma varredura que os lesse reportaria dívida em toda folha
 *    animada.
 * 2. **Não ler `{`, `}` ou `;` de dentro de string.** O `;` de
 *    `img[src^="data:image/png;base64"]` corta o seletor ao meio e produz uma
 *    entrada fantasma chamada `base64"]`.
 * 3. **Quebrar a lista na vírgula de nível zero**, para que um composto
 *    (`.a.b`) conte uma vez e uma lista (`.a, .b`) conte duas.
 */
final class CssSelectors {

	/**
	 * As folhas não minificadas de `assets/css/`, em ordem.
	 *
	 * @return array<int, string> Caminhos absolutos.
	 */
	public static function sheets(): array {
		$found = array();
		foreach ( glob( dirname( __DIR__, 2 ) . '/assets/css/*.css' ) ?: array() as $path ) {
			if ( ! str_ends_with( $path, '.min.css' ) ) {
				$found[] = $path;
			}
		}

		return $found;
	}

	/**
	 * Regras de estilo de uma folha, com o corpo.
	 *
	 * Enxerga DENTRO de `@media` e `@supports`, como `of()` sempre enxergou —
	 * e isso não é detalhe. Um parser de uso único escrito para medir a
	 * exposição a `forced-colors` (#1165) só lia o nível de topo e enxergou
	 * 270 das 2.111 regras da base, porque o responsivo mora quase todo dentro
	 * de `@media`. O erro foi do script, não desta classe; ele está registrado
	 * aqui porque é a razão de esta função existir em vez de cada guarda
	 * escrever a sua.
	 *
	 * @param string $css Conteúdo da folha.
	 * @return array<int, array{selector: string, body: string}>
	 */
	public static function rules( string $css ): array {
		$out = array();
		foreach ( self::walk( $css ) as $rule ) {
			$out[] = $rule;
		}

		return $out;
	}

	/**
	 * Extrai os seletores de uma folha, um por entrada da lista.
	 *
	 * @param string $css Conteúdo da folha.
	 * @return array<int, string>
	 */
	public static function of( string $css ): array {
		$out = array();
		foreach ( self::walk( $css ) as $rule ) {
			foreach ( self::split_list( $rule['selector'] ) as $one ) {
				$out[] = $one;
			}
		}

		return $out;
	}

	/**
	 * Percorre as regras, devolvendo seletor bruto + corpo.
	 *
	 * @param string $css Conteúdo da folha.
	 * @return array<int, array{selector: string, body: string}>
	 */
	private static function walk( string $css ): array {
		$css   = (string) preg_replace( '#/\*.*?\*/#s', '', $css );
		$out   = array();
		$buf   = '';
		$stack = array();
		$quote = '';
		$len   = strlen( $css );

		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $css[ $i ];

			if ( '' !== $quote ) {
				$buf .= $ch;
				if ( '\\' === $ch && $i + 1 < $len ) {
					$buf .= $css[ ++$i ];
					continue;
				}
				if ( $ch === $quote ) {
					$quote = '';
				}
				continue;
			}

			if ( '"' === $ch || "'" === $ch ) {
				$quote = $ch;
				$buf  .= $ch;
				continue;
			}

			if ( '{' === $ch ) {
				$prelude = trim( $buf );
				$buf     = '';
				if ( str_starts_with( $prelude, '@' ) ) {
					$name    = strtolower( strtok( $prelude, " \t\n(" ) ?: '' );
					$stack[] = array( 'kind' => str_contains( $name, 'keyframes' ) ? 'keyframes' : 'at' );
					continue;
				}
				$parent  = end( $stack );
				$stack[] = array(
					'kind'     => ( false !== $parent && 'keyframes' === $parent['kind'] ) ? 'step' : 'rule',
					'selector' => $prelude,
				);
				continue;
			}

			if ( '}' === $ch ) {
				$frame = array_pop( $stack );
				if ( is_array( $frame ) && 'rule' === $frame['kind'] && '' !== $frame['selector'] ) {
					$out[] = array(
						'selector' => $frame['selector'],
						'body'     => $buf,
					);
				}
				$buf = '';
				continue;
			}

			$buf .= $ch;
		}

		return $out;
	}

	/**
	 * Quebra uma lista de seletores nas vírgulas de nível zero.
	 *
	 * @param string $list Prelúdio da regra.
	 * @return array<int, string>
	 */
	public static function split_list( string $list ): array {
		$parts = array();
		$cur   = '';
		$depth = 0;
		$quote = '';
		$len   = strlen( $list );

		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $list[ $i ];

			if ( '' !== $quote ) {
				$cur .= $ch;
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
			} elseif ( ')' === $ch ) {
				--$depth;
			}
			if ( ',' === $ch && 0 === $depth ) {
				$parts[] = $cur;
				$cur     = '';
				continue;
			}
			$cur .= $ch;
		}

		$parts[] = $cur;

		$clean = array();
		foreach ( $parts as $part ) {
			$part = trim( (string) preg_replace( '/\s+/', ' ', $part ) );
			if ( '' !== $part ) {
				$clean[] = $part;
			}
		}

		return $clean;
	}

	/**
	 * Nome da classe quando o seletor inteiro é uma classe só.
	 *
	 * "Crua" = uma classe, sem ancestral, sem segunda classe, sem elemento --
	 * apenas pseudo-classes e pseudo-elementos são tolerados. É a forma que
	 * alcança qualquer elemento que carregue a classe, venha ele de qual
	 * componente vier.
	 *
	 * @param string $selector Seletor único.
	 * @return string|null Nome da classe, ou null se não for declaração crua.
	 */
	public static function bare_class( string $selector ): ?string {
		$matched = preg_match(
			'/^\.(-?[_a-zA-Z][\w-]*)(?:::?[\w-]+(?:\([^)]*\))?)*$/',
			trim( $selector ),
			$m
		);

		return 1 === $matched ? $m[1] : null;
	}
}
