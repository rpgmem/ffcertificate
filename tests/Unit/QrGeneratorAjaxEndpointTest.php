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
		Functions\when( 'wp_parse_url' )->alias( static fn( $u, $c = -1 ) => parse_url( $u, $c ) );
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

	public function test_init_registers_every_action(): void {
		Functions\expect( 'add_action' )->times( 3 );

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
		$_POST = array( 'type' => 'url', 'fields' => array( 'url' => 'not a url' ) );
		$this->run_handler( 'handle_shorten' );
		$this->stub_user_meta();
		$_POST = array( 'reset' => '1' );
		$this->run_handler( 'handle_remember' );

		$this->assertSame( array( 'ffc_qr_generate|nonce', 'ffc_qr_shorten|nonce', 'ffc_qr_remember|nonce' ), $checked );
	}

	public function test_without_the_manage_cap_nothing_is_drawn_or_shortened(): void {
		$this->caps = array( 'ffc_view_url_shortener' );

		$this->assertSame( 'error:403', $this->run_handler( 'handle_generate' )[0] );
		$this->service->shouldNotReceive( 'create_short_url' );
		$this->assertSame( 'error:403', $this->run_handler( 'handle_shorten' )[0] );
		Functions\expect( 'update_user_meta' )->never();
		Functions\expect( 'delete_user_meta' )->never();
		$this->assertSame( 'error:403', $this->run_handler( 'handle_remember' )[0] );
	}

	/** @var array<string, mixed> */
	private array $meta = array();

	/**
	 * User 5 is logged in; its meta lives in $this->meta.
	 */
	private function stub_user_meta(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( $s ) => strip_tags( (string) $s ) );
		Functions\when( 'get_user_meta' )->alias( fn( $id, $key ) => $this->meta[ $key ] ?? '' );
		Functions\when( 'update_user_meta' )->alias(
			function ( $id, $key, $value ) {
				$this->meta[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_user_meta' )->alias(
			function ( $id, $key ) {
				unset( $this->meta[ $key ] );
				return true;
			}
		);
		Functions\when( 'wp_get_attachment_image_url' )->justReturn( '' );
	}

	public function test_remember_stores_the_design_only(): void {
		$this->stub_user_meta();
		$_POST = array(
			'design'      => array( 'dots' => 'fluid', 'color' => '#112233', 'gradient' => '1', 'color_end' => '#445566' ),
			'logo_id'     => '0',
			'margin'      => '3',
			'error_level' => 'H',
			'fields'      => array( 'password' => 'secret' ),
		);

		list( $kind, $data ) = $this->run_handler( 'handle_remember' );

		$this->assertSame( 'success', $kind );
		$stored = $this->meta['ffc_qr_generator_design'];
		$this->assertSame( 'fluid', $stored['qr_design_dots'] );
		$this->assertSame( '#112233', $stored['qr_design_color'] );
		$this->assertTrue( $stored['qr_design_gradient'] );
		$this->assertSame( 3, $stored['margin'] );
		$this->assertSame( 'H', $stored['error_level'] );
		$this->assertStringNotContainsString( 'secret', (string) json_encode( $stored ), 'Content never reaches the database.' );
		$this->assertSame( $stored, $data['state'] );
	}

	public function test_remember_drops_a_logo_the_user_cannot_read(): void {
		$this->stub_user_meta();
		$_POST = array( 'design' => array(), 'logo_id' => '42' );

		$this->run_handler( 'handle_remember' );
		$this->assertSame( 0, $this->meta['ffc_qr_generator_design']['qr_design_logo_id'] );

		$this->caps[] = 'read_post';
		$this->run_handler( 'handle_remember' );
		$this->assertSame( 42, $this->meta['ffc_qr_generator_design']['qr_design_logo_id'] );
	}

	public function test_reset_forgets_and_answers_with_the_global_design(): void {
		$this->stub_user_meta();
		$this->meta['ffc_qr_generator_design'] = array( 'qr_design_dots' => 'fluid' );
		$_POST = array( 'reset' => '1' );

		list( $kind, $data ) = $this->run_handler( 'handle_remember' );

		$this->assertSame( 'success', $kind );
		$this->assertArrayNotHasKey( 'ffc_qr_generator_design', $this->meta );
		$this->assertSame( 'square', $data['state']['qr_design_dots'] );
		$this->assertSame( '', $data['logo_thumb'] );
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
		$this->assertSame( 'Q', $data['usage']['level'] );
		$this->assertFalse( $data['usage']['forced'] );
		$room = $data['usage']['remaining'];
		$this->assertSame( strlen( $data['payload'] ) + $room, $data['usage']['capacity'] );
		$this->assertNotSame( array(), \FreeFormCertificate\Generators\QrSvgRenderer::matrix( $data['payload'] . str_repeat( 'a', $room ), 'Q' ), 'The room reported fits the real encoder.' );
		$this->assertSame( array(), \FreeFormCertificate\Generators\QrSvgRenderer::matrix( $data['payload'] . str_repeat( 'a', $room + 1 ), 'Q' ), 'One more character does not.' );
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
		$this->assertSame( 0, $data['usage']['remaining'] );
		$this->assertSame( 1400 - 1268, $data['usage']['over'], 'H holds 1268 plain characters.' );
		$this->assertSame( 'This content is about 132 characters too long for error correction H. Shorten it, or lower the level.', $data['message'] );
	}

	/**
	 * A repository double answering the duplicate and code lookups.
	 *
	 * @param array<int, array<string, mixed>> $by_target Rows sending to the destination.
	 * @param array<string, mixed>|null        $by_code   Row for the code the browser holds.
	 * @return Mockery\MockInterface
	 */
	private function repository( array $by_target = array(), ?array $by_code = null ) {
		$repo = Mockery::mock( \FreeFormCertificate\UrlShortener\UrlShortenerRepository::class );
		$repo->shouldReceive( 'findByTargetUrl' )->andReturn( $by_target );
		$repo->shouldReceive( 'findByShortCode' )->andReturn( $by_code );
		$this->service->shouldReceive( 'get_repository' )->andReturn( $repo );
		$this->service->shouldReceive( 'get_short_url' )->andReturnUsing( static fn( $code ) => 'https://site.test/go/' . $code );
		Functions\when( 'mysql2date' )->alias( static fn( $format, $date ) => substr( (string) $date, 0, 10 ) );
		return $repo;
	}

	/** One stored short URL sending to https://example.com/page. */
	private const EXISTING = array(
		'short_code'  => 'old111',
		'target_url'  => 'https://example.com/page',
		'title'       => 'Flyer 2025',
		'created_at'  => '2025-03-01 10:00:00',
		'click_count' => '42',
		'status'      => 'active',
	);

	private function short_post( array $extra = array() ): void {
		$_POST = $extra + array(
			'type'   => 'url',
			'fields' => array( 'url' => 'example.com/page' ),
			'short'  => '1',
		);
	}

	public function test_generate_with_the_switch_on_draws_the_example_and_lists_duplicates(): void {
		$this->short_post();
		$this->service->shouldReceive( 'code_from_short_url' )->andReturn( '' );
		$this->service->shouldReceive( 'get_example_short_url' )->andReturn( 'https://site.test/go/Ab3dEf' );
		$this->repository( array( self::EXISTING ) );

		list( $kind, $data ) = $this->run_handler( 'handle_generate' );

		$this->assertSame( 'success', $kind );
		$this->assertSame( 'https://site.test/go/Ab3dEf', $data['payload'], 'The preview carries the example, never the destination.' );
		$this->assertTrue( $data['short']['example'] );
		$this->assertFalse( $data['short']['circular'] );
		$this->assertSame(
			array(
				'code'    => 'old111',
				'url'     => 'https://site.test/go/old111',
				'title'   => 'Flyer 2025',
				'created' => '2025-03-01',
				'clicks'  => 42,
				'active'  => true,
			),
			$data['short']['duplicates'][0]
		);
		$this->assertArrayNotHasKey( 'drawn', $data['short'] );
	}

	public function test_generate_draws_a_held_code_only_while_it_sends_to_this_destination(): void {
		$this->service->shouldReceive( 'code_from_short_url' )->andReturn( '' );
		$this->service->shouldReceive( 'get_example_short_url' )->andReturn( 'https://site.test/go/Ab3dEf' );
		$this->repository( array(), self::EXISTING );

		$this->short_post( array( 'short_code' => 'old111' ) );
		list( , $data ) = $this->run_handler( 'handle_generate' );
		$this->assertSame( 'https://site.test/go/old111', $data['payload'], '"Use this" puts the existing short URL in the code.' );
		$this->assertSame( 'old111', $data['short']['code'] );
		$this->assertFalse( $data['short']['example'] );

		$this->short_post( array( 'short_code' => 'old111', 'fields' => array( 'url' => 'example.com/other' ) ) );
		list( , $data ) = $this->run_handler( 'handle_generate' );
		$this->assertSame( 'https://site.test/go/Ab3dEf', $data['payload'], 'A code made for another destination is dropped.' );
		$this->assertSame( '', $data['short']['code'] );
	}

	public function test_generate_draws_a_circular_address_as_is(): void {
		$this->short_post( array( 'fields' => array( 'url' => 'https://site.test/go/old111' ) ) );
		$this->service->shouldReceive( 'code_from_short_url' )->andReturn( 'old111' );

		list( , $data ) = $this->run_handler( 'handle_generate' );

		$this->assertSame( 'https://site.test/go/old111', $data['payload'] );
		$this->assertTrue( $data['short']['circular'] );
	}

	public function test_generate_with_the_switch_off_or_another_type_stores_nothing_and_reports_no_short_state(): void {
		$this->service->shouldNotReceive( 'get_repository' );
		$this->service->shouldNotReceive( 'create_short_url' );

		$this->short_post( array( 'short' => '' ) );
		list( , $data ) = $this->run_handler( 'handle_generate' );
		$this->assertSame( 'https://example.com/page', $data['payload'], 'Off: the code carries the address itself.' );
		$this->assertNull( $data['short'] );

		$_POST = array( 'type' => 'text', 'fields' => array( 'text' => 'hi' ), 'short' => '1' );
		list( , $data ) = $this->run_handler( 'handle_generate' );
		$this->assertNull( $data['short'] );
	}

	public function test_shorten_creates_a_short_url_for_a_website(): void {
		$this->short_post( array( 'title' => ' Flyer ' ) );
		$this->service->shouldReceive( 'code_from_short_url' )->andReturn( '' );
		$this->repository();
		$this->service->shouldReceive( 'create_short_url' )->once()->with( 'https://example.com/page', 'Flyer' )
			->andReturn( array( 'success' => true, 'data' => array( 'short_code' => 'abc123' ) ) );

		list( $kind, $data ) = $this->run_handler( 'handle_shorten' );

		$this->assertSame( 'success', $kind );
		$this->assertSame( array( 'short_code' => 'abc123', 'short_url' => 'https://site.test/go/abc123' ), $data );
	}

	public function test_shorten_sends_a_social_profile_to_its_full_address(): void {
		$_POST = array(
			'type'   => 'social',
			'fields' => array( 'network' => 'instagram', 'username' => '@escola' ),
			'title'  => 'Instagram',
		);
		$this->service->shouldReceive( 'code_from_short_url' )->andReturn( '' );
		$this->repository();
		$this->service->shouldReceive( 'create_short_url' )->once()->with( 'https://www.instagram.com/escola', 'Instagram' )
			->andReturn( array( 'success' => true, 'data' => array( 'short_code' => 'ig0001' ) ) );

		$this->assertSame( 'success', $this->run_handler( 'handle_shorten' )[0] );
	}

	public function test_shorten_requires_a_title(): void {
		$this->short_post( array( 'title' => '   ' ) );
		$this->service->shouldNotReceive( 'create_short_url' );

		list( $kind, $data ) = $this->run_handler( 'handle_shorten' );

		$this->assertSame( 'error:400', $kind );
		$this->assertSame( 'Enter a title for the short URL.', $data['message'] );
	}

	public function test_shorten_refuses_an_address_that_is_already_a_short_url(): void {
		$this->short_post( array( 'fields' => array( 'url' => 'https://site.test/go/old111' ), 'title' => 'Loop' ) );
		$this->service->shouldReceive( 'code_from_short_url' )->andReturn( 'old111' );
		$this->service->shouldNotReceive( 'create_short_url' );

		list( $kind, $data ) = $this->run_handler( 'handle_shorten' );

		$this->assertSame( 'error:400', $kind );
		$this->assertStringContainsString( 'cannot be shortened again', $data['message'] );
	}

	public function test_a_duplicate_needs_the_acknowledgement(): void {
		$this->service->shouldReceive( 'code_from_short_url' )->andReturn( '' );
		$this->repository( array( self::EXISTING ) );

		$this->short_post( array( 'title' => 'Flyer 2026' ) );
		$this->service->shouldNotReceive( 'create_short_url' );
		list( $kind, $data ) = $this->run_handler( 'handle_shorten' );
		$this->assertSame( 'error:409', $kind );
		$this->assertSame( 'old111', $data['duplicates'][0]['code'] );
	}

	public function test_an_acknowledged_duplicate_is_created(): void {
		$this->service->shouldReceive( 'code_from_short_url' )->andReturn( '' );
		$this->repository( array( self::EXISTING ) );
		$this->service->shouldReceive( 'create_short_url' )->once()
			->andReturn( array( 'success' => true, 'data' => array( 'short_code' => 'new222' ) ) );

		$this->short_post( array( 'title' => 'Flyer 2026', 'acknowledge' => '1' ) );

		$this->assertSame( 'success', $this->run_handler( 'handle_shorten' )[0] );
	}

	public function test_shorten_refuses_other_types_and_invalid_addresses_without_writing(): void {
		$this->service->shouldNotReceive( 'create_short_url' );

		$_POST = array( 'type' => 'text', 'fields' => array( 'text' => 'x' ), 'title' => 'T' );
		$this->assertSame( 'error:400', $this->run_handler( 'handle_shorten' )[0] );

		$this->short_post( array( 'fields' => array( 'url' => 'not a url' ), 'title' => 'T' ) );
		$this->assertSame( 'error:400', $this->run_handler( 'handle_shorten' )[0] );
	}

	public function test_shorten_reports_a_failed_write(): void {
		$this->short_post( array( 'title' => 'T' ) );
		$this->service->shouldReceive( 'code_from_short_url' )->andReturn( '' );
		$this->repository();
		$this->service->shouldReceive( 'create_short_url' )->andReturn( array( 'success' => false, 'error' => 'Database error.' ) );

		list( $kind, $data ) = $this->run_handler( 'handle_shorten' );

		$this->assertSame( 'error:', $kind );
		$this->assertSame( 'Database error.', $data['message'] );
	}
}
