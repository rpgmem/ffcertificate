<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\DateMessages\Unsubscribe;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * The one-click unsubscribe link and the page it opens (#1538).
 *
 * `OptOut` is an alias mock, so each test runs in its own process.
 *
 * @covers \FreeFormCertificate\DateMessages\Unsubscribe
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class DateMessagesUnsubscribeTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface */
	private $opt_out;

	/**
	 * What the last `wp_die()` was given: message and response code.
	 *
	 * @var array{0: string, 1: int}|null
	 */
	private ?array $died = null;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$_GET  = array();
		$_POST = array();

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->alias( static fn( $v ) => trim( (string) $v ) );
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
		Functions\when( 'wp_salt' )->justReturn( 'test-salt' );
		Functions\when( 'admin_url' )->alias( static fn( $p = '' ) => 'https://example.org/wp-admin/' . $p );
		Functions\when( 'get_userdata' )->alias( static fn( $id ) => 42 === (int) $id ? (object) array( 'ID' => 42 ) : false );
		Functions\when( 'wp_nonce_field' )->justReturn( '<input name="_wpnonce" value="n">' );
		Functions\when( 'wp_die' )->alias(
			function ( $message, $title = '', $args = array() ) {
				$this->died = array( (string) $message, (int) ( $args['response'] ?? 500 ) );
				throw new \RuntimeException( 'wp_die' );
			}
		);

		$this->opt_out = Mockery::mock( 'alias:FreeFormCertificate\DateMessages\OptOut' );
	}

	protected function tearDown(): void {
		$_GET  = array();
		$_POST = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	private function handle(): void {
		try {
			Unsubscribe::handle();
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'wp_die', $e->getMessage() );
		}
		$this->assertNotNull( $this->died, 'Every path ends in wp_die().' );
	}

	public function test_a_token_verifies_for_its_own_account_only(): void {
		$token = Unsubscribe::token( 42 );

		$this->assertTrue( Unsubscribe::verify( 42, $token ) );
		$this->assertFalse( Unsubscribe::verify( 43, $token ), 'A token is bound to one account.' );
		$this->assertFalse( Unsubscribe::verify( 42, $token . 'x' ), 'A tampered token is refused.' );
		$this->assertFalse( Unsubscribe::verify( 42, '' ) );
		$this->assertFalse( Unsubscribe::verify( 0, Unsubscribe::token( 0 ) ), 'No account, no link.' );
	}

	public function test_the_token_changes_with_the_salt(): void {
		$before = Unsubscribe::token( 42 );
		Functions\when( 'wp_salt' )->justReturn( 'rotated' );

		$this->assertNotSame( $before, Unsubscribe::token( 42 ) );
	}

	public function test_the_url_carries_the_action_the_account_and_its_token(): void {
		Functions\when( 'add_query_arg' )->alias(
			static fn( array $args, string $url ) => $url . '?' . http_build_query( $args )
		);

		$url = Unsubscribe::url( 42 );

		$this->assertStringStartsWith( 'https://example.org/wp-admin/admin-post.php?', $url );
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );
		$this->assertSame( Unsubscribe::ACTION, $query['action'] );
		$this->assertSame( '42', $query['u'] );
		$this->assertSame( Unsubscribe::token( 42 ), $query['t'] );
	}

	public function test_init_registers_the_handler_for_both_audiences(): void {
		Unsubscribe::init();

		$this->assertNotFalse( has_action( 'admin_post_nopriv_' . Unsubscribe::ACTION, array( Unsubscribe::class, 'handle' ) ) );
		$this->assertNotFalse( has_action( 'admin_post_' . Unsubscribe::ACTION, array( Unsubscribe::class, 'handle' ) ) );
	}

	public function test_an_invalid_token_is_refused_with_403(): void {
		$_GET = array(
			'u' => '42',
			't' => 'forged',
		);
		$this->opt_out->shouldReceive( 'set' )->never();

		$this->handle();

		$this->assertSame( 403, $this->died[1] );
	}

	public function test_a_valid_token_for_a_deleted_account_is_refused(): void {
		$_GET = array(
			'u' => '7',
			't' => Unsubscribe::token( 7 ),
		);
		$this->opt_out->shouldReceive( 'set' )->never();

		$this->handle();

		$this->assertSame( 403, $this->died[1] );
	}

	public function test_a_get_only_shows_the_confirmation_and_changes_nothing(): void {
		$_GET = array(
			'u' => '42',
			't' => Unsubscribe::token( 42 ),
		);
		$this->opt_out->shouldReceive( 'set' )->never();

		$this->handle();

		$this->assertSame( 200, $this->died[1] );
		$this->assertStringContainsString( '<form method="post"', $this->died[0] );
		$this->assertStringContainsString( 'name="t" value="' . Unsubscribe::token( 42 ) . '"', $this->died[0] );
		$this->assertStringContainsString( '_wpnonce', $this->died[0] );
	}

	public function test_a_post_without_a_valid_nonce_changes_nothing(): void {
		$_POST = array(
			'u'        => '42',
			't'        => Unsubscribe::token( 42 ),
			'_wpnonce' => 'stale',
		);
		Functions\when( 'wp_verify_nonce' )->justReturn( false );
		$this->opt_out->shouldReceive( 'set' )->never();

		$this->handle();

		$this->assertSame( 403, $this->died[1] );
	}

	public function test_a_post_with_token_and_nonce_records_the_opt_out(): void {
		$_POST = array(
			'u'        => '42',
			't'        => Unsubscribe::token( 42 ),
			'_wpnonce' => 'good',
		);
		Functions\expect( 'wp_verify_nonce' )->once()->with( 'good', Unsubscribe::ACTION . '_42' )->andReturn( 1 );
		$this->opt_out->shouldReceive( 'set' )->once()->with( 42, false );

		$this->handle();

		$this->assertSame( 200, $this->died[1] );
	}
}
