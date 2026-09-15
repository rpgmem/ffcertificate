<?php
/**
 * Tests for the decrypt_failure audit entry emitted by Encryption::decrypt.
 *
 * Centralizing the log there replaces a handful of silent-null fallbacks
 * across the codebase (`decrypt(...) ?? ''`, swallowed try/catch, etc.).
 * This file pins:
 *   - the log fires when decrypt returns null from a non-empty input,
 *   - it stays silent for empty input and for successful round-trips,
 *   - the payload is metadata-only (so it cannot leak plaintext or
 *     recurse back into Encryption via the ActivityLog sensitivity gate),
 *   - the write is disabled when the activity log is disabled.
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\ActivityLog;
use FreeFormCertificate\Core\Encryption;
use FreeFormCertificate\Core\SensitiveFieldRegistry;

/**
 * The single method under test is Encryption::decrypt — these tests pin its
 * decrypt-failure audit emission. ActivityLog and SensitiveFieldRegistry are
 * exercised only as collaborators (the buffer sink + the sensitivity gate),
 * so attribution belongs to Encryption.
 *
 * @covers \FreeFormCertificate\Core\Encryption
 */
class DecryptFailureLoggingTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface */
	private $wpdb;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->resetActivityLogState();

		global $wpdb;
		$wpdb             = Mockery::mock( 'wpdb' )->makePartial();
		$wpdb->prefix     = 'wp_';
		$wpdb->last_error = '';
		$this->wpdb       = $wpdb;

		Functions\when( 'absint' )->alias( function ( $v ) {
			return abs( (int) $v );
		} );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'current_time' )->justReturn( '2026-04-23 00:00:00' );
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( function () {
			return func_get_args()[0];
		} )->byDefault();
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( null )->byDefault();
		$this->wpdb->shouldReceive( 'get_col' )->andReturn( array() )->byDefault();
	}

	protected function tearDown(): void {
		$this->resetActivityLogState();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Enable the activity log via ffc_settings. Must run inside the test,
	 * not setUp, because Brain\Monkey scopes stubs per test.
	 */
	private function enableActivityLog(): void {
		Functions\when( 'get_option' )->alias( function ( $key, $default = false ) {
			if ( 'ffc_settings' === $key ) {
				return array( 'enable_activity_log' => 1 );
			}
			return $default;
		} );
	}

	/**
	 * Disable the activity log (default state). Log calls must no-op.
	 */
	private function disableActivityLog(): void {
		Functions\when( 'get_option' )->justReturn( array() );
	}

	/**
	 * Zera o contador por requisicao do teto de `decrypt_failure` (#1234).
	 *
	 * E estado ESTATICO: a suite roda num processo so, entao sem este reset as
	 * falhas de um teste contam para o teto do seguinte e o sexto teste deste
	 * arquivo passaria a nao registrar nada -- uma falha que aponta para o
	 * arquivo errado.
	 */
	private function resetDecryptFailureCounter(): void {
		$ref = new \ReflectionClass( Encryption::class );
		$p   = $ref->getProperty( 'decrypt_failure_count' );
		$p->setAccessible( true );
		$p->setValue( 0 );
	}

	private function resetActivityLogState(): void {
		$this->resetDecryptFailureCounter();

		$ref = new \ReflectionClass( ActivityLog::class );

		$buffer = $ref->getProperty( 'write_buffer' );
		$buffer->setAccessible( true );
		$buffer->setValue( array() );

		$shutdown = $ref->getProperty( 'shutdown_registered' );
		$shutdown->setAccessible( true );
		$shutdown->setValue( false );

		$disabled = $ref->getProperty( 'logging_disabled' );
		$disabled->setAccessible( true );
		$disabled->setValue( false );

		$columns = $ref->getProperty( 'table_columns_cache' );
		$columns->setAccessible( true );
		$columns->setValue( null );
	}

	private function getWriteBuffer(): array {
		$ref = new \ReflectionClass( ActivityLog::class );
		$p   = $ref->getProperty( 'write_buffer' );
		$p->setAccessible( true );
		return $p->getValue();
	}

	// ==================================================================
	// Failure path
	// ==================================================================

	public function test_decrypt_failure_is_logged_when_activity_log_enabled(): void {
		$this->enableActivityLog();

		$result = Encryption::decrypt( '!!!invalid-base64!!!' );
		$this->assertNull( $result, 'Decrypt should still return null on failure.' );

		$buffer = $this->getWriteBuffer();
		$this->assertCount( 1, $buffer, 'Exactly one audit entry should be buffered.' );
		$this->assertSame( 'decrypt_failure', $buffer[0]['action'] );
		$this->assertSame( ActivityLog::LEVEL_WARNING, $buffer[0]['level'] );
	}

	public function test_decrypt_failure_log_context_is_metadata_only(): void {
		$this->enableActivityLog();

		Encryption::decrypt( '!!!invalid-base64!!!' );
		$buffer   = $this->getWriteBuffer();
		$context  = json_decode( $buffer[0]['context'], true );

		$this->assertIsArray( $context );
		$this->assertSame( strlen( '!!!invalid-base64!!!' ), $context['ciphertext_length'] );
		$this->assertFalse( $context['v2_prefix'] );
		// No key in context should map to something SensitiveFieldRegistry considers sensitive
		// — otherwise the log write would re-enter Encryption::encrypt on the gate path.
		$this->assertFalse(
			SensitiveFieldRegistry::contains_sensitive( $context ),
			'decrypt_failure context must not contain any sensitive keys.'
		);
	}

	public function test_decrypt_failure_log_flags_v2_prefix_when_present(): void {
		$this->enableActivityLog();

		// Well-formed v2: prefix but gibberish after — decoder should reject, still log.
		Encryption::decrypt( 'v2:not-valid-base64!!!' );
		$buffer  = $this->getWriteBuffer();
		$this->assertCount( 1, $buffer );
		$context = json_decode( $buffer[0]['context'], true );
		$this->assertTrue( $context['v2_prefix'] );
	}

	// ==================================================================
	// No-op paths
	// ==================================================================

	public function test_decrypt_empty_input_does_not_log(): void {
		$this->enableActivityLog();

		Encryption::decrypt( '' );
		$this->assertSame( array(), $this->getWriteBuffer() );
	}

	public function test_successful_decrypt_does_not_log(): void {
		$this->enableActivityLog();

		$ct = Encryption::encrypt( 'hello' );
		$this->assertNotNull( $ct );

		$pt = Encryption::decrypt( $ct );
		$this->assertSame( 'hello', $pt );
		$this->assertSame( array(), $this->getWriteBuffer() );
	}

	public function test_decrypt_failure_is_silent_when_activity_log_disabled(): void {
		$this->disableActivityLog();

		$result = Encryption::decrypt( '!!!invalid-base64!!!' );
		$this->assertNull( $result );

		// No buffer entry because ActivityLog::is_enabled() returned false.
		$this->assertSame( array(), $this->getWriteBuffer() );
	}

	// ==================================================================
	// Teto por requisicao (#1234)
	// ==================================================================

	/**
	 * Ate o teto, uma linha por falha -- nada muda para o caso normal.
	 */
	public function test_failures_up_to_the_cap_each_get_their_own_entry(): void {
		$this->enableActivityLog();

		for ( $i = 0; $i < Encryption::DECRYPT_FAILURE_LOG_CAP; $i++ ) {
			$this->assertNull( Encryption::decrypt( '!!!invalid-base64!!!' ) );
		}

		$buffer = $this->getWriteBuffer();
		$this->assertCount( Encryption::DECRYPT_FAILURE_LOG_CAP, $buffer );
		foreach ( $buffer as $entry ) {
			$this->assertSame( 'decrypt_failure', $entry['action'] );
		}
	}

	/**
	 * Passado o teto, UMA marca de supressao -- e so uma, por mais que chova.
	 *
	 * E o defeito que o #1234 descreve: uma chave quebrada numa exportacao de
	 * 5.000 submissoes escrevia 5.000 INSERTs em `ffc_activity_log`. O custo da
	 * auditoria passava o da leitura que falhou.
	 */
	public function test_crossing_the_cap_writes_exactly_one_suppression_marker(): void {
		$this->enableActivityLog();

		for ( $i = 0; $i < Encryption::DECRYPT_FAILURE_LOG_CAP + 50; $i++ ) {
			Encryption::decrypt( '!!!invalid-base64!!!' );
		}

		$buffer  = $this->getWriteBuffer();
		$actions = array_column( $buffer, 'action' );

		$this->assertCount(
			Encryption::DECRYPT_FAILURE_LOG_CAP + 1,
			$buffer,
			'Cinquenta e cinco falhas devem render cinco linhas mais uma marca, nao cinquenta e cinco.'
		);
		$this->assertSame(
			1,
			count( array_keys( $actions, 'decrypt_failure_suppressed', true ) ),
			'A marca e escrita na travessia do teto, uma unica vez.'
		);
		$this->assertSame( 'decrypt_failure_suppressed', $buffer[ Encryption::DECRYPT_FAILURE_LOG_CAP ]['action'] );
	}

	/**
	 * A marca diz qual foi o teto, e nada alem disso.
	 *
	 * Quem le o log precisa saber que houve corte e onde; o comprimento do
	 * texto cifrado da enesima falha nao acrescenta nada que a primeira ja nao
	 * tenha dito.
	 */
	public function test_the_suppression_marker_carries_the_cap_and_nothing_else(): void {
		$this->enableActivityLog();

		for ( $i = 0; $i < Encryption::DECRYPT_FAILURE_LOG_CAP + 1; $i++ ) {
			Encryption::decrypt( '!!!invalid-base64!!!' );
		}

		$buffer  = $this->getWriteBuffer();
		$context = json_decode( $buffer[ Encryption::DECRYPT_FAILURE_LOG_CAP ]['context'], true );

		$this->assertSame( array( 'cap' => Encryption::DECRYPT_FAILURE_LOG_CAP ), $context );
		$this->assertFalse( SensitiveFieldRegistry::contains_sensitive( $context ) );
	}

	/**
	 * Passado o teto, o caminho sai ANTES de ler a opcao do log.
	 *
	 * E a razao de o teto ser a PRIMEIRA coisa no metodo, e nao um filtro na
	 * hora de gravar: numa enxurrada, `ActivityLog::is_enabled()` -- que le
	 * `ffc_settings` -- passaria a ser o custo. Aqui contamos as leituras: elas
	 * param de crescer junto com as linhas.
	 */
	public function test_past_the_cap_the_settings_option_is_no_longer_read(): void {
		$reads = 0;
		Functions\when( 'get_option' )->alias(
			function ( $key, $default = false ) use ( &$reads ) {
				if ( 'ffc_settings' === $key ) {
					++$reads;
					return array( 'enable_activity_log' => 1 );
				}
				return $default;
			}
		);

		for ( $i = 0; $i < Encryption::DECRYPT_FAILURE_LOG_CAP + 1; $i++ ) {
			Encryption::decrypt( '!!!invalid-base64!!!' );
		}
		$at_the_crossing = $reads;

		for ( $i = 0; $i < 100; $i++ ) {
			Encryption::decrypt( '!!!invalid-base64!!!' );
		}

		$this->assertSame(
			$at_the_crossing,
			$reads,
			'Cem falhas depois do teto nao podem custar nem uma leitura de opcao.'
		);
	}

	/**
	 * O teto corta o LOG, nunca o resultado.
	 *
	 * `decrypt()` continua devolvendo null em toda falha -- se o teto mudasse
	 * isso, uma chave quebrada passaria a devolver texto cifrado como se fosse
	 * claro depois da quinta linha.
	 */
	public function test_the_cap_never_changes_what_decrypt_returns(): void {
		$this->enableActivityLog();

		for ( $i = 0; $i < Encryption::DECRYPT_FAILURE_LOG_CAP + 20; $i++ ) {
			$this->assertNull(
				Encryption::decrypt( '!!!invalid-base64!!!' ),
				'A falha numero ' . ( $i + 1 ) . ' deixou de devolver null.'
			);
		}
	}
}
