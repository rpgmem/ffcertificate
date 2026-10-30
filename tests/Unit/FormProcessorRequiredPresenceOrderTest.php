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
use FreeFormCertificate\Submissions\SubmissionHandler;
use FreeFormCertificate\Tests\Support\Identifiers;

/**
 * 6.6.4 Sprint 4 — LGPD + email presence checks are now run BEFORE
 * the per-field validation loop. The motivation:
 *
 *   - LGPD + email-presence are O(1) string compares; the field loop
 *     runs CPF/RF regex + checksum. Cheap → expensive ordering.
 *   - The presence check returns ALL missing-presence errors in a single
 *     `errors` array so the user sees "missing email AND missing
 *     LGPD" in one go instead of one-at-a-time round-trips.
 *
 * This test pins both contracts.
 *
 * The gates ahead of the presence check run for real — RateLimiter's IP
 * gate over empty transient counters and a `$wpdb` double that fails on any
 * query other than its day counter, and the math captcha, answered with a
 * challenge this test draws from SecurityService.
 */
class FormProcessorRequiredPresenceOrderTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var FormProcessor */
	private $processor;

	/** @var array<string, mixed>|null */
	private $captured_error = null;

	/** @var array<string, mixed> Transients, by key. */
	private array $transients = array();

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	/** @var array<string, mixed> The `$_POST` an earlier test left. */
	private array $post_backup = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'sanitize_email' )->returnArg();
		Functions\when( 'absint' )->alias( 'intval' );
		Functions\when( 'is_email' )->justReturn( true );
		// recursive_sanitize() calls wp_kses() internally.
		Functions\when( 'wp_kses' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( '_deprecated_function' )->justReturn( null );
		Functions\when( 'wp_parse_args' )->alias(
			static fn( $args, $defaults = array() ) => array_merge( (array) $defaults, (array) $args )
		);
		Functions\when( 'wp_salt' )->alias( static fn( string $scheme = 'auth' ): string => 'test-salt-' . $scheme );
		Functions\when( 'wp_rand' )->alias( static fn( int $min = 0, int $max = 0 ): int => random_int( $min, $max ) );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		// No stored option anywhere: every reader takes its defaults, so
		// IP rate limiting is ON and the captcha is math.
		Functions\when( 'get_option' )->alias( static fn( $key, $default = false ) => $default );

		$this->transients = array();
		Functions\when( 'get_transient' )->alias( fn( $key ) => $this->transients[ $key ] ?? false );
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value ) {
				$this->transients[ $key ] = $value;
				return true;
			}
		);

		// Reach the presence gate: rate-limit allows, nonce valid,
		// captcha valid, form_id valid, form config exists with an
		// email-typed field.
		Functions\when( 'wp_verify_nonce' )->justReturn( true );

		Functions\when( 'get_post_meta' )->alias( static function ( $post_id, $key ) {
			if ( '_ffc_form_fields' === $key ) {
				return array(
					array( 'name' => 'email', 'type' => 'email' ),
					array( 'name' => 'cpf_rf', 'type' => 'text' ),
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
				);
			}
			return '';
		} );
		Functions\when( 'get_post' )->alias( static function () {
			$p = new \stdClass();
			$p->ID = 1;
			$p->post_title = 'Form';
			$p->post_type   = 'ffc_form';
			$p->post_status = 'publish';
			return $p;
		} );

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
	 * The request fields every test posts: a valid nonce, the form, and a
	 * correct answer to a freshly drawn math challenge.
	 *
	 * @param array<string, mixed> $fields The form's own fields.
	 * @return array<string, mixed>
	 */
	private function request( array $fields ): array {
		$challenge = SecurityService::generate_simple_captcha();

		return array(
			'action'           => 'ffc_submit_form',
			'nonce'            => 'ok',
			'form_id'          => 1,
			'ffc_captcha_ans'  => (string) $challenge['answer'],
			'ffc_captcha_hash' => $challenge['hash'],
		) + $fields;
	}

	public function test_missing_lgpd_alone_returns_lgpd_error(): void {
		$_POST = $this->request(
			array(
				'email'  => 'user@example.org',
				'cpf_rf' => Identifiers::CPF_A, // Valid CPF — would pass the field loop.
				// ffc_lgpd_consent intentionally missing.
			)
		);

		$this->expectException( \RuntimeException::class );
		try {
			$this->processor->handle_submission_ajax();
		} finally {
			$this->assertIsArray( $this->captured_error );
			$this->assertArrayHasKey( 'errors', $this->captured_error );
			$this->assertCount( 1, $this->captured_error['errors'] );
			$this->assertStringContainsString( 'Privacy Policy', $this->captured_error['errors'][0] );
		}
	}

	public function test_missing_email_alone_returns_email_error(): void {
		$_POST = $this->request(
			array(
				'ffc_lgpd_consent' => '1',
				'cpf_rf'           => Identifiers::CPF_A,
				// email field intentionally missing.
			)
		);

		$this->expectException( \RuntimeException::class );
		try {
			$this->processor->handle_submission_ajax();
		} finally {
			$this->assertIsArray( $this->captured_error );
			$this->assertArrayHasKey( 'errors', $this->captured_error );
			$this->assertCount( 1, $this->captured_error['errors'] );
			$this->assertStringContainsString( 'Email', $this->captured_error['errors'][0] );
		}
	}

	public function test_missing_both_returns_combined_errors_array(): void {
		$_POST = $this->request(
			array(
				'cpf_rf' => Identifiers::CPF_A,
				// email + LGPD both missing.
			)
		);

		$this->expectException( \RuntimeException::class );
		try {
			$this->processor->handle_submission_ajax();
		} finally {
			$this->assertIsArray( $this->captured_error );
			$this->assertArrayHasKey( 'errors', $this->captured_error );
			$this->assertCount( 2, $this->captured_error['errors'] );
			// First error is LGPD (it's checked first), second is email.
			$this->assertStringContainsString( 'Privacy Policy', $this->captured_error['errors'][0] );
			$this->assertStringContainsString( 'Email', $this->captured_error['errors'][1] );
			// Legacy `message` field is the first error (backward compat).
			$this->assertSame( $this->captured_error['errors'][0], $this->captured_error['message'] );
		}
	}

	public function test_presence_check_passes_when_lgpd_and_email_are_filled(): void {
		// Both presence checks satisfied → the presence check does NOT
		// reject, which proves it is purely additive and does not change
		// the happy-path behaviour. The request continues through the field
		// sanitizer, the invite and field-rule guards and the device
		// resolver, and is refused by the first gate after them that can
		// refuse it: the form's GPS geofence, since the reading lies far
		// outside its area. That rejection has no `errors` array, so it is
		// not the presence check's.
		$_POST = $this->request(
			array(
				'ffc_lgpd_consent' => '1',
				'email'            => 'user@example.org',
				'cpf_rf'           => Identifiers::CPF_A,
				// A precise, fresh reading in Rio de Janeiro.
				'ffc_geo_lat'      => '-22.9068',
				'ffc_geo_lng'      => '-43.1729',
				'ffc_geo_accuracy' => '10',
				'ffc_geo_age'      => '1000',
			)
		);

		$this->expectException( \RuntimeException::class );
		try {
			$this->processor->handle_submission_ajax();
		} finally {
			$this->assertIsArray( $this->captured_error );
			$this->assertTrue( $this->captured_error['geofence_blocked'] ?? false, 'The request must get past the presence check to the geofence.' );
			$this->assertArrayNotHasKey(
				'errors',
				$this->captured_error,
				'presence check `errors` array should not be present on a non-presence-check failure'
			);
		}
	}
}
