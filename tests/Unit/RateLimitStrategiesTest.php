<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Security\RateLimitSupport;
use FreeFormCertificate\Security\IpLimiter;
use FreeFormCertificate\Security\EmailLimiter;
use FreeFormCertificate\Security\CpfLimiter;

/**
 * #563 Sprint 4 (A4) — unit tests for the per-dimension rate-limit strategies.
 *
 * Each strategy is exercised in isolation with a fixture-settings
 * RateLimitSupport injected and the static collaborators (RateLimitRepository,
 * object cache) mocked — the testability win the strategy split delivers.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class RateLimitStrategiesTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_cache_get' )->justReturn( false );
		// NOTE: the real RateLimiter facade is left un-mocked so IpLimiter can
		// read its CACHE_GROUP const (alias mocks define an empty class).
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function support( array $settings ): RateLimitSupport {
		return new RateLimitSupport( $settings );
	}

	// ===================== IpLimiter =====================

	public function test_ip_allows_when_disabled(): void {
		$repo = Mockery::mock( 'alias:FreeFormCertificate\Security\RateLimitRepository' );
		$repo->shouldNotReceive( 'get_count_from_db' );
		$limiter = new IpLimiter( $this->support( array( 'ip' => array( 'enabled' => false ) ) ) );
		$this->assertTrue( $limiter->check( '1.2.3.4', 7 )['allowed'] );
	}

	public function test_ip_blocks_on_hour_limit(): void {
		Functions\when( 'wp_cache_get' )->justReturn( 99 );
		Mockery::mock( 'alias:FreeFormCertificate\Security\RateLimitRepository' );
		$s       = array(
			'ip' => array( 'enabled' => true, 'max_per_hour' => 5, 'max_per_day' => 50, 'cooldown_seconds' => 0, 'message' => 'wait {time}' ),
		);
		$limiter = new IpLimiter( $this->support( $s ) );
		$res     = $limiter->check( '1.2.3.4', 7 );
		$this->assertFalse( $res['allowed'] );
		$this->assertSame( 'ip_hour_limit', $res['reason'] );
		$this->assertSame( 3600, $res['wait_seconds'] );
	}

	public function test_ip_allows_when_under_all_limits(): void {
		Functions\when( 'wp_cache_get' )->justReturn( false );
		$repo = Mockery::mock( 'alias:FreeFormCertificate\Security\RateLimitRepository' );
		$repo->shouldReceive( 'get_count_from_db' )->andReturn( 0 );
		$s = array( 'ip' => array( 'enabled' => true, 'max_per_hour' => 5, 'max_per_day' => 50, 'cooldown_seconds' => 30, 'message' => '' ) );
		$this->assertTrue( ( new IpLimiter( $this->support( $s ) ) )->check( '1.2.3.4', 7 )['allowed'] );
	}

	// ===================== EmailLimiter =====================

	public function test_email_allows_when_db_check_disabled(): void {
		Mockery::mock( 'alias:FreeFormCertificate\Security\RateLimitRepository' )
			->shouldNotReceive( 'get_submission_count' );
		$s = array( 'email' => array( 'check_database' => false ) );
		$this->assertTrue( ( new EmailLimiter( $this->support( $s ) ) )->check( 'a@b.co', 7 )['allowed'] );
	}

	public function test_email_blocks_on_day_limit(): void {
		$repo = Mockery::mock( 'alias:FreeFormCertificate\Security\RateLimitRepository' );
		$repo->shouldReceive( 'get_submission_count' )->with( 'email', 'a@b.co', 'day', 7 )->andReturn( 10 );
		$s   = array( 'email' => array( 'check_database' => true, 'max_per_day' => 3, 'max_per_week' => 9, 'max_per_month' => 20, 'message' => 'limit {count}' ) );
		$res = ( new EmailLimiter( $this->support( $s ) ) )->check( 'a@b.co', 7 );
		$this->assertFalse( $res['allowed'] );
		$this->assertSame( 'email_day_limit', $res['reason'] );
	}

	// ===================== CpfLimiter =====================

	public function test_cpf_blocks_when_temporarily_blocked(): void {
		Functions\when( 'wp_unslash' )->returnArg();
		$ds = Mockery::mock( 'alias:FreeFormCertificate\Core\DataSanitizer' );
		$ds->shouldReceive( 'normalize_cpf_rf' )->andReturnUsing( static fn( $v ) => $v );
		$repo = Mockery::mock( 'alias:FreeFormCertificate\Security\RateLimitRepository' );
		$repo->shouldReceive( 'is_temporarily_blocked' )->andReturn( true );
		$s   = array( 'cpf' => array( 'check_database' => true ) );
		$res = ( new CpfLimiter( $this->support( $s ) ) )->check( '12345678900', 7 );
		$this->assertFalse( $res['allowed'] );
		$this->assertSame( 'cpf_blocked', $res['reason'] );
	}

	public function test_cpf_blocks_and_records_on_abuse_threshold(): void {
		$ds = Mockery::mock( 'alias:FreeFormCertificate\Core\DataSanitizer' );
		$ds->shouldReceive( 'normalize_cpf_rf' )->andReturnUsing( static fn( $v ) => $v );
		$repo = Mockery::mock( 'alias:FreeFormCertificate\Security\RateLimitRepository' );
		$repo->shouldReceive( 'is_temporarily_blocked' )->andReturn( false );
		$repo->shouldReceive( 'get_submission_count' )->andReturn( 0 );
		$repo->shouldReceive( 'get_count_from_db' )->andReturn( 99 );
		$repo->shouldReceive( 'block_temporarily' )->once();
		$s   = array( 'cpf' => array( 'check_database' => true, 'max_per_month' => 5, 'max_per_year' => 50, 'block_threshold' => 10, 'block_duration' => 24, 'message' => 'x' ) );
		$res = ( new CpfLimiter( $this->support( $s ) ) )->check( '12345678900', 7 );
		$this->assertFalse( $res['allowed'] );
		$this->assertSame( 'cpf_abuse', $res['reason'] );
	}

	public function test_cpf_allows_when_under_limits(): void {
		$ds = Mockery::mock( 'alias:FreeFormCertificate\Core\DataSanitizer' );
		$ds->shouldReceive( 'normalize_cpf_rf' )->andReturnUsing( static fn( $v ) => $v );
		$repo = Mockery::mock( 'alias:FreeFormCertificate\Security\RateLimitRepository' );
		$repo->shouldReceive( 'is_temporarily_blocked' )->andReturn( false );
		$repo->shouldReceive( 'get_submission_count' )->andReturn( 0 );
		$repo->shouldReceive( 'get_count_from_db' )->andReturn( 0 );
		$s = array( 'cpf' => array( 'check_database' => true, 'max_per_month' => 5, 'max_per_year' => 50, 'block_threshold' => 10, 'block_duration' => 24, 'message' => 'x' ) );
		$this->assertTrue( ( new CpfLimiter( $this->support( $s ) ) )->check( '12345678900', 7 )['allowed'] );
	}

	// ===================== RateLimitSupport =====================

	public function test_support_format_message_interpolates_tokens(): void {
		$out = $this->support( array() )->format_message( 'Wait {time}, count {count}', array( 'time' => '1h', 'count' => 3 ) );
		$this->assertSame( 'Wait 1h, count 3', $out );
	}

	public function test_support_format_message_falls_back_on_blank_template(): void {
		$out = $this->support( array() )->format_message( '   ', array( 'time' => '5m' ) );
		$this->assertStringContainsString( '5m', $out );
	}
}
