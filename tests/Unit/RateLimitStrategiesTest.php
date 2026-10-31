<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\IpAddress;
use FreeFormCertificate\Core\SensitiveFieldRegistry;
use FreeFormCertificate\Security\RateLimitSupport;
use FreeFormCertificate\Security\IpLimiter;
use FreeFormCertificate\Security\EmailLimiter;
use FreeFormCertificate\Security\CpfLimiter;
use FreeFormCertificate\Tests\Support\Identifiers;

/**
 * #563 Sprint 4 (A4) — unit tests for the per-dimension rate-limit strategies.
 *
 * Each strategy is exercised with a fixture-settings RateLimitSupport
 * injected. Everything below it is real: RateLimitRepository, RateCounter,
 * DataSanitizer, IpAddress, SensitiveFieldRegistry and Encryption (with the
 * bootstrap's key constants). Storage is a `$wpdb` double that renders each
 * prepared query into plain SQL, answers the three reads the strategies make
 * (a counter row, a submission count, a temporary block) from the arrays a
 * test sets, records them, and fails on any other query; plus a transient
 * map for the hour counter.
 *
 * @covers \FreeFormCertificate\Security\IpLimiter
 * @covers \FreeFormCertificate\Security\EmailLimiter
 * @covers \FreeFormCertificate\Security\CpfLimiter
 * @covers \FreeFormCertificate\Security\RateLimitSupport
 */
class RateLimitStrategiesTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array<string, mixed> Transients, by key. */
	private array $transients = array();

	/** @var array<string, int> Counter rows in `ffc_rate_limits`, by "type:window". */
	private array $counters = array();

	/** @var array<string, int> Submission counts, by "hash column:days in the window". */
	private array $submissions = array();

	/** @var bool Whether `ffc_rate_limits` holds an active temporary block. */
	private bool $blocked = false;

	/** @var list<array<int, mixed>> Every read the double answered, as [kind, ...bound values]. */
	private array $reads = array();

	/** @var list<array{0: string, 1: array<string, mixed>}> Every insert, as [table, data]. */
	private array $inserts = array();

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->transients  = array();
		$this->counters    = array();
		$this->submissions = array();
		$this->blocked     = false;
		$this->reads       = array();
		$this->inserts     = array();

		Functions\when( '__' )->returnArg();
		Functions\when( 'get_transient' )->alias( fn ( $key ) => $this->transients[ $key ] ?? false );
		Functions\when( 'current_time' )->justReturn( '2026-04-01 12:00:00' );

		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']   = $this->make_wpdb();
	}

	protected function tearDown(): void {
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A `$wpdb` double that renders prepare() into plain SQL and answers the
	 * strategies' reads by their SQL text.
	 */
	private function make_wpdb(): object {
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( $sql, ...$args ) {
				if ( 1 === count( $args ) && is_array( $args[0] ) ) {
					$args = $args[0];
				}
				$i = 0;
				return (string) preg_replace_callback(
					'/%[ids]/',
					static function ( $m ) use ( &$i, $args ) {
						$v = $args[ $i++ ] ?? '';
						if ( '%i' === $m[0] ) {
							return '`' . $v . '`';
						}
						if ( '%d' === $m[0] ) {
							return (string) (int) $v;
						}
						return "'" . addslashes( (string) $v ) . "'";
					},
					(string) $sql
				);
			}
		);
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			function ( $sql ) {
				if ( preg_match( "/^SELECT count FROM `wp_ffc_rate_limits` WHERE type='(\\w+)' AND identifier='([^']*)' AND window_type='(\\w+)' AND form_id = (\\d+) AND window_start>=/", $sql, $m ) ) {
					$this->reads[] = array( 'counter', $m[1], $m[2], $m[3], (int) $m[4] );
					$count         = $this->counters[ $m[1] . ':' . $m[3] ] ?? null;
					return null === $count ? null : (string) $count;
				}
				if ( preg_match( "/^SELECT COUNT\\(\\*\\) FROM `wp_ffc_submissions` WHERE (\\w+)='([^']*)' AND submission_date >= DATE_SUB\\(NOW\\(\\), INTERVAL (\\d+) DAY\\) AND form_id=(\\d+)$/", $sql, $m ) ) {
					$this->reads[] = array( 'submissions', $m[1], $m[2], (int) $m[3], (int) $m[4] );
					return (string) ( $this->submissions[ $m[1] . ':' . $m[3] ] ?? 0 );
				}
				if ( preg_match( "/^SELECT blocked_until FROM `wp_ffc_rate_limits` WHERE type='(\\w+)' AND identifier='([^']*)' AND form_id = (\\d+) AND is_blocked=1 /", $sql, $m ) ) {
					$this->reads[] = array( 'blocked', $m[1], $m[2], (int) $m[3] );
					return $this->blocked ? '2099-01-01 00:00:00' : null;
				}
				$this->fail( 'Unexpected get_var(): ' . $sql );
			}
		);
		$wpdb->shouldReceive( 'insert' )->andReturnUsing(
			function ( $table, $data ) {
				if ( 'wp_ffc_rate_limits' !== $table ) {
					$this->fail( 'Unexpected insert into ' . $table );
				}
				$this->inserts[] = array( $table, $data );
				return 1;
			}
		);
		return $wpdb;
	}

	private function support( array $settings ): RateLimitSupport {
		return new RateLimitSupport( $settings );
	}

	/**
	 * The transient key IpLimiter keeps the per-hour counter under.
	 */
	private function ip_hour_key( string $ip, int $form_id ): string {
		return 'ffc_rate_ip_' . md5( IpAddress::fingerprint( $ip ) . $form_id ) . '_hour';
	}

	// ===================== IpLimiter =====================

	public function test_ip_allows_when_disabled(): void {
		$limiter = new IpLimiter( $this->support( array( 'ip' => array( 'enabled' => false ) ) ) );
		$this->assertTrue( $limiter->check( '1.2.3.4', 7 )['allowed'] );
		$this->assertSame( array(), $this->reads, 'A disabled IP limit reads no counter.' );
	}

	public function test_ip_blocks_on_hour_limit(): void {
		$this->transients[ $this->ip_hour_key( '1.2.3.4', 7 ) ] = 99;
		$s       = array(
			'ip' => array( 'enabled' => true, 'max_per_hour' => 5, 'max_per_day' => 50, 'cooldown_seconds' => 0, 'message' => 'wait {time}' ),
		);
		$limiter = new IpLimiter( $this->support( $s ) );
		$res     = $limiter->check( '1.2.3.4', 7 );
		$this->assertFalse( $res['allowed'] );
		$this->assertSame( 'ip_hour_limit', $res['reason'] );
		$this->assertSame( 3600, $res['wait_seconds'] );
		$this->assertSame( array(), $this->reads, 'The hour limit refuses before the day counter is read.' );
	}

	public function test_ip_allows_when_under_all_limits(): void {
		$s = array( 'ip' => array( 'enabled' => true, 'max_per_hour' => 5, 'max_per_day' => 50, 'cooldown_seconds' => 30, 'message' => '' ) );
		$this->assertTrue( ( new IpLimiter( $this->support( $s ) ) )->check( '1.2.3.4', 7 )['allowed'] );
		$this->assertSame(
			array( array( 'counter', 'ip', IpAddress::fingerprint( '1.2.3.4' ), 'day', 7 ) ),
			$this->reads,
			'The day counter is read once, keyed by the address fingerprint and the form.'
		);
	}

	// ===================== EmailLimiter =====================

	/**
	 * The email hash the submission count looks up.
	 */
	private function email_hash( string $email ): string {
		return (string) SensitiveFieldRegistry::hash_identifier( 'email', $email );
	}

	public function test_email_allows_when_db_check_disabled(): void {
		$s = array( 'email' => array( 'check_database' => false ) );
		$this->assertTrue( ( new EmailLimiter( $this->support( $s ) ) )->check( 'a@b.co', 7 )['allowed'] );
		$this->assertSame( array(), $this->reads, 'With the database check off no submission is counted.' );
	}

	public function test_email_blocks_on_day_limit(): void {
		$this->submissions['email_hash:1'] = 10;
		$s   = array( 'email' => array( 'check_database' => true, 'max_per_day' => 3, 'max_per_week' => 9, 'max_per_month' => 20, 'message' => 'limit {count}' ) );
		$res = ( new EmailLimiter( $this->support( $s ) ) )->check( 'a@b.co', 7 );
		$this->assertFalse( $res['allowed'] );
		$this->assertSame( 'email_day_limit', $res['reason'] );
		$this->assertSame( 86400, $res['wait_seconds'] );
		$this->assertSame(
			array( array( 'submissions', 'email_hash', $this->email_hash( 'a@b.co' ), 1, 7 ) ),
			$this->reads
		);
	}

	public function test_email_blocks_on_week_limit(): void {
		// Under the daily cap but at/over the weekly one.
		$this->submissions['email_hash:1'] = 0;
		$this->submissions['email_hash:7'] = 9;
		$s   = array( 'email' => array( 'check_database' => true, 'max_per_day' => 3, 'max_per_week' => 9, 'max_per_month' => 20, 'message' => 'limit {count}' ) );
		$res = ( new EmailLimiter( $this->support( $s ) ) )->check( 'a@b.co', 7 );
		$this->assertFalse( $res['allowed'] );
		$this->assertSame( 'email_week_limit', $res['reason'] );
		$this->assertSame( 604800, $res['wait_seconds'] );
		$hash = $this->email_hash( 'a@b.co' );
		$this->assertSame(
			array(
				array( 'submissions', 'email_hash', $hash, 1, 7 ),
				array( 'submissions', 'email_hash', $hash, 7, 7 ),
			),
			$this->reads
		);
	}

	public function test_email_blocks_on_month_limit(): void {
		// Under day + week caps but at/over the monthly one.
		$this->submissions['email_hash:1']  = 0;
		$this->submissions['email_hash:7']  = 0;
		$this->submissions['email_hash:30'] = 20;
		$s   = array( 'email' => array( 'check_database' => true, 'max_per_day' => 3, 'max_per_week' => 9, 'max_per_month' => 20, 'message' => 'limit {count}' ) );
		$res = ( new EmailLimiter( $this->support( $s ) ) )->check( 'a@b.co', 7 );
		$this->assertFalse( $res['allowed'] );
		$this->assertSame( 'email_month_limit', $res['reason'] );
		$this->assertSame( 2592000, $res['wait_seconds'] );
		$hash = $this->email_hash( 'a@b.co' );
		$this->assertSame(
			array(
				array( 'submissions', 'email_hash', $hash, 1, 7 ),
				array( 'submissions', 'email_hash', $hash, 7, 7 ),
				array( 'submissions', 'email_hash', $hash, 30, 7 ),
			),
			$this->reads
		);
	}

	public function test_email_allows_when_under_all_limits(): void {
		$s = array( 'email' => array( 'check_database' => true, 'max_per_day' => 3, 'max_per_week' => 9, 'max_per_month' => 20, 'message' => 'limit {count}' ) );
		$this->assertTrue( ( new EmailLimiter( $this->support( $s ) ) )->check( 'a@b.co', 7 )['allowed'] );
		$this->assertCount( 3, $this->reads, 'All three windows are counted before the address is allowed.' );
	}

	// ===================== CpfLimiter =====================

	public function test_cpf_blocks_when_temporarily_blocked(): void {
		$this->blocked = true;
		$s   = array( 'cpf' => array( 'check_database' => true ) );
		$res = ( new CpfLimiter( $this->support( $s ) ) )->check( Identifiers::CPF_A, 7 );
		$this->assertFalse( $res['allowed'] );
		$this->assertSame( 'cpf_blocked', $res['reason'] );
		$this->assertSame(
			array( array( 'blocked', 'cpf', hash( 'sha256', Identifiers::CPF_A ), 7 ) ),
			$this->reads,
			'The block is looked up by the hashed CPF, and nothing else is counted.'
		);
	}

	public function test_cpf_blocks_and_records_on_abuse_threshold(): void {
		$this->counters['cpf:hour'] = 99;
		$s   = array( 'cpf' => array( 'check_database' => true, 'max_per_month' => 5, 'max_per_year' => 50, 'block_threshold' => 10, 'block_duration' => 24, 'message' => 'x' ) );
		$res = ( new CpfLimiter( $this->support( $s ) ) )->check( Identifiers::CPF_A, 7 );
		$this->assertFalse( $res['allowed'] );
		$this->assertSame( 'cpf_abuse', $res['reason'] );

		$this->assertCount( 1, $this->inserts, 'The abuse threshold writes exactly one temporary block.' );
		$block = $this->inserts[0][1];
		$this->assertSame( 'cpf', $block['type'] );
		$this->assertSame( hash( 'sha256', Identifiers::CPF_A ), $block['identifier'] );
		$this->assertSame( 7, $block['form_id'] );
		$this->assertSame( 1, $block['is_blocked'] );
		$this->assertSame( 'abuse', $block['blocked_reason'] );
	}

	public function test_cpf_year_limit_counts_a_year_not_the_month(): void {
		// Fifty submissions older than a month and younger than a year: the
		// monthly window sees none, the yearly one sees all fifty. The yearly
		// count used to read the same 30 days as the monthly one, so this CPF
		// was allowed (#2156).
		$this->submissions['cpf_hash:365'] = 50;
		$s   = array( 'cpf' => array( 'check_database' => true, 'max_per_month' => 5, 'max_per_year' => 50, 'block_threshold' => 10, 'block_duration' => 24, 'message' => 'Yearly limit reached.' ) );
		$res = ( new CpfLimiter( $this->support( $s ) ) )->check( Identifiers::CPF_A, 7 );

		$this->assertFalse( $res['allowed'] );
		$this->assertSame( 'cpf_year_limit', $res['reason'] );
		$this->assertSame( 'Yearly limit reached.', $res['message'] );
		$windows = array_map(
			static fn ( array $read ): int => $read[3],
			array_values( array_filter( $this->reads, static fn ( array $read ): bool => 'submissions' === $read[0] ) )
		);
		$this->assertSame( array( 30, 365 ), $windows, 'The month is counted over 30 days and the year over 365.' );
	}

	public function test_cpf_allows_when_under_limits(): void {
		$s = array( 'cpf' => array( 'check_database' => true, 'max_per_month' => 5, 'max_per_year' => 50, 'block_threshold' => 10, 'block_duration' => 24, 'message' => 'x' ) );
		$this->assertTrue( ( new CpfLimiter( $this->support( $s ) ) )->check( Identifiers::CPF_A, 7 )['allowed'] );
		$this->assertSame( array(), $this->inserts, 'A CPF under every limit is not blocked.' );
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
