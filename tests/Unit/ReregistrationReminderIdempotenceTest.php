<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Reregistration\ReregistrationSubmissionReader;

/**
 * O lembrete de recadastramento e enviado UMA VEZ por campanha, mais uma a
 * cada extensao de prazo (#1232).
 *
 * O DEFEITO QUE ISTO CONSERTA
 *
 * `run_automated_reminders()` seleciona campanhas com
 * `DATEDIFF(end_date, CURDATE()) <= reminder_days`, que e uma JANELA e nao um
 * dia, e o cron e DIARIO. Sem marca por submissao, cada participante pendente
 * recebia `reminder_days` e-mails -- sete, na configuracao mais comum. Nao era
 * risco teorico: rodava assim em producao, sem erro e sem log.
 *
 * O QUE ESTE ARQUIVO TESTA, E POR QUE NAO PELO HANDLER
 *
 * A decisao de QUEM recebe mora inteira no predicado do reader, e e la que ela
 * pode regredir. Exercitar o handler exigiria encenar wp_mail, templates,
 * `PasswordInvite` e o cron -- muita encenacao para observar uma clausula
 * WHERE. Aqui o `$wpdb` e um duplo que apenas CAPTURA o SQL preparado, entao
 * as asercoes falam da consulta que o produto realmente emite.
 *
 * A contrapartida esta dita: isto prova o predicado, nao o envio. Que o
 * carimbo acontece por item apos cada envio e coberto em
 * `ReregistrationEmailHandlerTest`.
 *
 * @covers \FreeFormCertificate\Reregistration\ReregistrationSubmissionReader
 */
class ReregistrationReminderIdempotenceTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var string */
	private string $captured = '';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		class_exists( '\FreeFormCertificate\Reregistration\ReregistrationSubmissionReader' );

		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';

		// `prepare()` ingenuo: interpola para que o teste leia a INTENCAO da
		// consulta. O duplo nao simula banco -- o que importa aqui e o
		// predicado emitido, nao linhas devolvidas.
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( $sql, ...$args ) {
				$values = ( 1 === count( $args ) && is_array( $args[0] ) ) ? $args[0] : $args;
				foreach ( $values as $v ) {
					$sql = preg_replace( '/%[ids]/', (string) $v, (string) $sql, 1 );
				}
				$this->captured = (string) $sql;
				return (string) $sql;
			}
		);
		$wpdb->shouldReceive( 'get_results' )->andReturn( array() );

		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Sem extensao de prazo, so quem nunca foi lembrado entra.
	 *
	 * E a asercao que reprova a volta do defeito: sem `reminder_sent_at IS
	 * NULL` no predicado, a consulta devolve todo mundo em toda execucao
	 * diaria.
	 */
	public function test_without_an_extension_only_the_never_reminded_are_selected(): void {
		ReregistrationSubmissionReader::get_awaiting_reminder( 7, null );

		$this->assertStringContainsString(
			'reminder_sent_at IS NULL',
			$this->captured,
			'Sem esta clausula o lembrete volta a ser reenviado a cada execucao do cron.'
		);
		$this->assertStringNotContainsString(
			'reminder_sent_at <',
			$this->captured,
			'A reabertura so pode aparecer quando ha extensao de prazo.'
		);
	}

	/**
	 * Com extensao, quem foi lembrado ANTES dela volta a ser lembravel -- uma
	 * vez, porque a comparacao e contra o carimbo mais recente da extensao.
	 *
	 * E a mesma forma que o convite usa desde o #1190, com `reminder_sent_at`
	 * no lugar de `invited_at`.
	 */
	public function test_an_extension_reopens_the_reminder_once(): void {
		$extended_at = 1771000000;

		ReregistrationSubmissionReader::get_awaiting_reminder( 7, $extended_at );

		$this->assertStringContainsString( 'reminder_sent_at IS NULL', $this->captured );
		$this->assertStringContainsString(
			'reminder_sent_at < ' . $extended_at,
			$this->captured,
			'A reabertura compara contra o carimbo da extensao; sem isso, estender o prazo nao lembra ninguem.'
		);
	}

	/**
	 * O publico-base do lembrete e um subconjunto DELIBERADO do que o convite
	 * alcanca.
	 *
	 * O convite reabre para `UNFINISHED_STATUSES`, que inclui `expired` e
	 * `rejected`. Adotar esse conjunto como base do lembrete ALARGARIA quem
	 * recebe e-mail -- uma mudanca de comportamento que nao pertence a uma
	 * correcao de duplicidade. Esta asercao existe para que esse alargamento
	 * nao entre sem alguem decidir por ele.
	 */
	public function test_the_base_audience_stays_pending_and_in_progress(): void {
		ReregistrationSubmissionReader::get_awaiting_reminder( 7, null );

		// O `prepare()` do duplo interpola sem aspas, entao a forma capturada e
		// `IN (pending,in_progress)`. O que importa aqui e QUAIS status entram,
		// nao como o driver os cita.
		$this->assertStringContainsString( 'status IN (pending,in_progress)', $this->captured );
		$this->assertSame(
			array( 'pending', 'in_progress' ),
			ReregistrationSubmissionReader::REMINDABLE_STATUSES
		);
		$this->assertNotSame(
			ReregistrationSubmissionReader::UNFINISHED_STATUSES,
			ReregistrationSubmissionReader::REMINDABLE_STATUSES,
			'Se os dois conjuntos convergirem, foi por decisao — e esta asercao e onde ela se argumenta.'
		);
	}

	/**
	 * A ordem e por `id`, estavel sob insercao concorrente -- a mesma escolha
	 * que o contrato de exportacao do #772 faz, e o que permite lotear este
	 * envio depois sem que uma linha nova empurre outra para fora da pagina.
	 */
	public function test_rows_come_back_in_a_stable_order(): void {
		ReregistrationSubmissionReader::get_awaiting_reminder( 7, null );

		$this->assertStringContainsString( 'ORDER BY id ASC', $this->captured );
	}
}
