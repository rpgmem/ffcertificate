<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\Captcha\CaptchaProvider;
use FreeFormCertificate\Core\Captcha\MathCaptcha;
use FreeFormCertificate\Core\Encryption;
use FreeFormCertificate\Core\IpAddress;
use FreeFormCertificate\Core\SecurityService;
use FreeFormCertificate\Frontend\VerificationHandler;
use FreeFormCertificate\Security\RateLimitChecker;
use FreeFormCertificate\Tests\Support\ActivityLogBuffer;

/**
 * AJAX-handler coverage for VerificationHandler — handle_verification_ajax(),
 * handle_magic_verification_ajax() and the throttle on verify_certificate().
 *
 * Every collaborator runs for real: SubmissionHandler and the submission,
 * appointment and reregistration readers over a `$wpdb` double that answers
 * by table and column, records every write and fails the test on a query it
 * does not know; the math captcha (SecurityService, MathCaptcha,
 * ChallengeSigner, ChallengeStore) with `ffc_settings` stubbed; RateLimiter /
 * RateLimitChecker / RateCounter over an in-memory transient store, so a
 * denial comes from a stored count; ActivityLog with its buffer read back;
 * PdfGenerator, RecordGenerator, MagicLinkHelper and the response renderer.
 * wp_send_json_* are stubbed to throw so the handler halts like it does in
 * production, and the payload is captured.
 *
 * @covers \FreeFormCertificate\Frontend\VerificationHandler
 */
class VerificationHandlerAjaxTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const IP = '8.8.8.8';

	/** The verification throttle's hourly cap (RateLimitChecker::check_verification()). */
	private const MAX_PER_HOUR = 10;

	/** @var array<string, mixed>|null */
	private $captured_error = null;

	/** @var array<string, mixed>|null */
	private $captured_success = null;

	/** @var array<string, mixed> The transient store. */
	private array $transients = array();

	/** @var array<string, mixed> The `ffc_settings` option. */
	private array $settings = array();

	/** @var array<string, list<array<string, mixed>>> Rows per table, without the prefix. */
	private array $tables = array();

	/** @var list<string> Ids a lookup by id no longer finds: deleted after the auth-code lookup. */
	private array $deleted_ids = array();

	/** @var list<string> Every read query the double ran. */
	private array $reads = array();

	/** @var list<array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}> Every update: table, data, where. */
	private array $updates = array();

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	/** @var array<string, mixed> `$_SERVER` before this test. */
	private array $server_backup = array();

	/** @var array<string, mixed> `$_POST` before this test. */
	private array $post_backup = array();

	private VerificationHandler $handler;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Static state the real classes keep: the limiter's settings memo,
		// the captcha provider instances and the activity-log buffer.
		$cache = new \ReflectionProperty( RateLimitChecker::class, 'settings_cache' );
		$cache->setAccessible( true );
		$cache->setValue( null, null );
		CaptchaProvider::reset();
		MathCaptcha::reset_instances();
		ActivityLogBuffer::reset();

		$this->captured_error   = null;
		$this->captured_success = null;
		$this->transients       = array();
		$this->settings         = ActivityLogBuffer::ENABLED_SETTINGS + array( 'captcha_provider' => 'math' );
		$this->tables           = array();
		$this->reads            = array();
		$this->deleted_ids      = array();
		$this->updates          = array();

		ActivityLogBuffer::stub_functions();
		Functions\when( '__' )->returnArg();
		Functions\when( '_x' )->returnArg();
		Functions\when( '_n' )->alias( static fn( $single, $plural, $n ) => 1 === (int) $n ? $single : $plural );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( static function ( $text ) { echo $text; } );
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'wp_kses' )->alias( static fn( $v ) => (string) $v );
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'number_format_i18n' )->alias( static fn( $n ) => (string) $n );
		Functions\when( 'is_wp_error' )->alias( static fn( $t ) => $t instanceof \WP_Error );
		Functions\when( 'apply_filters' )->alias( static function () { $a = func_get_args(); return $a[1] ?? null; } );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'wp_parse_args' )->alias( static fn( $args, $defaults = array() ) => array_merge( (array) $defaults, (array) $args ) );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'wp_salt' )->justReturn( 'test-salt-verification-ajax' );
		Functions\when( 'wp_rand' )->alias( static fn( $min = 0, $max = 0 ) => random_int( (int) $min, (int) $max ) );
		Functions\when( 'wp_date' )->alias( static fn( $format, $ts = null ) => gmdate( $format, $ts ?? time() ) );
		Functions\when( 'wp_timezone' )->alias( static fn() => new \DateTimeZone( 'UTC' ) );
		Functions\when( 'get_bloginfo' )->justReturn( 'Test Site' );
		Functions\when( 'get_home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'home_url' )->alias( static fn( $path = '' ) => 'https://example.com' . $path );
		Functions\when( 'site_url' )->alias( static fn( $p = '' ) => 'https://example.com/' . ltrim( (string) $p, '/' ) );
		Functions\when( 'untrailingslashit' )->alias( static fn( $u ) => rtrim( (string) $u, '/' ) );
		Functions\when( 'trailingslashit' )->alias( static fn( $u ) => rtrim( (string) $u, '/' ) . '/' );
		Functions\when( 'wp_parse_url' )->alias( static fn( $url, $component = -1 ) => parse_url( $url, $component ) );
		Functions\when( 'wp_normalize_path' )->alias( static fn( $p ) => str_replace( '\\', '/', (string) $p ) );
		Functions\when( 'get_template_directory' )->justReturn( '/tmp/theme' );
		Functions\when( 'get_stylesheet_directory' )->justReturn( '/tmp/theme' );
		Functions\when( 'sanitize_hex_color' )->justReturn( null );
		Functions\when( 'get_the_title' )->justReturn( 'Workshop' );
		Functions\when( 'get_post' )->justReturn( (object) array( 'post_title' => 'Workshop' ) );
		Functions\when( 'get_post_meta' )->alias(
			static fn( $id, $key ) => '_ffc_form_config' === $key ? array( 'pdf_layout' => '<p>{{name}} {{email}}</p>' ) : ''
		);
		Functions\when( 'is_email' )->alias( static fn( $email ) => false !== filter_var( (string) $email, FILTER_VALIDATE_EMAIL ) );
		Functions\when( 'get_userdata' )->justReturn( false );
		Functions\when( 'get_option' )->alias(
			function ( $key, $default = false ) {
				if ( 'ffc_settings' === $key ) {
					return $this->settings;
				}
				if ( 'ffc_rate_limit_settings' === $key ) {
					// Off so an allowed verification writes only its two counters.
					return array( 'logging' => array( 'enabled' => false ) );
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

		$err = &$this->captured_error;
		Functions\when( 'wp_send_json_error' )->alias(
			static function ( $p ) use ( &$err ) {
				$err = $p;
				throw new \RuntimeException( 'wp_send_json_error' );
			}
		);
		$succ = &$this->captured_success;
		Functions\when( 'wp_send_json_success' )->alias(
			static function ( $p ) use ( &$succ ) {
				$succ = $p;
				throw new \RuntimeException( 'wp_send_json_success' );
			}
		);

		$this->server_backup    = $_SERVER ?? array();
		$this->post_backup      = $_POST ?? array();
		$_SERVER['REMOTE_ADDR'] = self::IP;
		// The headers ClientIpResolver reads ahead of REMOTE_ADDR.
		unset( $_SERVER['HTTP_CLIENT_IP'], $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_X_FORWARDED'], $_SERVER['HTTP_X_CLUSTER_CLIENT_IP'], $_SERVER['HTTP_FORWARDED_FOR'], $_SERVER['HTTP_FORWARDED'] );

		$this->had_wpdb = array_key_exists( 'wpdb', $GLOBALS );
		if ( $this->had_wpdb ) {
			$this->wpdb_backup = $GLOBALS['wpdb'];
		}
		$GLOBALS['wpdb'] = $this->wpdb_double();

		$this->handler = new VerificationHandler();
	}

	protected function tearDown(): void {
		$_SERVER = $this->server_backup;
		$_POST   = $this->post_backup;
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		ActivityLogBuffer::reset();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A `$wpdb` double over $this->tables.
	 *
	 * `prepare()` binds `%i` as the bare name, `%s` quoted and `%d` as an
	 * integer; `get_row()` answers `SELECT * FROM <table> WHERE <col> = <v>`,
	 * honouring a trailing `status IN (...)` or `status = '...'`, from the
	 * rows the test stored.
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
					static function ( $m ) use ( &$flat ) {
						$v = array_shift( $flat );
						if ( '%s' === $m[0] ) {
							return "'" . $v . "'";
						}
						return '%d' === $m[0] ? (string) (int) $v : (string) $v;
					},
					$sql
				);
			}
		);
		$wpdb->shouldReceive( 'get_row' )->andReturnUsing(
			function ( $q, $output = 'OBJECT' ) {
				$this->reads[] = $q;
				if ( 1 !== preg_match( "/^SELECT \\* FROM wp_(\\w+) WHERE (\\w+) = '?([^'\\s]*)'?(?: AND status (?:IN \\(([^)]*)\\)|= '(\\w+)'))?$/", $q, $m ) ) {
					$this->fail( 'Unexpected get_row(): ' . $q );
				}
				$statuses = ! empty( $m[4] ) ? str_replace( "'", '', explode( ', ', $m[4] ) ) : ( ! empty( $m[5] ) ? array( $m[5] ) : null );
				foreach ( $this->tables[ $m[1] ] ?? array() as $row ) {
					if ( (string) ( $row[ $m[2] ] ?? '' ) !== $m[3] ) {
						continue;
					}
					if ( 'id' === $m[2] && in_array( $m[3], $this->deleted_ids, true ) ) {
						continue;
					}
					if ( null !== $statuses && ! in_array( $row['status'] ?? '', $statuses, true ) ) {
						continue;
					}
					return 'ARRAY_A' === $output ? $row : (object) $row;
				}
				return null;
			}
		);
		$wpdb->shouldReceive( 'get_col' )->andReturnUsing(
			function ( $q ) {
				$this->reads[] = $q;
				if ( 0 === strpos( $q, 'SELECT audience_id FROM wp_ffc_reregistration_audiences WHERE reregistration_id = ' ) ) {
					return array();
				}
				$this->fail( 'Unexpected get_col(): ' . $q );
			}
		);
		$wpdb->shouldReceive( 'update' )->andReturnUsing(
			function ( $table, $data, $where ) {
				$this->updates[] = array( $table, $data, $where );
				return 1;
			}
		);
		return $wpdb;
	}

	/**
	 * Store the verification count the limiter reads for this address.
	 *
	 * The key carries the hour, so the count is stored for the hour now and
	 * a minute from now: a run that crosses the hour still reads it.
	 *
	 * @param int $count Attempts already made this hour.
	 */
	private function store_verification_count( int $count ): void {
		$prefix = 'ffc_verify_ip_' . md5( IpAddress::fingerprint( self::IP ) ) . '_hour_';
		foreach ( array( time(), time() + 60 ) as $ts ) {
			$this->transients[ $prefix . gmdate( 'YmdH', $ts ) ] = $count;
		}
	}

	/**
	 * A POST body carrying a correctly answered math challenge.
	 *
	 * @param array<string, string> $fields Fields to add.
	 * @return array<string, mixed>
	 */
	private function answered_post( array $fields ): array {
		$challenge = SecurityService::generate_simple_captcha();
		return array(
			'nonce'            => 'ok',
			'ffc_captcha_ans'  => (string) $challenge['answer'],
			'ffc_captcha_hash' => $challenge['hash'],
		) + $fields;
	}

	/**
	 * The certificate row stored in `ffc_submissions`, e-mail encrypted.
	 *
	 * @param array<string, mixed> $overrides Columns to replace.
	 */
	private function store_certificate( array $overrides ): void {
		$this->tables['ffc_submissions'][] = $overrides + array(
			'form_id'         => '2',
			'email'           => '',
			'email_encrypted' => Encryption::encrypt( 'ok@test.com' ),
			'data'            => '{"name":"Ana Lima"}',
			'status'          => 'publish',
			'submission_date' => '1700000000',
		);
	}

	/**
	 * The `data_accessed` entries ActivityLog buffered, as action + submission.
	 *
	 * @return list<array{0: string, 1: int}>
	 */
	private function logged_accesses(): array {
		$out = array();
		foreach ( ActivityLogBuffer::entries() as $row ) {
			$out[] = array( (string) $row['action'], (int) $row['submission_id'] );
		}
		return $out;
	}

	// ==================== handle_verification_ajax ====================

	public function test_verification_ajax_rate_limited(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		$this->store_verification_count( self::MAX_PER_HOUR );

		$_POST = $this->answered_post( array( 'ffc_auth_code' => 'CODE12345678' ) );

		try {
			$this->handler->handle_verification_ajax();
			$this->fail( 'expected halt' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'wp_send_json_error', $e->getMessage() );
			$this->assertStringContainsString( 'Too many', $this->captured_error['message'] );
			$this->assertTrue( $this->captured_error['refresh_captcha'] );
		}
		$this->assertSame( array(), $this->reads, 'A throttled request must not reach the repository.' );
	}

	public function test_verify_certificate_no_js_fallback_is_rate_limited(): void {
		// #839 S11 — the no-JS shortcode fallback must apply the same
		// verification throttle as the AJAX path, before any lookup. A blocked
		// check returns the rate-limit message and never touches the repository.
		$this->store_verification_count( self::MAX_PER_HOUR );

		$result = $this->handler->verify_certificate( 'CODE12345678' );

		// The limiter's own message reaches the visitor; a denial always
		// carries one, so the handler has no fallback text of its own (#2153).
		$this->assertFalse( $result['success'] );
		$this->assertStringStartsWith( 'Too many verification attempts. Please wait ', $result['message'] );
		$this->assertSame( array(), $this->reads, 'A throttled request must not reach the repository.' );
	}

	public function test_verification_ajax_security_fields_fail_refreshes_captcha(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		$challenge = SecurityService::generate_simple_captcha();

		$_POST = array(
			'nonce'            => 'ok',
			'ffc_captcha_ans'  => (string) ( $challenge['answer'] + 1 ),
			'ffc_captcha_hash' => $challenge['hash'],
		);

		try {
			$this->handler->handle_verification_ajax();
			$this->fail( 'expected halt' );
		} catch ( \RuntimeException $e ) {
			// The rejection is routed through the refresh helper, which
			// issues a new challenge alongside the message.
			$this->assertSame( 'Error: The math answer is incorrect.', $this->captured_error['message'] );
			$this->assertTrue( $this->captured_error['refresh_captcha'] );
			$this->assertNotEmpty( $this->captured_error['new_hash'] );
			$this->assertNotSame( $challenge['hash'], $this->captured_error['new_hash'] );
		}
	}

	public function test_verification_ajax_not_found_refreshes_captcha(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( true );

		$_POST = $this->answered_post( array( 'ffc_auth_code' => 'NOPE12345678' ) );

		try {
			$this->handler->handle_verification_ajax();
			$this->fail( 'expected halt' );
		} catch ( \RuntimeException $e ) {
			$this->assertTrue( $this->captured_error['refresh_captcha'] );
			$this->assertStringContainsString( 'not found', $this->captured_error['message'] );
		}
		// Certificates, then appointments, then reregistrations were searched.
		$this->assertCount( 3, $this->reads );
	}

	public function test_verification_ajax_certificate_success(): void {
		$this->store_certificate(
			array(
				'id'          => '7',
				'auth_code'   => 'GOOD12345678',
				'magic_token' => 't',
			)
		);
		Functions\when( 'wp_verify_nonce' )->justReturn( true );

		$_POST = $this->answered_post( array( 'ffc_auth_code' => 'GOOD12345678' ) );

		try {
			$this->handler->handle_verification_ajax();
			$this->fail( 'expected halt' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'wp_send_json_success', $e->getMessage() );
			$this->assertStringContainsString( 'C-GOOD-1234-5678', $this->captured_success['html'] );
			$this->assertStringContainsString( 'Ana Lima', $this->captured_success['html'] );
			$this->assertSame( '7', $this->captured_success['submission_id'] );
			$pdf = $this->captured_success['pdf_data'];
			$this->assertSame( 7, $pdf['submission_id'] );
			$this->assertSame( 'GOOD12345678', $pdf['auth_code'] );
			$this->assertStringContainsString( 'GOOD12345678', $pdf['filename'] );
			// The answers stay on the server (#2241): the PDF carries only its HTML.
			$this->assertArrayNotHasKey( 'submission', $pdf );
			$this->assertStringContainsString( 'Ana Lima ok@test.com', $pdf['html'] );
		}
	}

	public function test_verification_ajax_pdf_error_returns_error(): void {
		// The auth-code lookup finds the certificate, but the row is gone by
		// the time the generator reads it by id: the generator's own error
		// reaches the visitor, with a fresh challenge.
		$this->store_certificate(
			array(
				'id'          => '8',
				'auth_code'   => 'PDFE12345678',
				'magic_token' => '',
			)
		);
		$this->deleted_ids = array( '8' );
		Functions\when( 'wp_verify_nonce' )->justReturn( true );

		$_POST = $this->answered_post( array( 'ffc_auth_code' => 'PDFE12345678' ) );

		try {
			$this->handler->handle_verification_ajax();
			$this->fail( 'expected halt' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'wp_send_json_error', $e->getMessage() );
			$this->assertSame( 'Submission not found.', $this->captured_error['message'] );
			$this->assertTrue( $this->captured_error['refresh_captcha'] );
		}
	}

	public function test_verification_ajax_appointment_success(): void {
		$this->tables['ffc_self_scheduling_appointments'][] = array(
			'id'                 => '40',
			'calendar_id'        => '0',
			'name'               => 'Appt Ajax',
			'email'              => 'aa@test.com',
			'cpf'                => '',
			'validation_code'    => 'APPTAJAX1234',
			'appointment_date'   => '2026-09-01',
			'start_time'         => '10:00',
			'end_time'           => '10:30',
			'status'             => 'confirmed',
			'confirmation_token' => 'tok',
			'created_at'         => '2026-08-01',
		);
		Functions\when( 'wp_verify_nonce' )->justReturn( true );

		$_POST = $this->answered_post( array( 'ffc_auth_code' => 'APPTAJAX1234' ) );

		try {
			$this->handler->handle_verification_ajax();
			$this->fail( 'expected halt' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'wp_send_json_success', $e->getMessage() );
			$this->assertStringContainsString( 'Appointment Receipt Valid', $this->captured_success['html'] );
			$this->assertStringContainsString( 'A-APPT-AJAX-1234', $this->captured_success['html'] );
			$this->assertStringContainsString( 'Appt Ajax', $this->captured_success['html'] );
			$pdf = $this->captured_success['pdf_data'];
			$this->assertStringContainsString( 'APPTAJAX1234', $pdf['filename'] );
			$this->assertStringContainsString( 'Appt Ajax', $pdf['html'] );
		}
	}

	public function test_verification_ajax_reregistration_confirms_an_approved_record_without_the_pdf(): void {
		$this->tables['ffc_reregistration_submissions'][] = array(
			'id'                => '41',
			'reregistration_id' => '9',
			'user_id'           => '5',
			'auth_code'         => 'RRAJAX123456',
			'status'            => 'approved',
			'data'              => '{"fields":{"display_name":"RR Ajax","cpf":"51817842080"}}',
			'magic_token'       => '',
			'submitted_at'      => '1769904000',
		);
		$this->tables['ffc_reregistrations'][] = array(
			'id'    => '9',
			'title' => 'RR Ajax Campaign',
		);
		Functions\when( 'get_userdata' )->justReturn(
			(object) array(
				'ID'           => 5,
				'display_name' => 'RR Account',
				'user_email'   => 'rr@test.com',
			)
		);
		Functions\when( 'wp_verify_nonce' )->justReturn( true );

		$_POST = $this->answered_post( array( 'ffc_auth_code' => 'RRAJAX123456' ) );

		try {
			$this->handler->handle_verification_ajax();
			$this->fail( 'expected halt' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'wp_send_json_success', $e->getMessage() );
			$this->assertStringContainsString( 'RR Ajax Campaign', $this->captured_success['html'] );
			$this->assertStringContainsString( 'RR Ajax', $this->captured_success['html'] );
			$this->assertStringNotContainsString( 'rr@test.com', $this->captured_success['html'], 'The e-mail is masked.' );
			$this->assertStringNotContainsString( '51817842080', $this->captured_success['html'], 'The CPF is masked.' );
			$this->assertNull( $this->captured_success['pdf_data'], 'Public verification never hands out the Record (#2242).' );
		}
	}

	public function test_verification_ajax_refuses_a_reregistration_that_is_not_approved(): void {
		// A submission awaiting review, or returned to draft with its code
		// kept, must not verify as valid (#2242).
		$this->tables['ffc_reregistration_submissions'][] = array(
			'id'                => '41',
			'reregistration_id' => '9',
			'user_id'           => '5',
			'auth_code'         => 'RRAJAX123456',
			'status'            => 'in_progress',
			'data'              => '{"fields":{}}',
			'magic_token'       => '',
			'submitted_at'      => null,
		);
		Functions\when( 'wp_verify_nonce' )->justReturn( true );

		$_POST = $this->answered_post( array( 'ffc_auth_code' => 'RRAJAX123456' ) );

		try {
			$this->handler->handle_verification_ajax();
			$this->fail( 'expected halt' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'wp_send_json_error', $e->getMessage() );
			$this->assertStringContainsString( 'not found', $this->captured_error['message'] );
		}
	}

	// ==================== handle_magic_verification_ajax ====================

	public function test_magic_ajax_rate_limited(): void {
		$this->store_verification_count( self::MAX_PER_HOUR );

		$_POST = array( 'token' => str_repeat( 'ab', 16 ) );

		try {
			$this->handler->handle_magic_verification_ajax();
			$this->fail( 'expected halt' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'Too many', $this->captured_error['message'] );
		}
		$this->assertSame( array(), $this->reads, 'A throttled request must not reach the repository.' );
	}

	public function test_magic_ajax_empty_token(): void {
		$_POST = array( 'token' => '' );

		try {
			$this->handler->handle_magic_verification_ajax();
			$this->fail( 'expected halt' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'Invalid token', $this->captured_error['message'] );
		}
	}

	public function test_magic_ajax_invalid_format_returns_not_found(): void {
		// Non-hex token of a valid length → verify_by_magic_token returns
		// invalid_token_format (found=false, error != rate_limited).
		$_POST = array( 'token' => str_repeat( 'z', 32 ) );

		try {
			$this->handler->handle_magic_verification_ajax();
			$this->fail( 'expected halt' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'not found', $this->captured_error['message'] );
		}
		$this->assertSame( array(), $this->reads, 'A malformed token must not reach the repository.' );
	}

	/**
	 * #2241 — an open spends one attempt of the address's budget, not two:
	 * with one attempt left, the link still opens.
	 */
	public function test_magic_ajax_counts_one_attempt_per_open(): void {
		$token = str_repeat( 'cd', 16 );
		$this->store_certificate(
			array(
				'id'          => '13',
				'auth_code'   => 'ONCE12345678',
				'magic_token' => $token,
			)
		);
		$this->store_verification_count( self::MAX_PER_HOUR - 1 );

		$_POST = array( 'token' => $token );

		try {
			$this->handler->handle_magic_verification_ajax();
			$this->fail( 'expected halt' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'wp_send_json_success', $e->getMessage(), 'The last attempt of the hour opens the link.' );
		}
	}

	public function test_magic_ajax_rate_limited_carries_the_limiters_message(): void {
		$this->store_verification_count( self::MAX_PER_HOUR );

		$_POST = array( 'token' => str_repeat( 'cd', 16 ) );

		try {
			$this->handler->handle_magic_verification_ajax();
			$this->fail( 'expected halt' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'Too many verification attempts. Please wait', $this->captured_error['message'] );
		}
		$this->assertSame( array(), $this->reads, 'A throttled lookup must not reach the repository.' );
	}

	public function test_magic_ajax_success_certificate(): void {
		$token = str_repeat( 'ab', 16 );
		$this->store_certificate(
			array(
				'id'          => '12',
				'auth_code'   => 'MAGI12345678',
				'magic_token' => $token,
			)
		);

		$_POST = array( 'token' => $token );

		try {
			$this->handler->handle_magic_verification_ajax();
			$this->fail( 'expected halt' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'wp_send_json_success', $e->getMessage() );
			$this->assertStringContainsString( 'C-MAGI-1234-5678', $this->captured_success['html'] );
			$pdf = $this->captured_success['pdf_data'];
			$this->assertSame( 12, $pdf['submission_id'] );
			$this->assertStringContainsString( 'MAGI12345678', $pdf['filename'] );
		}
		$this->assertContains( array( 'data_accessed', 12 ), $this->logged_accesses() );
		$this->assertSame( array(), $this->updates, 'A stored token is reused, never rewritten.' );
	}
}
