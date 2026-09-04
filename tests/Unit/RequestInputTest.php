<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\RequestInput;

/**
 * #563 Sprint 5 phase 2 (B1) — unit tests for RequestInput::get_user_ip(),
 * the client-IP resolver relocated from Core\Utils. The other RequestInput
 * accessors ($_POST/$_GET readers) are exercised through their many callers;
 * get_user_ip had no direct coverage before the move, so we pin its header
 * walk + private/reserved filtering + fallback here.
 *
 * The wp_unslash / sanitize_text_field stubs live in each test (not setUp) to
 * match the proven RateLimiterTest get_user_ip pattern; the class also runs in
 * isolated processes so a prior suite test that left wp_unslash under Brain
 * Monkey/Patchwork management can't surface as "not defined nor mocked" here.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class RequestInputTest extends TestCase {

	/** @var array<string, mixed> */
	private array $server_backup = array();

	/** @var array<string, mixed> */
	private array $get_backup = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->server_backup = $_SERVER;
		$this->get_backup    = $_GET;
		// Start from a clean slate for the headers get_user_ip inspects.
		foreach ( array( 'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR' ) as $k ) {
			unset( $_SERVER[ $k ] );
		}
	}

	protected function tearDown(): void {
		$_SERVER = $this->server_backup;
		$_GET    = $this->get_backup;
		Monkey\tearDown();
		parent::tearDown();
	}

	private function stub_request_funcs(): void {
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
	}

	public function test_returns_remote_addr_when_only_header_present(): void {
		$this->stub_request_funcs();
		$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
		$this->assertSame( '198.51.100.7', RequestInput::get_user_ip() );
	}

	public function test_prefers_forwarded_for_over_remote_addr(): void {
		$this->stub_request_funcs();
		// HTTP_X_FORWARDED_FOR is earlier in the precedence chain.
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.9';
		$_SERVER['REMOTE_ADDR']          = '198.51.100.7';
		$this->assertSame( '203.0.113.9', RequestInput::get_user_ip() );
	}

	public function test_skips_private_and_reserved_ips(): void {
		$this->stub_request_funcs();
		// Private (10.x) + loopback are reserved → skipped; falls through.
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '10.0.0.5';
		$_SERVER['REMOTE_ADDR']          = '127.0.0.1';
		$this->assertSame( '0.0.0.0', RequestInput::get_user_ip() );
	}

	public function test_returns_first_public_ip_from_comma_list(): void {
		$this->stub_request_funcs();
		// Proxy chains stack IPs; the first public one wins.
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '10.0.0.5, 203.0.113.42, 8.8.8.8';
		$this->assertSame( '203.0.113.42', RequestInput::get_user_ip() );
	}

	public function test_falls_back_to_zero_when_nothing_present(): void {
		$this->stub_request_funcs();
		$this->assertSame( '0.0.0.0', RequestInput::get_user_ip() );
	}

	/**
	 * @dataProvider truthy_provider
	 * @param mixed $raw      Input value.
	 * @param bool  $expected Expected coercion.
	 */
	public function test_is_truthy_coerces_via_allowlist( $raw, bool $expected ): void {
		$this->assertSame( $expected, RequestInput::is_truthy( $raw ) );
	}

	/**
	 * @return array<string, array{0: mixed, 1: bool}>
	 */
	public function truthy_provider(): array {
		return array(
			'string 1'       => array( '1', true ),
			'true'           => array( 'true', true ),
			'on'             => array( 'on', true ),
			'yes'            => array( 'yes', true ),
			'uppercase TRUE' => array( 'TRUE', true ),
			'mixed On'       => array( 'On', true ),
			'string 0'       => array( '0', false ),
			'false'          => array( 'false', false ),
			'off'            => array( 'off', false ),
			'empty string'   => array( '', false ),
			'other'          => array( 'banana', false ),
			'array rejected' => array( array( '1' ), false ),
		);
	}

	/**
	 * Stub the WordPress sanitisers the $_GET readers delegate to. `sanitize_key`
	 * is stubbed with its real behaviour, not returnArg, because the whole point
	 * of get_get_key over get_get_string is *which* characters survive.
	 */
	private function stub_get_readers(): void {
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_key' )->alias(
			static fn( $v ): string => (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) )
		);
		Functions\when( 'absint' )->alias( static fn( $v ): int => abs( (int) $v ) );
	}

	public function test_get_get_key_lowercases_and_strips_disallowed_characters(): void {
		$this->stub_get_readers();
		$_GET['tab'] = 'Ffc<script>_Tab-1';
		$this->assertSame( 'ffcscript_tab-1', RequestInput::get_get_key( 'tab' ) );
	}

	public function test_get_get_key_returns_default_when_absent(): void {
		$this->stub_get_readers();
		unset( $_GET['tab'] );
		$this->assertSame( 'general', RequestInput::get_get_key( 'tab', 'general' ) );
	}

	public function test_get_get_key_returns_default_for_an_array_value(): void {
		$this->stub_get_readers();
		$_GET['tab'] = array( 'a', 'b' );
		$this->assertSame( 'general', RequestInput::get_get_key( 'tab', 'general' ) );
	}

	public function test_get_get_int_casts_through_absint(): void {
		$this->stub_get_readers();
		$_GET['id'] = '-42abc';
		$this->assertSame( 42, RequestInput::get_get_int( 'id' ) );
	}

	public function test_get_get_int_returns_default_when_absent(): void {
		$this->stub_get_readers();
		unset( $_GET['id'] );
		$this->assertSame( 7, RequestInput::get_get_int( 'id', 7 ) );
	}

	public function test_has_get_is_presence_not_truthiness(): void {
		$_GET['ffc_saved'] = '';
		$this->assertTrue( RequestInput::has_get( 'ffc_saved' ) );

		unset( $_GET['ffc_saved'] );
		$this->assertFalse( RequestInput::has_get( 'ffc_saved' ) );
	}
}
