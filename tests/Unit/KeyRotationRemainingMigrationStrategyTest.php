<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\Encryption;
use FreeFormCertificate\Migrations\Strategies\KeyRotationRemainingMigrationStrategy;

/**
 * A migração que termina a rotação de chaves nas áreas que a primeira nunca
 * percorreu (#1236).
 *
 * A `Encryption` REAL é usada, não um alias mock: a estratégia lê
 * `Encryption::V2_PREFIX`, e um alias do Mockery não declara constantes de
 * classe (a armadilha que o CLAUDE.md registra). Com a classe real, o
 * ciphertext das fixtures é genuíno e o ida-e-volta é verificável de verdade.
 *
 * O portão de desacoplamento é atravessado por uma SUBCLASSE que sobrescreve
 * `is_decoupled()`, e não definindo `FFC_ENCRYPTION_KEY`: uma constante é do
 * processo inteiro e mudaria o comportamento de `Encryption` para todo teste
 * que rodasse depois deste, alfabeticamente -- exatamente o tipo de
 * dependência de ordem que o CLAUDE.md manda evitar.
 *
 * @covers \FreeFormCertificate\Migrations\Strategies\KeyRotationRemainingMigrationStrategy
 */
class KeyRotationRemainingMigrationStrategyTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const CANDIDATES = 'wp_ffc_recruitment_candidate';
	private const REREG      = 'wp_ffc_reregistration_submissions';

	/** @var array<string, array<int, array<string, mixed>>> */
	private array $rows = array();

	/** @var array<string, mixed> */
	private array $options = array();

	/** @var array<int, array<string, mixed>> */
	private array $updates = array();

	private KeyRotationRemainingMigrationStrategy $strategy;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		class_exists( '\FreeFormCertificate\Migrations\Strategies\KeyRotationRemainingMigrationStrategy' );

		$this->rows    = array(
			self::CANDIDATES => array(),
			self::REREG      => array(),
		);
		$this->options = array();
		$this->updates = array();

		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' )->makePartial();
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'esc_like' )->andReturnUsing( static fn( $v ) => $v )->byDefault();
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( array( $this, 'fake_prepare' ) )->byDefault();
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing( array( $this, 'fake_get_var' ) )->byDefault();
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing( array( $this, 'fake_get_results' ) )->byDefault();
		$wpdb->shouldReceive( 'update' )->andReturnUsing(
			function ( $table, $data, $where ) {
				$this->updates[] = array(
					'table' => $table,
					'data'  => $data,
					'id'    => (int) $where['id'],
				);

				foreach ( $this->rows[ $table ] as $i => $row ) {
					if ( (int) $row['id'] === (int) $where['id'] ) {
						$this->rows[ $table ][ $i ] = array_merge( $row, $data );
					}
				}

				return 1;
			}
		)->byDefault();

		if ( ! class_exists( 'FreeFormCertificate\Migrations\Strategies\WP_Error' ) ) {
			class_alias( 'WP_Error', 'FreeFormCertificate\Migrations\Strategies\WP_Error' );
		}

		Functions\when( '__' )->returnArg();
		Functions\when( 'is_wp_error' )->alias( static fn( $t ) => $t instanceof \WP_Error );

		$get = function ( $key, $default_value = false ) {
			return $this->options[ $key ] ?? $default_value;
		};
		$set = function ( $key, $value, $autoload = null ) {
			$this->options[ $key ] = $value;
			return true;
		};
		// SÓ as globais, deliberadamente. Uma chamada sem barra dentro de um
		// namespace cai no global quando não existe a versão namespaced -- e
		// stubar a namespaced a CRIA via Patchwork, para o resto do processo.
		// A partir daí todo teste posterior que alcance aquele código passa a
		// resolver a versão namespaced, que já não tem expectativa, e falha com
		// "is not defined nor mocked". Foi assim que este arquivo quebrou o
		// `RewriteHtmlImageRefsMigrationStrategyTest`, que stuba só a global.
		Functions\when( 'get_option' )->alias( $get );
		Functions\when( 'update_option' )->alias( $set );
		Functions\when( 'wp_json_encode' )->alias( static fn( $v ) => json_encode( $v ) );

		$this->strategy = new class() extends KeyRotationRemainingMigrationStrategy {
			/**
			 * Atravessa o portão sem definir constante de processo.
			 *
			 * @return bool
			 */
			protected function is_decoupled(): bool {
				return true;
			}
		};
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * `prepare()` ingênuo: interpola para que o duplo possa ler a intenção.
	 *
	 * @param string $sql  SQL com marcadores.
	 * @param mixed  ...$a Valores.
	 * @return string
	 */
	public function fake_prepare( $sql, ...$a ): string {
		$values = ( 1 === count( $a ) && is_array( $a[0] ) ) ? $a[0] : $a;

		foreach ( $values as $v ) {
			$sql = preg_replace( '/%[ids]/', is_int( $v ) ? (string) $v : (string) $v, (string) $sql, 1 );
		}

		return (string) $sql;
	}

	/**
	 * @param string $sql SQL interpolado.
	 * @return mixed
	 */
	public function fake_get_var( $sql ) {
		$sql = (string) $sql;

		if ( str_contains( $sql, 'SHOW TABLES LIKE' ) ) {
			foreach ( array_keys( $this->rows ) as $table ) {
				if ( str_contains( $sql, $table ) ) {
					return $table;
				}
			}
			return null;
		}

		$table = $this->table_in( $sql );
		if ( '' === $table ) {
			return 0;
		}

		if ( str_contains( $sql, 'MAX(id)' ) ) {
			$ids = array_map( static fn( $r ) => (int) $r['id'], $this->rows[ $table ] );
			return $ids ? max( $ids ) : 0;
		}

		// COUNT(*) — com ou sem o recorte pelo cursor.
		$matching = $this->matching_rows( $table, $sql );

		return count( $matching );
	}

	/**
	 * @param string $sql SQL interpolado.
	 * @return array<int, array<string, mixed>>
	 */
	public function fake_get_results( $sql ) {
		$table = $this->table_in( (string) $sql );
		if ( '' === $table ) {
			return array();
		}

		return array_values( $this->matching_rows( $table, (string) $sql ) );
	}

	/**
	 * Linhas do alvo que satisfazem os recortes presentes no SQL.
	 *
	 * @param string $table Tabela.
	 * @param string $sql   SQL interpolado.
	 * @return array<int, array<string, mixed>>
	 */
	private function matching_rows( string $table, string $sql ): array {
		$rows = $this->rows[ $table ];

		if ( str_contains( $sql, 'data LIKE' ) ) {
			$rows = array_filter(
				$rows,
				static fn( $r ) => is_string( $r['data'] ?? null ) && str_contains( (string) $r['data'], Encryption::V2_PREFIX )
			);
		}

		if ( preg_match( '/id <= (\d+)/', $sql, $m ) ) {
			$rows = array_filter( $rows, static fn( $r ) => (int) $r['id'] <= (int) $m[1] );
		}

		if ( preg_match( '/id > (\d+)/', $sql, $m ) ) {
			$rows = array_filter( $rows, static fn( $r ) => (int) $r['id'] > (int) $m[1] );
		}

		return $rows;
	}

	/**
	 * @param string $sql SQL.
	 * @return string
	 */
	private function table_in( string $sql ): string {
		foreach ( array_keys( $this->rows ) as $table ) {
			if ( str_contains( $sql, $table ) ) {
				return $table;
			}
		}

		return '';
	}

	// ------------------------------------------------------------------
	// O portão
	// ------------------------------------------------------------------

	public function test_can_run_refuses_while_the_site_is_not_decoupled(): void {
		// Esta usa a estratégia REAL, sem a subclasse: o ambiente de teste não
		// define nenhuma das duas constantes, que é o estado a recusar.
		$real   = new KeyRotationRemainingMigrationStrategy();
		$result = $real->can_run( 'key_rotation_remaining', array() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertStringContainsString( 'FFC_ENCRYPTION_KEY', $result->get_error_message() );
		$this->assertStringContainsString( 'FFC_HASH_SALT', $result->get_error_message() );
	}

	public function test_execute_refuses_instead_of_rewriting_under_the_wrong_key(): void {
		$real = new KeyRotationRemainingMigrationStrategy();

		$this->rows[ self::CANDIDATES ][] = $this->candidate( 1, '11111111111' );

		$result = $real->execute( 'key_rotation_remaining', array() );

		$this->assertFalse( $result['success'] );
		$this->assertSame( array(), $this->updates, 'Nada pode ser escrito quando o portão recusa.' );
	}

	// ------------------------------------------------------------------
	// Recrutamento: re-cifra e reconstrói o hash
	// ------------------------------------------------------------------

	public function test_recruitment_row_is_reencrypted_and_its_hash_rebuilt(): void {
		$cpf = '11111111111';

		$row               = $this->candidate( 1, $cpf );
		$row['cpf_hash']   = 'hash-sob-o-salt-antigo';
		$this->rows[ self::CANDIDATES ][] = $row;

		$this->strategy->execute( 'key_rotation_remaining', array() );

		$this->assertCount( 1, $this->updates );
		$written = $this->updates[0]['data'];

		$this->assertArrayHasKey( 'cpf_hash', $written, 'O hash obsoleto tinha de ser reescrito.' );
		$this->assertSame( Encryption::hash( $cpf ), $written['cpf_hash'] );

		$this->assertArrayHasKey( 'cpf_encrypted', $written );
		$this->assertSame(
			$cpf,
			Encryption::decrypt( (string) $written['cpf_encrypted'] ),
			'O ciphertext reescrito tem de continuar decifrando para o mesmo valor.'
		);
	}

	public function test_a_hash_already_current_is_not_rewritten(): void {
		$cpf = '22222222222';

		$row                              = $this->candidate( 1, $cpf );
		$row['cpf_hash']                  = (string) Encryption::hash( $cpf );
		$this->rows[ self::CANDIDATES ][] = $row;

		$this->strategy->execute( 'key_rotation_remaining', array() );

		$written = $this->updates[0]['data'];

		$this->assertArrayNotHasKey(
			'cpf_hash',
			$written,
			'Um hash já sob o salt corrente não deve custar uma escrita.'
		);
		$this->assertArrayHasKey( 'cpf_encrypted', $written );
	}

	/**
	 * Uma colisão de UNIQUE é reportada com o texto do banco, não como falha genérica.
	 *
	 * POR QUE ESTE CASO EXISTE
	 *
	 * `cpf_hash` e `rf_hash` são UNIQUE sobre o VALOR do hash, não sobre a
	 * pessoa. Sob salts diferentes a mesma pessoa produz valores diferentes,
	 * então duas linhas dela passam pela restrição — e é exatamente isso que a
	 * parte 2 da #1236 descreve: depois do desacoplamento a busca deixou de
	 * achar o candidato antigo e o dedup do importador criou uma linha nova.
	 *
	 * Onde esse par existe, reconstruir o hash da linha antiga produz o valor
	 * que a nova já tem, e o UPDATE bate na restrição. O docblock do método
	 * afirmava o contrário; esta asserção é o que o mantém honesto.
	 *
	 * O QUE ELA PROVA, E O QUE NÃO PROVA
	 *
	 * Prova que a falha é reportada com o texto do banco — que nomeia a chave
	 * e o valor duplicados, e é o que distingue "reconcilie os duplicados à
	 * mão" de "tente de novo" — e que o laço sobrevive a ela.
	 *
	 * Não prova que a colisão acontece: isso é o servidor aplicando a UNIQUE,
	 * e nenhum duplo de `$wpdb` a reproduz. O que existe aqui é a falha
	 * SIMULADA, que é o único lado deste caso que o código controla.
	 */
	public function test_a_unique_collision_is_reported_with_the_database_message(): void {
		global $wpdb;

		$this->rows[ self::CANDIDATES ][] = array_merge(
			$this->candidate( 1, '11111111111' ),
			array( 'cpf_hash' => 'hash-sob-o-salt-antigo' )
		);

		$wpdb->last_error = "Duplicate entry 'abc123' for key 'cpf_hash'";
		$wpdb->shouldReceive( 'update' )->andReturn( false );

		$result = $this->strategy->execute( 'key_rotation_remaining', array() );

		$this->assertCount( 1, $result['errors'] );
		$this->assertStringContainsString( "Duplicate entry 'abc123' for key 'cpf_hash'", $result['errors'][0] );
		$this->assertStringContainsString( '1', $result['errors'][0], 'O id do candidato tem de estar na mensagem.' );
	}

	/**
	 * Sem texto do banco, a mensagem antiga continua valendo.
	 *
	 * `last_error` pode vir vazio — uma falha de conexão, um driver que não o
	 * preenche. Interpolar vazio produziria uma frase terminando em dois
	 * pontos e nada, que é pior que a mensagem curta.
	 */
	public function test_a_write_failure_without_a_database_message_still_reports_the_candidate(): void {
		global $wpdb;

		$this->rows[ self::CANDIDATES ][] = array_merge(
			$this->candidate( 7, '11111111111' ),
			array( 'cpf_hash' => 'hash-sob-o-salt-antigo' )
		);

		$wpdb->last_error = '';
		$wpdb->shouldReceive( 'update' )->andReturn( false );

		$result = $this->strategy->execute( 'key_rotation_remaining', array() );

		$this->assertCount( 1, $result['errors'] );
		$this->assertStringContainsString( '7', $result['errors'][0] );

		// A forma curta termina em ponto final. Interpolar um detalhe vazio
		// produziria uma frase terminando em `: ` e nada -- que e o que esta
		// asercao reprova. MEDIDO: a primeira versao procurava `': .'`, que a
		// mutacao nunca produz, entao ela passava verde sem medir nada.
		$this->assertStringEndsWith( '.', $result['errors'][0] );
	}

	// ------------------------------------------------------------------
	// Recadastramento: o despacho é pelo valor, não pela configuração
	// ------------------------------------------------------------------

	public function test_only_values_carrying_the_ciphertext_prefix_are_touched(): void {
		$segredo = 'cpf-do-participante';

		$body = array(
			'fields' => array(
				'cpf'  => (string) Encryption::encrypt( $segredo ),
				'nome' => 'Texto claro que nunca foi cifrado',
			),
		);

		$this->rows[ self::REREG ][] = array(
			'id'   => 1,
			'data' => (string) json_encode( $body ),
		);

		$result = $this->strategy->execute( 'key_rotation_remaining', array() );

		$this->assertCount( 1, $this->updates );
		$saved = json_decode( (string) $this->updates[0]['data']['data'], true );

		$this->assertSame(
			'Texto claro que nunca foi cifrado',
			$saved['fields']['nome'],
			'Um valor em texto claro não pode ser cifrado pela migração.'
		);

		// A asserção que realmente cobra o despacho pelo prefixo. Sem ele, o
		// texto claro vai parar em `decrypt()`, que devolve null, e o valor
		// sobrevive intacto -- então a asserção acima passa mesmo com o
		// despacho quebrado. O que NÃO sobrevive é a conclusão: cada campo em
		// claro vira uma mensagem de erro, e `mark_completed()` exige a lista
		// vazia, de modo que a migração nunca chegaria a 100%. Medido por
		// mutação: removendo a checagem do prefixo, é esta asserção que
		// reprova, e só ela.
		$this->assertSame(
			array(),
			$result['errors'],
			'Texto claro não é uma falha de decifragem: reportá-lo como erro impediria a migração de concluir.'
		);
		$this->assertTrue( $result['has_more'] === false || 0 === $result['pending'] );
		$this->assertStringStartsWith( Encryption::V2_PREFIX, $saved['fields']['cpf'] );
		$this->assertSame( $segredo, Encryption::decrypt( $saved['fields']['cpf'] ) );
	}

	public function test_a_body_without_ciphertext_is_never_written(): void {
		$this->rows[ self::REREG ][] = array(
			'id'   => 1,
			'data' => (string) json_encode( array( 'fields' => array( 'nome' => 'Fulano' ) ) ),
		);

		$this->strategy->execute( 'key_rotation_remaining', array() );

		$this->assertSame( array(), $this->updates );
	}

	// ------------------------------------------------------------------
	// Estado: fingerprint e conclusão
	// ------------------------------------------------------------------

	public function test_a_changed_key_rearms_instead_of_reporting_complete(): void {
		$this->rows[ self::CANDIDATES ][] = $this->candidate( 1, '33333333333' );

		$this->strategy->execute( 'key_rotation_remaining', array() );
		$concluida = $this->strategy->calculate_status( 'key_rotation_remaining', array() );
		$this->assertTrue( $concluida['is_complete'] );

		// A chave muda: o que já foi reescrito virou legado de novo.
		$estado                = $this->options['ffc_key_rotation_remaining_state'];
		$estado['fingerprint'] = 'impressao-de-outra-chave';
		$this->options['ffc_key_rotation_remaining_state'] = $estado;

		$depois = $this->strategy->calculate_status( 'key_rotation_remaining', array() );

		$this->assertFalse(
			$depois['is_complete'],
			'Uma chave diferente tem de re-armar; reportar "completa" deixaria dados sob a chave antiga.'
		);
		$this->assertSame( 1, $depois['pending'] );
	}

	public function test_an_empty_install_reports_complete_without_writing(): void {
		$status = $this->strategy->calculate_status( 'key_rotation_remaining', array() );

		$this->assertTrue( $status['is_complete'] );
		$this->assertSame( 0, $status['total'] );
		$this->assertSame( array(), $this->updates );
	}

	public function test_name_is_the_one_the_registry_advertises(): void {
		$this->assertSame( 'Encryption Key Rotation — Remaining Areas', $this->strategy->get_name() );
	}

	/**
	 * Uma linha de candidato com CPF cifrado de verdade.
	 *
	 * @param int    $id  Id.
	 * @param string $cpf CPF em claro.
	 * @return array<string, mixed>
	 */
	private function candidate( int $id, string $cpf ): array {
		return array(
			'id'              => $id,
			'cpf_encrypted'   => (string) Encryption::encrypt( $cpf ),
			'cpf_hash'        => 'hash-antigo',
			'rf_encrypted'    => null,
			'rf_hash'         => null,
			'email_encrypted' => null,
			'email_hash'      => null,
		);
	}
}
