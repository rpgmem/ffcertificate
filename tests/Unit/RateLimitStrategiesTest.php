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

	/** @var array<string, int> Oldest counted submission, by "hash column:days in the window". */
	private array $oldest = array();

	/** @var int|null Latest submission with the identifier, at any age. */
	private ?int $latest = null;

	/** @var string Blocked-until datetime the double answers with. */
	private string $blocked_until = '2099-01-01 00:00:00';

	/** @var list<string> The window starts each hourly sum was asked from. */
	private array $hour_sums = array();

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
		$this->oldest      = array();
		$this->latest      = null;
		$this->hour_sums   = array();
		$this->reads       = array();
		$this->inserts     = array();

		Functions\when( '__' )->returnArg();
		Functions\when( '_n' )->alias( static fn ( $single, $plural, $n ) => 1 === (int) $n ? $single : $plural );
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
				if ( preg_match( "/^SELECT COUNT\\(\\*\\) FROM `wp_ffc_submissions` WHERE (\\w+)='([^']*)' AND submission_date >= (\\d+) AND form_id=(\\d+)$/", $sql, $m ) ) {
					// The window is an instant (#2339); read back the days it spans.
					$m[3]          = (string) (int) round( ( time() - (int) $m[3] ) / DAY_IN_SECONDS );
					$this->reads[] = array( 'submissions', $m[1], $m[2], (int) $m[3], (int) $m[4] );
					return (string) ( $this->submissions[ $m[1] . ':' . $m[3] ] ?? 0 );
				}
				if ( preg_match( "/^SELECT blocked_until FROM `wp_ffc_rate_limits` WHERE type='(\\w+)' AND identifier='([^']*)' AND form_id = (\\d+) AND is_blocked=1 /", $sql, $m ) ) {
					$this->reads[] = array( 'blocked', $m[1], $m[2], (int) $m[3] );
					return $this->blocked ? $this->blocked_until : null;
				}
				if ( preg_match( "/^SELECT MIN\\(submission_date\\) FROM `wp_ffc_submissions` WHERE (\\w+)='[^']*' AND submission_date >= (\\d+) AND form_id=\\d+$/", $sql, $m ) ) {
					$days = (int) round( ( time() - (int) $m[2] ) / DAY_IN_SECONDS );
					$at   = $this->oldest[ $m[1] . ':' . $days ] ?? null;
					return null === $at ? null : (string) $at;
				}
				if ( preg_match( "/^SELECT MAX\\(submission_date\\) FROM `wp_ffc_submissions` WHERE \\w+='[^']*'  AND form_id=\\d+$/", $sql ) ) {
					return null === $this->latest ? null : (string) $this->latest;
				}
				if ( preg_match( "/^SELECT SUM\\(count\\) FROM `wp_ffc_rate_limits` WHERE type='(\\w+)' AND identifier='[^']*' AND window_type='hour' AND is_blocked=0 AND form_id = \\d+ AND window_start>='([^']*)'$/", $sql, $m ) ) {
					$this->hour_sums[] = $m[2];
					$count             = $this->counters[ $m[1] . ':hour' ] ?? null;
					return null === $count ? null : (string) $count;
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
	private function ip_hour_key( string $ip, int $form_id, int $ahead = 0 ): string {
		return 'ffc_rate_ip_' . md5( IpAddress::fingerprint( $ip ) . $form_id ) . '_hour_' . (int) floor( ( time() + $ahead ) / HOUR_IN_SECONDS );
	}

	// ===================== IpLimiter =====================

	public function test_ip_allows_when_disabled(): void {
		$limiter = new IpLimiter( $this->support( array( 'ip' => array( 'enabled' => false ) ) ) );
		$this->assertTrue( $limiter->check( '1.2.3.4', 7 )['allowed'] );
		$this->assertSame( array(), $this->reads, 'A disabled IP limit reads no counter.' );
	}

	public function test_ip_blocks_on_hour_limit(): void {
		// The key carries the hour's number: seed this hour and the next, so a
		// run that crosses the hour still finds its counter.
		$this->transients[ $this->ip_hour_key( '1.2.3.4', 7 ) ]                   = 99;
		$this->transients[ $this->ip_hour_key( '1.2.3.4', 7, HOUR_IN_SECONDS ) ] = 99;
		$s       = array(
			'ip' => array( 'enabled' => true, 'max_per_hour' => 5, 'max_per_day' => 50, 'cooldown_seconds' => 0, 'message' => 'wait {time}' ),
		);
		$limiter = new IpLimiter( $this->support( $s ) );
		$res     = $limiter->check( '1.2.3.4', 7 );
		$this->assertFalse( $res['allowed'] );
		$this->assertSame( 'ip_hour_limit', $res['reason'] );
		// The wait is what is left of the hour, not a whole hour (#2339).
		$this->assertGreaterThanOrEqual( 1, $res['wait_seconds'] );
		$this->assertLessThanOrEqual( HOUR_IN_SECONDS, $res['wait_seconds'] );
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
			array( array( 'blocked', 'cpf', \FreeFormCertificate\Core\Encryption::keyed_hash( Identifiers::CPF_A, 'rate_limit_cpf' ), 7 ) ),
			$this->reads,
			'The block is looked up by the hashed CPF, and nothing else is counted.'
		);
	}

	public function test_cpf_blocks_and_records_on_abuse_threshold(): void {
		$this->counters['cpf:hour'] = 99;
		$s   = array( 'cpf' => array( 'check_database' => true, 'max_per_month' => 5, 'max_per_year' => 50, 'block_threshold' => 10, 'block_hours' => 1, 'block_duration' => 24, 'message' => 'x' ) );
		$res = ( new CpfLimiter( $this->support( $s ) ) )->check( Identifiers::CPF_A, 7 );
		$this->assertFalse( $res['allowed'] );
		$this->assertSame( 'cpf_abuse', $res['reason'] );

		$this->assertCount( 1, $this->inserts, 'The abuse threshold writes exactly one temporary block.' );
		$block = $this->inserts[0][1];
		$this->assertSame( 'cpf', $block['type'] );
		$this->assertSame( \FreeFormCertificate\Core\Encryption::keyed_hash( Identifiers::CPF_A, 'rate_limit_cpf' ), $block['identifier'] );
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
		$s   = array( 'cpf' => array( 'check_database' => true, 'max_per_month' => 5, 'max_per_year' => 50, 'block_threshold' => 10, 'block_hours' => 1, 'block_duration' => 24, 'message' => 'Yearly limit reached.' ) );
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
		$s = array( 'cpf' => array( 'check_database' => true, 'max_per_month' => 5, 'max_per_year' => 50, 'block_threshold' => 10, 'block_hours' => 1, 'block_duration' => 24, 'message' => 'x' ) );
		$this->assertTrue( ( new CpfLimiter( $this->support( $s ) ) )->check( Identifiers::CPF_A, 7 )['allowed'] );
		$this->assertSame( array(), $this->inserts, 'A CPF under every limit is not blocked.' );
	}

	public function test_email_wait_is_when_the_oldest_counted_submission_leaves_the_window(): void {
		// #2339: never a fixed "24 hours" when the next slot frees sooner.
		$this->submissions['email_hash:1'] = 3;
		$this->oldest['email_hash:1']      = time() - 20 * HOUR_IN_SECONDS;
		$s   = array( 'email' => array( 'check_database' => true, 'max_per_day' => 3, 'max_per_week' => 9, 'max_per_month' => 20, 'message' => 'Try again in {time}.' ) );
		$res = ( new EmailLimiter( $this->support( $s + array( 'ui' => array( 'show_wait_time' => true ) ) ) ) )->check( 'a@b.co', 7 );

		$this->assertEqualsWithDelta( 4 * HOUR_IN_SECONDS, $res['wait_seconds'], 5 );
		$this->assertSame( 'Try again in 4 hours.', $res['message'] );
	}

	public function test_the_email_interval_refuses_until_it_has_passed(): void {
		$this->latest = time() - 2 * HOUR_IN_SECONDS;
		$s   = array( 'email' => array( 'check_database' => true, 'wait_hours' => 6, 'max_per_day' => 3, 'max_per_week' => 9, 'max_per_month' => 20, 'message' => 'x' ) );
		$res = ( new EmailLimiter( $this->support( $s ) ) )->check( 'a@b.co', 7 );

		$this->assertFalse( $res['allowed'] );
		$this->assertSame( 'email_interval', $res['reason'] );
		$this->assertEqualsWithDelta( 4 * HOUR_IN_SECONDS, $res['wait_seconds'], 5 );
	}

	public function test_the_email_interval_lets_through_once_passed_and_is_off_at_zero(): void {
		$this->latest = time() - 7 * HOUR_IN_SECONDS;
		$limits       = array( 'check_database' => true, 'max_per_day' => 3, 'max_per_week' => 9, 'max_per_month' => 20, 'message' => 'x' );

		$this->assertTrue( ( new EmailLimiter( $this->support( array( 'email' => $limits + array( 'wait_hours' => 6 ) ) ) ) )->check( 'a@b.co', 7 )['allowed'] );

		$this->latest = time() - 60;
		$this->assertTrue( ( new EmailLimiter( $this->support( array( 'email' => $limits + array( 'wait_hours' => 0 ) ) ) ) )->check( 'a@b.co', 7 )['allowed'], '0 is off.' );
	}

	public function test_cpf_block_counts_over_the_configured_hours(): void {
		// #2339: "block after N attempts in X hours" sums X hour rows; reading
		// only the current hour's row reset the count on every boundary.
		$this->counters['cpf:hour'] = 3;
		$s   = array( 'cpf' => array( 'check_database' => false, 'block_threshold' => 3, 'block_hours' => 6, 'block_duration' => 24 ) );
		$res = ( new CpfLimiter( $this->support( $s ) ) )->check( Identifiers::CPF_A, 7 );

		$this->assertSame( 'cpf_abuse', $res['reason'] );
		$this->assertSame( array( gmdate( 'Y-m-d H:00:00', time() - 5 * HOUR_IN_SECONDS ) ), $this->hour_sums, 'Six clock hours, this one included.' );
		$this->assertSame( 24 * HOUR_IN_SECONDS, $res['wait_seconds'] );
		$this->assertStringContainsString( 'CPF/RF', $res['message'] );
	}

	public function test_an_already_blocked_cpf_waits_out_what_is_left_of_the_block(): void {
		$this->blocked       = true;
		$this->blocked_until = gmdate( 'Y-m-d H:i:s', time() + 90 * MINUTE_IN_SECONDS );
		$s   = array( 'cpf' => array( 'check_database' => true, 'block_duration' => 24 ) );
		$res = ( new CpfLimiter( $this->support( $s ) ) )->check( Identifiers::CPF_A, 7 );

		$this->assertSame( 'cpf_blocked', $res['reason'] );
		$this->assertEqualsWithDelta( 90 * MINUTE_IN_SECONDS, $res['wait_seconds'], 5 );
	}

	// ===================== RateLimitSupport =====================

	public function test_support_format_message_resolves_every_token(): void {
		$out = $this->support( array( 'ui' => array( 'show_wait_time' => true ) ) )->format_message(
			'Wait {time}: {count} of {max}, {remaining} left',
			array(
				'count' => 2,
				'max'   => 5,
				'wait'  => 90,
			)
		);
		$this->assertSame( 'Wait 2 minutes: 2 of 5, 3 left', $out );
	}

	public function test_remaining_never_goes_below_zero(): void {
		$out = $this->support( array() )->format_message( '{remaining}', array( 'count' => 9, 'max' => 5 ) );
		$this->assertSame( '0', $out );
	}

	public function test_with_show_wait_time_off_the_time_is_not_told(): void {
		$out = $this->support( array( 'ui' => array( 'show_wait_time' => false ) ) )->format_message( 'Wait {time}.', array( 'wait' => 600 ) );
		$this->assertSame( 'Wait a while.', $out );
	}

	public function test_show_remaining_adds_how_much_was_used_unless_the_message_says_it(): void {
		$on = $this->support( array( 'ui' => array( 'show_remaining' => true ) ) );
		$this->assertSame( 'Limit reached. You have used 3 of 3.', $on->format_message( 'Limit reached.', array( 'count' => 3, 'max' => 3 ) ) );
		$this->assertSame( 'You already have 3.', $on->format_message( 'You already have {count}.', array( 'count' => 3, 'max' => 3 ) ) );
		$this->assertSame( 'Limit reached.', $on->format_message( 'Limit reached.', array( 'wait' => 10 ) ), 'Nothing to count, nothing added.' );

		$off = $this->support( array( 'ui' => array( 'show_remaining' => false ) ) );
		$this->assertSame( 'Limit reached.', $off->format_message( 'Limit reached.', array( 'count' => 3, 'max' => 3 ) ) );
	}

	public function test_support_format_message_falls_back_on_blank_template(): void {
		$out = $this->support( array( 'ui' => array( 'show_wait_time' => true ) ) )->format_message( '   ', array( 'wait' => 300 ) );
		$this->assertStringContainsString( '5 minutes', $out );
	}

	public function test_wait_words_round_up_and_change_unit(): void {
		$this->assertSame( '1 second', RateLimitSupport::wait_words( 1 ) );
		$this->assertSame( '1 minute', RateLimitSupport::wait_words( 60 ) );
		$this->assertSame( '2 minutes', RateLimitSupport::wait_words( 61 ) );
		$this->assertSame( '47 hours', RateLimitSupport::wait_words( 47 * HOUR_IN_SECONDS ) );
		$this->assertSame( '3 days', RateLimitSupport::wait_words( 50 * HOUR_IN_SECONDS ) );
	}

	public function test_the_countdown_needs_both_switches(): void {
		$this->assertTrue( $this->support( array( 'ui' => array( 'countdown_timer' => true, 'show_wait_time' => true ) ) )->countdown_on() );
		$this->assertFalse( $this->support( array( 'ui' => array( 'countdown_timer' => true, 'show_wait_time' => false ) ) )->countdown_on(), 'A countdown tells the wait.' );
		$this->assertFalse( $this->support( array( 'ui' => array( 'countdown_timer' => false, 'show_wait_time' => true ) ) )->countdown_on() );
	}
}
