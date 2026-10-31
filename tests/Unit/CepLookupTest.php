<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Integrations\CepLookup;
use PHPUnit\Framework\TestCase;

/**
 * The address lookup by CEP (#2234): one shape over two providers, the
 * fallback only on an outage, and a cache that never stores an outage.
 *
 * Each test answers `wp_remote_get()` per URL, so what the code asked for is
 * read off the recorded calls rather than assumed.
 *
 * @covers \FreeFormCertificate\Integrations\CepLookup
 */
class CepLookupTest extends TestCase {

	/**
	 * URL of every outbound request, with its arguments.
	 *
	 * @var list<array{url: string, args: array<string, mixed>}>
	 */
	private array $requests = array();

	/**
	 * Transients written, by key.
	 *
	 * @var array<string, array{value: mixed, ttl: int}>
	 */
	private array $stored = array();

	/**
	 * What each provider answers: a response array, or 'error' for a WP_Error.
	 *
	 * @var array<string, mixed>
	 */
	private array $answers = array();

	/**
	 * The `ffc_settings` option.
	 *
	 * @var array<string, mixed>
	 */
	private array $settings = array();

	/**
	 * The cached answer `get_transient()` returns, or false.
	 *
	 * @var mixed
	 */
	private $cached = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->requests = array();
		$this->stored   = array();
		$this->answers  = array();
		$this->settings = array();
		$this->cached   = false;

		Functions\when( 'get_option' )->alias(
			function ( $key, $default = false ) {
				return 'ffc_settings' === $key ? $this->settings : $default;
			}
		);
		Functions\when( 'get_transient' )->alias(
			function () {
				return $this->cached;
			}
		);
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value, $ttl ): bool {
				$this->stored[ (string) $key ] = array(
					'value' => $value,
					'ttl'   => (int) $ttl,
				);
				return true;
			}
		);
		Functions\when( 'wp_remote_get' )->alias(
			function ( $url, $args = array() ) {
				$this->requests[] = array(
					'url'  => (string) $url,
					'args' => (array) $args,
				);
				$provider = false !== strpos( (string) $url, 'brasilapi' ) ? 'brasilapi' : 'viacep';
				return $this->answers[ $provider ] ?? 'error';
			}
		);
		Functions\when( 'is_wp_error' )->alias(
			static fn( $thing ): bool => 'error' === $thing
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static fn( $response ): int => is_array( $response ) ? (int) $response['code'] : 0
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static fn( $response ): string => is_array( $response ) ? (string) $response['body'] : ''
		);
		Functions\when( 'sanitize_text_field' )->alias(
			static fn( $value ): string => trim( strip_tags( (string) $value ) )
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A provider response.
	 *
	 * @param int                  $code HTTP status.
	 * @param array<string, mixed> $body Decoded body.
	 * @return array{code: int, body: string}
	 */
	private static function response( int $code, array $body = array() ): array {
		return array(
			'code' => $code,
			'body' => (string) json_encode( $body, JSON_UNESCAPED_UNICODE ),
		);
	}

	private static function viacep_found(): array {
		return self::response(
			200,
			array(
				'cep'         => '01001-000',
				'logradouro'  => 'Praça da Sé',
				'complemento' => 'lado ímpar',
				'bairro'      => 'Sé',
				'localidade'  => 'São Paulo',
				'uf'          => 'sp',
			)
		);
	}

	private static function brasilapi_found(): array {
		return self::response(
			200,
			array(
				'cep'          => '01001000',
				'state'        => 'SP',
				'city'         => 'São Paulo',
				'neighborhood' => 'Sé',
				'street'       => 'Praça da Sé',
			)
		);
	}

	public function test_primary_viacep_answers_and_the_answer_is_cached_for_30_days(): void {
		$this->answers['viacep'] = self::viacep_found();

		$answer = CepLookup::lookup( '01001-000' );

		$this->assertSame( CepLookup::FOUND, $answer['status'] );
		$this->assertSame( 'viacep', $answer['provider'] );
		$this->assertSame(
			array(
				'street'       => 'Praça da Sé',
				'neighborhood' => 'Sé',
				'city'         => 'São Paulo',
				'uf'           => 'SP',
				'complement'   => 'lado ímpar',
			),
			$answer['address']
		);
		$this->assertCount( 1, $this->requests, 'BrasilAPI is not asked when ViaCEP answers.' );
		$this->assertSame( 'https://viacep.com.br/ws/01001000/json/', $this->requests[0]['url'] );
		$this->assertSame( 3, $this->requests[0]['args']['timeout'] );
		$this->assertSame( 0, $this->requests[0]['args']['redirection'] );
		$this->assertSame( array( 'ffc_cep_01001000' ), array_keys( $this->stored ) );
		$this->assertSame( 30 * DAY_IN_SECONDS, $this->stored['ffc_cep_01001000']['ttl'] );
	}

	public function test_brasilapi_can_be_the_primary(): void {
		$this->settings['cep_lookup_primary'] = 'brasilapi';
		$this->answers['brasilapi']           = self::brasilapi_found();

		$answer = CepLookup::lookup( '01001000' );

		$this->assertSame( 'brasilapi', $answer['provider'] );
		$this->assertSame( 'Praça da Sé', $answer['address']['street'] );
		$this->assertSame( '', $answer['address']['complement'] );
		$this->assertSame( 'https://brasilapi.com.br/api/cep/v2/01001000', $this->requests[0]['url'] );
	}

	public function test_an_unknown_primary_reads_as_viacep(): void {
		$this->settings['cep_lookup_primary'] = 'correios';
		$this->assertSame( 'viacep', CepLookup::primary() );
	}

	public function test_a_primary_outage_falls_back_with_the_shorter_timeout(): void {
		$this->answers['viacep']    = 'error';
		$this->answers['brasilapi'] = self::brasilapi_found();

		$answer = CepLookup::lookup( '01001-000' );

		$this->assertSame( CepLookup::FOUND, $answer['status'] );
		$this->assertSame( 'brasilapi', $answer['provider'] );
		$this->assertCount( 2, $this->requests );
		$this->assertSame( 2, $this->requests[1]['args']['timeout'] );
	}

	public function test_a_5xx_is_an_outage_too(): void {
		$this->answers['viacep']    = self::response( 503 );
		$this->answers['brasilapi'] = self::brasilapi_found();

		$this->assertSame( 'brasilapi', CepLookup::lookup( '01001-000' )['provider'] );
	}

	/**
	 * @dataProvider not_found_from_viacep
	 *
	 * @param array{code: int, body: string} $response ViaCEP's answer.
	 */
	public function test_not_found_from_the_primary_is_final_and_cached( array $response ): void {
		$this->answers['viacep']    = $response;
		$this->answers['brasilapi'] = self::brasilapi_found();

		$answer = CepLookup::lookup( '01001-000' );

		$this->assertSame( CepLookup::NOT_FOUND, $answer['status'] );
		$this->assertCount( 1, $this->requests, 'BrasilAPI is never asked after a "not found".' );
		$this->assertArrayHasKey( 'ffc_cep_01001000', $this->stored );
	}

	/**
	 * @return array<string, array{0: array{code: int, body: string}}>
	 */
	public static function not_found_from_viacep(): array {
		return array(
			'erro as a string'  => array( self::response( 200, array( 'erro' => 'true' ) ) ),
			'erro as a boolean' => array( self::response( 200, array( 'erro' => true ) ) ),
			'a 400'             => array( self::response( 400 ) ),
			'a 404'             => array( self::response( 404 ) ),
		);
	}

	public function test_both_down_is_unavailable_and_not_cached(): void {
		$answer = CepLookup::lookup( '01001-000' );

		$this->assertSame( CepLookup::UNAVAILABLE, $answer['status'] );
		$this->assertCount( 2, $this->requests );
		$this->assertSame( array(), $this->stored, 'An outage is never cached.' );
	}

	public function test_an_answer_with_no_city_and_no_uf_names_no_place(): void {
		$this->answers['viacep'] = self::response( 200, array( 'logradouro' => 'Rua X' ) );

		$this->assertSame( CepLookup::UNAVAILABLE, CepLookup::query( 'viacep', '01001000', 3 )['status'] );
	}

	public function test_a_city_only_cep_is_found_with_an_empty_street(): void {
		$this->answers['viacep'] = self::response(
			200,
			array(
				'logradouro' => '',
				'bairro'     => '',
				'localidade' => 'Ribeirão Branco',
				'uf'         => 'SP',
			)
		);

		$answer = CepLookup::lookup( '18430-000' );
		$this->assertSame( CepLookup::FOUND, $answer['status'] );
		$this->assertSame( '', $answer['address']['street'] );
		$this->assertSame( 'Ribeirão Branco', $answer['address']['city'] );
	}

	public function test_markup_in_an_answer_is_reduced_to_text(): void {
		$this->answers['viacep'] = self::response(
			200,
			array(
				'logradouro' => '<script>alert(1)</script>Rua A',
				'localidade' => 'São Paulo',
				'uf'         => 'SP',
			)
		);

		$this->assertSame( 'alert(1)Rua A', CepLookup::lookup( '01001-000' )['address']['street'] );
	}

	/**
	 * @dataProvider invalid_ceps
	 */
	public function test_a_cep_the_offline_rule_refuses_never_leaves_the_server( string $cep ): void {
		$this->assertSame( CepLookup::NOT_FOUND, CepLookup::lookup( $cep )['status'] );
		$this->assertSame( array(), $this->requests );
		$this->assertSame( array(), $this->stored );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function invalid_ceps(): array {
		return array(
			'short'    => array( '0100' ),
			'repeated' => array( '11111-111' ),
			'range'    => array( '00999-000' ),
			'letters'  => array( 'abcde-fgh' ),
		);
	}

	public function test_a_cached_answer_is_returned_without_a_request(): void {
		$this->cached = array(
			'status'   => CepLookup::FOUND,
			'address'  => array(
				'street'       => 'Cached',
				'neighborhood' => '',
				'city'         => 'São Paulo',
				'uf'           => 'SP',
				'complement'   => '',
			),
			'provider' => 'viacep',
		);

		$this->assertSame( 'Cached', CepLookup::lookup( '01001-000' )['address']['street'] );
		$this->assertSame( array(), $this->requests );
	}

	public function test_a_malformed_cache_entry_is_ignored(): void {
		$this->cached            = array( 'status' => CepLookup::FOUND );
		$this->answers['viacep'] = self::viacep_found();

		$this->assertSame( 'Praça da Sé', CepLookup::lookup( '01001-000' )['address']['street'] );
		$this->assertCount( 1, $this->requests );
	}

	public function test_enabled_reads_the_toggle_and_is_off_by_default(): void {
		$this->assertFalse( CepLookup::enabled() );
		$this->settings['cep_lookup_enabled'] = true;
		$this->assertTrue( CepLookup::enabled() );
	}
}

