<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use FreeFormCertificate\Core\RequestInput;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\ActivityLog;
use FreeFormCertificate\Core\Encryption;
use FreeFormCertificate\Frontend\PreflightTelemetry;

/**
 * 6.6.4 follow-up (#361 Sprint 2) — PreflightTelemetry AJAX handler.
 *
 * Pins:
 *   - Nonce failure returns the standard refresh_nonce + new_nonce
 *     payload (so the client's #356 auto-recovery kicks in).
 *   - Invalid ID / unknown type / unknown reason are rejected.
 *   - Successful call writes one ActivityLog row with the expected
 *     action / level / context shape (including the truncated keyed
 *     IP fingerprint, not the raw IP).
 *
 * The row goes through the real ActivityLog: it is read back from the
 * class's write buffer, and from the `$wpdb` double's INSERTs when the
 * buffer reaches its flush threshold.
 *
 * @covers \FreeFormCertificate\Frontend\PreflightTelemetry
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

	/** @var list<array<string, mixed>> Rows the `$wpdb` double's insert() received. */
	private array $inserts = array();

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	/** @var array<string, mixed> */
	private array $post_backup = array();

	/** @var array<string, mixed> */
	private array $server_backup = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'absint' )->alias( 'intval' );
		Functions\when( 'wp_salt' )->justReturn( 'test-salt' );
		Functions\when( 'wp_create_nonce' )->justReturn( 'fresh-nonce-xyz' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'current_time' )->justReturn( '2026-04-23 00:00:00' );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		// The dynamic sensitive-key set is answered from the object cache
		// (none configured), so the sensitivity gate reads no table.
		Functions\when( 'wp_cache_get' )->alias(
			static fn( $key ) => 'ffc_sensitive_field_keys_dynamic' === $key ? array() : false
		);
		// The activity log is enabled; every debug area stays off, so
		// Debug::is_enabled() takes the same branch whatever ran before.
		Functions\when( 'get_option' )->alias(
			static fn( $key, $fallback = false ) => 'ffc_settings' === $key ? array( 'enable_activity_log' => 1 ) : $fallback
		);

		$this->reset_activity_log_state();

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

		// The buffer flush reads the table's columns and inserts each row.
		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $this->had_wpdb ? $GLOBALS['wpdb'] : null;
		$this->inserts     = array();
		$inserts           = &$this->inserts;
		$wpdb              = Mockery::mock( 'wpdb' );
		$wpdb->prefix      = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->with( 'DESCRIBE %i', 'wp_ffc_activity_log' )->andReturn( 'DESCRIBE `wp_ffc_activity_log`' );
		$wpdb->shouldReceive( 'get_col' )->with( 'DESCRIBE `wp_ffc_activity_log`', 0 )->andReturn(
			array( 'id', 'action', 'level', 'context', 'context_encrypted', 'user_id', 'user_ip_encrypted', 'submission_id', 'created_at' )
		);
		$wpdb->shouldReceive( 'insert' )->andReturnUsing(
			function ( string $table, array $data ) use ( &$inserts ): int {
				$this->assertSame( 'wp_ffc_activity_log', $table );
				$inserts[] = $data;
				return 1;
			}
		);
		$GLOBALS['wpdb'] = $wpdb;

		// The real RequestInput::get_post_string reads $_POST after
		// sanitize_text_field + wp_unslash; both stubbed to identity
		// above, so the production call returns the raw $_POST value.
		// RequestInput::get_user_ip reads REMOTE_ADDR / proxy headers — we
		// pin REMOTE_ADDR via $_SERVER for predictable hash output.
		$this->post_backup      = $_POST ?? array();
		$this->server_backup    = $_SERVER ?? array();
		$_SERVER['REMOTE_ADDR'] = '203.0.113.42';
		foreach ( array( 'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED' ) as $header ) {
			unset( $_SERVER[ $header ] );
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
		$this->reset_activity_log_state();
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
	 * The ActivityLog statics a log() call touches: the write buffer, the
	 * shutdown-hook flag, the bulk-disable flag and the column cache.
	 */
	private function reset_activity_log_state(): void {
		$ref = new \ReflectionClass( ActivityLog::class );
		foreach ( array(
			'write_buffer'        => array(),
			'shutdown_registered' => false,
			'logging_disabled'    => false,
			'table_columns_cache' => null,
		) as $name => $value ) {
			$prop = $ref->getProperty( $name );
			$prop->setAccessible( true );
			$prop->setValue( null, $value );
		}
	}

	/**
	 * Every row ActivityLog accepted: those already flushed to the table,
	 * then those still in the write buffer.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function logged_rows(): array {
		$prop = new \ReflectionProperty( ActivityLog::class, 'write_buffer' );
		$prop->setAccessible( true );
		return array_merge( $this->inserts, $prop->getValue() );
	}

	/**
	 * A logged row's context, decoded (decrypted first when ActivityLog
	 * classified it as sensitive).
	 *
	 * @param array<string, mixed> $row Logged row.
	 * @return array<string, mixed>
	 */
	private function context_of( array $row ): array {
		$json = $row['context'] ?? null;
		if ( null === $json && ! empty( $row['context_encrypted'] ) ) {
			$json = Encryption::decrypt( (string) $row['context_encrypted'] );
		}
		$decoded = json_decode( (string) $json, true );
		return is_array( $decoded ) ? $decoded : array();
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

		$_POST = array(
			'action'  => 'ffc_log_preflight_bail',
			'nonce'   => 'ok',
			'form_id' => '42',
			'reason'  => 'cookies',
		);

		try {
			$this->handler->handle_ajax();
		} catch ( \RuntimeException $e ) {
			// Expected — wp_send_json_success threw via our stub.
		}

		$rows = $this->logged_rows();
		$this->assertCount( 1, $rows, 'Exactly one activity-log row is written.' );
		$this->assertSame( 'preflight_blocked', $rows[0]['action'] );
		$context = $this->context_of( $rows[0] );
		$this->assertSame( 42, $context['form_id'] ?? null );
		$this->assertSame( 'cookies', $context['reason'] ?? null );
		// The row carries the truncated fingerprint, never the raw address.
		$this->assertArrayHasKey( 'ip_hash', $context );
		$this->assertStringNotContainsString( '203.0.113.42', (string) json_encode( $rows[0] ) );
		$this->assertSame( array( 'logged' => true ), $this->captured_success );
	}

	public function test_one_visitor_writes_at_most_the_cap_per_window(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );

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
		$this->assertCount( PreflightTelemetry::MAX_PER_WINDOW, $this->logged_rows() );
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
		$rows = $this->logged_rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( 'preflight_blocked', $rows[0]['action'] );
		$this->assertSame( 'info', $rows[0]['level'] );
		$context = $this->context_of( $rows[0] );
		$this->assertSame( 'csv_download', $context['type'] ?? null );
		$this->assertSame( 15, $context['id'] ?? null );
		$this->assertSame( 'browser_inapp', $context['reason'] ?? null );
		$this->assertArrayNotHasKey( 'form_id', $context );
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
		$rows = $this->logged_rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( 'preflight_blocked', $rows[0]['action'] );
		$this->assertSame( 'info', $rows[0]['level'] );
		$context = $this->context_of( $rows[0] );
		$this->assertSame( 42, $context['form_id'] ?? null );
		$this->assertArrayNotHasKey( 'type', $context );
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
