<?php
/**
 * Toda tela de admin que o plugin desenha diz, na marcação, que é nossa.
 *
 * @package FreeFormCertificate\Tests
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * A âncora de escopo de página (#1184, sub-issue da #1148).
 *
 * O `CLAUDE.md` chama isto de *"genuinely worth doing"* desde a #1167, e o que
 * falta é sempre a mesma coisa: a catraca da #1152 congela 41 seletores que não
 * nomeiam nada nosso, e os que sobraram depois da #1170 **não são classe nossa
 * mal nomeada** -- são `.tablenav`, `.column-*`, `.form-table`, `.button`,
 * `.card`, `code`: marcação que o WordPress emite e que não dá para prefixar.
 * O que falta neles é um ancestral dizendo *esta tela é nossa*.
 *
 * A convenção, em duas classes no `div.wrap`:
 *
 *  - **`ffc-admin-page`** -- toda tela de admin nossa. É a âncora para a regra
 *    que vale em todas elas; `ffc-admin.css` é enfileirada sem condição
 *    (`AdminUserColumns`), então hoje o `.tablenav` dela alcança `users.php`.
 *  - **`ffc-page-<slug>`** -- uma tela só, onde `<slug>` é o `?page=` da tela
 *    sem o `ffc-` da frente. É a âncora para a regra que vale numa tela e chega
 *    às outras por portão largo: `ffc-admin-submissions.css` tem portão
 *    `is_ffc_page()`, que casa QUALQUER `?page=ffc-*`, e seu `.button[title]`
 *    monta uma dica em todo botão com `title` de toda tela FFC.
 *
 * As duas existem porque as duas têm consumidor -- não é um nível de reserva.
 *
 * **Esta guarda é a metade barata: ela prova que a âncora está na marcação, e
 * não que alguém a leia.** Quem cobra a leitura é `CssNamespaceAnchorTest`, e é
 * lá que a dívida encolhe. Separar as duas é o que torna esta entrega provável
 * por construção: acrescentar uma classe que nenhuma regra lê não muda pixel
 * nenhum, e essa é a prova mais forte que existe de que nada se moveu.
 *
 * Três coisas que a medição da #1184 achou, e que não se adivinha:
 *
 * 1. **O `?page=` não serve como fonte derivada.** Dois dos catorze slugs --
 *    `ffc-scheduling-dashboard` e `ffc-scheduling-environments` -- não existem
 *    como literal em lugar nenhum do repositório: são montados por concatenação
 *    (`self::MENU_SLUG . '-dashboard'`). Uma checagem que exigisse achar o slug
 *    no código reportaria os dois como erro de digitação. É a mesma descoberta
 *    que `CssClassEmitters` registra para nome montado em runtime, e é por isso
 *    que o mapa abaixo é um registro congelado em vez de uma derivação.
 * 2. **`.ffc-settings-wrap` NÃO é um nome concorrente de escopo de página.** A
 *    #1184 contou três precedentes sem convenção; medindo, são três coisas
 *    diferentes. `ffc-settings-wrap` está em DUAS telas (`ffc-settings` e
 *    `ffc-scheduling-settings`) -- é classe de **layout**, "esta tela usa o
 *    desenho de abas verticais", e continua valendo. `ffc-recruitment-admin` é
 *    âncora de página de verdade, com exatamente uma regra lendo-a
 *    (`.wrap.ffc-recruitment-admin .card`), e é o precedente que esta convenção
 *    generaliza.
 * 3. **Dois `div.wrap` são ANINHADOS dentro do wrap das Configurações**, e por
 *    isso não recebem âncora: não são telas. O aninhamento em si é dívida --
 *    `.wrap` do core traz margem própria, então aninhar dobra -- e um deles
 *    (`ffc-settings-page`) fez nascer sete seletores duplicados em
 *    `ffc-admin-settings.css`, que escrevem `.ffc-settings-wrap .card,
 *    .ffc-settings-page .card` para alcançar o mesmo elemento duas vezes. Está
 *    em NESTED abaixo, com o motivo.
 */
class AdminPageScopeTest extends TestCase {

	/**
	 * A classe genérica, em toda tela de admin nossa.
	 */
	private const GENERIC = 'ffc-admin-page';

	/**
	 * Mapa congelado: classe de tela => o `?page=` que a serve.
	 *
	 * Registro, não derivação -- ver o item 1 do docblock.
	 *
	 * @var array<string, string>
	 */
	private const SCREENS = array(
		'ffc-page-certificates-dashboard' => 'ffc-certificates-dashboard',
		'ffc-page-submissions'            => 'ffc-submissions',
		'ffc-page-settings'               => 'ffc-settings',
		'ffc-page-scheduling-audiences'   => 'ffc-scheduling-audiences',
		'ffc-page-scheduling-bookings'    => 'ffc-scheduling-bookings',
		'ffc-page-scheduling-calendars'   => 'ffc-scheduling-calendars',
		// O menu-pai `ffc-scheduling` e o submenu `ffc-scheduling-dashboard`
		// chamam o MESMO `render_dashboard_page()`. Uma tela com dois endereços
		// continua sendo uma tela: a classe nomeia a tela, pelo slug do submenu.
		'ffc-page-scheduling-dashboard'   => 'ffc-scheduling-dashboard',
		'ffc-page-scheduling-environments' => 'ffc-scheduling-environments',
		'ffc-page-scheduling-settings'    => 'ffc-scheduling-settings',
		'ffc-page-recruitment'            => 'ffc-recruitment',
		'ffc-page-reregistration'         => 'ffc-reregistration',
		'ffc-page-custom-fields'          => 'ffc-custom-fields',
		'ffc-page-appointments'           => 'ffc-appointments',
		'ffc-page-short-urls'             => 'ffc-short-urls',
	);

	/**
	 * `div.wrap` que fica sem âncora, com o motivo.
	 *
	 * Arquivo => motivo. Não é dívida de âncora: é `wrap` aninhado, que é outra
	 * dívida e tem correção própria (tirar o `wrap` de dentro move o render,
	 * porque `.wrap` do core traz margem).
	 *
	 * @var array<string, string>
	 */
	private const NESTED = array(
		'includes/settings/views/ffc-tab-user-access.php'      => 'aba das Configurações; este wrap está DENTRO de .ffc-settings-wrap',
		'includes/admin/class-ffc-admin-activity-log-page.php' => 'aba das Configurações; wrap de erro, dentro de .ffc-settings-wrap',
	);

	/**
	 * Diretórios varridos, relativos à raiz do repositório.
	 *
	 * @var array<int, string>
	 */
	private const ROOTS = array( 'includes', 'templates' );

	/**
	 * Raiz do repositório.
	 *
	 * @return string
	 */
	private function root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * Todo `class="wrap…"` emitido, com o arquivo e a linha.
	 *
	 * @return array<int, array{file: string, line: int, classes: string}>
	 */
	private function wraps(): array {
		$found = array();
		$root  = $this->root();

		foreach ( self::ROOTS as $dir ) {
			$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( "{$root}/{$dir}" ) );
			foreach ( $it as $file ) {
				if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
					continue;
				}
				$rel   = str_replace( $root . '/', '', (string) $file->getPathname() );
				$lines = explode( "\n", (string) file_get_contents( (string) $file->getPathname() ) );
				foreach ( $lines as $i => $line ) {
					if ( 1 !== preg_match( '/class="(wrap(?:\s[^"]*)?)"/', $line, $m ) ) {
						continue;
					}
					$found[] = array(
						'file'    => $rel,
						'line'    => $i + 1,
						'classes' => $m[1],
					);
				}
			}
		}

		sort( $found );
		return $found;
	}

	/**
	 * A varredura não pode passar por vazia.
	 *
	 * Um resultado vazio jamais pode ser lido como "limpo" -- a lição das
	 * #1071 / #1094, que toda guarda deste arco carrega.
	 *
	 * @return void
	 */
	public function test_the_scan_finds_the_admin_screens(): void {
		$this->assertGreaterThanOrEqual(
			20,
			count( $this->wraps() ),
			'A varredura de `class="wrap"` colapsou. Um resultado vazio não é "limpo".'
		);
	}

	/**
	 * Todo `div.wrap` nosso carrega a âncora genérica e uma de tela.
	 *
	 * @return void
	 */
	public function test_every_admin_wrap_declares_its_page_scope(): void {
		$missing = array();

		foreach ( $this->wraps() as $wrap ) {
			if ( isset( self::NESTED[ $wrap['file'] ] ) ) {
				continue;
			}

			$classes = preg_split( '/\s+/', $wrap['classes'] ) ?: array();
			$where   = "{$wrap['file']}:{$wrap['line']}";

			if ( ! in_array( self::GENERIC, $classes, true ) ) {
				$missing[] = "{$where}: falta `" . self::GENERIC . '`.';
			}

			$screen = array_values( array_intersect( $classes, array_keys( self::SCREENS ) ) );
			if ( 1 !== count( $screen ) ) {
				$missing[] = "{$where}: esperava exatamente uma classe `ffc-page-*` conhecida, achei "
					. ( array() === $screen ? 'nenhuma' : implode( ' + ', $screen ) ) . '.';
			}
		}

		$this->assertSame(
			array(),
			$missing,
			"Tela de admin sem âncora de escopo. Acrescente `" . self::GENERIC
				. ' ffc-page-<slug>` no `div.wrap` e registre a tela em SCREENS; se o `wrap` for'
				. " aninhado dentro de outro, ele não é uma tela e vai para NESTED com o motivo:\n"
				. implode( "\n", $missing )
		);
	}

	/**
	 * Toda classe de tela registrada é realmente emitida.
	 *
	 * A catraca vale nos dois sentidos: uma tela que saiu do plugin não pode
	 * deixar um nome morto no mapa, porque um nome morto no mapa é o que faz
	 * uma regra CSS órfã passar por ancorada.
	 *
	 * @return void
	 */
	public function test_no_registered_screen_class_is_dead(): void {
		$emitted = array();
		foreach ( $this->wraps() as $wrap ) {
			foreach ( preg_split( '/\s+/', $wrap['classes'] ) ?: array() as $class ) {
				$emitted[ $class ] = true;
			}
		}

		$dead = array_values( array_diff( array_keys( self::SCREENS ), array_keys( $emitted ) ) );

		$this->assertSame(
			array(),
			$dead,
			"Classe de tela registrada que ninguém emite. Tire-a de SCREENS:\n" . implode( "\n", $dead )
		);
	}

	/**
	 * As exceções de `wrap` aninhado continuam existindo.
	 *
	 * @return void
	 */
	public function test_every_nested_exception_still_matches_a_real_wrap(): void {
		$files = array();
		foreach ( $this->wraps() as $wrap ) {
			$files[ $wrap['file'] ] = true;
		}

		$stale = array_values( array_diff( array_keys( self::NESTED ), array_keys( $files ) ) );

		$this->assertSame(
			array(),
			$stale,
			"Exceção em NESTED que não casa mais com nenhum `class=\"wrap\"`. Tire-a:\n" . implode( "\n", $stale )
		);
	}
}
