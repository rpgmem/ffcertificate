<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\Encryption;
use FreeFormCertificate\Core\IpAddress;
use FreeFormCertificate\Frontend\Submission\SubmissionContext;
use FreeFormCertificate\Frontend\Submission\SubmissionRejected;
use FreeFormCertificate\Frontend\Submission\RateLimitGuard;
use FreeFormCertificate\Security\RateLimitChecker;
use FreeFormCertificate\Tests\Support\Identifiers;

/**
 * Dedicated coverage for RateLimitGuard — the consolidated submission
 * rate-limit gate. Exercises the reprint-detection skip-device path, the
 * CPF normalize/record branch, and the deferred device-signal persistence
 * hook (add_action), beyond the deny/allow basics in SubmissionGuardsTest.
 *
 * The rate limiter runs for real — RateLimiter, RateLimitChecker, the IP and
 * device limiters, RateCounter, RateLimitRepository, ReprintDetector,
 * ClientIpResolver and Encryption with the bootstrap's key constants — over
 * an in-memory transient store, the rate-limit option the test sets, and a
 * `$wpdb` double that answers by SQL text, records every write, and fails
 * the test on a query it does not know.
 *
 * @covers \FreeFormCertificate\Frontend\Submission\RateLimitGuard
 */
class RateLimitGuardTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const IP = '203.0.113.7';

	/** @var array<string, mixed> Overrides merged over the limiter defaults. */
	private array $settings = array();

	/** @var array<string, mixed> The transient store. */
	private array $transients = array();

	/** @var object|null The submission row the reprint lookup answers. */
	private ?object $submission_row = null;

	/** @var bool Whether the device-signal count query is expected. */
	private bool $device_queries = false;

	/** @var list<string> Every read query the double ran. */
	private array $reads = array();

	/** @var list<array{0: string, 1: array<string, mixed>}> Every insert: table and row. */
	private array $inserts = array();

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	/** @var array<string, mixed> `$_SERVER` before this test. */
	private array $server_backup = array();

	/**
	 * A CPF in its `ddd.ddd.ddd-dd` display form, built from the standard fixture.
	 *
	 * @param string $cpf Eleven digits.
	 * @return string
	 */
	private static function mask_cpf( string $cpf ): string {
		return substr( $cpf, 0, 3 ) . '.' . substr( $cpf, 3, 3 ) . '.' . substr( $cpf, 6, 3 ) . '-' . substr( $cpf, 9, 2 );
	}

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// The limiter memoises its settings per request.
		$cache = new \ReflectionProperty( RateLimitChecker::class, 'settings_cache' );
		$cache->setAccessible( true );
		$cache->setValue( null, null );

		$this->settings       = array(
			// Off so a denial writes nothing and an allowed submission writes
			// only the counters these tests assert on.
			'logging' => array(
				'enabled'     => false,
				'log_allowed' => false,
			),
			'email'   => array( 'enabled' => false ),
		);
		$this->transients     = array();
		$this->submission_row = null;
		$this->device_queries = false;
		$this->reads          = array();
		$this->inserts        = array();

		Functions\when( '__' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'current_time' )->justReturn( '2027-01-15 08:00:00' );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'wp_parse_args' )->alias( static fn( $args, $defaults ) => array_merge( (array) $defaults, (array) $args ) );
		Functions\when( 'get_option' )->alias(
			function ( $key, $default = false ) {
				if ( 'ffc_rate_limit_settings' === $key ) {
					return array_replace_recursive( (array) $default, $this->settings );
				}
				if ( 'ffc_settings' === $key ) {
					return array();
				}
				return $default;
			}
		);
		Functions\when( 'get_transient' )->alias( fn( $key ) => $this->transients[ $key ] ?? false );
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value ) {
				$this->transients[ $key ] = $value;
				return true;
			}
		);

		$this->server_backup   = $_SERVER ?? array();
		$_SERVER['REMOTE_ADDR'] = self::IP;
		// The headers ClientIpResolver reads ahead of REMOTE_ADDR.
		unset( $_SERVER['HTTP_CLIENT_IP'], $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_X_FORWARDED'], $_SERVER['HTTP_X_CLUSTER_CLIENT_IP'], $_SERVER['HTTP_FORWARDED_FOR'], $_SERVER['HTTP_FORWARDED'] );

		$this->had_wpdb = array_key_exists( 'wpdb', $GLOBALS );
		if ( $this->had_wpdb ) {
			$this->wpdb_backup = $GLOBALS['wpdb'];
		}
		$GLOBALS['wpdb'] = $this->wpdb_double();
	}

	protected function tearDown(): void {
		$_SERVER = $this->server_backup;
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A `$wpdb` double: `prepare()` binds its arguments into the SQL, reads
	 * are answered by SQL text, inserts are recorded.
	 *
	 * @return \Mockery\MockInterface
	 */
	private function wpdb_double() {
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( $sql, ...$args ) {
				$flat = 1 === count( $args ) && is_array( $args[0] ) ? $args[0] : $args;
				return (string) preg_replace_callback(
					'/%[ids]/',
					static function () use ( &$flat ) {
						return (string) array_shift( $flat );
					},
					$sql
				);
			}
		);
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			function ( $q ) {
				$this->reads[] = $q;
				if ( 0 === strpos( $q, 'SELECT count FROM wp_ffc_rate_limits' ) ) {
					return null;
				}
				if ( $this->device_queries && 0 === strpos( $q, 'SELECT COUNT(*) FROM wp_ffc_device_signals' ) ) {
					return '0';
				}
				$this->fail( 'Unexpected get_var(): ' . $q );
			}
		);
		$wpdb->shouldReceive( 'get_row' )->andReturnUsing(
			function ( $q ) {
				$this->reads[] = $q;
				if ( 0 === strpos( $q, 'SELECT id,count FROM wp_ffc_rate_limits' ) ) {
					return null;
				}
				if ( 0 === strpos( $q, 'SELECT * FROM wp_ffc_submissions' ) ) {
					return $this->submission_row;
				}
				$this->fail( 'Unexpected get_row(): ' . $q );
			}
		);
		$wpdb->shouldReceive( 'insert' )->andReturnUsing(
			function ( $table, $row ) {
				$this->inserts[] = array( $table, $row );
				return 1;
			}
		);
		return $wpdb;
	}

	/**
	 * The counter rows written to the rate-limit table, as `type/window` with
	 * the stored identifier.
	 *
	 * @return list<array{0: string, 1: string}>
	 */
	private function counter_rows(): array {
		$rows = array();
		foreach ( $this->inserts as list( $table, $row ) ) {
			if ( 'wp_ffc_rate_limits' === $table ) {
				$rows[] = array( $row['type'] . '/' . $row['window_type'], $row['identifier'] );
			}
		}
		return $rows;
	}

	/**
	 * The reprint lookups the double answered.
	 *
	 * @return list<string>
	 */
	private function reprint_lookups(): array {
		return array_values( array_filter( $this->reads, static fn( $q ) => 0 === strpos( $q, 'SELECT * FROM wp_ffc_submissions' ) ) );
	}

	private function ctx(): SubmissionContext {
		return new SubmissionContext();
	}

	public function test_throws_when_check_all_denies(): void {
		// The address already reached its hourly limit, under the operator's message.
		$this->settings['ip'] = array(
			'max_per_hour' => 5,
			'message'      => 'too many',
		);
		$this->transients[ 'ffc_rate_ip_' . md5( IpAddress::fingerprint( self::IP ) . '0' ) . '_hour' ] = 5;

		$ctx             = $this->ctx();
		$ctx->user_email = 'a@b.co';

		try {
			( new RateLimitGuard() )->apply( $ctx );
			$this->fail( 'expected SubmissionRejected' );
		} catch ( SubmissionRejected $e ) {
			$payload = $e->get_payload();
			$this->assertTrue( $payload['rate_limit'] );
			$this->assertSame( 'too many', $payload['message'] );
			$this->assertSame( 3600, $payload['wait_seconds'] );
		}
		$this->assertSame( array(), $this->inserts, 'A denied submission records no attempt.' );
	}

	/**
	 * Every denial the real limiter returns carries a message, so the
	 * guard's own fallback message is unreachable from it; what a blacklist
	 * denial does exercise is the fallback wait of zero seconds.
	 */
	public function test_a_denial_without_a_wait_reports_zero_seconds(): void {
		$this->settings['blacklist'] = array( 'ips' => array( self::IP ) );

		try {
			( new RateLimitGuard() )->apply( $this->ctx() );
			$this->fail( 'expected SubmissionRejected' );
		} catch ( SubmissionRejected $e ) {
			$payload = $e->get_payload();
			$this->assertSame( 'IP blocked.', $payload['message'] );
			$this->assertSame( 0, $payload['wait_seconds'] );
		}
		$this->assertSame( array(), $this->inserts, 'A denied submission records no attempt.' );
	}

	public function test_records_ip_and_email_when_allowed_no_cpf(): void {
		$ctx             = $this->ctx();
		$ctx->user_email = 'a@b.co';
		// device_signals null → no add_action branch.
		( new RateLimitGuard() )->apply( $ctx );

		$this->assertSame(
			array(
				array( 'ip/day', IpAddress::fingerprint( self::IP ) ),
				array( 'email/day', hash( 'sha256', 'a@b.co' ) ),
				array( 'email/month', hash( 'sha256', 'a@b.co' ) ),
			),
			$this->counter_rows(),
			'IP and email are recorded; with no CPF, no CPF counter is written.'
		);
		$this->assertSame( array(), $this->reprint_lookups(), 'No CPF, so no reprint lookup.' );
		$this->assertNull( $ctx->device_signals );
	}

	public function test_records_cpf_attempt_with_normalized_value(): void {
		$ctx             = $this->ctx();
		$ctx->user_email = 'c@d.co';
		$ctx->val_cpf    = self::mask_cpf( Identifiers::CPF_A );
		// device_signals null → no deferred hook.
		( new RateLimitGuard() )->apply( $ctx );

		$cpf = hash( 'sha256', Identifiers::CPF_A );
		$this->assertSame(
			array(
				array( 'ip/day', IpAddress::fingerprint( self::IP ) ),
				array( 'email/day', hash( 'sha256', 'c@d.co' ) ),
				array( 'email/month', hash( 'sha256', 'c@d.co' ) ),
				array( 'cpf/day', $cpf ),
				array( 'cpf/month', $cpf ),
				array( 'cpf/hour', $cpf ),
			),
			$this->counter_rows(),
			'The CPF counter is keyed on the normalized digits.'
		);
		// ReprintDetector is consulted because val_cpf is set and skip_device false.
		$lookups = $this->reprint_lookups();
		$this->assertCount( 1, $lookups );
		$this->assertStringContainsString( 'cpf_hash = ' . Encryption::hash( Identifiers::CPF_A ), $lookups[0] );
		$this->assertFalse( $ctx->skip_device );
	}

	public function test_reprint_detection_sets_skip_device(): void {
		// The device limit is on and signals arrived, so only skip_device
		// keeps the double from being asked for a device count it does not know.
		$this->settings['device'] = array( 'enabled' => true );
		$this->submission_row     = (object) array(
			'id'              => '12',
			'submission_date' => '1800000000',
			'data'            => '{}',
		);

		$ctx                 = $this->ctx();
		$ctx->user_email     = 'r@e.co';
		$ctx->val_cpf        = Identifiers::CPF_A;
		$ctx->form_id        = 7;
		$ctx->device_signals = array( 'cookie' => 'abc' );

		Functions\expect( 'add_action' )->never();

		( new RateLimitGuard() )->apply( $ctx );

		$this->assertCount( 1, $this->reprint_lookups() );
		$this->assertTrue( $ctx->skip_device, 'reprint must whitelist the per-device gate' );
	}

	public function test_registers_deferred_device_signal_hook_when_signals_present(): void {
		$this->settings['device'] = array( 'enabled' => true );
		$this->device_queries     = true;

		$captured = null;
		Functions\expect( 'add_action' )->once()->andReturnUsing(
			function ( $hook, $cb ) use ( &$captured ) {
				$this->assertSame( 'ffcertificate_after_submission_save', $hook );
				$captured = $cb;
				return true;
			}
		);

		$ctx                 = $this->ctx();
		$ctx->user_email     = 'd@e.co';
		$ctx->form_id        = 7;
		$ctx->device_signals = array( 'cookie' => 'abc' );
		// val_cpf empty → ReprintDetector not consulted.
		( new RateLimitGuard() )->apply( $ctx );

		$this->assertIsCallable( $captured );
		$signal_rows = static function ( array $inserts ): array {
			return array_values( array_filter( $inserts, static fn( $i ) => 'wp_ffc_device_signals' === $i[0] ) );
		};
		$this->assertSame( array(), $signal_rows( $this->inserts ), 'Nothing is persisted before the row exists.' );

		// Mismatched form id → early return, nothing persisted.
		$captured( 99, 8 );
		$this->assertSame( array(), $signal_rows( $this->inserts ) );

		// Matching form id → the signals are persisted against the submission.
		$captured( 99, 7 );
		$rows = $signal_rows( $this->inserts );
		$this->assertCount( 1, $rows );
		$this->assertSame( 99, $rows[0][1]['submission_id'] );
		$this->assertSame( 7, $rows[0][1]['form_id'] );
		$this->assertSame( 'abc', $rows[0][1]['sig_cookie'] );
	}

	public function test_deferred_hook_skipped_when_skip_device(): void {
		Functions\expect( 'add_action' )->never();

		$ctx                 = $this->ctx();
		$ctx->user_email     = 'd@e.co';
		$ctx->skip_device    = true;
		$ctx->device_signals = array( 'fp' => 'abc' );
		( new RateLimitGuard() )->apply( $ctx );

		$this->assertTrue( $ctx->skip_device );
	}
}
