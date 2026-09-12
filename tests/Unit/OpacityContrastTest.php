<?php
/**
 * Catraca de `opacity` sobre texto (#1126, #1170).
 *
 * O `DarkModeCssTest` mede pares de cor e é cego para esta classe de defeito
 * **por construção**: `opacity` desbota o texto E o fundo juntos, então ela
 * multiplica para baixo qualquer contraste que os tokens tenham garantido
 * enquanto os tokens continuam certos. É a lição 5 do arco do tema — "diga
 * estado com cor, não com desbotamento" — e foi medida de novo aqui: a linha
 * passada da tabela de recadastração dava **3,11:1** no tema claro e **4,23:1**
 * no escuro a `opacity: 0.75`; sem ela, 5,15:1 e 6,37:1.
 *
 * A guarda congela toda declaração de `opacity` abaixo de 1 nas folhas, por
 * folha e por seletor, com a razão de cada uma. Catraca nos dois sentidos: uma
 * declaração nova falha (justifique ou use cor), e uma que sumiu também (o
 * ganho fica travado).
 *
 * **Ela não sabe ler contraste** — não tem como: o que `opacity` faz depende do
 * que está ATRÁS do elemento, que é DOM, não folha. O que ela garante é que
 * nenhuma entra sem alguém ter olhado. As categorias legítimas são três:
 *
 *  - **componente inativo** (`:disabled`, `[disabled]`, linha desativada): a
 *    SC 1.4.3 isenta texto de componente inativo, a mesma razão que o
 *    `DarkModeCssTest::DERIVED_EXCEPTIONS` já usa;
 *  - **estado transitório** (carregando, `:hover`): não é o estado de repouso
 *    que alguém lê;
 *  - **`opacity: 0`**: o elemento não é pintado — esconder não é desbotar.
 *
 * Fora dessas, `opacity` sobre texto de repouso é o defeito.
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
final class OpacityContrastTest extends TestCase {

	/**
	 * Toda declaração de `opacity` abaixo de 1, com a razão.
	 *
	 * Folha => seletor => razão. O seletor é o do primeiro seletor da regra,
	 * normalizado em espaços simples.
	 */
	private const ALLOWED = array(
		'ffc-admin-settings.css'          => array(
			'.ffc-settings-tabs__external' => 'ícone de link externo, glifo decorativo ao lado do rótulo',
			'.ffc-settings-back-to-top'    => 'botão flutuante de voltar ao topo; o rótulo é um glifo',
		),
		'ffc-admin-submission-edit.css'   => array(
			'.ffc-consent-header:hover' => 'realimentação de :hover, estado transitório',
		),
		'ffc-admin.css'                   => array(
			'#ffc-preview-modal' => 'opacity: 0 — o modal fechado não é pintado',
		),
		'ffc-audience-admin.css'          => array(
			'.ffc-selected-user .ffc-selected-user-remove' => 'o × de remover, glifo decorativo',
		),
		'ffc-audience.css'                => array(
			'.ffc-shortcode .ffc-day.ffc-other-month'  => 'dia do mês vizinho: componente inativo, não é clicável',
			'.ffc-shortcode .ffc-booking-cancelled'    => 'reserva cancelada no calendário público — o texto NÃO declara cor nossa, então o par não é nosso para medir (#1126 defeito 3); dar cor vem antes de tirar o desbotamento',
		),
		'ffc-calendar-frontend.css'       => array(
			'.ffc-shortcode .ffc-timeslot-available' => 'contagem de vagas dentro do horário — mesmo caso: sem cor declarada nossa',
		),
		'ffc-certificates-dashboard.css'  => array(
			'.ffc-certificates-submissions-link'        => 'link secundário do card, glifo + contagem',
			'.ffc-calendar-core .ffc-day.ffc-other-month' => 'dia do mês vizinho: componente inativo',
		),
		'ffc-common.css'                  => array(
			'.ffc-loading'                                            => 'estado de carregamento, transitório',
			'.ffc-form button[type="submit"]:disabled'                => 'componente inativo, isento pela SC 1.4.3',
			'.ffc-btn:disabled'                                       => 'componente inativo, isento pela SC 1.4.3',
			'.ffc-toggle input[type="checkbox"]'                      => 'opacity: 0 — o input real fica invisível sob a trilha desenhada',
			'.ffc-toggle input[type="checkbox"]:disabled + .ffc-toggle-track' => 'componente inativo, isento pela SC 1.4.3',
			'#ffc-activity-log-table.ffc-loading'                     => 'estado de carregamento, transitório',
		),
		'ffc-custom-fields-admin.css'     => array(
			'.ffc-custom-field-row.ffc-field-inactive' => 'campo desativado: componente inativo, isento pela SC 1.4.3',
		),
		'ffc-frontend.css'                => array(
			'.ffc-submit-btn.ffc-btn-loading' => 'estado de carregamento, transitório',
			'.ffc-public-csv-download .ffc-info-btn-primary[disabled], .ffc-open-early-modal .ffc-info-btn-primary[disabled], .ffc-extend-end-modal .ffc-info-btn-primary[disabled]'   => 'componente inativo, isento pela SC 1.4.3',
			'.ffc-public-csv-download .ffc-info-btn-secondary[disabled], .ffc-open-early-modal .ffc-info-btn-secondary[disabled], .ffc-extend-end-modal .ffc-info-btn-secondary[disabled]' => 'componente inativo, isento pela SC 1.4.3',
			'.ffc-public-csv-download .ffc-info-btn-warning[disabled], .ffc-open-early-modal .ffc-info-btn-warning[disabled], .ffc-extend-end-modal .ffc-info-btn-warning[disabled]'   => 'componente inativo, isento pela SC 1.4.3',
			'#ffc-preview-modal' => 'opacity: 0 — o modal fechado não é pintado',
		),
		'ffc-pdf-core.css'                => array(
			'.ffc-btn-loading' => 'estado de carregamento, transitório',
		),
		'ffc-reregistration-frontend.css' => array(
			'.ffc-rereg-header-subtitle' => 'subtítulo do cabeçalho — o texto não declara cor nossa, então o par não é nosso para medir (#1126 defeito 3)',
		),
		'ffc-url-shortener-admin.css'     => array(
			'.ffc-shorturl-toast' => 'opacity: 0 — o aviso só aparece via animação',
		),
		'ffc-user-dashboard.css'          => array(
			'.ffc-audience-join-item .button.button-primary:disabled' => 'componente inativo, isento pela SC 1.4.3',
		),
		'ffc-user-permissions.css'        => array(
			'.ffc-cap-role[disabled]' => 'componente inativo, isento pela SC 1.4.3',
		),
	);

	/**
	 * Cada declaração de `opacity` abaixo de 1, por folha.
	 *
	 * @return array<string, array<string, string>> Folha => seletor => valor.
	 */
	private static function found(): array {
		$out = array();

		foreach ( CssSelectors::sheets() as $path ) {
			$sheet = basename( $path );

			foreach ( CssSelectors::rules( (string) file_get_contents( $path ) ) as $rule ) {
				$body = (string) preg_replace( '~/\*.*?\*/~s', '', $rule['body'] );

				if ( ! preg_match( '/(?<![-\w])opacity\s*:\s*(0(?:\.\d+)?)\s*(?:;|$)/', $body, $m ) ) {
					continue;
				}
				if ( 1.0 <= (float) $m[1] ) {
					continue;
				}

				$selector = trim( (string) preg_replace( '/\s+/', ' ', $rule['selector'] ) );
				$out[ $sheet ][ $selector ] = $m[1];
			}
		}

		return $out;
	}

	/**
	 * Nenhuma declaração nova de `opacity` entra sem razão escrita.
	 */
	public function test_no_new_opacity_fades_text(): void {
		$new = array();

		foreach ( self::found() as $sheet => $rules ) {
			foreach ( $rules as $selector => $value ) {
				if ( ! isset( self::ALLOWED[ $sheet ][ $selector ] ) ) {
					$new[] = sprintf( '%s: %s { opacity: %s }', $sheet, $selector, $value );
				}
			}
		}

		$this->assertSame(
			array(),
			$new,
			"`opacity` nova, sem razão escrita:\n  " . implode( "\n  ", $new )
			. "\n\nO medidor de pares NÃO enxerga isto: o desbotamento multiplica o contraste"
			. "\npara baixo enquanto os tokens continuam certos. Diga estado com COR."
			. "\nSe for componente inativo, estado transitório ou `opacity: 0`, acrescente"
			. "\na ALLOWED com a razão."
		);
	}

	/**
	 * Uma entrada que sumiu das folhas sai da lista.
	 */
	public function test_the_list_only_shrinks(): void {
		$found = self::found();
		$stale = array();

		foreach ( self::ALLOWED as $sheet => $rules ) {
			foreach ( array_keys( $rules ) as $selector ) {
				if ( ! isset( $found[ $sheet ][ $selector ] ) ) {
					$stale[] = $sheet . ': ' . $selector;
				}
			}
		}

		$this->assertSame(
			array(),
			$stale,
			"Estas não existem mais — tire-as de ALLOWED para travar o ganho:\n  " . implode( "\n  ", $stale )
		);
	}

	/**
	 * Toda razão diz alguma coisa.
	 *
	 * Piso contra "ok" / "ver acima" — nunca uma régua de qualidade, o mesmo
	 * critério das guardas de supressão (#1027 / #1035).
	 */
	public function test_every_reason_says_something(): void {
		$short = array();

		foreach ( self::ALLOWED as $sheet => $rules ) {
			foreach ( $rules as $selector => $reason ) {
				if ( 20 > strlen( $reason ) ) {
					$short[] = $sheet . ': ' . $selector;
				}
			}
		}

		$this->assertSame( array(), $short, "Razão curta demais:\n  " . implode( "\n  ", $short ) );
	}

	/**
	 * A varredura não pode colapsar em silêncio.
	 *
	 * Um mapa vazio satisfaz o primeiro teste tão bem quanto um mapa correto —
	 * a forma do #1071 / #1094.
	 */
	public function test_the_scan_cannot_collapse_in_silence(): void {
		$found = self::found();
		$total = 0;
		foreach ( $found as $rules ) {
			$total += count( $rules );
		}

		$this->assertGreaterThan( 20, $total, 'A varredura de `opacity` colapsou.' );
		$this->assertGreaterThan( 20, count( CssSelectors::sheets() ), 'A leitura das folhas colapsou.' );

		// E a tabela que motivou a guarda não pode voltar a desbotar.
		$this->assertArrayNotHasKey(
			'.ffc-reregistrations-table.ffc-table-past',
			$found['ffc-user-dashboard.css'] ?? array(),
			'A linha passada da recadastração voltou a usar `opacity` — era 3,11:1 no tema claro.'
		);
	}
}
