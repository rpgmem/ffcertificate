<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\Captcha\CaptchaProvider;
use FreeFormCertificate\Frontend\FormProcessor;
use FreeFormCertificate\Submissions\SubmissionHandler;

/**
 * 6.6.3 — pins the stale-nonce auto-recovery contract on the server.
 * When the AJAX form-submission nonce check fails, the response MUST
 * include `refresh_nonce: true` + `new_nonce: '<hash>'` so the client
 * (FFC.request in ffc-core.js) can transparently retry once with the
 * fresh nonce. Without this, iOS Safari + cached-HTML hosts would
 * keep surfacing "Security check failed" even after the user reloads.
 *
 * The gates ahead of the nonce run for real — the schedule-exception guard
 * and RateLimiter's IP gate, whose transient counters are empty and whose
 * day counter comes from a `$wpdb` double that fails on any other query —
 * and so does the fresh captcha the rejection carries.
 */
class FormProcessorNonceRecoveryTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var FormProcessor */
	private $processor;

	/** @var array<string, mixed>|null Captured payload from wp_send_json_error. */
	private $captured_error = null;

	/** @var list<string> Every SQL string the `$wpdb` double was asked to run, bound arguments included. */
	private array $ran = array();

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	/** @var array<string, mixed> The `$_POST` an earlier test left. */
	private array $post_backup = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// No stored option anywhere: CaptchaProvider::resolve() falls back to
		// math, and RateLimiter's IP gate runs on its defaults (ON).
		Functions\when( 'get_option' )->alias( static fn( $key, $default = false ) => $default );
		Functions\when( 'wp_parse_args' )->alias(
			static fn( $args, $defaults = array() ) => array_merge( (array) $defaults, (array) $args )
		);

		// Captcha challenges are signed + single-use since 6.23.0.
		Functions\when( 'wp_salt' )->alias( function ( string $scheme = 'auth' ): string {
			return 'test-salt-' . $scheme;
		} );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'wp_rand' )->alias( function ( int $min = 0, int $max = 0 ): int {
			return random_int( $min, $max );
		} );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		// Pretend the request is well-formed enough to reach the nonce gate.
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'is_user_logged_in' )->justReturn( false );

		// Nonce: we ARE the nonce-failure path under test.
		Functions\when( 'wp_verify_nonce' )->justReturn( false );
		// Server-issued replacement nonce — must be a non-empty string.
		Functions\when( 'wp_create_nonce' )->justReturn( 'fresh-nonce-abc123' );

		// Capture the wp_send_json_error payload and throw so the handler
		// function short-circuits (mirrors WP's wp_die() behaviour).
		$captured = &$this->captured_error;
		Functions\when( 'wp_send_json_error' )->alias( static function ( $payload ) use ( &$captured ) {
			$captured = $payload;
			throw new \RuntimeException( 'wp_send_json_error called' );
		} );

		$this->reset_static_state();
		$this->install_wpdb();

		$mock_handler    = Mockery::mock( SubmissionHandler::class );
		$this->processor = new FormProcessor( $mock_handler );

		$this->post_backup = $_POST ?? array();

		$_POST = array(
			'action'   => 'ffc_submit_form',
			'nonce'    => 'stale-or-mismatched',
			'form_id'  => 1,
		);
	}

	protected function tearDown(): void {
		$_POST = $this->post_backup;
		$this->reset_static_state();
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Forget the rate-limit settings and the captcha strategy memoised by
	 * an earlier test, so this one reads its own options.
	 */
	private function reset_static_state(): void {
		$cache = new \ReflectionProperty( \FreeFormCertificate\Security\RateLimitChecker::class, 'settings_cache' );
		$cache->setAccessible( true );
		$cache->setValue( null, null );
		CaptchaProvider::reset();
	}

	/**
	 * A `$wpdb` double that answers the IP gate's day counter (no row yet,
	 * so the gate allows) and fails on any other query.
	 */
	private function install_wpdb(): void {
		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $this->had_wpdb ? $GLOBALS['wpdb'] : null;
		$this->ran         = array();

		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( $sql, ...$args ) {
				$flat = 1 === count( $args ) && is_array( $args[0] ) ? $args[0] : $args;
				return $sql . ' -- ' . (string) json_encode( $flat );
			}
		);
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			function ( $sql ) {
				$this->ran[] = (string) $sql;
				if ( 0 === strpos( (string) $sql, 'SELECT count FROM %i WHERE type=%s' ) ) {
					return null;
				}
				$this->fail( 'Unexpected get_var(): ' . $sql );
			}
		);
		foreach ( array( 'get_row', 'get_results', 'get_col', 'insert', 'update', 'replace', 'delete', 'query' ) as $method ) {
			$wpdb->shouldReceive( $method )->andReturnUsing(
				function ( ...$args ) use ( $method ) {
					$this->fail( 'Unexpected $wpdb->' . $method . '(): ' . print_r( $args[0] ?? '', true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r -- test diagnostics.
				}
			);
		}
		$GLOBALS['wpdb'] = $wpdb;
	}

	public function test_nonce_failure_returns_refresh_nonce_with_fresh_value(): void {
		$this->expectException( \RuntimeException::class );

		try {
			$this->processor->handle_submission_ajax();
		} finally {
			// RuntimeException propagates, but we still need to assert
			// the captured payload — do it inside finally so the
			// expectException contract is satisfied AND we can check.
			$this->assertIsArray( $this->captured_error, 'wp_send_json_error must be called' );
			$this->assertArrayHasKey( 'message', $this->captured_error );
			$this->assertArrayHasKey( 'refresh_nonce', $this->captured_error );
			$this->assertArrayHasKey( 'new_nonce', $this->captured_error );
			$this->assertTrue( $this->captured_error['refresh_nonce'] );
			$this->assertSame( 'fresh-nonce-abc123', $this->captured_error['new_nonce'] );
			$this->assertNotSame( '', $this->captured_error['new_nonce'] );
			// The real IP gate ran ahead of the nonce and let the request through.
			$this->assertCount( 1, $this->ran, 'The IP gate reads its day counter once.' );
			$this->assertStringContainsString( '"ip"', $this->ran[0] );
		}
	}
}
