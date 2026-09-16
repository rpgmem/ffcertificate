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
 * The shortener's QR cache only serves the size it declared (#1233).
 *
 * THE DEFECT THIS FIXES
 *
 * `qr_cache` was keyed on the `short_code` alone, ignoring the requested size.
 * The callers ask for different sizes -- 200 in the metabox, 100 to 1000 in
 * REST, 400 on download -- so a REST call with `size=1000` read the 200px PNG
 * the metabox had stored; with an empty cache, it stored 1000px there and the
 * metabox started rendering a 1000px PNG in a 200px space. Content corruption
 * between callers, with no error and no log.
 *
 * THE TWO HALVES, AND WHY BOTH ARE NEEDED
 *
 * The size gate in `generate_qr_base64()` decides WHO talks to the cache; the
 * envelope in `set_qr_cache()`/`get_qr_cache()` decides what counts as a hit.
 * The gate alone would not be enough: the rows stored BEFORE this fix are still
 * there, in bare base64 and at a size nobody recorded, and would be served as
 * though they were 200. The envelope alone would not be enough either: without
 * the gate, REST would keep storing 901 possible variants per URL, each one
 * overwriting the last.
 *
 * WHAT THIS FILE DOES NOT PROVE
 *
 * That the PNG comes out correct -- that depends on GD and on phpqrcode, and is
 * not what regresses here. The assertions speak about the cache's contract,
 * which is where the defect lived.
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

		// `Debug::is_enabled()` guards on `function_exists( 'get_option' )`, and
		// the generator calls `Debug::log_qrcode()` on the empty-URL exit these
		// tests use as a seam. Stubbed here on purpose, and not inherited from
		// whatever ran before -- see the ordering note in CLAUDE.md.
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
	 * Captures the payload `set_qr_cache()` sends to the repository.
	 *
	 * @param int    $size   Declared size.
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
	// The envelope
	// ==================================================================

	/**
	 * What goes into the database declares the size alongside the PNG.
	 *
	 * It is the assertion holding all the others up: without the stored size,
	 * there is no way for a later read to know whether what is there serves.
	 */
	public function test_the_stored_payload_declares_its_size(): void {
		$payload = $this->stored_payload( 200, 'PNGDATA' );

		$decoded = json_decode( $payload, true );

		$this->assertIsArray( $decoded, 'The cache must store a readable envelope, not raw base64.' );
		$this->assertSame( 200, $decoded['size'] ?? null );
		$this->assertSame( 'PNGDATA', $decoded['png'] ?? null );
	}

	/**
	 * Round trip at the declared size: store 200, read 200, get the PNG back.
	 *
	 * The file's self-check. Without it, an `assertSame( '', ... )` in the tests
	 * below would pass even if the read were broken for ALL cases -- which is the
	 * measurement defect CLAUDE.md records: an empty scan must never read as
	 * "clean".
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
	 * A row stored in the OLD scheme (bare base64) is a miss.
	 *
	 * It is the upgrade path, and it costs no migration at all: the base64
	 * alphabet never starts with `{`, so the old row fails `json_decode` and is
	 * rewritten in the new format on the first read. Without this assertion, the
	 * fix would leave out precisely the installs already carrying the poisoned
	 * cache.
	 */
	public function test_a_legacy_bare_base64_row_is_a_miss(): void {
		$this->repo->shouldReceive( 'findQrCacheByShortCode' )->with( 'abc123' )
			->andReturn( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAAB' );

		$this->assertSame( '', $this->call( 'get_qr_cache', array( 'abc123', 200 ) ) );
	}

	/**
	 * Old base64 that happens to be VALID JSON is a miss too.
	 *
	 * The base64 alphabet produces valid JSON in some cases -- an all-digit
	 * payload decodes to a number, and a four-character one can literally be
	 * `true`. Without these assertions, "an old row is always a miss" would be a
	 * statement about the easy case only.
	 *
	 * WHAT THESE ASSERTIONS DO NOT PIN, and it is worth knowing before touching
	 * them: they prove the BEHAVIOUR (a miss), not the mechanism. Measured by
	 * mutation -- swapping the product's `is_array()` for `null === $payload`
	 * keeps all three cases green, because what actually refuses is the envelope
	 * validation further on. The `is_array()` is a type guard, against the
	 * offset-access-on-int warning; the reason is written in the method itself.
	 *
	 * @dataProvider valid_json_that_is_not_an_envelope
	 *
	 * @param string $stored Raw value in the column.
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
	 * An envelope of unknown version is a miss.
	 *
	 * It is what makes `CACHE_SIZE` safe to change later: the validation does not
	 * depend on anybody remembering to clear the column.
	 */
	public function test_an_unknown_envelope_version_is_a_miss(): void {
		$this->repo->shouldReceive( 'findQrCacheByShortCode' )->with( 'abc123' )
			->andReturn( (string) json_encode( array( 'v' => 99, 'size' => 200, 'png' => 'PNGDATA' ) ) );

		$this->assertSame( '', $this->call( 'get_qr_cache', array( 'abc123', 200 ) ) );
	}

	// ==================================================================
	// The size gate
	// ==================================================================

	/**
	 * A non-canonical size does not talk to the cache — neither to read nor to
	 * write.
	 *
	 * The empty URL is the seam: `QRCodeGenerator::generate()` returns '' before
	 * touching a tempfile or GD, so the test observes the gate without depending
	 * on any extension. If the gate regressed, the read would happen BEFORE the
	 * generator and the `shouldNotReceive` would fail.
	 */
	public function test_a_non_canonical_size_bypasses_the_cache_entirely(): void {
		$this->repo->shouldNotReceive( 'findQrCacheByShortCode' );
		$this->repo->shouldNotReceive( 'setQrCacheForShortCode' );

		$this->handler->generate_qr_base64( '', 1000, 'abc123' );
	}

	/**
	 * At the canonical size the cache IS consulted.
	 *
	 * The pair of the assertion above: without it, a gate closed to everybody
	 * would pass both tests and the cache would be dead.
	 */
	public function test_the_canonical_size_does_reach_the_cache(): void {
		$this->repo->shouldReceive( 'findQrCacheByShortCode' )->with( 'abc123' )->once()->andReturn( '' );

		$this->handler->generate_qr_base64( '', UrlShortenerQrHandler::CACHE_SIZE, 'abc123' );
	}

	/**
	 * With no `short_code` there is no cache, whatever the size.
	 *
	 * It is what keeps `handle_download_png()` out of the cache; since the size
	 * gate that stopped being the only thing protecting it, but it is still true
	 * and worth pinning.
	 */
	public function test_no_short_code_means_no_cache(): void {
		$this->repo->shouldNotReceive( 'findQrCacheByShortCode' );
		$this->repo->shouldNotReceive( 'setQrCacheForShortCode' );

		$this->handler->generate_qr_base64( '', UrlShortenerQrHandler::CACHE_SIZE, '' );
	}

	/**
	 * The metabox — the only repeated caller, and the reason the cache exists —
	 * asks for exactly the size the cache serves.
	 *
	 * It freezes the coupling the literal `200` hid: both sides now read the same
	 * constant, so changing it cannot switch the cache off in silence.
	 */
	public function test_the_metabox_asks_for_the_size_the_cache_serves(): void {
		$source = (string) file_get_contents(
			dirname( __DIR__, 2 ) . '/includes/url-shortener/class-ffc-url-shortener-meta-box.php'
		);

		$this->assertNotSame( '', $source, 'Could not read the metabox — the check did not run.' );
		$this->assertStringContainsString(
			'UrlShortenerQrHandler::CACHE_SIZE',
			$source,
			'A metabox voltou a pedir um tamanho literal: se ele divergir de CACHE_SIZE, ela para de cachear sem avisar.'
		);
	}
}
