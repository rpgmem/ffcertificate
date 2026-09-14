<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * As quatro cadeias de activator que o `Loader` chama em `plugins_loaded`
 * sondam o schema no maximo uma vez por `FFC_VERSION` (#1231).
 *
 * O QUE ESTAVA ERRADO
 *
 * `table_exists()` e um `SHOW TABLES LIKE` sem cache e todo
 * `add_column_if_missing()` dispara um `SHOW COLUMNS` antes de decidir nao
 * fazer nada. Somadas, as quatro cadeias custavam 48 queries DDL por
 * requisicao HTTP -- frontend anonimo incluido -- numa instalacao onde nao
 * havia nada a migrar.
 *
 * POR QUE A GUARDA E `FFC_VERSION` E NAO UM MARCADOR ONE-SHOT
 *
 * Estas chamadas existem porque um update in-place do plugin NAO dispara
 * `register_activation_hook`. A propriedade a preservar e "o schema se cura
 * depois de um update", nao "roda a cada request" -- e so a guarda por versao
 * preserva as duas metades. Por isso este teste cobra as DUAS direcoes: a
 * segunda chamada na mesma versao nao sonda nada, e uma versao diferente
 * re-arma. Um teste que so verificasse a primeira passaria com um booleano
 * one-shot, que e justamente a implementacao errada.
 *
 * COMO A SONDAGEM E OBSERVADA
 *
 * Sem banco, o que se pode observar sao as chamadas a `$wpdb`. O duplo abaixo
 * conta toda consulta que chegue nele; a cadeia guardada nao deve emitir
 * nenhuma, e a nao guardada tem de emitir pelo menos uma -- senao o teste
 * estaria verde sobre uma cadeia que nunca sondou coisa alguma, que e o
 * defeito de medicao que o CLAUDE.md registra (uma varredura vazia nunca pode
 * ser lida como "limpa").
 *
 * @covers \FreeFormCertificate\SelfScheduling\SelfSchedulingActivator
 * @covers \FreeFormCertificate\Audience\AudienceActivator
 * @covers \FreeFormCertificate\UrlShortener\UrlShortenerActivator
 * @covers \FreeFormCertificate\Recruitment\RecruitmentActivator
 */
class ActivatorSchemaGuardTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Cadeia => opcao de versao que a guarda.
	 *
	 * Congelado de proposito: uma cadeia nova chamada de `plugins_loaded` sem
	 * guarda nao aparece aqui sozinha, mas o teste de manifesto abaixo cobra
	 * que toda opcao listada esteja declarada em `uninstall.php`.
	 *
	 * @var array<string, array{0: class-string, 1: string, 2: string}>
	 */
	private const GUARDED_CHAINS = array(
		'self-scheduling' => array( '\FreeFormCertificate\SelfScheduling\SelfSchedulingActivator', 'maybe_migrate', 'ffc_self_scheduling_schema_version' ),
		'audience'        => array( '\FreeFormCertificate\Audience\AudienceActivator', 'maybe_migrate', 'ffc_audience_schema_version' ),
		'url-shortener'   => array( '\FreeFormCertificate\UrlShortener\UrlShortenerActivator', 'maybe_migrate', 'ffc_url_shortener_schema_version' ),
		'recruitment'     => array( '\FreeFormCertificate\Recruitment\RecruitmentActivator', 'create_tables', 'ffc_recruitment_tables_version' ),
	);

	/**
	 * Contagem de consultas que chegaram ao duplo de `$wpdb`.
	 *
	 * @var int
	 */
	private int $queries = 0;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		foreach ( self::GUARDED_CHAINS as $chain ) {
			class_exists( $chain[0] );
		}

		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );

		$this->install_wpdb_double();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Um `$wpdb` que apenas CONTA — nao simula schema nenhum. O que este teste
	 * mede e se a cadeia chegou a falar com o banco, nao o que ela perguntou.
	 */
	private function install_wpdb_double(): void {
		$counter = function (): void {
			++$this->queries;
		};

		$wpdb = new class( $counter ) {
			// phpcs:ignore Squiz.Commenting.VariableComment.Missing
			public string $prefix = 'wp_';

			/** @var callable */
			private $counter;

			/**
			 * @param callable $counter Incrementa a contagem.
			 */
			public function __construct( callable $counter ) {
				$this->counter = $counter;
			}

			/**
			 * @param mixed ...$args Ignorados.
			 * @return string
			 */
			public function prepare( ...$args ): string {
				return is_string( $args[0] ?? '' ) ? (string) $args[0] : '';
			}

			/**
			 * @param mixed ...$args Ignorados.
			 * @return null
			 */
			public function get_var( ...$args ) {
				( $this->counter )();
				return null;
			}

			/**
			 * @param mixed ...$args Ignorados.
			 * @return array<int, mixed>
			 */
			public function get_results( ...$args ): array {
				( $this->counter )();
				return array();
			}

			/**
			 * @param mixed ...$args Ignorados.
			 * @return int
			 */
			public function query( ...$args ): int {
				( $this->counter )();
				return 0;
			}

			/**
			 * @return string
			 */
			public function get_charset_collate(): string {
				return '';
			}

			/**
			 * @param string $value Valor.
			 * @return string
			 */
			public function esc_like( string $value ): string {
				return $value;
			}

			/**
			 * @param bool $suppress Suprimir.
			 * @return bool
			 */
			public function suppress_errors( bool $suppress = true ): bool {
				return ! $suppress;
			}

			/**
			 * @param string $message Mensagem.
			 * @return void
			 */
			public function print_error( string $message = '' ): void {
			}
		};

		$GLOBALS['wpdb'] = $wpdb;
	}

	/**
	 * Roda a cadeia com a opcao num valor dado e devolve quantas consultas
	 * chegaram ao banco.
	 *
	 * @param string $class    Classe do activator.
	 * @param string $method   Metodo da cadeia.
	 * @param string $option   Opcao de versao.
	 * @param string $stored   Valor que a opcao ja tem.
	 * @return int
	 */
	private function run_chain( string $class, string $method, string $option, string $stored ): int {
		$this->queries = 0;

		Functions\when( 'get_option' )->alias(
			static function ( string $key, $default_value = false ) use ( $option, $stored ) {
				return $key === $option ? $stored : $default_value;
			}
		);
		Functions\when( 'update_option' )->justReturn( true );

		// `dbDelta()` mora em wp-admin/includes/upgrade.php, que nao existe
		// aqui; as cadeias fazem `require_once ABSPATH . …` antes de chamar.
		Functions\when( 'dbDelta' )->justReturn( array() );

		call_user_func( array( $class, $method ) );

		return $this->queries;
	}

	/**
	 * @dataProvider guarded_chains
	 *
	 * @param string $class  Classe do activator.
	 * @param string $method Metodo da cadeia.
	 * @param string $option Opcao de versao.
	 */
	public function test_chain_does_not_probe_the_schema_on_the_current_version( string $class, string $method, string $option ): void {
		// Autoverificacao: sem a guarda a cadeia TEM de falar com o banco.
		// Se nao falasse, um zero abaixo nao significaria nada.
		$unguarded = $this->run_chain( $class, $method, $option, 'uma-versao-antiga' );
		$this->assertGreaterThan(
			0,
			$unguarded,
			sprintf( '%s::%s() nao sondou o schema nem sem a guarda — a medicao esta quebrada, nao a cadeia.', $class, $method )
		);

		$guarded = $this->run_chain( $class, $method, $option, FFC_VERSION );
		$this->assertSame(
			0,
			$guarded,
			sprintf( '%s::%s() sondou o schema com a opcao ja em FFC_VERSION (%d consultas).', $class, $method, $guarded )
		);
	}

	/**
	 * A outra direcao da guarda: uma versao diferente RE-ARMA a cadeia.
	 *
	 * E o que separa a guarda por versao de um marcador one-shot — e o que
	 * preserva a cura de schema depois de um update in-place, que e a razao
	 * de estas chamadas existirem.
	 *
	 * @dataProvider guarded_chains
	 *
	 * @param string $class  Classe do activator.
	 * @param string $method Metodo da cadeia.
	 * @param string $option Opcao de versao.
	 */
	public function test_a_version_bump_rearms_the_chain( string $class, string $method, string $option ): void {
		$after_bump = $this->run_chain( $class, $method, $option, FFC_VERSION . '-anterior' );

		$this->assertGreaterThan(
			0,
			$after_bump,
			sprintf(
				'%s::%s() nao re-armou numa versao diferente: um update in-place deixaria de curar o schema.',
				$class,
				$method
			)
		);
	}

	/**
	 * Toda opcao de guarda tem de estar declarada em `uninstall.php`.
	 *
	 * O job `fresh-install` compara nos dois sentidos (#994): uma opcao que a
	 * ativacao escreve e o manifesto nao declara reprova o CI. Cobrar aqui
	 * tambem faz a falha aparecer no PHPUnit, que e onde quem escreveu a
	 * guarda esta olhando.
	 */
	public function test_every_guard_option_is_declared_in_the_uninstall_manifest(): void {
		$manifest = (string) file_get_contents( dirname( __DIR__, 2 ) . '/uninstall.php' );

		$this->assertNotSame( '', $manifest, 'Nao consegui ler uninstall.php — a verificacao nao rodou.' );

		foreach ( self::GUARDED_CHAINS as $chain ) {
			$this->assertStringContainsString(
				"'" . $chain[2] . "'",
				$manifest,
				sprintf( 'A opcao %s nao esta declarada em uninstall.php — o gate fresh-install vai reprovar.', $chain[2] )
			);
		}
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function guarded_chains(): array {
		$cases = array();
		foreach ( self::GUARDED_CHAINS as $name => $chain ) {
			$cases[ $name ] = array( $chain[0], $chain[1], $chain[2] );
		}
		return $cases;
	}
}
