<?php
/**
 * Dark-mode CSS guard (#1126).
 *
 * The dark mode is a token swap: `:root.ffc-dark-mode` redefines ~50 custom
 * properties and everything painted through `var(--ffc-*)` follows. A literal
 * colour inside a dark-mode rule opts that one declaration out of the swap and
 * is invisible in exactly one of the two themes — which is why the class keeps
 * coming back rather than being caught in review. It was found once in the
 * autosave badge (#1116, hardcoded hex so the badge ignored dark mode) and
 * measured as a population in #1126.
 *
 * This guard is deliberately narrow: it covers the dark-mode override block
 * this repository owns, not every admin stylesheet. The wider sweep — seven
 * admin stylesheets with zero tokens between them — is the second half of
 * #1126, and gets its own guard with a per-file allowlist when those are
 * converted. A guard that fails on work not yet done teaches people to skip it.
 *
 * Dependency-free on purpose — no WordPress, no Brain\Monkey — like the module
 * boundary and settings-defaults guards. It reads source text only.
 *
 * @package FreeFormCertificate\Tests\Unit
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class DarkModeCssTest extends TestCase {

	/**
	 * Absolute path to the stylesheet that owns the dark mode.
	 */
	private static function stylesheet(): string {
		return dirname( __DIR__, 2 ) . '/assets/css/ffc-common.css';
	}

	/**
	 * Every rule whose selector mentions `.ffc-dark-mode`, as raw text.
	 *
	 * Returns the selector plus its declaration block, so a caller can report
	 * which rule is at fault rather than just that one exists.
	 *
	 * @return array<int, array{selector: string, body: string}>
	 */
	public static function dark_mode_rules(): array {
		$css = (string) file_get_contents( self::stylesheet() );

		// Strip comments first: the block above talks about `#1d2327` in prose,
		// and a scanner that reads its own documentation as a violation is
		// worse than no scanner.
		$css = (string) preg_replace( '#/\*.*?\*/#s', '', $css );

		if ( ! preg_match_all( '/([^{}]*\.ffc-dark-mode[^{}]*)\{([^}]*)\}/s', $css, $matches, PREG_SET_ORDER ) ) {
			return array();
		}

		$out = array();
		foreach ( $matches as $match ) {
			$out[] = array(
				'selector' => trim( (string) $match[1] ),
				'body'     => (string) $match[2],
			);
		}

		return $out;
	}

	/**
	 * A dark-mode rule may not name a colour literally.
	 *
	 * The one exception is the token block itself — `:root.ffc-dark-mode { … }`
	 * with nothing but custom-property declarations — which is where the
	 * literals are supposed to live. It is recognised by what it declares, not
	 * by its position in the file, so moving it does not silently exempt
	 * something else.
	 */
	public function test_no_dark_mode_rule_paints_with_a_literal_colour(): void {
		$offenders = array();

		foreach ( self::dark_mode_rules() as $rule ) {
			foreach ( explode( ';', $rule['body'] ) as $declaration ) {
				$declaration = trim( $declaration );
				if ( '' === $declaration ) {
					continue;
				}

				// The token definitions are the intended home for literals.
				if ( strpos( $declaration, '--ffc-' ) === 0 ) {
					continue;
				}

				if ( preg_match( '/#[0-9a-fA-F]{3,8}\b|\brgba?\(|\bhsla?\(/', $declaration ) ) {
					$offenders[] = $rule['selector'] . ' → ' . $declaration;
				}
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			"A dark-mode rule names a colour literally, so it will not follow the theme:\n  "
			. implode( "\n  ", $offenders )
			. "\n\nUse a var(--ffc-*) token. If the palette has no role for it, add the token"
			. "\nto BOTH the light and the dark block — never only to the dark one."
		);
	}

	/**
	 * The guard's own self-check.
	 *
	 * `assertSame( array(), $offenders )` is satisfied by a scan that found
	 * nothing to look at, which is the failure mode #1094 found in four guards
	 * at once. The override block is ~40 rules; the floor is set well under
	 * that so ordinary edits do not trip it, but a collapsed regex does.
	 */
	public function test_the_scan_sees_the_override_block(): void {
		$rules = self::dark_mode_rules();

		$this->assertGreaterThan(
			20,
			count( $rules ),
			'The dark-mode scan collapsed — check the regex and that the block still exists.'
		);

		$selectors = implode( ' ', array_column( $rules, 'selector' ) );

		// Two landmarks from the two halves: the token block, and the core
		// override that the 6.24.0 smoke reported as invisible.
		$this->assertStringContainsString( ':root.ffc-dark-mode', $selectors );
		$this->assertStringContainsString( '.form-table th', $selectors, 'The label override is what #1126 was opened for.' );
	}

	/**
	 * The token block must define both grounds it is asked for.
	 *
	 * Every override above resolves `--ffc-text` and `--ffc-bg-card`; a rename
	 * that dropped one would leave those declarations resolving to nothing,
	 * which renders as *inherited*, not as an error.
	 */
	public function test_the_tokens_the_overrides_depend_on_are_defined(): void {
		$css = (string) file_get_contents( self::stylesheet() );

		foreach ( array( '--ffc-text', '--ffc-text-muted', '--ffc-bg-alt', '--ffc-bg-card', '--ffc-bg-input', '--ffc-border' ) as $token ) {
			$this->assertMatchesRegularExpression(
				'/\.ffc-dark-mode\s*\{[^}]*' . preg_quote( $token, '/' ) . '\s*:/s',
				$css,
				"The dark palette does not define {$token}, which the core overrides read."
			);
		}
	}

	// ==================================================================
	// Contraste medido (#1132)
	// ==================================================================
	//
	// O guarda acima vê PRESENÇA — se a declaração usa token ou literal.
	// Não vê se o par resultante é legível, e foi por aí que dez pares
	// chegaram ao repositório reprovando o WCAG AA, o `on-primary` do tema
	// escuro entre eles: branco sobre a primária clara, 2,52:1, desde o dia
	// em que o tema escuro foi escrito. Nada media, então nada acusou.
	//
	// Isto calcula a razão a partir do próprio CSS. Bloqueia em zero.

	/**
	 * Pares que o CSS realmente pinta, com o piso de cada um.
	 *
	 * O piso não é uma opinião: 4,5:1 é o mínimo do WCAG AA para texto
	 * normal, 3:1 para o que identifica um componente (SC 1.4.11) — e é o
	 * mesmo piso que o Material 3 adota. Um par que não aparece na tela não
	 * entra aqui; a lista descreve composições reais, não o produto
	 * cartesiano dos tokens.
	 *
	 * @return array<int, array{0: string, 1: string, 2: float, 3: string}>
	 */
	private static function pairs(): array {
		return array(
			array( '--ffc-text', '--ffc-bg', 4.5, 'texto sobre o fundo' ),
			array( '--ffc-text', '--ffc-bg-alt', 4.5, 'texto sobre o fundo alternado' ),
			array( '--ffc-text', '--ffc-bg-card', 4.5, 'texto sobre card' ),
			array( '--ffc-text', '--ffc-bg-input', 4.5, 'texto dentro de um campo' ),
			array( '--ffc-text-secondary', '--ffc-bg-card', 4.5, 'texto secundário sobre card' ),
			array( '--ffc-text-muted', '--ffc-bg-card', 4.5, 'descrição sobre card' ),
			array( '--ffc-text-muted', '--ffc-bg-alt', 4.5, 'descrição sobre o fundo alternado' ),
			array( '--ffc-text-light', '--ffc-bg-input', 4.5, 'placeholder dentro de um campo' ),
			array( '--ffc-link', '--ffc-bg-card', 4.5, 'link sobre card' ),
			array( '--ffc-text-on-primary', '--ffc-primary', 4.5, 'rótulo do botão primário' ),
			array( '--ffc-success-text', '--ffc-success-bg', 4.5, 'texto de sucesso' ),
			array( '--ffc-warning-text', '--ffc-warning-bg', 4.5, 'texto de aviso' ),
			array( '--ffc-danger-text', '--ffc-danger-bg', 4.5, 'texto de perigo' ),
			array( '--ffc-info-text', '--ffc-info-bg', 4.5, 'texto informativo' ),
			// Pares que as sete folhas de admin passaram a pintar (#1126 B).
			// Duas delas já reprovavam antes da conversão: --ffc-danger como
			// texto sobre card (4,29:1 no escuro) e o rótulo branco do botão
			// .ffc-btn-success (3,35:1 no claro). Nenhum guarda media isso.
			array( '--ffc-text-secondary', '--ffc-bg-alt', 4.5, 'badge de estado neutro' ),
			array( '--ffc-text-muted', '--ffc-bg-alt', 4.5, 'badge de estado encerrado' ),
			array( '--ffc-text-secondary', '--ffc-bg-card', 4.5, 'rótulo dentro do modal' ),
			array( '--ffc-primary-hover', '--ffc-primary-light', 4.5, 'badge "enviado"' ),
			array( '--ffc-success-text', '--ffc-bg-card', 4.5, 'estado positivo como texto' ),
			array( '--ffc-danger-text', '--ffc-bg-card', 4.5, 'link de exclusão' ),
			array( '--ffc-danger', '--ffc-bg-card', 4.5, 'perigo como texto sobre card' ),
			array( '--ffc-text-light', '--ffc-bg-card', 4.5, 'vazio dentro do modal' ),
			array( '--ffc-primary', '--ffc-bg-card', 4.5, 'primária como texto sobre card' ),
			array( '--ffc-text-on-primary', '--ffc-primary-hover', 4.5, 'botão primário sob o mouse' ),
			array( '--ffc-text-on-danger', '--ffc-danger', 4.5, 'rótulo do botão destrutivo' ),
			array( '--ffc-text-on-danger', '--ffc-danger-hover', 4.5, 'botão destrutivo sob o mouse' ),
			array( '--ffc-text-on-success', '--ffc-success', 4.5, 'rótulo do botão de sucesso' ),
			array( '--ffc-text-on-success', '--ffc-success-hover', 4.5, 'botão de sucesso sob o mouse' ),
			// O rótulo do botão de aviso era branco sobre --ffc-warning: 3,04:1
			// no tema claro, desde sempre, e nada media (#1126, 2ª passada).
			array( '--ffc-text-on-warning', '--ffc-warning', 4.5, 'rótulo do botão de aviso' ),
			// Pares do smoke da 6.24.0: a linha cancelada da agenda e o rótulo
			// de aviso usado como TEXTO (--ffc-warning é cor de sinal, piso 3:1,
			// e dava 3,04:1 sobre branco quando usado em .ffc-text-warning).
			array( '--ffc-text-muted', '--ffc-danger-bg', 4.5, 'linha cancelada da agenda' ),
			array( '--ffc-warning-text', '--ffc-bg', 4.5, 'aviso como texto' ),
			array( '--ffc-warning-text', '--ffc-bg-card', 4.5, 'aviso como texto sobre card' ),
			array( '--ffc-inverse-on-surface', '--ffc-inverse-surface', 4.5, 'balão de toast' ),
			// Par de base (#1126, 5ª passada). O texto que não declara cor
			// herda de FORA daqui — do `body { color: #3c434a }` do core no
			// admin, do tema numa página pública — e cai em 1,28:1 sobre um
			// fundo escuro. A regra de base o traz para --ffc-text; estes são
			// os fundos que ela precisa cobrir, agora medidos como qualquer
			// outro par em vez de dependerem de herança.
			array( '--ffc-text', '--ffc-gray-100', 4.5, 'texto herdado sobre o cabeçalho do calendário' ),
			array( '--ffc-text', '--ffc-gray-50', 4.5, 'texto herdado sobre a superfície mais rasa' ),
			// Não-texto: o contorno que identifica o componente, e as cores
			// de estado usadas como sinal (o ponto colorido de um badge).
			array( '--ffc-border', '--ffc-bg', 3.0, 'contorno sobre o fundo' ),
			array( '--ffc-border', '--ffc-bg-card', 3.0, 'contorno sobre card' ),
			array( '--ffc-border', '--ffc-bg-input', 3.0, 'contorno de campo' ),
			array( '--ffc-border', '--ffc-bg-alt', 3.0, 'contorno sobre o fundo alternado' ),
			array( '--ffc-primary', '--ffc-bg', 3.0, 'primária como sinal' ),
			array( '--ffc-danger', '--ffc-bg', 3.0, 'perigo como sinal' ),
			array( '--ffc-success', '--ffc-bg', 3.0, 'sucesso como sinal' ),
			array( '--ffc-warning', '--ffc-bg', 3.0, 'aviso como sinal' ),
			array( '--ffc-info', '--ffc-bg', 3.0, 'info como sinal' ),
		);
	}

	/**
	 * Os tokens de cor de um dos dois temas.
	 *
	 * O tema escuro é o claro **sobrescrito**, não um conjunto próprio: o
	 * bloco `:root.ffc-dark-mode` só redefine parte dos tokens, e o resto
	 * segue valendo. Ler o bloco escuro isolado mediria um tema que não
	 * existe — daí a mesclagem.
	 *
	 * @param string $theme 'light' ou 'dark'.
	 * @return array<string, string> Token => valor.
	 */
	private static function palette( string $theme ): array {
		$css = (string) preg_replace( '#/\*.*?\*/#s', '', (string) file_get_contents( self::stylesheet() ) );

		$read = static function ( string $selector ) use ( $css ): array {
			if ( ! preg_match( '/' . preg_quote( $selector, '/' ) . '\s*\{(.*?)\n\}/s', $css, $m ) ) {
				return array();
			}
			$out = array();
			foreach ( explode( ';', $m[1] ) as $declaration ) {
				$parts = explode( ':', $declaration, 2 );
				if ( count( $parts ) === 2 && strpos( trim( $parts[0] ), '--ffc-' ) === 0 ) {
					$out[ trim( $parts[0] ) ] = trim( $parts[1] );
				}
			}
			return $out;
		};

		$light = $read( ':root' );
		return 'dark' === $theme ? array_merge( $light, $read( ':root.ffc-dark-mode' ) ) : $light;
	}

	/**
	 * `#rgb`, `#rrggbb` ou `rgba()` para [r, g, b]; null para o resto.
	 *
	 * @param string $value Valor CSS.
	 * @return array{0: int, 1: int, 2: int}|null
	 */
	private static function to_rgb( string $value ): ?array {
		if ( preg_match( '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', trim( $value ), $m ) ) {
			$hex = $m[1];
			if ( strlen( $hex ) === 3 ) {
				$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
			}
			return array(
				(int) hexdec( substr( $hex, 0, 2 ) ),
				(int) hexdec( substr( $hex, 2, 2 ) ),
				(int) hexdec( substr( $hex, 4, 2 ) ),
			);
		}

		if ( preg_match( '/^rgba?\(\s*([0-9]+)\s*,\s*([0-9]+)\s*,\s*([0-9]+)/', trim( $value ), $m ) ) {
			return array( (int) $m[1], (int) $m[2], (int) $m[3] );
		}

		return null;
	}

	/**
	 * Razão de contraste do WCAG 2.x entre duas cores.
	 *
	 * @param array{0: int, 1: int, 2: int} $a Primeira cor.
	 * @param array{0: int, 1: int, 2: int} $b Segunda cor.
	 */
	private static function contrast( array $a, array $b ): float {
		$luminance = static function ( array $c ): float {
			$channel = static function ( int $v ): float {
				$s = $v / 255;
				return $s <= 0.03928 ? $s / 12.92 : pow( ( $s + 0.055 ) / 1.055, 2.4 );
			};
			return 0.2126 * $channel( $c[0] ) + 0.7152 * $channel( $c[1] ) + 0.0722 * $channel( $c[2] );
		};

		$la = $luminance( $a );
		$lb = $luminance( $b );

		return ( max( $la, $lb ) + 0.05 ) / ( min( $la, $lb ) + 0.05 );
	}

	/**
	 * Todo par pintado precisa atingir o piso do WCAG AA, nos dois temas.
	 *
	 * @dataProvider provider_themes
	 * @param string $theme Nome do tema.
	 */
	public function test_every_painted_pair_meets_its_contrast_floor( string $theme ): void {
		$palette  = self::palette( $theme );
		$failures = array();

		foreach ( self::pairs() as list( $fg, $bg, $floor, $what ) ) {
			$a = self::to_rgb( $palette[ $fg ] ?? '' );
			$b = self::to_rgb( $palette[ $bg ] ?? '' );

			$this->assertNotNull( $a, "Token {$fg} ausente ou ilegível no tema {$theme}." );
			$this->assertNotNull( $b, "Token {$bg} ausente ou ilegível no tema {$theme}." );

			$ratio = self::contrast( $a, $b );
			if ( $ratio < $floor ) {
				$failures[] = sprintf(
					'%s: %s sobre %s = %.2f:1, mínimo %.1f:1  (%s / %s)',
					$what,
					$palette[ $fg ],
					$palette[ $bg ],
					$ratio,
					$floor,
					$fg,
					$bg
				);
			}
		}

		$this->assertSame(
			array(),
			$failures,
			"Pares abaixo do piso do WCAG AA no tema {$theme}:\n  " . implode( "\n  ", $failures )
			. "\n\nAjuste a LUMINOSIDADE do token preservando o matiz, e confira contra TODOS os"
			. "\nfundos em que ele aparece — um token costuma ser pintado sobre mais de um."
		);
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function provider_themes(): array {
		return array(
			'claro'  => array( 'light' ),
			'escuro' => array( 'dark' ),
		);
	}

	/**
	 * Autoverificação do medidor.
	 *
	 * `assertSame( array(), $failures )` também é satisfeito por uma paleta
	 * que não foi lida — a forma que o #1094 achou em quatro guardas de uma
	 * vez. Aqui a checagem é dupla: a paleta precisa ter tamanho plausível,
	 * o tema escuro precisa de fato diferir do claro, e o cálculo precisa
	 * reproduzir dois valores conhecidos.
	 */
	public function test_the_meter_cannot_collapse_in_silence(): void {
		$light = self::palette( 'light' );
		$dark  = self::palette( 'dark' );

		$this->assertGreaterThan( 30, count( $light ), 'A paleta clara não foi lida.' );
		$this->assertGreaterThan( 30, count( $dark ), 'A paleta escura não foi lida.' );
		$this->assertNotSame( $light, $dark, 'O tema escuro leu igual ao claro — a mesclagem quebrou.' );

		// Preto sobre branco é 21:1 e branco sobre branco é 1:1, por definição.
		$this->assertEqualsWithDelta( 21.0, self::contrast( array( 0, 0, 0 ), array( 255, 255, 255 ) ), 0.01 );
		$this->assertEqualsWithDelta( 1.0, self::contrast( array( 255, 255, 255 ), array( 255, 255, 255 ) ), 0.01 );
	}
}

