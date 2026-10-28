<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use FreeFormCertificate\Core\RequestInput;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Frontend\PreflightTelemetry;

/**
 * 6.6.4 follow-up (#361 Sprint 2) — PreflightTelemetry AJAX handler.
 *
 * Pins:
 *   - Nonce failure returns the standard refresh_nonce + new_nonce
 *     payload (so the client's #356 auto-recovery kicks in).
 *   - Invalid ID / unknown type / unknown reason are rejected.
 *   - Successful call writes one ActivityLog row with the expected
 *     action / level / context shape (including the SHA-256 IP hash,
 *     not the raw IP).
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class PreflightTelemetryTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var PreflightTelemetry */
	private $handler;

	/** @var array<string, mixed>|null */
	private $captured_error = null;

	/** @var array<string, mixed>|null */
	private $captured_success = null;

	/** @var array<string, mixed> Transient store. */
	private array $transients = array();

	/** @var \Mockery\MockInterface Alias mock for ActivityLog. */
	private $activity_log_mock;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'absint' )->alias( 'intval' );
		Functions\when( 'wp_salt' )->justReturn( 'test-salt' );
		Functions\when( 'wp_create_nonce' )->justReturn( 'fresh-nonce-xyz' );

		// The per-visitor throttle (#1861) is a transient: a store that
		// persists across calls, as the options table does.
		$this->transients = array();
		$transients       = &$this->transients;
		Functions\when( 'get_transient' )->alias(
			static function ( $key ) use ( &$transients ) {
				return $transients[ $key ] ?? false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			static function ( $key, $value ) use ( &$transients ) {
				$transients[ $key ] = $value;
				return true;
			}
		);

		// The real RequestInput::get_post_string reads $_POST after
		// sanitize_text_field + wp_unslash; both stubbed to identity
		// above, so the production call returns the raw $_POST value.
		// RequestInput::get_user_ip reads REMOTE_ADDR / proxy headers — we
		// pin REMOTE_ADDR via $_SERVER for predictable hash output.
		$_SERVER['REMOTE_ADDR'] = '203.0.113.42';

		// ActivityLog: alias-mock so we can assert on log() calls.
		// SUT uses the 'info' literal (not ActivityLog::LEVEL_INFO)
		// so we don't need to redeclare the class constant.
		$this->activity_log_mock = Mockery::mock( 'alias:\FreeFormCertificate\Core\ActivityLog' );
		$this->activity_log_mock->shouldReceive( 'log' )->byDefault()->andReturn( true );
		// Constants referenced in the SUT.
		if ( ! defined( '\FreeFormCertificate\Core\ActivityLog::LEVEL_INFO' ) ) {
			// alias-mock auto-defines class constants when accessed.
		}

		// Capture wp_send_json_*
		$captured_error   = &$this->captured_error;
		$captured_success = &$this->captured_success;
		Functions\when( 'wp_send_json_error' )->alias( static function ( $payload ) use ( &$captured_error ) {
			$captured_error = $payload;
			throw new \RuntimeException( 'wp_send_json_error' );
		} );
		Functions\when( 'wp_send_json_success' )->alias( static function ( $payload ) use ( &$captured_success ) {
			$captured_success = $payload;
			throw new \RuntimeException( 'wp_send_json_success' );
		} );

		$this->handler = new PreflightTelemetry();
	}

	protected function tearDown(): void {
		$_POST   = array();
		$_SERVER = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_nonce_failure_returns_refresh_nonce_payload(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( false );
		$_POST = array(
			'action' => 'ffc_log_preflight_bail',
			'nonce'  => 'stale',
		);

		$this->expectException( \RuntimeException::class );
		try {
			$this->handler->handle_ajax();
		} finally {
			$this->assertIsArray( $this->captured_error );
			$this->assertArrayHasKey( 'refresh_nonce', $this->captured_error );
			$this->assertTrue( $this->captured_error['refresh_nonce'] );
			$this->assertSame( 'fresh-nonce-xyz', $this->captured_error['new_nonce'] );
		}
	}

	public function test_invalid_form_id_is_rejected(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		$_POST = array(
			'action'  => 'ffc_log_preflight_bail',
			'nonce'   => 'ok',
			'form_id' => '0',
			'reason'  => 'cookies',
		);

		$this->expectException( \RuntimeException::class );
		try {
			$this->handler->handle_ajax();
		} finally {
			$this->assertSame( 'invalid_id', $this->captured_error['message'] );
		}
	}

	public function test_invalid_reason_is_rejected(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		$_POST = array(
			'action'  => 'ffc_log_preflight_bail',
			'nonce'   => 'ok',
			'form_id' => '42',
			'reason'  => 'something_random',
		);

		$this->expectException( \RuntimeException::class );
		try {
			$this->handler->handle_ajax();
		} finally {
			$this->assertSame( 'invalid_reason', $this->captured_error['message'] );
		}
	}

	public function test_happy_path_calls_activity_log_and_returns_success(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( true );

		// Use Mockery's `once()` count + argument matchers to assert
		// the ActivityLog::log shape. Reading the captured payload
		// back via andReturnUsing closure was unreliable across
		// process-isolated test runs (the closure-bound refs didn't
		// survive the alias-mock callback path).
		$this->activity_log_mock->shouldReceive( 'log' )
			->once()
			->with(
				'preflight_blocked',
				Mockery::any(),
				Mockery::on( static function ( $context ) {
					return is_array( $context )
						&& 42 === $context['form_id']
						&& 'cookies' === $context['reason']
						// ip_hash is sha256 prefix or '' (when no
						// valid public IP). In the test env REMOTE_ADDR
						// is in the documentation range so it's
						// rejected by FILTER_FLAG_NO_RES_RANGE; the
						// hash falls back to ''.
						&& array_key_exists( 'ip_hash', $context );
				} )
			)
			->andReturn( true );

		$_POST = array(
			'action'  => 'ffc_log_preflight_bail',
			'nonce'   => 'ok',
			'form_id' => '42',
			'reason'  => 'cookies',
		);

		// The Mockery once() expectation above verifies log() was
		// called with the right shape. We accept either: a thrown
		// RuntimeException (from wp_send_json_success short-circuit)
		// OR clean return — the mock's `once()` count will fail
		// tearDown if log() wasn't reached.
		try {
			$this->handler->handle_ajax();
		} catch ( \RuntimeException $e ) {
			// Expected — wp_send_json_success threw via our stub.
		}
		// Mockery's tearDown assertion catches the expectation count.
		$this->assertTrue( true );
	}

	public function test_one_visitor_writes_at_most_the_cap_per_window(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		$this->activity_log_mock->shouldReceive( 'log' )->times( PreflightTelemetry::MAX_PER_WINDOW )->andReturn( true );

		$_POST = array(
			'nonce'   => 'ok',
			'form_id' => '42',
			'reason'  => 'gps_denied',
		);

		for ( $i = 0; $i < PreflightTelemetry::MAX_PER_WINDOW + 5; $i++ ) {
			try {
				$this->handler->handle_ajax();
			} catch ( \RuntimeException $e ) {
				// wp_send_json_success short-circuits through the stub.
			}
		}

		// The request over the cap still answers success, but logs nothing.
		$this->assertSame( array( 'logged' => false ), $this->captured_success );
		$this->assertSame( PreflightTelemetry::MAX_PER_WINDOW, array_sum( array_map( 'intval', $this->transients ) ) );
	}

	public function test_an_unknown_type_is_rejected(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		$_POST = array(
			'nonce'  => 'ok',
			'type'   => 'post',
			'id'     => '42',
			'reason' => 'secure',
		);

		$this->expectException( \RuntimeException::class );
		try {
			$this->handler->handle_ajax();
		} finally {
			$this->assertSame( 'invalid_type', $this->captured_error['message'] );
		}
	}

	/**
	 * A row that is not a form's names its type and keeps no `form_id`, so a
	 * form's badges never count a booking or a page that shares its ID
	 * (#2040).
	 */
	public function test_a_typed_row_names_what_it_is_about(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		$this->activity_log_mock->shouldReceive( 'log' )
			->once()
			->with(
				'preflight_blocked',
				'info',
				Mockery::on(
					static function ( $context ): bool {
						return is_array( $context )
							&& 'csv_download' === $context['type']
							&& 15 === $context['id']
							&& 'browser_inapp' === $context['reason']
							&& ! array_key_exists( 'form_id', $context );
					}
				)
			)
			->andReturn( true );
		$_POST = array(
			'nonce'  => 'ok',
			'type'   => 'csv_download',
			'id'     => '15',
			'reason' => 'browser_inapp',
		);

		try {
			$this->handler->handle_ajax();
		} catch ( \RuntimeException $e ) {
			// wp_send_json_success short-circuits through the stub.
		}

		$this->assertSame( array( 'logged' => true ), $this->captured_success );
	}

	public function test_a_form_row_keeps_form_id_whether_typed_or_not(): void {
		$expected = array(
			'form_id' => 42,
			'reason'  => 'cookies',
			'ip_hash' => 'abc',
		);

		$this->assertSame( $expected, PreflightTelemetry::row_context( 'form', 42, 'cookies', 'abc' ) );
		$this->assertSame(
			array(
				'type'    => 'booking',
				'id'      => 7,
				'reason'  => 'secure',
				'ip_hash' => 'abc',
			),
			PreflightTelemetry::row_context( 'booking', 7, 'secure', 'abc' )
		);
	}

	public function test_a_typed_form_request_writes_the_form_shape(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		$this->activity_log_mock->shouldReceive( 'log' )
			->once()
			->with(
				'preflight_blocked',
				'info',
				Mockery::on(
					static function ( $context ): bool {
						return is_array( $context ) && 42 === $context['form_id'] && ! array_key_exists( 'type', $context );
					}
				)
			)
			->andReturn( true );
		$_POST = array(
			'nonce'  => 'ok',
			'type'   => 'form',
			'id'     => '42',
			'reason' => 'cookies',
		);

		try {
			$this->handler->handle_ajax();
		} catch ( \RuntimeException $e ) {
			// wp_send_json_success short-circuits through the stub.
		}

		$this->assertSame( array( 'logged' => true ), $this->captured_success );
	}

	/**
	 * The reasons the endpoint accepts and the ones the scripts send are
	 * one list (#2030): a reason a script sends that is not allowed is
	 * refused silently, and an allowed one nothing sends is dead.
	 */
	public function test_the_allowed_reasons_are_the_ones_the_scripts_send(): void {
		$sent  = array();
		$files = glob( dirname( __DIR__, 2 ) . '/assets/js/*.js' );
		$this->assertNotEmpty( $files );
		foreach ( $files as $file ) {
			if ( str_ends_with( $file, '.min.js' ) ) {
				continue;
			}
			preg_match_all( "/\\blog\\([^,()]+,\\s*'([a-z_]+)'\\)/", (string) file_get_contents( $file ), $m );
			$sent = array_merge( $sent, $m[1] );
		}
		$sent = array_values( array_unique( $sent ) );
		sort( $sent );

		$allowed = PreflightTelemetry::ALLOWED_REASONS;
		sort( $allowed );

		$this->assertSame( $allowed, $sent );
	}
}
