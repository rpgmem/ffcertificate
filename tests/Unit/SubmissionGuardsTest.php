<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\Captcha\CaptchaProvider;
use FreeFormCertificate\Core\IpAddress;
use FreeFormCertificate\Core\SecurityService;
use FreeFormCertificate\Frontend\Submission\SubmissionContext;
use FreeFormCertificate\Frontend\Submission\SubmissionRejected;
use FreeFormCertificate\Frontend\Submission\IpRateLimitGuard;
use FreeFormCertificate\Frontend\Submission\NonceGuard;
use FreeFormCertificate\Frontend\Submission\SecurityFieldsGuard;
use FreeFormCertificate\Frontend\Submission\FormConfigResolver;
use FreeFormCertificate\Frontend\Submission\RequiredPresenceGuard;
use FreeFormCertificate\Frontend\Submission\FieldSanitizer;
use FreeFormCertificate\Frontend\Submission\DeviceSignalsResolver;
use FreeFormCertificate\Frontend\Submission\GeofenceGuard;
use FreeFormCertificate\Frontend\Submission\RateLimitGuard;
use FreeFormCertificate\Frontend\Submission\AccessRestrictionGuard;
use FreeFormCertificate\Frontend\Submission\ScheduleExceptionGuard;
use FreeFormCertificate\Security\GpsReading;
use FreeFormCertificate\Security\RateLimitChecker;

/**
 * #563 Sprint 1 — unit tests for the extracted submission entry guards.
 *
 * Each guard is exercised in isolation against a SubmissionContext: this is
 * the testability win the pipeline split delivers (the legacy monolith could
 * only be driven end-to-end). The collaborators run for real — RateLimiter
 * and its checker, limiters, counters and repository; SecurityService with
 * the math captcha, its signer and its ledger; DataSanitizer; Geofence and
 * GpsReading; AccessRestrictionChecker; Debug and ActivityLog, both off —
 * over an in-memory transient store, the options the test sets, the post
 * meta it answers, and a `$wpdb` double that answers by SQL text, records
 * every write, and fails the test on a query it does not know (#2091).
 */
class SubmissionGuardsTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const IP = '203.0.113.7';

	/** @var array<string, mixed> Overrides merged over the rate-limit defaults. */
	private array $settings = array();

	/** @var array<string, mixed> The transient store. */
	private array $transients = array();

	/** @var list<string> Every transient key read. */
	private array $transient_reads = array();

	/** @var array<string, mixed> Post meta by key, answered for any post. */
	private array $post_meta = array();

	/** @var list<array{0: int, 1: string, 2: mixed}> Every update_post_meta() call. */
	private array $meta_writes = array();

	/** @var list<string> Every read query the double ran. */
	private array $reads = array();

	/** @var list<array{0: string, 1: array<string, mixed>}> Every insert: table and row. */
	private array $inserts = array();

	/** @var list<string> Every query() the double ran. */
	private array $queries = array();

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	/** @var array<string, mixed> `$_SERVER` before this test. */
	private array $server_backup = array();

	/** @var array<string, mixed> `$_POST` before this test. */
	private array $post_backup = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Memoised per request by the real classes.
		$cache = new \ReflectionProperty( RateLimitChecker::class, 'settings_cache' );
		$cache->setAccessible( true );
		$cache->setValue( null, null );
		CaptchaProvider::reset();

		$this->settings        = array(
			// Off, so a denial writes no log row and the double sees only the
			// counters the tests assert on.
			'logging' => array(
				'enabled'     => false,
				'log_allowed' => false,
			),
			'email'   => array( 'enabled' => false ),
		);
		$this->transients      = array();
		$this->transient_reads = array();
		$this->post_meta       = array();
		$this->meta_writes     = array();
		$this->reads           = array();
		$this->inserts         = array();
		$this->queries         = array();

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_email' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'wp_kses' )->returnArg();
		Functions\when( 'is_email' )->alias( static fn( $v ) => false !== filter_var( (string) $v, FILTER_VALIDATE_EMAIL ) ? $v : false );
		Functions\when( 'absint' )->alias( 'intval' );
		Functions\when( 'wp_rand' )->alias( static fn( $min = 0, $max = 0 ) => random_int( (int) $min, (int) $max ) );
		Functions\when( 'wp_salt' )->justReturn( 'submission-guards-test-salt' );
		Functions\when( 'wp_date' )->alias( static fn( $format, $ts = null ) => gmdate( $format, null === $ts ? time() : $ts ) );
		Functions\when( 'current_time' )->justReturn( '2027-01-15 08:00:00' );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'wp_parse_args' )->alias( static fn( $args, $defaults ) => array_merge( (array) $defaults, (array) $args ) );
		Functions\when( 'get_option' )->alias(
			function ( $key, $default = false ) {
				if ( 'ffc_rate_limit_settings' === $key ) {
					return array_replace_recursive( (array) $default, $this->settings );
				}
				if ( 'ffc_settings' === $key ) {
					// Debug, the activity log and the captcha provider all
					// read their defaults: off, off, math.
					return array();
				}
				return $default;
			}
		);
		Functions\when( 'get_transient' )->alias(
			function ( $key ) {
				$this->transient_reads[] = $key;
				return $this->transients[ $key ] ?? false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value ) {
				$this->transients[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'get_post_meta' )->alias( fn( $id, $key ) => $this->post_meta[ $key ] ?? '' );
		Functions\when( 'update_post_meta' )->alias(
			function ( $id, $key, $value ) {
				$this->meta_writes[] = array( (int) $id, (string) $key, $value );
				return true;
			}
		);

		$this->post_backup   = $_POST ?? array();
		$this->server_backup = $_SERVER ?? array();
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
		$_POST   = $this->post_backup;
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
	 * A `$wpdb` double: `prepare()` binds its arguments into the SQL, the
	 * rate-limit counter reads find no row, the ticket claim succeeds, and
	 * anything else fails the test.
	 *
	 * @return \Mockery\MockInterface
	 */
	private function wpdb_double() {
		$wpdb                = Mockery::mock( 'wpdb' );
		$wpdb->prefix        = 'wp_';
		$wpdb->options       = 'wp_options';
		$wpdb->rows_affected = 0;
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
				$this->fail( 'Unexpected get_var(): ' . $q );
			}
		);
		$wpdb->shouldReceive( 'get_row' )->andReturnUsing(
			function ( $q ) {
				$this->reads[] = $q;
				if ( 0 === strpos( $q, 'SELECT id,count FROM wp_ffc_rate_limits' ) ) {
					return null;
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
		$wpdb->shouldReceive( 'query' )->andReturnUsing(
			function ( $q ) use ( $wpdb ) {
				$this->queries[] = $q;
				if ( 0 === strpos( $q, 'INSERT IGNORE INTO wp_options' ) ) {
					// The first claim of a ticket wins.
					$wpdb->rows_affected = 1;
					return 1;
				}
				$this->fail( 'Unexpected query(): ' . $q );
			}
		);
		return $wpdb;
	}

	/**
	 * The counter rows written to the rate-limit table, as `type/window`.
	 *
	 * @return list<string>
	 */
	private function counter_rows(): array {
		$rows = array();
		foreach ( $this->inserts as list( $table, $row ) ) {
			if ( 'wp_ffc_rate_limits' === $table ) {
				$rows[] = $row['type'] . '/' . $row['window_type'];
			}
		}
		return $rows;
	}

	/**
	 * The transient that counts this test's address per hour.
	 *
	 * @param int|null $form_id The form the counter belongs to.
	 * @return string
	 */
	private static function ip_hour_key( ?int $form_id ): string {
		return 'ffc_rate_ip_' . md5( IpAddress::fingerprint( self::IP ) . $form_id ) . '_hour';
	}

	private function ctx(): SubmissionContext {
		return new SubmissionContext();
	}

	// ===================== ScheduleExceptionGuard =====================

	public function test_schedule_exception_guard_is_noop_without_token(): void {
		$_POST = array();
		$ctx   = $this->ctx();
		( new ScheduleExceptionGuard() )->apply( $ctx );
		$this->assertFalse( $ctx->has_exception );
		$this->assertNull( $ctx->schedule_exception_payload );
	}

	// ===================== IpRateLimitGuard =====================

	public function test_ip_guard_throws_when_over_limit(): void {
		// The address already reached its hourly limit, under the operator's message.
		$this->settings['ip']                      = array(
			'max_per_hour' => 5,
			'message'      => 'slow down',
		);
		$this->transients[ self::ip_hour_key( null ) ] = 5;

		try {
			( new IpRateLimitGuard() )->apply( $this->ctx() );
			$this->fail( 'expected SubmissionRejected' );
		} catch ( SubmissionRejected $e ) {
			$payload = $e->get_payload();
			$this->assertTrue( $payload['rate_limit'] );
			$this->assertSame( 'slow down', $payload['message'] );
			$this->assertSame( 3600, $payload['wait_seconds'] );
		}
	}

	public function test_ip_guard_passes_when_allowed(): void {
		$ctx = $this->ctx();
		( new IpRateLimitGuard() )->apply( $ctx );
		$this->assertFalse( $ctx->has_exception );
		$this->assertContains( self::ip_hour_key( null ), $this->transient_reads, 'The limiter was consulted.' );
		$this->assertCount( 1, $this->reads, 'The daily counter was read from the table.' );
	}

	public function test_ip_guard_skipped_on_exception_path(): void {
		// A counter that would block, so only the skip lets the guard pass.
		$this->transients[ self::ip_hour_key( null ) ] = 99;
		$ctx                                            = $this->ctx();
		$ctx->has_exception                             = true;
		( new IpRateLimitGuard() )->apply( $ctx );
		$this->assertTrue( $ctx->has_exception );
		$this->assertSame( array(), $this->transient_reads, 'The limiter is not consulted on the exception path.' );
		$this->assertSame( array(), $this->reads );
	}

	// ===================== NonceGuard =====================

	public function test_nonce_guard_throws_with_fresh_nonce_when_invalid(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( false );
		Functions\when( 'wp_create_nonce' )->justReturn( 'fresh-nonce' );
		$_POST = array( 'nonce' => 'stale' );

		try {
			( new NonceGuard() )->apply( $this->ctx() );
			$this->fail( 'expected SubmissionRejected' );
		} catch ( SubmissionRejected $e ) {
			$payload = $e->get_payload();
			$this->assertTrue( $payload['refresh_nonce'] );
			$this->assertSame( 'fresh-nonce', $payload['new_nonce'] );
		}
	}

	public function test_nonce_guard_passes_when_valid(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		$_POST = array( 'nonce' => 'ok' );

		// #1030: a guard signals rejection by throwing SubmissionRejected, so
		// "passes" means apply() returns instead. Assert the return contract
		// and name the mechanism, rather than asserting a literal true.
		$this->assertNull( ( new NonceGuard() )->apply( $this->ctx() ) );
	}

	// ===================== SecurityFieldsGuard =====================

	public function test_security_guard_throws_with_new_captcha_on_failure(): void {
		$_POST = array();

		try {
			( new SecurityFieldsGuard() )->apply( $this->ctx() );
			$this->fail( 'expected SubmissionRejected' );
		} catch ( SubmissionRejected $e ) {
			$payload = $e->get_payload();
			// The guard rejects carrying a usable challenge, issued by the
			// configured provider (math, by default).
			$this->assertSame( 'Error: Please answer the security question.', $payload['message'] );
			$this->assertTrue( $payload['refresh_captcha'] );
			$this->assertSame( 'math', $payload['provider'] );
			$this->assertNotEmpty( $payload['new_label'] );
			$this->assertNotEmpty( $payload['new_hash'] );
		}
	}

	public function test_security_guard_passes_when_valid(): void {
		$challenge = SecurityService::generate_simple_captcha();
		$_POST     = array(
			'ffc_captcha_ans'  => (string) $challenge['answer'],
			'ffc_captcha_hash' => $challenge['hash'],
		);

		$this->assertNull( ( new SecurityFieldsGuard() )->apply( $this->ctx() ) );
		$spent = array_filter( array_keys( $this->transients ), static fn( $k ) => 0 === strpos( $k, 'ffc_captcha_used_' ) );
		$this->assertCount( 1, $spent, 'A valid answer spends its challenge.' );
	}

	// ===================== FormConfigResolver =====================

	public function test_form_config_resolver_rejects_missing_form_id(): void {
		$_POST = array();
		try {
			( new FormConfigResolver() )->apply( $this->ctx() );
			$this->fail( 'expected SubmissionRejected' );
		} catch ( SubmissionRejected $e ) {
			$this->assertStringContainsString( 'Invalid Form ID', $e->get_payload()['message'] );
		}
	}

	/**
	 * A published `ffc_form`, as `get_post()` answers it.
	 *
	 * @param string $status    Post status.
	 * @param string $post_type Post type.
	 */
	private function stub_form_post( string $status = 'publish', string $post_type = 'ffc_form' ): void {
		Functions\when( 'get_post' )->justReturn(
			(object) array(
				'ID'          => 7,
				'post_type'   => $post_type,
				'post_status' => $status,
			)
		);
	}

	/**
	 * #2241 — only a published form takes submissions; REST already refused
	 * the others, and the AJAX path did not.
	 *
	 * @dataProvider provider_unavailable_forms
	 */
	public function test_form_config_resolver_rejects_a_form_that_is_not_published( string $status, string $post_type ): void {
		$_POST = array( 'form_id' => 7 );
		$this->stub_form_post( $status, $post_type );
		Functions\when( 'get_post_meta' )->alias(
			static fn( $id, $key ) => '_ffc_form_config' === $key ? array() : array( array( 'name' => 'email', 'type' => 'email' ) )
		);
		try {
			( new FormConfigResolver() )->apply( $this->ctx() );
			$this->fail( 'expected SubmissionRejected' );
		} catch ( SubmissionRejected $e ) {
			$this->assertStringContainsString( 'not available', $e->get_payload()['message'] );
		}
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function provider_unavailable_forms(): array {
		return array(
			'draft'          => array( 'draft', 'ffc_form' ),
			'private'        => array( 'private', 'ffc_form' ),
			'trashed'        => array( 'trash', 'ffc_form' ),
			'not a form'     => array( 'publish', 'post' ),
		);
	}

	public function test_form_config_resolver_rejects_a_missing_post(): void {
		$_POST = array( 'form_id' => 7 );
		Functions\when( 'get_post' )->justReturn( null );
		try {
			( new FormConfigResolver() )->apply( $this->ctx() );
			$this->fail( 'expected SubmissionRejected' );
		} catch ( SubmissionRejected $e ) {
			$this->assertStringContainsString( 'not available', $e->get_payload()['message'] );
		}
	}

	public function test_form_config_resolver_rejects_absent_fields(): void {
		$_POST = array( 'form_id' => 7 );
		$this->stub_form_post();
		Functions\when( 'get_post_meta' )->alias(
			static fn( $id, $key ) => '_ffc_form_config' === $key ? array() : ''
		);
		try {
			( new FormConfigResolver() )->apply( $this->ctx() );
			$this->fail( 'expected SubmissionRejected' );
		} catch ( SubmissionRejected $e ) {
			$this->assertStringContainsString( 'not available', $e->get_payload()['message'] );
		}
	}

	public function test_form_config_resolver_populates_context(): void {
		$_POST = array( 'form_id' => 7 );
		$this->stub_form_post();
		Functions\when( 'get_post_meta' )->alias(
			static fn( $id, $key ) => '_ffc_form_config' === $key
				? array( 'quiz_enabled' => '0' )
				: array( array( 'name' => 'email', 'type' => 'email' ) )
		);
		$ctx = $this->ctx();
		( new FormConfigResolver() )->apply( $ctx );
		$this->assertSame( 7, $ctx->form_id );
		$this->assertSame( '0', $ctx->form_config['quiz_enabled'] );
		$this->assertCount( 1, $ctx->fields_config );
	}

	// ===================== RequiredPresenceGuard =====================

	public function test_presence_check_collects_both_lgpd_and_email_errors(): void {
		$_POST              = array(); // no consent, no email value.
		$ctx                = $this->ctx();
		$ctx->fields_config = array( array( 'name' => 'email', 'type' => 'email' ) );
		try {
			( new RequiredPresenceGuard() )->apply( $ctx );
			$this->fail( 'expected SubmissionRejected' );
		} catch ( SubmissionRejected $e ) {
			$errors = $e->get_payload()['errors'];
			$this->assertCount( 2, $errors );
		}
	}

	public function test_presence_check_passes_when_consent_and_email_present(): void {
		$_POST              = array( 'ffc_lgpd_consent' => '1', 'email' => 'a@b.co' );
		$ctx                = $this->ctx();
		$ctx->fields_config = array( array( 'name' => 'email', 'type' => 'email' ) );

		// Same contract as the nonce guard: passing means apply() returns
		// rather than throwing SubmissionRejected.
		$this->assertNull( ( new RequiredPresenceGuard() )->apply( $ctx ) );
	}

	// ===================== FieldSanitizer =====================

	public function test_field_sanitizer_rejects_bad_cpf_length(): void {
		$_POST              = array( 'cpf_rf' => '123' );
		$ctx                = $this->ctx();
		$ctx->fields_config = array( array( 'name' => 'cpf_rf', 'type' => 'text' ) );
		try {
			( new FieldSanitizer() )->apply( $ctx );
			$this->fail( 'expected SubmissionRejected' );
		} catch ( SubmissionRejected $e ) {
			$this->assertStringContainsString( '7 or 11 digits', $e->get_payload()['message'] );
		}
	}

	public function test_field_sanitizer_says_invalid_when_an_address_was_typed(): void {
		Functions\when( 'sanitize_email' )->justReturn( '' );
		$_POST              = array( 'email' => 'not-an-email' );
		$ctx                = $this->ctx();
		$ctx->fields_config = array( array( 'name' => 'email', 'type' => 'email' ) );
		try {
			( new FieldSanitizer() )->apply( $ctx );
			$this->fail( 'expected SubmissionRejected' );
		} catch ( SubmissionRejected $e ) {
			$this->assertSame( 'Enter a valid email address.', $e->get_payload()['message'] );
		}
	}

	/**
	 * #1734 — an invalid address was reported as a missing one.
	 */
	public function test_field_sanitizer_says_required_only_when_nothing_was_typed(): void {
		$_POST              = array( 'email' => '   ' );
		$ctx                = $this->ctx();
		$ctx->fields_config = array( array( 'name' => 'email', 'type' => 'email' ) );
		try {
			( new FieldSanitizer() )->apply( $ctx );
			$this->fail( 'expected SubmissionRejected' );
		} catch ( SubmissionRejected $e ) {
			$this->assertSame( 'Email address is required.', $e->get_payload()['message'] );
		}
	}

	public function test_field_sanitizer_refuses_an_address_is_email_rejects(): void {
		$_POST              = array( 'email' => 'user@' );
		$ctx                = $this->ctx();
		$ctx->fields_config = array( array( 'name' => 'email', 'type' => 'email' ) );
		try {
			( new FieldSanitizer() )->apply( $ctx );
			$this->fail( 'expected SubmissionRejected' );
		} catch ( SubmissionRejected $e ) {
			$this->assertSame( 'Enter a valid email address.', $e->get_payload()['message'] );
		}
	}

	public function test_field_sanitizer_populates_context_on_happy_path(): void {
		$_POST              = array( 'email' => 'USER@EX.CO', 'ffc_ticket' => 'abc' );
		$ctx                = $this->ctx();
		$ctx->fields_config = array( array( 'name' => 'email', 'type' => 'email' ) );
		( new FieldSanitizer() )->apply( $ctx );
		$this->assertSame( 'user@ex.co', $ctx->user_email );
		$this->assertSame( '1', $ctx->submission_data['ffc_lgpd_consent'] );
		$this->assertSame( 'ABC', $ctx->val_ticket );
	}

	public function test_field_sanitizer_takes_a_hidden_field_from_the_form_never_the_request(): void {
		// #1574: a hidden field has no visible control, so a different value
		// in the request can only come from an edited page.
		$_POST              = array(
			'email' => 'u@ex.co',
			'turma' => 'tampered',
		);
		$ctx                = $this->ctx();
		$ctx->fields_config = array(
			array( 'name' => 'email', 'type' => 'email' ),
			array( 'name' => 'turma', 'type' => 'hidden', 'default_value' => '2026-A' ),
			array( 'name' => 'origem', 'type' => 'hidden' ),
		);
		( new FieldSanitizer() )->apply( $ctx );
		$this->assertSame( '2026-A', $ctx->submission_data['turma'] );
		$this->assertSame( '', $ctx->submission_data['origem'], 'A hidden field with no value configured submits an empty one, not whatever was posted.' );
	}

	// ===================== DeviceSignalsResolver =====================

	public function test_device_resolver_null_when_globally_disabled(): void {
		// The form asks for the device limit; the global switch is off.
		$this->settings['device']                     = array( 'enabled' => false );
		$this->post_meta['_ffc_device_limit_enabled'] = '1';
		$_POST['ffc_device_signals']                  = (string) json_encode( array( 'cookie' => str_repeat( 'a', 64 ) ) );
		$ctx                                          = $this->ctx();
		( new DeviceSignalsResolver() )->apply( $ctx );
		$this->assertNull( $ctx->device_signals );
		$this->assertFalse( $ctx->skip_device );
	}

	public function test_device_resolver_sets_skip_on_manager_bypass(): void {
		$this->settings['device']                     = array( 'enabled' => true );
		$this->post_meta['_ffc_device_limit_enabled'] = '1';
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'current_user_can' )->alias( static fn( $cap ) => 'manage_options' === $cap );
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		$ctx = $this->ctx();
		( new DeviceSignalsResolver() )->apply( $ctx );
		$this->assertTrue( $ctx->skip_device );
		$this->assertNull( $ctx->device_signals );
	}

	// ===================== GeofenceGuard =====================

	/**
	 * A GPS-only form around one area, and a reading the browser posts.
	 *
	 * @param string $lat Posted latitude.
	 * @param string $lng Posted longitude.
	 */
	private function gps_form_with_reading( string $lat, string $lng ): void {
		$this->post_meta['_ffc_geofence_config'] = array(
			'geo_enabled'     => '1',
			'geo_gps_enabled' => '1',
			'geo_areas'       => '-23.5505, -46.6333, 100',
			'msg_geo_blocked' => 'outside',
		);
		$_POST = array(
			'ffc_geo_lat'      => $lat,
			'ffc_geo_lng'      => $lng,
			'ffc_geo_accuracy' => '10',
			'ffc_geo_age'      => '0',
		);
	}

	public function test_geofence_guard_throws_when_blocked(): void {
		$this->gps_form_with_reading( '0', '0' );
		try {
			( new GeofenceGuard() )->apply( $this->ctx() );
			$this->fail( 'expected SubmissionRejected' );
		} catch ( SubmissionRejected $e ) {
			$payload = $e->get_payload();
			$this->assertTrue( $payload['geofence_blocked'] );
			$this->assertSame( GpsReading::REASON_OUTSIDE, $payload['reason'] );
			$this->assertSame( 'outside', $payload['message'] );
		}
	}

	public function test_geofence_guard_passes_when_allowed(): void {
		$this->gps_form_with_reading( '-23.5505', '-46.6333' );
		$this->assertNull( ( new GeofenceGuard() )->apply( $this->ctx() ) );
	}

	// ===================== RateLimitGuard =====================

	public function test_rate_limit_guard_throws_when_blocked(): void {
		$this->settings['ip']                       = array(
			'max_per_hour' => 5,
			'message'      => 'limited',
		);
		$this->transients[ self::ip_hour_key( 0 ) ] = 5;
		$before                                     = $this->transients;
		$ctx                                        = $this->ctx();
		$ctx->user_email                            = 'a@b.co';
		try {
			( new RateLimitGuard() )->apply( $ctx );
			$this->fail( 'expected SubmissionRejected' );
		} catch ( SubmissionRejected $e ) {
			$this->assertTrue( $e->get_payload()['rate_limit'] );
			$this->assertSame( 'limited', $e->get_payload()['message'] );
		}
		$this->assertSame( array(), $this->inserts, 'A denied submission records no attempt.' );
		$this->assertSame( $before, $this->transients, 'Nor does it move a counter.' );
	}

	public function test_rate_limit_guard_records_when_allowed(): void {
		$ctx             = $this->ctx();
		$ctx->user_email = 'a@b.co';
		( new RateLimitGuard() )->apply( $ctx );
		$this->assertSame( array( 'ip/day', 'email/day', 'email/month' ), $this->counter_rows() );
		$this->assertSame( 1, $this->transients[ self::ip_hour_key( 0 ) ] ?? null, 'The hourly IP counter moved.' );
	}

	// ===================== AccessRestrictionGuard =====================

	public function test_access_restriction_guard_throws_when_denied(): void {
		$ctx              = $this->ctx();
		$ctx->form_config = array( 'restrictions' => array( 'password' => '1' ), 'validation_code' => 'secret' );
		try {
			( new AccessRestrictionGuard() )->apply( $ctx );
			$this->fail( 'expected SubmissionRejected' );
		} catch ( SubmissionRejected $e ) {
			$this->assertSame( 'Password is required.', $e->get_payload()['message'] );
		}
		$this->assertSame( array(), $ctx->restriction_result );
	}

	public function test_access_restriction_guard_stores_result_when_allowed(): void {
		$ctx              = $this->ctx();
		$ctx->form_id     = 7;
		$ctx->val_ticket  = 'ABC';
		$ctx->form_config = array(
			'restrictions'         => array( 'ticket' => '1' ),
			'generated_codes_list' => "ABC\nXYZ",
		);
		( new AccessRestrictionGuard() )->apply( $ctx );
		$this->assertTrue( $ctx->restriction_result['is_ticket'] );
		$this->assertCount( 1, $this->queries, 'The ticket was claimed once.' );
		$this->assertCount( 1, $this->meta_writes );
		$this->assertSame( array( 7, '_ffc_form_config' ), array_slice( $this->meta_writes[0], 0, 2 ) );
		$this->assertSame( 'XYZ', $this->meta_writes[0][2]['generated_codes_list'], 'The claimed ticket leaves the list.' );
	}
}
