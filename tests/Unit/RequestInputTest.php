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
 */
class RequestInputTest extends TestCase {

	/** @var array<string, mixed> */
	private array $server_backup = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		$this->server_backup = $_SERVER;
		// Start from a clean slate for the headers get_user_ip inspects.
		foreach ( array( 'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR' ) as $k ) {
			unset( $_SERVER[ $k ] );
		}
	}

	protected function tearDown(): void {
		$_SERVER = $this->server_backup;
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_returns_remote_addr_when_only_header_present(): void {
		$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
		$this->assertSame( '198.51.100.7', RequestInput::get_user_ip() );
	}

	public function test_prefers_forwarded_for_over_remote_addr(): void {
		// HTTP_X_FORWARDED_FOR is earlier in the precedence chain.
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.9';
		$_SERVER['REMOTE_ADDR']          = '198.51.100.7';
		$this->assertSame( '203.0.113.9', RequestInput::get_user_ip() );
	}

	public function test_skips_private_and_reserved_ips(): void {
		// Private (10.x) + loopback are reserved → skipped; falls through.
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '10.0.0.5';
		$_SERVER['REMOTE_ADDR']          = '127.0.0.1';
		$this->assertSame( '0.0.0.0', RequestInput::get_user_ip() );
	}

	public function test_returns_first_public_ip_from_comma_list(): void {
		// Proxy chains stack IPs; the first public one wins.
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '10.0.0.5, 203.0.113.42, 8.8.8.8';
		$this->assertSame( '203.0.113.42', RequestInput::get_user_ip() );
	}

	public function test_falls_back_to_zero_when_nothing_present(): void {
		$this->assertSame( '0.0.0.0', RequestInput::get_user_ip() );
	}
}
