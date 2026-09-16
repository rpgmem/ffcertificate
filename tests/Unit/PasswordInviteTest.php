<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Core\PasswordInvite;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * The link that lets an invited user set their own password (#1212).
 *
 * Three invariants carry the security weight and each has its own test:
 *
 * 1. **The user comes from the TOKEN, never from the request.**
 *    `test_*_ignores_a_posted_user_id` puts a `user_id` in the POST; reading it
 *    from there breaks the test.
 * 2. **The expiration filter is scoped.** `password_reset_expiration` does not
 *    receive the user, so a permanent registration would change the expiration
 *    of every reset on the site. The test requires `add_filter` AND
 *    `remove_filter`.
 * 3. **The token dies on a successful reset, not before.** Passwords that do
 *    not match must NOT call `reset_password`, or a typo burns the invitation.
 *
 * @covers \FreeFormCertificate\Core\PasswordInvite
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class PasswordInviteTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		class_exists( '\FreeFormCertificate\Core\PasswordInvite' );

		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'is_wp_error' )->alias( static function ( $thing ) {
			return $thing instanceof \WP_Error;
		} );
		Functions\when( 'get_option' )->justReturn( 0 );
		Functions\when( 'home_url' )->justReturn( 'https://example.test/dashboard' );
		// Core's `add_query_arg()` accepts TWO shapes -- `( array, url )` and
		// `( key, value, url )`. A double that only knows the first scrambles the
		// second's arguments and makes the test assert about a string the product
		// never generates.
		Functions\when( 'add_query_arg' )->alias( static function ( $a, $b = '', $c = '' ) {
			if ( is_array( $a ) ) {
				return $b . '|' . http_build_query( $a );
			}
			return $c . '|' . rawurlencode( (string) $a ) . '=' . rawurlencode( (string) $b );
		} );

		$_POST = array();
		$_GET  = array();
	}

	protected function tearDown(): void {
		$_POST = array();
		$_GET  = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	private function makeUser( int $id = 7, string $login = 'maria' ): object {
		$user             = Mockery::mock( 'WP_User' );
		$user->ID         = $id;
		$user->user_login = $login;

		return $user;
	}

	private function mockSettings( int $hours = 48 ): void {
		$reader = Mockery::mock( 'alias:FreeFormCertificate\Settings\SettingsReader' );
		$reader->shouldReceive( 'invite_password_link_hours' )->andReturn( $hours );
		// The REAL `ActivityLog` is loaded on purpose: a Mockery alias does not
		// declare class constants, and `log()` uses `LEVEL_INFO`. Switching it off
		// through the very gate it consults is more faithful than replacing it --
		// and it is the same path an install with the log disabled takes.
		$reader->shouldReceive( 'activity_log_enabled' )->andReturn( false );
	}

	// ==================================================================
	// clamp_hours
	// ==================================================================

	public function test_clamp_hours_holds_the_declared_range(): void {
		$this->assertSame( PasswordInvite::MIN_HOURS, PasswordInvite::clamp_hours( 0 ) );
		$this->assertSame( PasswordInvite::MIN_HOURS, PasswordInvite::clamp_hours( -5 ) );
		$this->assertSame( PasswordInvite::MAX_HOURS, PasswordInvite::clamp_hours( 10000 ) );
		$this->assertSame( 48, PasswordInvite::clamp_hours( 48 ) );
	}

	public function test_expiration_is_clamped_on_read_not_only_on_write(): void {
		// A value written before a bound moved still has to land somewhere the
		// code can use.
		$this->mockSettings( 99999 );
		$this->assertSame( PasswordInvite::MAX_HOURS, PasswordInvite::expiration_hours() );
	}

	// ==================================================================
	// issue_for
	// ==================================================================

	public function test_issue_for_returns_empty_for_an_unknown_user(): void {
		Functions\when( 'get_userdata' )->justReturn( false );
		Functions\expect( 'get_password_reset_key' )->never();

		$this->assertSame( '', PasswordInvite::issue_for( 404 ) );
	}

	public function test_issue_for_returns_empty_when_the_key_cannot_be_issued(): void {
		$this->mockSettings();
		Functions\when( 'get_userdata' )->justReturn( $this->makeUser() );
		Functions\expect( 'get_password_reset_key' )->once()->andReturn( new \WP_Error( 'no_password_reset', 'nope' ) );

		$this->assertSame( '', PasswordInvite::issue_for( 7 ) );
	}

	public function test_issue_for_builds_a_url_carrying_key_and_login(): void {
		$this->mockSettings();
		Functions\when( 'get_userdata' )->justReturn( $this->makeUser( 7, 'maria' ) );
		Functions\expect( 'get_password_reset_key' )->once()->andReturn( 'ABC123' );

		$url = PasswordInvite::issue_for( 7 );

		$this->assertStringContainsString( PasswordInvite::ARG_KEY . '=ABC123', $url );
		$this->assertStringContainsString( PasswordInvite::ARG_LOGIN . '=maria', $url );
	}

	// ==================================================================
	// validate — o filtro escopado
	// ==================================================================

	public function test_validate_registers_and_removes_the_expiration_filter(): void {
		$this->mockSettings( 48 );
		Functions\expect( 'add_filter' )->once()->with( 'password_reset_expiration', Mockery::type( 'callable' ) );
		Functions\expect( 'remove_filter' )->once()->with( 'password_reset_expiration', Mockery::type( 'callable' ) );
		Functions\expect( 'check_password_reset_key' )->once()->andReturn( $this->makeUser() );

		PasswordInvite::validate( 'ABC123', 'maria' );
	}

	public function test_validate_removes_the_filter_even_when_the_key_is_rejected(): void {
		// A filter that survives the rejection leaks into the password reset of
		// every other user on the site.
		$this->mockSettings();
		Functions\expect( 'add_filter' )->once();
		Functions\expect( 'remove_filter' )->once();
		Functions\expect( 'check_password_reset_key' )->once()->andReturn( new \WP_Error( 'expired_key', 'x' ) );

		$this->assertInstanceOf( \WP_Error::class, PasswordInvite::validate( 'ABC123', 'maria' ) );
	}

	public function test_validate_rejects_an_empty_pair_without_touching_core(): void {
		Functions\expect( 'check_password_reset_key' )->never();
		Functions\expect( 'add_filter' )->never();

		$this->assertInstanceOf( \WP_Error::class, PasswordInvite::validate( '', 'maria' ) );
		$this->assertInstanceOf( \WP_Error::class, PasswordInvite::validate( 'ABC123', '' ) );
	}

	// ==================================================================
	// handle_submit
	// ==================================================================

	/**
	 * @param array<string, string> $post
	 */
	private function submitExpectingRedirect( array $post, string $expectedFragment ): void {
		$_POST = $post;

		Functions\expect( 'wp_safe_redirect' )
			->once()
			->andReturnUsing( static function ( $url ) {
				throw new \RuntimeException( 'redirect:' . $url );
			} );

		try {
			PasswordInvite::handle_submit();
			$this->fail( 'handle_submit did not redirect.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( $expectedFragment, $e->getMessage() );
		}
	}

	public function test_handle_submit_refuses_a_bad_nonce(): void {
		$this->mockSettings();
		Functions\when( 'wp_verify_nonce' )->justReturn( false );
		Functions\expect( 'check_password_reset_key' )->never();
		Functions\expect( 'reset_password' )->never();

		$this->submitExpectingRedirect(
			array( '_ffc_nonce' => 'bogus' ),
			'ffc_password_error=nonce'
		);
	}

	public function test_handle_submit_refuses_an_expired_key(): void {
		$this->mockSettings();
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'remove_filter' )->justReturn( true );
		Functions\when( 'check_password_reset_key' )->justReturn( new \WP_Error( 'expired_key', 'x' ) );
		Functions\expect( 'reset_password' )->never();

		$this->submitExpectingRedirect(
			array(
				'_ffc_nonce'              => 'ok',
				PasswordInvite::ARG_KEY   => 'ABC123',
				PasswordInvite::ARG_LOGIN => 'maria',
			),
			'ffc_password_error=expired'
		);
	}

	public function test_handle_submit_does_not_spend_the_token_when_the_passwords_differ(): void {
		// The token MUST survive: a typo cannot burn the invitation.
		// `reset_password` is what would spend it.
		$this->mockSettings();
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'remove_filter' )->justReturn( true );
		Functions\when( 'check_password_reset_key' )->justReturn( $this->makeUser() );
		Functions\expect( 'reset_password' )->never();
		Functions\expect( 'wp_set_auth_cookie' )->never();

		$this->submitExpectingRedirect(
			array(
				'_ffc_nonce'              => 'ok',
				PasswordInvite::ARG_KEY   => 'ABC123',
				PasswordInvite::ARG_LOGIN => 'maria',
				'ffc_pass1'               => 'aGoodPassword1',
				'ffc_pass2'               => 'outraSenha99',
			),
			'ffc_password_error=mismatch'
		);
	}

	public function test_handle_submit_refuses_a_password_under_the_minimum(): void {
		$this->mockSettings();
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'remove_filter' )->justReturn( true );
		Functions\when( 'check_password_reset_key' )->justReturn( $this->makeUser() );
		Functions\expect( 'reset_password' )->never();

		$short = str_repeat( 'a', PasswordInvite::MIN_PASSWORD_LENGTH - 1 );

		$this->submitExpectingRedirect(
			array(
				'_ffc_nonce'              => 'ok',
				PasswordInvite::ARG_KEY   => 'ABC123',
				PasswordInvite::ARG_LOGIN => 'maria',
				'ffc_pass1'               => $short,
				'ffc_pass2'               => $short,
			),
			'ffc_password_error=short'
		);
	}

	public function test_handle_submit_sets_the_password_and_opens_the_session(): void {
		$this->mockSettings();
		$user = $this->makeUser( 7, 'maria' );

		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'remove_filter' )->justReturn( true );
		Functions\when( 'check_password_reset_key' )->justReturn( $user );
		Functions\expect( 'reset_password' )->once()->with( $user, 'aGoodPassword1' );
		Functions\expect( 'wp_set_current_user' )->once()->with( 7 );
		// The session is born AFTER the password exists, never from the link alone.
		Functions\expect( 'wp_set_auth_cookie' )->once()->with( 7, false );

		$this->submitExpectingRedirect(
			array(
				'_ffc_nonce'              => 'ok',
				PasswordInvite::ARG_KEY   => 'ABC123',
				PasswordInvite::ARG_LOGIN => 'maria',
				'ffc_pass1'               => 'aGoodPassword1',
				'ffc_pass2'               => 'aGoodPassword1',
			),
			'ffc_password=set'
		);
	}

	public function test_handle_submit_ignores_a_posted_user_id(): void {
		// The user comes from the token. If anybody ever reads `user_id` from the
		// POST, this test breaks: the posted id (99) is not the token's (7).
		$this->mockSettings();
		$user = $this->makeUser( 7, 'maria' );

		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'remove_filter' )->justReturn( true );
		Functions\when( 'check_password_reset_key' )->justReturn( $user );
		Functions\expect( 'reset_password' )->once()->with( $user, 'aGoodPassword1' );
		Functions\expect( 'wp_set_auth_cookie' )->once()->with( 7, false );
		Functions\when( 'wp_set_current_user' )->justReturn( null );

		$this->submitExpectingRedirect(
			array(
				'_ffc_nonce'              => 'ok',
				PasswordInvite::ARG_KEY   => 'ABC123',
				PasswordInvite::ARG_LOGIN => 'maria',
				'user_id'                 => '99',
				'ffc_pass1'               => 'aGoodPassword1',
				'ffc_pass2'               => 'aGoodPassword1',
			),
			'ffc_password=set'
		);
	}

	// ==================================================================
	// Leitura da requisição
	// ==================================================================

	public function test_request_has_link_needs_both_args(): void {
		$this->assertFalse( PasswordInvite::request_has_link() );

		$_GET[ PasswordInvite::ARG_KEY ] = 'ABC123';
		$this->assertFalse( PasswordInvite::request_has_link() );

		$_GET[ PasswordInvite::ARG_LOGIN ] = 'maria';
		$this->assertTrue( PasswordInvite::request_has_link() );
	}
}
