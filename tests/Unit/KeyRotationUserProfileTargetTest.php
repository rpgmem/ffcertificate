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
use FreeFormCertificate\UserDashboard\UserProfileFieldMap;

/**
 * O terceiro alvo da rotacao de chaves: a usermeta sensivel de perfil (#1236).
 *
 * POR QUE ISTO EXISTE
 *
 * `ffc_user_cpf`, `ffc_user_rf` e `ffc_user_rg` guardam PII cifrada sob a chave
 * DERIVADA dos salts do WordPress. Enquanto estiverem assim, trocar
 * `SECURE_AUTH_KEY` / `LOGGED_IN_KEY` / `NONCE_KEY` -- coisa que varias
 * hospedagens oferecem num clique -- torna esses valores ilegiveis para
 * sempre, porque a chave E derivada deles. Nao e item de desempenho.
 *
 * O ARQUIVO SEPARADO E DELIBERADO
 *
 * `KeyRotationRemainingMigrationStrategyTest` monta um duplo de `$wpdb`
 * orientado a LINHAS DE TABELA, que e a forma dos outros dois alvos. Este alvo
 * nao tem tabela propria: pagina por `user_id` com `get_col()` e le e escreve
 * valor pela API de meta do WordPress. Encaixar as duas formas num harness so
 * deixaria ambas menos legiveis.
 *
 * A `Encryption` REAL e usada, nao um alias mock -- a estrategia le
 * `Encryption::V2_PREFIX`, e um alias do Mockery nao declara constantes de
 * classe. Com a classe real o ciphertext das fixtures e genuino e o
 * ida-e-volta e verificavel.
 *
 * @covers \FreeFormCertificate\Migrations\Strategies\KeyRotationRemainingMigrationStrategy
 */
class KeyRotationUserProfileTargetTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array<int, array<string, string>> */
	private array $meta = array();

	/** @var array<string, mixed> */
	private array $options = array();

	private KeyRotationRemainingMigrationStrategy $strategy;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		class_exists( '\FreeFormCertificate\Migrations\Strategies\KeyRotationRemainingMigrationStrategy' );
		class_exists( '\FreeFormCertificate\UserDashboard\UserProfileFieldMap' );

		$this->meta    = array();
		$this->options = array();

		global $wpdb;
		$wpdb           = Mockery::mock( 'wpdb' )->makePartial();
		$wpdb->prefix   = 'wp_';
		$wpdb->usermeta = 'wp_usermeta';
		$wpdb->shouldReceive( 'esc_like' )->andReturnUsing( static fn( $v ) => $v )->byDefault();
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( $sql, ...$a ): string {
				$values = ( 1 === count( $a ) && is_array( $a[0] ) ) ? $a[0] : $a;
				foreach ( $values as $v ) {
					$sql = preg_replace( '/%[ids]/', (string) $v, (string) $sql, 1 );
				}
				return (string) $sql;
			}
		)->byDefault();

		// Nenhum dos OUTROS dois alvos tem tabela neste harness, entao ambos
		// contam zero e o despacho cai neste. E o que permite exercitar um alvo
		// isoladamente sem encenar os outros.
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			function ( $sql ) {
				$sql = (string) $sql;
				if ( str_contains( $sql, 'SHOW TABLES LIKE' ) ) {
					return null;
				}
				if ( str_contains( $sql, 'COUNT(DISTINCT user_id)' ) ) {
					return count( $this->pending_users( $sql ) );
				}
				return 0;
			}
		)->byDefault();

		$wpdb->shouldReceive( 'get_col' )->andReturnUsing(
			function ( $sql ) {
				return array_map( 'strval', $this->pending_users( (string) $sql ) );
			}
		)->byDefault();

		if ( ! class_exists( 'FreeFormCertificate\Migrations\Strategies\WP_Error' ) ) {
			class_alias( 'WP_Error', 'FreeFormCertificate\Migrations\Strategies\WP_Error' );
		}

		Functions\when( '__' )->returnArg();
		Functions\when( 'is_wp_error' )->alias( static fn( $t ) => $t instanceof \WP_Error );
		Functions\when( 'wp_json_encode' )->alias( static fn( $v ) => json_encode( $v ) );

		// So as GLOBAIS: stubar a versao namespaced a CRIA via Patchwork para o
		// resto do processo, e todo teste posterior que alcance aquele codigo
		// passa a resolver uma funcao sem expectativa. O CLAUDE.md registra o
		// caso, que ja quebrou um teste vizinho nesta mesma familia.
		Functions\when( 'get_option' )->alias(
			function ( $key, $default_value = false ) {
				return $this->options[ $key ] ?? $default_value;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $key, $value ) {
				$this->options[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'get_user_meta' )->alias(
			function ( $user_id, $key, $single = false ) {
				return $this->meta[ (int) $user_id ][ $key ] ?? '';
			}
		);
		Functions\when( 'update_user_meta' )->alias(
			function ( $user_id, $key, $value ) {
				$this->meta[ (int) $user_id ][ $key ] = (string) $value;
				return true;
			}
		);

		$this->strategy = new class() extends KeyRotationRemainingMigrationStrategy {
			/**
			 * Atravessa o portao de desacoplamento sem definir constante de
			 * processo -- uma constante valeria para todo teste posterior.
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
	 * Os `user_id` que o SQL capturado selecionaria, lidos do armazenamento em
	 * memoria. Honra o cursor (`user_id > N`) e o recorte (`user_id <= N`).
	 *
	 * @param string $sql SQL ja interpolado pelo `prepare()` do duplo.
	 * @return list<int>
	 */
	private function pending_users( string $sql ): array {
		$ids = array();

		foreach ( $this->meta as $user_id => $rows ) {
			$has = false;
			foreach ( $rows as $key => $value ) {
				if ( str_contains( $sql, $key ) && 0 === strpos( $value, Encryption::V2_PREFIX ) ) {
					$has = true;
					break;
				}
			}
			if ( ! $has ) {
				continue;
			}

			if ( 1 === preg_match( '/user_id > (\d+)/', $sql, $m ) && $user_id <= (int) $m[1] ) {
				continue;
			}
			if ( 1 === preg_match( '/user_id <= (\d+)/', $sql, $m ) && $user_id > (int) $m[1] ) {
				continue;
			}

			$ids[] = (int) $user_id;
		}

		sort( $ids );

		return $ids;
	}

	// ==================================================================
	// A guarda de concordancia
	// ==================================================================

	/**
	 * O mapa da migracao conhece TODO campo sensivel de usermeta do
	 * `UserProfileFieldMap` -- nem mais, nem menos.
	 *
	 * E a asercao que justifica os literais no produto. Importar o mapa la
	 * criaria a aresta `Migrations > UserDashboard`, que nao existe na baseline
	 * do `ModuleBoundaryTest`; um teste vive fora do grafo e pode cobrar a
	 * concordancia sem acoplar os modulos.
	 *
	 * Sem isto, um quarto campo sensivel adicionado ao mapa ficaria fora da
	 * rotacao em silencio -- e o silencio aqui custa PII ilegivel.
	 */
	public function test_the_migration_knows_every_sensitive_usermeta_field(): void {
		$expected = array();

		foreach ( UserProfileFieldMap::sensitive_field_keys() as $field ) {
			$spec = UserProfileFieldMap::get( $field );
			if ( null === $spec || UserProfileFieldMap::STORAGE_USERMETA !== ( $spec['storage'] ?? null ) ) {
				continue;
			}
			$meta_key              = (string) $spec['meta_key'];
			$expected[ $meta_key ] = empty( $spec['hashable'] ) ? null : $meta_key . '_hash';
		}

		$this->assertNotSame( array(), $expected, 'Nao li campo sensivel nenhum do mapa — a verificacao nao rodou.' );

		$ref = new \ReflectionMethod( KeyRotationRemainingMigrationStrategy::class, 'profile_meta_map' );
		$ref->setAccessible( true );
		$actual = $ref->invoke( $this->strategy );

		ksort( $expected );
		ksort( $actual );

		$this->assertSame( $expected, $actual, 'O mapa da migracao divergiu do UserProfileFieldMap — um campo sensivel ficaria sem rotacao.' );
	}

	/**
	 * As colunas de `ffc_user_profiles` NAO entram no alvo.
	 *
	 * A #1236 junta "ffc_user_profiles / wp_usermeta" numa linha so. Medido, as
	 * seis colunas daquela tabela sao texto puro, e recifrar o que nunca foi
	 * cifrado seria corromper. Esta asercao congela a medicao.
	 */
	public function test_the_profile_table_columns_are_not_sensitive(): void {
		$table_fields = array( 'display_name', 'phone', 'department', 'organization', 'notes', 'preferences' );

		foreach ( $table_fields as $field ) {
			$spec = UserProfileFieldMap::get( $field );

			$this->assertNotNull( $spec, sprintf( 'O campo %s sumiu do mapa — re-medir antes de confiar nesta asercao.', $field ) );
			$this->assertSame( UserProfileFieldMap::STORAGE_PROFILE_TABLE, $spec['storage'] );
			$this->assertEmpty(
				$spec['sensitive'] ?? false,
				sprintf( 'O campo %s virou sensivel: a migracao de rotacao precisa passar a cobrir a tabela de perfis.', $field )
			);
		}
	}

	// ==================================================================
	// O lote
	// ==================================================================

	/**
	 * Semeia a meta de um usuario com ciphertext genuino.
	 *
	 * @param int                   $user_id Usuario.
	 * @param array<string, string> $plain   Chave de meta => valor em claro.
	 * @return void
	 */
	private function seed_user( int $user_id, array $plain ): void {
		foreach ( $plain as $key => $value ) {
			$cipher = Encryption::encrypt( $value );
			$this->assertIsString( $cipher, 'A fixture precisa de ciphertext real.' );
			$this->meta[ $user_id ][ $key ] = $cipher;
		}
	}

	/**
	 * Um usuario com tres metas tem as TRES recifradas no mesmo lote.
	 *
	 * E a propriedade que sustenta a paginacao por usuario. Se a pagina fosse de
	 * linhas de meta e o cursor avancasse por `user_id`, um usuario partido
	 * entre dois lotes perderia as metas que ficaram para tras -- o cursor ja
	 * teria passado por ele, e ninguem voltaria.
	 */
	public function test_every_meta_of_a_user_is_rewritten_in_one_batch(): void {
		$this->seed_user( 10, array( 'ffc_user_cpf' => '11122233344', 'ffc_user_rf' => '7654321', 'ffc_user_rg' => '12345678' ) );
		$before = $this->meta[10];

		$this->strategy->execute( '', array() );

		foreach ( array( 'ffc_user_cpf', 'ffc_user_rf', 'ffc_user_rg' ) as $key ) {
			$this->assertNotSame( $before[ $key ], $this->meta[10][ $key ], sprintf( '%s nao foi recifrada.', $key ) );
			$this->assertSame(
				Encryption::decrypt( $before[ $key ] ),
				Encryption::decrypt( $this->meta[10][ $key ] ),
				sprintf( '%s mudou de VALOR, nao so de cifra — a migracao corrompeu o dado.', $key )
			);
		}
	}

	/**
	 * Os campos pesquisaveis ganham o hash pareado; o nao pesquisavel, nao.
	 *
	 * O hash e a metade que conserta um defeito VIVO, e nao apenas previne um
	 * futuro: `Encryption::hash()` le `FFC_HASH_SALT` assim que ela existe,
	 * entao todo hash gravado antes do desacoplamento e inalcancavel por
	 * qualquer busca feita depois.
	 */
	public function test_hashes_are_rebuilt_only_for_the_searchable_fields(): void {
		$this->seed_user( 10, array( 'ffc_user_cpf' => '11122233344', 'ffc_user_rf' => '7654321', 'ffc_user_rg' => '12345678' ) );

		$this->strategy->execute( '', array() );

		$this->assertSame( Encryption::hash( '11122233344' ), $this->meta[10]['ffc_user_cpf_hash'] ?? null );
		$this->assertSame( Encryption::hash( '7654321' ), $this->meta[10]['ffc_user_rf_hash'] ?? null );
		$this->assertArrayNotHasKey(
			'ffc_user_rg_hash',
			$this->meta[10],
			'RG nao e pesquisavel por hash no mapa; gravar um cria uma chave que nada le.'
		);
	}

	/**
	 * O cursor avanca, entao o lote seguinte nao reprocessa quem ja passou.
	 *
	 * Sem isto a migracao nunca termina: o predicado de pendencia e
	 * `meta_value LIKE '%v2:%'`, e um valor RECIFRADO continua casando com ele.
	 * E o cursor -- nao o predicado -- que faz o trabalho progredir.
	 */
	public function test_the_cursor_advances_so_a_second_batch_moves_on(): void {
		$this->seed_user( 10, array( 'ffc_user_cpf' => '11122233344' ) );
		$this->seed_user( 20, array( 'ffc_user_cpf' => '55566677788' ) );

		$this->strategy->execute( '', array() );

		$state = $this->options['ffc_key_rotation_remaining_state'] ?? array();
		$this->assertIsArray( $state );

		$json = wp_json_encode( $state );
		$this->assertIsString( $json );
		$this->assertStringContainsString( '20', $json, 'O cursor nao chegou ao ultimo usuario do lote.' );
	}

	/**
	 * Valor que nao esta sob o esquema `v2:` e deixado intacto.
	 *
	 * Uma meta em texto claro -- ou sob um esquema que esta migracao nao
	 * conhece -- nao e dela para reescrever. Recifrar texto claro o tornaria
	 * ilegivel para o proprio `UserProfileService`.
	 *
	 * O QUE ESTA ASERCAO NAO PRENDE, medido por mutacao: trocar a checagem de
	 * prefixo por um simples `'' === $stored` mantem os 6 testes verdes. E que
	 * `Encryption::decrypt()` ja devolve null para o que nao sabe decifrar, e o
	 * `continue` seguinte protege o valor de qualquer jeito. O comportamento
	 * observavel e o mesmo; o que muda e o CUSTO.
	 *
	 * E por isso que a checagem fica: sem ela, toda meta em texto claro entra em
	 * `decrypt()` -- abrir o envelope, comparar o HMAC, chamar
	 * `openssl_decrypt` -- num laco que percorre todos os usuarios do site.
	 *
	 * Ate o #1234 havia um segundo motivo, maior: cada falha gravava uma linha
	 * em `ffc_activity_log`, sem teto. O teto agora existe (cinco por
	 * requisicao), entao o que sobra e o custo da decifragem em si.
	 *
	 * Prender esse custo exigiria alias-mockar `ActivityLog`, uma classe real e
	 * ja carregada -- fragil e dependente de ordem, que e o que o CLAUDE.md
	 * manda evitar. A razao fica escrita no metodo do produto em vez disso.
	 */
	public function test_a_value_outside_the_v2_scheme_is_left_alone(): void {
		$this->meta[10] = array( 'ffc_user_cpf' => 'texto-em-claro' );

		$this->strategy->execute( '', array() );

		$this->assertSame( 'texto-em-claro', $this->meta[10]['ffc_user_cpf'] );
		$this->assertArrayNotHasKey( 'ffc_user_cpf_hash', $this->meta[10] );
	}
}
