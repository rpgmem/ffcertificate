<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\Captcha\CaptchaProvider;
use FreeFormCertificate\Core\SecurityService;
use FreeFormCertificate\Frontend\FormProcessor;
use FreeFormCertificate\Security\GpsReading;
use FreeFormCertificate\Submissions\SubmissionHandler;

/**
 * 6.6.4 Sprint 5 — Geofence::can_access_form (read-only) runs BEFORE
 * RateLimiter::check_all + record_attempt (writes). Without this
 * order, a visitor outside the geofence ticked the rate-limit
 * counter on every retry, burning their budget for the next
 * legitimate attempt.
 *
 * Contract pinned here: when geofence rejects, RateLimiter::check_all
 * is NEVER invoked.
 *
 * The whole pipeline runs for real — RateLimiter and its IP limiter, the
 * math captcha and its challenge store, Geofence and GpsReading — over a
 * `$wpdb` double that records every query and fails on any it does not
 * expect, and a transient store kept in this test. The form's geofence is a
 * GPS area the posted reading lies far outside of.
 */
class FormProcessorGeofenceBeforeRateLimitTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** Message the form's geofence shows a reading outside its areas. */
	private const OUTSIDE_MESSAGE = 'You are outside the allowed area.';

	/** @var FormProcessor */
	private $processor;

	/** @var array<string, mixed>|null */
	private $captured_error = null;

	/** @var array<string, mixed> Transients, by key. */
	private array $transients = array();

	/** @var list<string> Keys of every transient written. */
	private array $transient_writes = array();

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
		// Several methods this class reaches are deprecated (#2089) and emit
		// the notice; stub it so each test chooses the branch rather than
		// inheriting it from an earlier class.
		Functions\when( '_deprecated_function' )->justReturn( null );

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_email' )->returnArg();
		Functions\when( 'absint' )->alias( 'intval' );
		Functions\when( 'is_email' )->justReturn( true );
		// recursive_sanitize() calls wp_kses() internally.
		Functions\when( 'wp_kses' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'wp_parse_args' )->alias(
			static fn( $args, $defaults = array() ) => array_merge( (array) $defaults, (array) $args )
		);
		Functions\when( 'wp_salt' )->alias( static fn( string $scheme = 'auth' ): string => 'test-salt-' . $scheme );
		Functions\when( 'wp_rand' )->alias( static fn( int $min = 0, int $max = 0 ): int => random_int( $min, $max ) );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		// No stored option anywhere: every reader takes its defaults, so
		// IP rate limiting is ON (5 per hour) and the captcha is math.
		Functions\when( 'get_option' )->alias( static fn( $key, $default = false ) => $default );

		$this->transients       = array();
		$this->transient_writes = array();
		Functions\when( 'get_transient' )->alias( fn( $key ) => $this->transients[ $key ] ?? false );
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value ) {
				$this->transients[ $key ] = $value;
				$this->transient_writes[] = (string) $key;
				return true;
			}
		);

		Functions\when( 'wp_verify_nonce' )->justReturn( true );

		Functions\when( 'get_post_meta' )->alias(
			static function ( $post_id, $key ) {
				if ( '_ffc_form_fields' === $key ) {
					return array(
						array( 'name' => 'email', 'type' => 'email' ),
					);
				}
				if ( '_ffc_form_config' === $key ) {
					return array();
				}
				if ( '_ffc_geofence_config' === $key ) {
					// One GPS area of 100 m around São Paulo's Praça da Sé.
					return array(
						'geo_enabled'     => '1',
						'geo_gps_enabled' => '1',
						'geo_areas'       => '-23.5503, -46.6339, 100',
						'msg_geo_blocked' => self::OUTSIDE_MESSAGE,
					);
				}
				return '';
			}
		);
		Functions\when( 'get_post' )->alias(
			static function () {
				$p             = new \stdClass();
				$p->ID         = 1;
				$p->post_title  = 'Form';
				$p->post_type   = 'ffc_form';
				$p->post_status = 'publish';
				return $p;
			}
		);

		$captured = &$this->captured_error;
		Functions\when( 'wp_send_json_error' )->alias(
			static function ( $payload ) use ( &$captured ) {
				$captured = $payload;
				throw new \RuntimeException( 'wp_send_json_error called' );
			}
		);

		$this->reset_static_state();
		$this->install_wpdb();

		$mock_handler    = Mockery::mock( SubmissionHandler::class );
		$this->processor = new FormProcessor( $mock_handler );

		$this->post_backup = $_POST ?? array();

		$challenge = SecurityService::generate_simple_captcha();

		$_POST = array(
			'action'           => 'ffc_submit_form',
			'nonce'            => 'ok',
			'form_id'          => 1,
			'ffc_lgpd_consent' => '1',
			'email'            => 'user@example.org',
			'ffc_captcha_ans'  => (string) $challenge['answer'],
			'ffc_captcha_hash' => $challenge['hash'],
			// A precise, fresh reading in Rio de Janeiro: far outside the area.
			'ffc_geo_lat'      => '-22.9068',
			'ffc_geo_lng'      => '-43.1729',
			'ffc_geo_accuracy' => '10',
			'ffc_geo_age'      => '1000',
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
	 * A `$wpdb` double that answers the rate-limit day counter (no row yet)
	 * and fails on any other query.
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

	/**
	 * The rate-limit queries the double ran.
	 *
	 * @return list<string>
	 */
	private function rate_limit_queries(): array {
		return array_values(
			array_filter( $this->ran, static fn( string $sql ): bool => false !== strpos( $sql, 'ffc_rate_limits' ) )
		);
	}

	public function test_geofence_rejection_returns_geofence_blocked_payload(): void {
		$this->expectException( \RuntimeException::class );
		try {
			$this->processor->handle_submission_ajax();
		} finally {
			$this->assertIsArray( $this->captured_error );
			$this->assertArrayHasKey( 'geofence_blocked', $this->captured_error );
			$this->assertTrue( $this->captured_error['geofence_blocked'] );
			// The real GPS verdict for a reading outside every area.
			$this->assertSame( GpsReading::REASON_OUTSIDE, $this->captured_error['reason'] );
			$this->assertStringContainsString( 'outside the allowed area', $this->captured_error['message'] );
		}
	}

	public function test_geofence_rejection_does_NOT_invoke_rate_limiter_check_all_or_record_attempt(): void {
		// Critical contract: a visitor outside the geofence does NOT tick
		// the rate-limit counter. The only rate-limit read is the initial
		// IP gate's day counter — not tied to a form, `form_id IS NULL`.
		// check_all() would read the IP counter again for this form and the
		// e-mail counter, and record_attempt() / record_device_signals()
		// would write transients or rows (the double fails on any write).
		try {
			$this->processor->handle_submission_ajax();
			$this->fail( 'Expected the geofence rejection.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'wp_send_json_error called', $e->getMessage() );
		}

		$this->assertTrue( $this->captured_error['geofence_blocked'] ?? false, 'The rejection must be the geofence one.' );

		$reads = $this->rate_limit_queries();
		$this->assertCount( 1, $reads, 'Only the initial IP gate reads a rate-limit counter: ' . implode( ' | ', $reads ) );
		$this->assertStringContainsString( 'form_id IS NULL', $reads[0] );
		$this->assertStringContainsString( '"ip"', $reads[0] );

		$rate_writes = array_filter( $this->transient_writes, static fn( string $key ): bool => 0 === strpos( $key, 'ffc_rate_' ) );
		$this->assertSame( array(), array_values( $rate_writes ), 'No rate-limit counter may be written.' );
	}
}
