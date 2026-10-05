<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\UrlShortener\QrGeneratorAjaxEndpoint;
use FreeFormCertificate\UrlShortener\UrlShortenerService;

/**
 * The manual generator's endpoints (#1563): drawing is read-only and follows
 * every change; shortening is the one write, behind its own nonce.
 *
 * @covers \FreeFormCertificate\UrlShortener\QrGeneratorAjaxEndpoint
 */
class QrGeneratorAjaxEndpointTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var UrlShortenerService|Mockery\MockInterface */
	private $service;

	private QrGeneratorAjaxEndpoint $endpoint;

	/** @var array<int, string> */
	private array $caps = array( 'ffc_manage_url_shortener' );

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_key' )->alias( static fn( $k ) => preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ) );
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
		Functions\when( 'wp_check_invalid_utf8' )->returnArg();
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_http_validate_url' )->alias( static fn( $u ) => filter_var( $u, FILTER_VALIDATE_URL ) ? $u : false );
		Functions\when( 'is_wp_error' )->alias( static fn( $v ) => $v instanceof \WP_Error );
		Functions\when( 'check_ajax_referer' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->alias( fn( $cap ) => in_array( $cap, $this->caps, true ) );
		Functions\when( 'wp_send_json_error' )->alias(
			static function ( $data = null, $status = null ) {
				throw new \RuntimeException( 'error:' . ( $status ?? '' ) . ':' . json_encode( $data ) );
			}
		);
		Functions\when( 'wp_send_json_success' )->alias(
			static function ( $data = null ) {
				throw new \RuntimeException( 'success:' . json_encode( $data ) );
			}
		);

		$this->service  = Mockery::mock( UrlShortenerService::class );
		$this->endpoint = new QrGeneratorAjaxEndpoint( $this->service );
	}

	protected function tearDown(): void {
		$_POST = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Run a handler and return [kind, decoded data].
	 *
	 * @return array{0: string, 1: mixed}
	 */
	private function run_handler( string $method ): array {
		try {
			$this->endpoint->$method();
		} catch ( \RuntimeException $e ) {
			$message = $e->getMessage();
			if ( 0 === strpos( $message, 'success:' ) ) {
				return array( 'success', json_decode( substr( $message, 8 ), true ) );
			}
			$parts = explode( ':', $message, 3 );
			return array( 'error:' . $parts[1], json_decode( $parts[2], true ) );
		}
		$this->fail( 'The handler must answer.' );
	}

	public function test_init_registers_both_actions(): void {
		Functions\expect( 'add_action' )->twice();

		$this->endpoint->init();
	}

	public function test_both_actions_check_their_own_nonce(): void {
		$checked = array();
		Functions\when( 'check_ajax_referer' )->alias(
			static function ( $action, $field ) use ( &$checked ) {
				$checked[] = $action . '|' . $field;
				return 1;
			}
		);
		$this->service->shouldReceive( 'create_short_url' )->andReturn( array( 'success' => false ) );

		$_POST = array( 'type' => 'text', 'fields' => array( 'text' => 'x' ) );
		$this->run_handler( 'handle_generate' );
		$_POST = array( 'url' => 'https://example.com' );
		$this->run_handler( 'handle_shorten' );

		$this->assertSame( array( 'ffc_qr_generate|nonce', 'ffc_qr_shorten|nonce' ), $checked );
	}

	public function test_without_the_manage_cap_nothing_is_drawn_or_shortened(): void {
		$this->caps = array( 'ffc_view_url_shortener' );

		$this->assertSame( 'error:403', $this->run_handler( 'handle_generate' )[0] );
		$this->service->shouldNotReceive( 'create_short_url' );
		$this->assertSame( 'error:403', $this->run_handler( 'handle_shorten' )[0] );
	}

	public function test_generate_draws_the_payload_with_usage_and_checks(): void {
		$_POST = array(
			'type'        => 'wifi',
			'fields'      => array(
				'ssid'     => 'Escola',
				'password' => "p'a<s>s",
				'security' => 'WPA',
			),
			'design'      => array( 'dots' => 'dots' ),
			'error_level' => 'q',
		);

		list( $kind, $data ) = $this->run_handler( 'handle_generate' );

		$this->assertSame( 'success', $kind );
		// The password is encoded as typed: no sanitiser mangles it.
		$this->assertSame( "WIFI:T:WPA;S:Escola;P:p'a<s>s;;", $data['payload'] );
		$this->assertStringContainsString( '<circle', $data['svg'] );
		$this->assertSame( 1000, $data['width'] );
		$this->assertSame( 1663, $data['usage']['capacity'] );
		$this->assertFalse( $data['checks']['inverted'] );
	}

	public function test_invalid_content_is_a_400_with_the_reason(): void {
		$_POST = array(
			'type'   => 'email',
			'fields' => array( 'email' => 'nobody@' ),
		);
		Functions\when( 'sanitize_email' )->returnArg();
		Functions\when( 'is_email' )->justReturn( false );

		list( $kind, $data ) = $this->run_handler( 'handle_generate' );

		$this->assertSame( 'error:400', $kind );
		$this->assertSame( 'Enter a valid e-mail address.', $data['message'] );
	}

	public function test_content_over_capacity_reports_the_usage(): void {
		$_POST = array(
			'type'        => 'text',
			'fields'      => array( 'text' => str_repeat( 'x', 1400 ) ),
			'error_level' => 'H',
		);

		list( $kind, $data ) = $this->run_handler( 'handle_generate' );

		$this->assertSame( 'error:400', $kind );
		$this->assertSame( 1400, $data['usage']['bytes'] );
		$this->assertSame( 100, $data['usage']['percent'] );
	}

	public function test_shorten_creates_a_short_url_for_a_valid_address(): void {
		$_POST = array(
			'url'   => 'example.com/page',
			'title' => 'Flyer',
		);
		$this->service->shouldReceive( 'create_short_url' )->once()->with( 'https://example.com/page', 'Flyer' )
			->andReturn( array( 'success' => true, 'data' => array( 'short_code' => 'abc123' ) ) );
		$this->service->shouldReceive( 'get_short_url' )->with( 'abc123' )->andReturn( 'https://site.test/go/abc123' );

		list( $kind, $data ) = $this->run_handler( 'handle_shorten' );

		$this->assertSame( 'success', $kind );
		$this->assertSame( 'https://site.test/go/abc123', $data['short_url'] );
	}

	public function test_shorten_refuses_an_invalid_address_without_writing(): void {
		$_POST = array( 'url' => 'not a url' );
		$this->service->shouldNotReceive( 'create_short_url' );

		$this->assertSame( 'error:400', $this->run_handler( 'handle_shorten' )[0] );
	}

	public function test_shorten_reports_a_failed_write(): void {
		$_POST = array( 'url' => 'https://example.com' );
		$this->service->shouldReceive( 'create_short_url' )->andReturn( array( 'success' => false, 'error' => 'Database error.' ) );

		list( $kind, $data ) = $this->run_handler( 'handle_shorten' );

		$this->assertSame( 'error:', $kind );
		$this->assertSame( 'Database error.', $data['message'] );
	}
}
