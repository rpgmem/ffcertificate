<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Reregistration\ReregistrationEmailHandler;

/**
 * O lembrete de recadastramento sai em LOTES, e o lote avanca (#1232 passo 2).
 *
 * POR QUE LOTEAR
 *
 * `run_automated_reminders()` roda no wp-cron, isto e, dentro da requisicao de
 * um visitante. O carimbo por item entregue no passo 1 ja tornava o envio
 * retomavel ENTRE execucoes diarias, mas o alcance ficava limitado a
 * (quanto cabe numa execucao) x `reminder_days` -- numa campanha de milhares,
 * o prazo vence antes de todo mundo ser lembrado.
 *
 * A PROPRIEDADE QUE ESTE ARQUIVO EXISTE PARA PRENDER
 *
 * Nao e "o lote tem 50 linhas": e que o lote **PROGRIDE**. O cursor keyset
 * avanca por linha VISTA, nao por envio bem-sucedido, e isso e o que separa
 * um loop que termina de um que nao termina.
 *
 * O caso nao e hipotetico e esta documentado no codigo: `user_id` em
 * `ffc_reregistration_submissions` e `NOT NULL` e ORFAO ACEITO (#822), entao
 * apagar a conta no WordPress deixa a submissao apontando para um usuario que
 * nao existe. `send_to_user()` devolve `false` em `get_userdata()`,
 * `mark_reminded()` nunca roda, e `reminder_sent_at` fica NULL para sempre.
 * Um loop guiado so por `reminder_sent_at IS NULL` rebuscaria essa linha a
 * cada lote e se reagendaria a cada 60 segundos, sem fim.
 *
 * O QUE ESTE ARQUIVO NAO PROVA
 *
 * Que o e-mail chega. O `SchedulingMailer` e um duplo; o que se observa aqui
 * e o agendamento e o cursor.
 *
 * @covers \FreeFormCertificate\Reregistration\ReregistrationEmailHandler
 * @runClassInSeparateProcess
 * @preserveGlobalState disabled
 */
class ReregistrationReminderBatchTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface */
	private $wpdb;

	/**
	 * Chamadas capturadas de `wp_schedule_single_event`.
	 *
	 * @var list<array{0: int, 1: string, 2: array<int, mixed>}>
	 */
	private array $scheduled = array();

	/**
	 * O que `wp_next_scheduled` responde.
	 *
	 * @var int|false
	 */
	private $next_scheduled = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		class_exists( '\FreeFormCertificate\Reregistration\ReregistrationEmailHandler' );

		Functions\when( 'get_option' )->alias(
			static function ( $key, $default_value = false ) {
				if ( 'ffc_settings' === $key ) {
					return array();
				}
				if ( 'ffc_dashboard_page_id' === $key ) {
					return 0;
				}
				return $default_value;
			}
		);

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'sanitize_text_field' )->alias( 'trim' );
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'get_bloginfo' )->justReturn( 'Test Site' );
		Functions\when( 'wp_mail' )->justReturn( true );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/dashboard' );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );

		Functions\when( 'wp_next_scheduled' )->alias(
			function () {
				return $this->next_scheduled;
			}
		);
		Functions\when( 'wp_schedule_single_event' )->alias(
			function ( $timestamp, $hook, $args = array() ) {
				$this->scheduled[] = array( (int) $timestamp, (string) $hook, (array) $args );
				return true;
			}
		);

		global $wpdb;
		$wpdb             = Mockery::mock( 'wpdb' );
		$wpdb->prefix     = 'wp_';
		$wpdb->users      = 'wp_users';
		$wpdb->last_error = '';
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'query' )->byDefault();
		$wpdb->shouldReceive( 'update' )->andReturn( 1 )->byDefault();
		$this->wpdb = $wpdb;

		Mockery::mock( 'alias:FreeFormCertificate\Core\DateFormatter' )
			->shouldReceive( 'format_date' )->andReturn( '2026-01-01' );
		Mockery::mock( 'alias:FreeFormCertificate\Scheduling\SchedulingMailer' )
			->shouldReceive( 'send' )->andReturn( true );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Arma a campanha e a pagina de submissoes que o reader devolve.
	 *
	 * @param int       $rows      Quantas linhas a pagina traz.
	 * @param list<int> $failing   `user_id`s cujo `get_userdata` falha.
	 * @return void
	 */
	private function stage( int $rows, array $failing = array() ): void {
		$rereg = (object) array(
			'id'                     => 7,
			'title'                  => 'Campanha',
			'email_reminder_enabled' => 1,
			'start_date'             => '2026-01-01',
			'end_date'               => '2099-12-31',
		);

		$this->wpdb->shouldReceive( 'get_row' )->andReturn( $rereg );

		$page = array();
		for ( $i = 1; $i <= $rows; $i++ ) {
			$page[] = (object) array(
				'id'      => $i,
				'user_id' => 1000 + $i,
				'status'  => 'pending',
			);
		}
		$this->wpdb->shouldReceive( 'get_results' )->andReturn( $page );

		Functions\when( 'get_userdata' )->alias(
			static function ( $id ) use ( $failing ) {
				if ( in_array( (int) $id, $failing, true ) ) {
					// Submissao cujo usuario foi apagado: o orfao aceito do
					// #822. E o caso que trava a fila sem o cursor.
					return false;
				}
				return (object) array(
					'display_name' => 'U' . $id,
					'user_email'   => 'u' . $id . '@example.com',
				);
			}
		);
	}

	// ==================================================================
	// Encerramento
	// ==================================================================

	/**
	 * Pagina menor que o lote encerra a fila: nada e reagendado.
	 *
	 * E o que mantem uma campanha pequena identica ao comportamento anterior
	 * ao loteamento -- ela termina numa execucao so, sem enfileirar nada.
	 */
	public function test_a_short_page_does_not_reschedule(): void {
		$this->stage( ReregistrationEmailHandler::REMINDER_BATCH_SIZE - 1 );

		ReregistrationEmailHandler::send_reminder_batch( 7, 0 );

		$this->assertSame( array(), $this->scheduled, 'Uma pagina incompleta significa fila vazia; reagendar ali gera execucao inutil para sempre.' );
	}

	/**
	 * Pagina cheia reagenda, com a campanha e o cursor no payload.
	 *
	 * O par da asercao acima: sem ela, um driver que nunca reagenda passaria
	 * nos dois testes e o loteamento nao existiria.
	 */
	public function test_a_full_page_reschedules_with_the_cursor(): void {
		$size = ReregistrationEmailHandler::REMINDER_BATCH_SIZE;
		$this->stage( $size );

		ReregistrationEmailHandler::send_reminder_batch( 7, 0 );

		$this->assertCount( 1, $this->scheduled );
		$this->assertSame( ReregistrationEmailHandler::REMINDER_BATCH_HOOK, $this->scheduled[0][1] );
		$this->assertSame( array( 7, $size ), $this->scheduled[0][2] );
	}

	// ==================================================================
	// Progresso — a propriedade central
	// ==================================================================

	/**
	 * O cursor avanca alem de uma linha que NAO pode ser enviada.
	 *
	 * Esta e a asercao que o arquivo existe para sustentar. A ultima linha da
	 * pagina tem um usuario apagado, entao ela nunca recebe carimbo. Se o
	 * cursor fosse "o ultimo enviado com sucesso", o lote seguinte comecaria
	 * antes dela, a rebuscaria, veria pagina cheia de novo e reagendaria --
	 * a cada 60 segundos, para sempre.
	 */
	public function test_the_cursor_advances_past_a_row_that_cannot_be_sent(): void {
		$size = ReregistrationEmailHandler::REMINDER_BATCH_SIZE;
		// A ULTIMA linha da pagina e a que falha: e onde a diferenca entre
		// "linha vista" e "envio bem-sucedido" fica visivel.
		$this->stage( $size, array( 1000 + $size ) );

		ReregistrationEmailHandler::send_reminder_batch( 7, 0 );

		$this->assertCount( 1, $this->scheduled );
		$this->assertSame(
			array( 7, $size ),
			$this->scheduled[0][2],
			'O cursor parou numa linha que nunca sera carimbada — o lote seguinte a rebusca e o ciclo nao termina.'
		);
	}

	/**
	 * O payload leva apenas escalares.
	 *
	 * A opcao `cron` e autoloaded e desserializada em TODA requisicao do site,
	 * entao um payload gordo custa em todo lugar, o tempo todo -- e nao so
	 * aqui. Dois inteiros e o que o desenho pede.
	 */
	public function test_the_payload_carries_only_scalars(): void {
		$this->stage( ReregistrationEmailHandler::REMINDER_BATCH_SIZE );

		ReregistrationEmailHandler::send_reminder_batch( 7, 0 );

		$this->assertCount( 1, $this->scheduled );
		foreach ( $this->scheduled[0][2] as $arg ) {
			$this->assertIsInt( $arg );
		}
	}

	/**
	 * Um lote identico ja enfileirado nao e enfileirado de novo.
	 *
	 * Dois visitantes podem disparar o wp-cron quase juntos; sem a guarda, a
	 * mesma campanha entraria duas vezes na fila e cada participante levaria
	 * dois e-mails -- exatamente a duplicidade que o passo 1 consertou,
	 * reintroduzida pelo passo 2.
	 */
	public function test_an_already_queued_batch_is_not_queued_twice(): void {
		$this->next_scheduled = time() + 30;
		$this->stage( ReregistrationEmailHandler::REMINDER_BATCH_SIZE );

		ReregistrationEmailHandler::send_reminder_batch( 7, 0 );

		$this->assertSame( array(), $this->scheduled );
	}

	/**
	 * O atraso entre lotes e o declarado.
	 *
	 * Congela o valor contra uma mudanca acidental, e o docblock da constante
	 * guarda a ressalva que o numero nao consegue expressar: com WP-Cron ele e
	 * um piso, nao uma promessa.
	 */
	public function test_the_next_batch_is_scheduled_after_the_declared_delay(): void {
		$this->stage( ReregistrationEmailHandler::REMINDER_BATCH_SIZE );

		$before = time();
		ReregistrationEmailHandler::send_reminder_batch( 7, 0 );

		$this->assertCount( 1, $this->scheduled );
		$this->assertGreaterThanOrEqual(
			$before + ReregistrationEmailHandler::REMINDER_BATCH_DELAY,
			$this->scheduled[0][0]
		);
	}
}
