<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\UrlShortener\UrlShortenerQrHandler;
use FreeFormCertificate\UrlShortener\UrlShortenerRepository;
use FreeFormCertificate\UrlShortener\UrlShortenerService;

/**
 * O cache de QR do encurtador so serve o tamanho que declarou (#1233).
 *
 * O DEFEITO QUE ISTO CONSERTA
 *
 * `qr_cache` era indexado apenas pelo `short_code`, ignorando o tamanho
 * pedido. Os chamadores pedem tamanhos diferentes -- 200 na metabox, 100 a
 * 1000 no REST, 400 no download --, entao uma chamada REST com `size=1000`
 * lia o PNG de 200px gravado pela metabox; com o cache vazio, gravava 1000px
 * la e a metabox passava a renderizar um PNG de 1000 num espaco de 200.
 * Corrupcao de conteudo entre chamadores, sem erro e sem log.
 *
 * AS DUAS METADES, E POR QUE AMBAS SAO NECESSARIAS
 *
 * O portao por tamanho em `generate_qr_base64()` decide QUEM fala com o
 * cache; o envelope em `set_qr_cache()`/`get_qr_cache()` decide o que conta
 * como acerto. So o portao nao bastaria: as linhas gravadas ANTES desta
 * correcao estao la, em base64 puro e num tamanho que ninguem registrou, e
 * seriam servidas como se fossem 200. So o envelope tambem nao bastaria: sem
 * o portao, o REST continuaria gravando 901 variantes possiveis por URL,
 * cada uma sobrescrevendo a anterior.
 *
 * O QUE ESTE ARQUIVO NAO PROVA
 *
 * Que o PNG sai correto -- isso depende de GD e do phpqrcode, e nao e o que
 * regride aqui. As asercoes falam do contrato do cache, que e onde o defeito
 * morava.
 *
 * @covers \FreeFormCertificate\UrlShortener\UrlShortenerQrHandler
 */
class UrlShortenerQrCacheSizeTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private UrlShortenerQrHandler $handler;

	/** @var UrlShortenerService|Mockery\MockInterface */
	private $service;

	/** @var UrlShortenerRepository|Mockery\MockInterface */
	private $repo;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		class_exists( '\FreeFormCertificate\UrlShortener\UrlShortenerQrHandler' );

		// `Debug::is_enabled()` guarda em `function_exists( 'get_option' )`, e
		// o gerador chama `Debug::log_qrcode()` na saida por URL vazia que
		// estes testes usam como costura. Stubado aqui de proposito, e nao
		// herdado de quem rodou antes -- ver a nota de ordem no CLAUDE.md.
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data ) {
				return json_encode( $data );
			}
		);

		$this->repo    = Mockery::mock( UrlShortenerRepository::class );
		$this->service = Mockery::mock( UrlShortenerService::class );
		$this->service->shouldReceive( 'get_repository' )->andReturn( $this->repo )->byDefault();

		$this->handler = new UrlShortenerQrHandler( $this->service );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Invoca um metodo privado do handler.
	 *
	 * @param string       $method Nome do metodo.
	 * @param array<mixed> $args   Argumentos.
	 * @return mixed
	 */
	private function call( string $method, array $args ) {
		$ref = new \ReflectionMethod( UrlShortenerQrHandler::class, $method );
		$ref->setAccessible( true );
		return $ref->invokeArgs( $this->handler, $args );
	}

	/**
	 * Captura o payload que `set_qr_cache()` manda para o repositorio.
	 *
	 * @param int    $size   Tamanho declarado.
	 * @param string $base64 PNG.
	 * @return string
	 */
	private function stored_payload( int $size, string $base64 ): string {
		$captured = '';
		$this->repo->shouldReceive( 'setQrCacheForShortCode' )->andReturnUsing(
			function ( $code, $payload ) use ( &$captured ) {
				$captured = (string) $payload;
				return true;
			}
		);

		$this->call( 'set_qr_cache', array( 'abc123', $size, $base64 ) );

		return $captured;
	}

	// ==================================================================
	// O envelope
	// ==================================================================

	/**
	 * O que vai para o banco declara o tamanho junto do PNG.
	 *
	 * E a asercao que sustenta todas as outras: sem o tamanho gravado, nao ha
	 * como uma leitura futura saber se o que esta la serve.
	 */
	public function test_the_stored_payload_declares_its_size(): void {
		$payload = $this->stored_payload( 200, 'PNGDATA' );

		$decoded = json_decode( $payload, true );

		$this->assertIsArray( $decoded, 'O cache precisa gravar um envelope legivel, nao base64 cru.' );
		$this->assertSame( 200, $decoded['size'] ?? null );
		$this->assertSame( 'PNGDATA', $decoded['png'] ?? null );
	}

	/**
	 * Round-trip no tamanho declarado: grava 200, le 200, volta o PNG.
	 *
	 * Autoverificacao do arquivo. Sem ela, um `assertSame( '', ... )` nos
	 * testes abaixo passaria mesmo que a leitura estivesse quebrada para
	 * TODOS os casos -- que e o defeito de medicao que o CLAUDE.md registra:
	 * uma varredura vazia nunca pode ser lida como "limpa".
	 */
	public function test_a_matching_size_reads_back_the_cached_png(): void {
		$payload = $this->stored_payload( 200, 'PNGDATA' );

		$this->repo->shouldReceive( 'findQrCacheByShortCode' )->with( 'abc123' )->andReturn( $payload );

		$this->assertSame( 'PNGDATA', $this->call( 'get_qr_cache', array( 'abc123', 200 ) ) );
	}

	/**
	 * Gravado em 200, pedido em 1000: NAO volta o de 200.
	 *
	 * E o criterio explicito da #1233, e a forma exata do defeito relatado.
	 */
	public function test_a_different_size_is_never_served_from_cache(): void {
		$payload = $this->stored_payload( 200, 'PNGDATA' );

		$this->repo->shouldReceive( 'findQrCacheByShortCode' )->with( 'abc123' )->andReturn( $payload );

		$this->assertSame(
			'',
			$this->call( 'get_qr_cache', array( 'abc123', 1000 ) ),
			'Um cache de 200px foi servido para um pedido de 1000px — e o defeito do #1233 de volta.'
		);
	}

	/**
	 * Uma linha gravada no esquema ANTIGO (base64 cru) e um miss.
	 *
	 * E o caminho de upgrade, e ele nao custa migracao nenhuma: o alfabeto do
	 * base64 nunca comeca por `{`, entao a linha antiga falha o `json_decode`
	 * e e regravada no formato novo na primeira leitura. Sem esta asercao, o
	 * conserto deixaria de fora justamente as instalacoes que ja carregam o
	 * cache envenenado.
	 */
	public function test_a_legacy_bare_base64_row_is_a_miss(): void {
		$this->repo->shouldReceive( 'findQrCacheByShortCode' )->with( 'abc123' )
			->andReturn( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAAB' );

		$this->assertSame( '', $this->call( 'get_qr_cache', array( 'abc123', 200 ) ) );
	}

	/**
	 * Base64 antigo que por acidente e JSON VALIDO tambem e um miss.
	 *
	 * O alfabeto do base64 produz JSON valido em alguns casos -- um payload so
	 * de digitos decodifica para um numero, e um de quatro caracteres pode ser
	 * literalmente `true`. Sem estas asercoes, "linha antiga e sempre miss"
	 * seria uma afirmacao sobre o caso facil apenas.
	 *
	 * O QUE ESTAS ASERCOES NAO PRENDEM, e vale saber antes de mexer: elas
	 * provam o COMPORTAMENTO (miss), nao o mecanismo. Medido por mutacao --
	 * trocar o `is_array()` do produto por `null === $payload` mantem os tres
	 * casos verdes, porque quem reprova de fato e a validacao do envelope
	 * adiante. O `is_array()` e guarda de tipo, contra o warning de acesso a
	 * offset em int; a razao esta escrita no proprio metodo.
	 *
	 * @dataProvider valid_json_that_is_not_an_envelope
	 *
	 * @param string $stored Valor cru na coluna.
	 */
	public function test_legacy_base64_that_parses_as_json_is_still_a_miss( string $stored ): void {
		$this->repo->shouldReceive( 'findQrCacheByShortCode' )->with( 'abc123' )->andReturn( $stored );

		$this->assertSame( '', $this->call( 'get_qr_cache', array( 'abc123', 200 ) ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function valid_json_that_is_not_an_envelope(): array {
		return array(
			'so digitos'  => array( '12345' ),
			'literal true' => array( 'true' ),
			'string JSON'  => array( '"iVBORw0KGgo"' ),
		);
	}

	/**
	 * Um envelope de versao desconhecida e um miss.
	 *
	 * E o que torna `CACHE_SIZE` seguro de mudar depois: a validacao nao
	 * depende de ninguem lembrar de limpar a coluna.
	 */
	public function test_an_unknown_envelope_version_is_a_miss(): void {
		$this->repo->shouldReceive( 'findQrCacheByShortCode' )->with( 'abc123' )
			->andReturn( (string) json_encode( array( 'v' => 99, 'size' => 200, 'png' => 'PNGDATA' ) ) );

		$this->assertSame( '', $this->call( 'get_qr_cache', array( 'abc123', 200 ) ) );
	}

	// ==================================================================
	// O portao por tamanho
	// ==================================================================

	/**
	 * Um tamanho fora do canonico nao fala com o cache — nem para ler, nem
	 * para gravar.
	 *
	 * A URL vazia e a costura: `QRCodeGenerator::generate()` devolve '' antes
	 * de tocar em tempfile ou GD, entao o teste observa o portao sem depender
	 * de extensao nenhuma. Se o portao regredisse, a leitura aconteceria
	 * ANTES do gerador e o `shouldNotReceive` reprovaria.
	 */
	public function test_a_non_canonical_size_bypasses_the_cache_entirely(): void {
		$this->repo->shouldNotReceive( 'findQrCacheByShortCode' );
		$this->repo->shouldNotReceive( 'setQrCacheForShortCode' );

		$this->handler->generate_qr_base64( '', 1000, 'abc123' );
	}

	/**
	 * No tamanho canonico o cache E consultado.
	 *
	 * O par da asercao acima: sem ela, um portao fechado para todo mundo
	 * passaria nos dois testes e o cache estaria morto.
	 */
	public function test_the_canonical_size_does_reach_the_cache(): void {
		$this->repo->shouldReceive( 'findQrCacheByShortCode' )->with( 'abc123' )->once()->andReturn( '' );

		$this->handler->generate_qr_base64( '', UrlShortenerQrHandler::CACHE_SIZE, 'abc123' );
	}

	/**
	 * Sem `short_code` nao ha cache, qualquer que seja o tamanho.
	 *
	 * E o que mantem `handle_download_png()` fora do cache; desde o portao por
	 * tamanho isso deixou de ser a unica coisa que o protegia, mas continua
	 * sendo verdade e vale estar preso.
	 */
	public function test_no_short_code_means_no_cache(): void {
		$this->repo->shouldNotReceive( 'findQrCacheByShortCode' );
		$this->repo->shouldNotReceive( 'setQrCacheForShortCode' );

		$this->handler->generate_qr_base64( '', UrlShortenerQrHandler::CACHE_SIZE, '' );
	}

	/**
	 * A metabox — o unico chamador repetido, e a razao de o cache existir —
	 * pede exatamente o tamanho que o cache serve.
	 *
	 * Congela o acoplamento que o literal `200` escondia: os dois lados agora
	 * leem a mesma constante, entao muda-la nao pode desligar o cache em
	 * silencio.
	 */
	public function test_the_metabox_asks_for_the_size_the_cache_serves(): void {
		$source = (string) file_get_contents(
			dirname( __DIR__, 2 ) . '/includes/url-shortener/class-ffc-url-shortener-meta-box.php'
		);

		$this->assertNotSame( '', $source, 'Nao consegui ler a metabox — a verificacao nao rodou.' );
		$this->assertStringContainsString(
			'UrlShortenerQrHandler::CACHE_SIZE',
			$source,
			'A metabox voltou a pedir um tamanho literal: se ele divergir de CACHE_SIZE, ela para de cachear sem avisar.'
		);
	}
}
