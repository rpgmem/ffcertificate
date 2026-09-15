<?php
/**
 * Cada `font-size` pinta pela escala, ou diz por que não.
 *
 * @package FreeFormCertificate\Tests
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Catraca de tipografia por folha (#1148 item 4).
 *
 * A escala existia desde sempre em `ffc-common.css`, era semântica e estava
 * certa — e tinha **zero** consumidores: 415 declarações `font-size` e nenhuma
 * lia um token. Isto é a adoção, folha a folha, no molde da catraca de cor:
 * o orçamento só pode encolher. Passar do orçamento falha (tokenize); ficar
 * ABAIXO dele também falha (uma folha foi convertida — tranque o ganho).
 *
 * Três coisas que a medição ensinou e que não se adivinha:
 *
 * 1. **O 12px não era deriva, era degrau faltando.** 69 usos, terceiro valor
 *    mais frequente da base, e nenhum token — a escala pulava de 11 para 13.
 *    Em quatro folhas ele é o passo responsivo do 13px. O degrau `xs` passou a
 *    ser 12px e o 11px virou `2xs`; renomear custou zero, porque não havia um
 *    único call site para atualizar.
 *
 * 2. **Dezessete dos dezoito casos de "mesmo seletor, dois tamanhos" são
 *    `@media`**, não deriva — a folha desce um degrau no celular. A hipótese
 *    oposta (deriva) era a intuitiva e estava errada; medir custou um script.
 *
 * 3. **Um ícone dimensionado por `font-size` não é tipografia.** É uma caixa
 *    de glifo (dashicons, `&times;`, o ícone do card de estatística), e a
 *    escala de texto não o governa. São exceções com o motivo escrito ao lado
 *    no CSS, não dívida silenciosa.
 *
 * O que esta guarda NÃO vê: se o degrau escolhido é o certo. `var(--ffc-font-
 * size-2xl)` num rótulo de 11px passa — ela mede tokenização, nunca acerto.
 *
 * Uma armadilha de tooling, porque ela já mordeu durante a própria conversão:
 * contar literal com `font-size\s*:\s*(?!var\()` **não funciona**. O `\s*` é
 * guloso e volta atrás para o lookahead passar, então toda declaração
 * tokenizada é contada como literal. Capture o valor e teste o começo dele.
 */
class TypographyTokensTest extends TestCase {

	/**
	 * Máximo de `font-size` literais por folha. Só pode encolher.
	 *
	 * `0` é o estado normal. Cada entrada diferente de zero é uma decisão com
	 * o motivo INLINE no CSS, ou uma folha ainda não convertida — e as duas
	 * estão distinguidas aqui, porque "não convertida" some quando a segunda
	 * etapa chegar e "decisão" fica.
	 *
	 * @var array<string, int>
	 */
	private const BUDGET = array(
		// Zero é o estado normal: a folha inteira pinta pela escala.
		'ffc-admin-move-submissions.css'   => 0,
		'ffc-admin-submission-edit.css'    => 0,
		'ffc-admin-submissions.css'        => 0,
		'ffc-admin-utilities.css'          => 0,
		'ffc-appointment-cancellation.css' => 0,
		'ffc-calendar-admin.css'           => 0,
		'ffc-calendar-editor.css'          => 0,
		'ffc-certificates-dashboard.css'   => 0,
		'ffc-custom-fields-admin.css'      => 0,
		'ffc-email-model.css'              => 0,
		'ffc-progress-overlay.css'         => 0,
		'ffc-recruitment-admin.css'        => 0,
		'ffc-recruitment-public.css'       => 0,
		'ffc-reregistration-frontend.css'  => 0,
		'ffc-url-shortener-admin.css'      => 0,
		'ffc-user-permissions.css'         => 0,
		'ffc-working-hours.css'            => 0,

		// Glifos: um ícone (dashicon, `&times;`, marca de sucesso) dimensionado
		// por font-size é uma caixa de glifo, não texto.
		'ffc-admin-settings.css'           => 1,
		'ffc-calendar-frontend.css'        => 1,
		'ffc-reregistration-admin.css'     => 1,
		'ffc-admin.css'                    => 3,
		'ffc-frontend.css'                 => 2,

		// Números-herói de card, deliberadamente acima da escala de texto —
		// mais o passo móvel que ficaria sem sentido se subisse ao piso.
		'ffc-audience-admin.css'           => 2,
		'ffc-user-dashboard.css'           => 2,

		// Selo que cabe dentro da célula do dia, com passo responsivo próprio.
		'ffc-audience.css'                 => 2,

		// Dois `em` relativos ao pai de propósito: o componente é solto em
		// contextos de tamanhos diferentes e acompanha cada um.
		'ffc-common.css'                   => 2,

		// `ffc-pdf-core.css` fica inteira literal, por duas razões distintas.
		// Os seis h1–h6 redeclaram os tamanhos padrão do agente de usuário
		// (2em … 0.75em) para PRESERVÁ-LOS dentro do wrapper: são relativos ao
		// pai de propósito, porque o corpo do certificado define o próprio
		// tamanho-base e os títulos acompanham. E o `font-size` do wrapper é
		// literal porque esta folha é a BASE da cadeia — enfileirada com
		// `array()`, sem dependência declarada de `ffc-common`, e com um segundo
		// enfileiramento no frontend. Ler um token que a página pode não ter
		// invalida a declaração inteira.
		'ffc-pdf-core.css'                 => 7,
	);

	/**
	 * Os sete degraus declarados, em ordem.
	 *
	 * @var array<int, string>
	 */
	private const STEPS = array( '2xs', 'xs', 'sm', 'base', 'lg', 'xl', '2xl' );

	/**
	 * Conta `font-size` por folha.
	 *
	 * @return array<string, array{literal: int, token: int}>
	 */
	private function scan(): array {
		$root  = dirname( __DIR__, 2 ) . '/assets/css/';
		$found = array();

		foreach ( glob( $root . '*.css' ) ?: array() as $path ) {
			if ( str_ends_with( $path, '.min.css' ) ) {
				continue;
			}

			$css = (string) preg_replace( '#/\*.*?\*/#s', '', (string) file_get_contents( $path ) );
			preg_match_all( '/font-size\s*:\s*([^;}\n]+)/', $css, $m );

			$literal = 0;
			$token   = 0;
			foreach ( $m[1] as $value ) {
				if ( str_starts_with( trim( $value ), 'var(' ) ) {
					++$token;
				} else {
					++$literal;
				}
			}

			if ( $literal || $token ) {
				$found[ basename( $path ) ] = array(
					'literal' => $literal,
					'token'   => $token,
				);
			}
		}

		return $found;
	}

	public function test_no_stylesheet_exceeds_its_literal_budget(): void {
		$over = array();
		foreach ( $this->scan() as $name => $counts ) {
			$budget = self::BUDGET[ $name ] ?? null;
			if ( null === $budget ) {
				$over[] = "{$name} não está no BUDGET — acrescente com a contagem medida (ou 0).";
				continue;
			}
			if ( $counts['literal'] > $budget ) {
				$over[] = "{$name}: {$counts['literal']} literais, orçamento {$budget}.";
			}
		}

		$this->assertSame(
			array(),
			$over,
			"Literal de `font-size` acima do orçamento. Use `var(--ffc-font-size-*)`; "
				. "se o valor for decisão (glifo de ícone, número-herói), deixe literal COM o "
				. "motivo ao lado e suba o orçamento:\n" . implode( "\n", $over )
		);
	}

	public function test_budgets_are_ratcheted_down_when_a_sheet_is_converted(): void {
		$slack = array();
		foreach ( $this->scan() as $name => $counts ) {
			$budget = self::BUDGET[ $name ] ?? null;
			if ( null !== $budget && $counts['literal'] < $budget ) {
				$slack[] = "{$name}: {$counts['literal']} literais, orçamento ainda {$budget}.";
			}
		}

		$this->assertSame(
			array(),
			$slack,
			"Uma folha melhorou e o orçamento não acompanhou — baixe-o para trancar o ganho:\n"
				. implode( "\n", $slack )
		);
	}

	/**
	 * Cada degrau é `max(<piso px>, <rem>)`, e as duas metades concordam a 16px.
	 *
	 * O `rem` sozinho resolve contra a raiz do DOCUMENTO, que no frontend é do
	 * tema do site: sob `html { font-size: 62.5% }` — idioma comum — a escala
	 * inteira encolhe, e o degrau `sm` mede 8,1px em vez de 13px. Medido em
	 * Chromium (#1157), não estimado. O piso anula isso; o `rem` no outro lado
	 * do `max()` preserva a preferência de fonte do navegador, que é o motivo
	 * de a escala ser `rem` em primeiro lugar.
	 *
	 * A igualdade a 16px é o que esta guarda mede de mais específico:
	 * `max(13px, 0.8125rem)` é uma IDENTIDADE naquele ponto, não um intervalo.
	 * Um `max(13px, 0.75rem)` digitado por engano passaria despercebido para
	 * sempre — as duas metades são plausíveis isoladamente, e a diferença só
	 * aparece renderizada, num tema que ninguém aqui roda.
	 *
	 * O que ela não vê: se o piso é o tamanho certo para aquele papel. Ela
	 * mede coerência da escala, nunca acerto de design.
	 *
	 * @return void
	 */
	public function test_every_step_floors_in_px_and_scales_in_rem(): void {
		$css = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/css/ffc-common.css' );

		$broken = array();
		$seen   = 0;

		foreach ( self::STEPS as $step ) {
			$found = preg_match(
				'/--ffc-font-size-' . preg_quote( $step, '/' ) . '\s*:\s*([^;]+);/',
				$css,
				$m
			);

			if ( ! $found ) {
				$broken[] = "{$step}: não declarado.";
				continue;
			}

			$value = trim( $m[1] );
			if ( ! preg_match( '/^max\(\s*([\d.]+)px\s*,\s*([\d.]+)rem\s*\)$/', $value, $parts ) ) {
				$broken[] = "{$step}: `{$value}` — a forma tem de ser `max(<px>, <rem>)`.";
				continue;
			}

			++$seen;
			$px  = (float) $parts[1];
			$rem = (float) $parts[2] * 16.0;
			if ( abs( $px - $rem ) > 0.001 ) {
				$broken[] = "{$step}: piso {$px}px, mas o rem vale {$rem}px numa raiz de 16px — as metades divergem.";
			}
		}

		$this->assertSame(
			array(),
			$broken,
			"A escala perdeu a forma que a torna segura sob o tema do site (#1157):\n" . implode( "\n", $broken )
		);
		$this->assertSame( count( self::STEPS ), $seen, 'A varredura da escala colapsou — nenhum degrau casou a forma.' );
	}

	public function test_the_scale_declares_exactly_the_seven_steps(): void {
		$css = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/css/ffc-common.css' );

		preg_match_all( '/--ffc-font-size-([\w]+)\s*:/', $css, $m );
		$declared = array_values( array_unique( $m[1] ) );

		$this->assertSame(
			self::STEPS,
			$declared,
			'A escala mudou de forma. Um degrau que some leva junto toda declaração que o lê — '
				. 'uma custom property não declarada INVALIDA a declaração inteira, não cai para o valor anterior.'
		);
	}

	public function test_every_token_read_names_a_declared_step(): void {
		$root    = dirname( __DIR__, 2 ) . '/assets/css/';
		$unknown = array();

		foreach ( glob( $root . '*.css' ) ?: array() as $path ) {
			if ( str_ends_with( $path, '.min.css' ) ) {
				continue;
			}
			$css = (string) preg_replace( '#/\*.*?\*/#s', '', (string) file_get_contents( $path ) );
			preg_match_all( '/var\(\s*--ffc-font-size-([\w]+)\s*[),]/', $css, $m );
			foreach ( $m[1] as $step ) {
				if ( ! in_array( $step, self::STEPS, true ) ) {
					$unknown[] = basename( $path ) . ": --ffc-font-size-{$step}";
				}
			}
		}

		$this->assertSame( array(), $unknown, 'Token de tipografia que ninguém declara — a declaração inteira é invalidada.' );
	}

	/**
	 * O auto-teste: uma varredura que colapsa passa em tudo acima achando nada.
	 *
	 * @return void
	 */
	public function test_the_scan_still_sees_the_stylesheets(): void {
		$found = $this->scan();

		$this->assertGreaterThan( 20, count( $found ), 'A varredura parou de enxergar folhas.' );

		$tokens = array_sum( array_column( $found, 'token' ) );
		$this->assertGreaterThan(
			100,
			$tokens,
			'A varredura não encontra mais declarações tokenizadas — o regex parou de casar, '
				. 'e os orçamentos passariam por vacuidade.'
		);
	}

	public function test_every_budget_entry_names_a_real_stylesheet(): void {
		$root = dirname( __DIR__, 2 ) . '/assets/css/';
		foreach ( array_keys( self::BUDGET ) as $name ) {
			$this->assertFileExists( $root . $name, "BUDGET cita {$name}, que não existe." );
		}
	}
}
