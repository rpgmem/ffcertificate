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
use FreeFormCertificate\Core\IpAddress;
use FreeFormCertificate\Core\SecurityService;
use FreeFormCertificate\Frontend\ExtendEndAction;
use FreeFormCertificate\Frontend\PublicCsvDownload;
use FreeFormCertificate\Frontend\ScheduleExceptionSession;
use FreeFormCertificate\Security\RateLimitChecker;
use FreeFormCertificate\Tests\Support\SiteClock;

/**
 * Coverage for the AJAX handlers + the admin-post audit-log export on
 * {@see PublicCsvDownload}.
 *
 * The collaborators run for real: CsvDownloadValidator,
 * CsvDownloadFormInfoBuilder and CsvDownloadFlash (built by the
 * constructor), EarlyOpenAction / ExtendEndAction / ScheduleExceptionAction
 * and ScheduleExceptionSession, Geofence, FormCache, the math captcha
 * (SecurityService, MathCaptcha, ChallengeSigner, ChallengeStore) with
 * `ffc_settings` stubbed, and RateLimiter / IpLimiter / RateCounter /
 * RateLimitRepository over an in-memory transient store. The form is post
 * meta in an in-memory store the actions read and write; the site timezone
 * is chosen so that now is noon (SiteClock), which keeps every window hours
 * from a day boundary; `$wpdb` is a double that answers the two counts
 * these paths read and fails the test on any other query.
 *
 * wp_send_json_error / wp_send_json_success are stubbed to throw, since they
 * terminate the request in production, and the payload is captured.
 *
 * @covers \FreeFormCertificate\Frontend\PublicCsvDownload
 */
class PublicCsvDownloadAjaxHandlersTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const IP = '203.0.113.5';

	private const FORM_ID = 42;

	private const HASH = 'validhash';

	/** @var array<string, mixed> json payload captured before the terminator throws */
	private array $captured = array();

	/** @var array<int, array<string, mixed>> Post meta by post id and key. */
	private array $meta = array();

	/** @var array<string, mixed> The transient store. */
	private array $transients = array();

	/** @var list<array{0: string, 1: string}> Every setcookie(): name and value. */
	private array $cookies = array();

	/** @var list<string> Every read query the double ran. */
	private array $reads = array();

	private SiteClock $clock;

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	/** @var array<string, mixed> `$_SERVER` before this test. */
	private array $server_backup = array();

	/** @var array<string, mixed> `$_POST` before this test. */
	private array $post_backup = array();

	/** @var array<string, mixed> `$_GET` before this test. */
	private array $get_backup = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Static state the real classes keep: the limiter's settings memo and
		// the captcha provider instances.
		$cache = new \ReflectionProperty( RateLimitChecker::class, 'settings_cache' );
		$cache->setAccessible( true );
		$cache->setValue( null, null );
		CaptchaProvider::reset();
		MathCaptcha::reset_instances();

		$this->captured   = array();
		$this->meta       = array();
		$this->transients = array();
		$this->cookies    = array();
		$this->reads      = array();

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( '_n' )->alias( static fn( $single, $plural, $n ) => 1 === (int) $n ? $single : $plural );
		Functions\when( 'absint' )->alias( fn( $v ) => abs( (int) $v ) );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias( static fn( $v ) => json_encode( $v ) );
		Functions\when( 'wp_parse_args' )->alias( static fn( $args, $defaults = array() ) => array_merge( (array) $defaults, (array) $args ) );
		Functions\when( 'wp_salt' )->justReturn( 'test-salt-public-csv' );
		Functions\when( 'wp_rand' )->alias( static fn( $min = 0, $max = 0 ) => random_int( (int) $min, (int) $max ) );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'wp_date' )->alias( static fn( $format, $ts = null ) => gmdate( $format, $ts ?? time() ) );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'is_ssl' )->justReturn( false );
		Functions\when( 'home_url' )->alias( static fn( $p = '' ) => 'https://example.com' . $p );
		Functions\when( 'get_the_title' )->justReturn( 'Form X' );
		Functions\when( 'get_post_type' )->alias( fn( $id ) => isset( $this->meta[ (int) $id ] ) ? 'ffc_form' : false );
		// The participant form is embedded in one page.
		Functions\when( 'get_posts' )->justReturn( array( 5 ) );
		Functions\when( 'get_post_field' )->justReturn( '[ffc_form id="' . self::FORM_ID . '"]' );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/form' );
		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = false ) {
				if ( 'ffc_settings' === $key ) {
					return array( 'captcha_provider' => 'math' );
				}
				if ( 'ffc_rate_limit_settings' === $key ) {
					// Off so an allowed request writes nothing.
					return array( 'logging' => array( 'enabled' => false ) );
				}
				return $default;
			}
		);
		Functions\when( 'get_post_meta' )->alias( fn( $id, $key ) => $this->meta[ (int) $id ][ $key ] ?? '' );
		Functions\when( 'update_post_meta' )->alias(
			function ( $id, $key, $value ) {
				$this->meta[ (int) $id ][ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'get_transient' )->alias( fn( $key ) => $this->transients[ $key ] ?? false );
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value ) {
				$this->transients[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'setcookie' )->alias(
			function ( $name, $value = '' ) {
				$this->cookies[] = array( (string) $name, (string) $value );
				return true;
			}
		);

		$this->clock = new SiteClock();
		$this->clock->stub();

		// Terminators throw so the branch under test halts (as in prod).
		Functions\when( 'wp_send_json_error' )->alias(
			function ( $payload = null, $code = null ) {
				$this->captured = array(
					'kind'    => 'error',
					'payload' => $payload,
					'code'    => $code,
				);
				throw new \RuntimeException( 'json_error' );
			}
		);
		Functions\when( 'wp_send_json_success' )->alias(
			function ( $payload = null ) {
				$this->captured = array(
					'kind'    => 'success',
					'payload' => $payload,
				);
				throw new \RuntimeException( 'json_success' );
			}
		);

		$this->server_backup        = $_SERVER ?? array();
		$this->post_backup          = $_POST ?? array();
		$this->get_backup           = $_GET ?? array();
		$_SERVER['REMOTE_ADDR']     = self::IP;
		$_SERVER['HTTP_USER_AGENT'] = 'phpunit';
		// The headers ClientIpResolver reads ahead of REMOTE_ADDR.
		unset( $_SERVER['HTTP_CLIENT_IP'], $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_X_FORWARDED'], $_SERVER['HTTP_X_CLUSTER_CLIENT_IP'], $_SERVER['HTTP_FORWARDED_FOR'], $_SERVER['HTTP_FORWARDED'] );

		$this->had_wpdb = array_key_exists( 'wpdb', $GLOBALS );
		if ( $this->had_wpdb ) {
			$this->wpdb_backup = $GLOBALS['wpdb'];
		}
		$GLOBALS['wpdb'] = $this->wpdb_double();

		$this->configure_form( array() );
	}

	protected function tearDown(): void {
		$_SERVER = $this->server_backup;
		$_POST   = $this->post_backup;
		$_GET    = $this->get_backup;
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A `$wpdb` double: `prepare()` binds its arguments into the SQL; the
	 * IP limiter's daily count and the form's submission count are answered,
	 * anything else fails the test.
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
				if ( 0 === strpos( $q, 'SELECT COUNT(*) FROM wp_ffc_submissions' ) ) {
					return '3';
				}
				$this->fail( 'Unexpected get_var(): ' . $q );
			}
		);
		return $wpdb;
	}

	/**
	 * Store the form: public access on with HASH, the datetime window on,
	 * open from 11:00 to 14:00 today (it is noon), no CPF gate.
	 *
	 * @param array<string, mixed> $geofence Geofence keys to replace.
	 * @param array<string, mixed> $meta     Meta keys to replace.
	 */
	private function configure_form( array $geofence, array $meta = array() ): void {
		$this->meta[ self::FORM_ID ] = $meta + array(
			PublicCsvDownload::META_ENABLED => '1',
			PublicCsvDownload::META_HASH    => self::HASH,
			'_ffc_form_config'              => array(),
			'_ffc_geofence_config'          => $geofence + array(
				'datetime_enabled' => '1',
				'date_start'       => $this->clock->day(),
				'time_start'       => '11:00',
				'date_end'         => $this->clock->day(),
				'time_end'         => '14:00',
			),
		);
	}

	/**
	 * A POST body for the form, carrying a correctly answered math challenge.
	 *
	 * @param array<string, string> $fields Fields to replace.
	 * @return array{0: array<string, string>, 1: array{answer: int, hash: string}}
	 */
	private function answered_post( array $fields = array() ): array {
		$challenge = SecurityService::generate_simple_captcha();
		$post      = $fields + array(
			'_ffc_pcd_nonce'   => 'n',
			'form_id'          => (string) self::FORM_ID,
			'hash'             => self::HASH,
			'cpf'              => '',
			'ffc_captcha_ans'  => (string) $challenge['answer'],
			'ffc_captcha_hash' => $challenge['hash'],
		);
		return array(
			$post,
			array(
				'answer' => (int) $challenge['answer'],
				'hash'   => (string) $challenge['hash'],
			),
		);
	}

	private function valid_post(): void {
		$_POST = array(
			'_ffc_pcd_nonce' => 'n',
			'form_id'        => (string) self::FORM_ID,
			'hash'           => self::HASH,
			'cpf'            => '',
		);
	}

	/**
	 * The `result` of every entry in the form's download audit log.
	 *
	 * @return list<string>
	 */
	private function audit_results(): array {
		$log = $this->meta[ self::FORM_ID ][ PublicCsvDownload::META_DOWNLOAD_LOG ] ?? array();
		return array_map( static fn( $row ) => (string) $row['result'], is_array( $log ) ? $log : array() );
	}

	/**
	 * Store the IP limiter's hourly count for this address at its cap.
	 */
	private function exhaust_ip_hour_limit(): void {
		$max = RateLimitChecker::get_settings()['ip']['max_per_hour'];
		$this->transients[ 'ffc_rate_ip_' . md5( IpAddress::fingerprint( self::IP ) ) . '_hour' ] = $max;
	}

	// ==================================================================
	//  ajax_info()
	// ==================================================================

	public function test_ajax_info_rejects_bad_nonce(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( false );
		$this->valid_post();

		try {
			( new PublicCsvDownload() )->ajax_info();
			$this->fail( 'expected json_error' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'json_error', $e->getMessage() );
			$this->assertSame( 'Security check failed. Please refresh the page and try again.', $this->captured['payload']['message'] );
		}
	}

	public function test_ajax_info_rejects_when_rate_limited(): void {
		$this->exhaust_ip_hour_limit();
		$this->valid_post();

		try {
			( new PublicCsvDownload() )->ajax_info();
			$this->fail( 'expected json_error' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'json_error', $e->getMessage() );
			// The limiter's own message, as a denial always carries one (#2153).
			$this->assertSame( 'Limit reached. Please wait 1 hour.', $this->captured['payload']['message'] );
		}
	}

	public function test_ajax_info_rejects_failed_captcha_and_logs(): void {
		list( $post, $challenge ) = $this->answered_post();
		$post['ffc_captcha_ans']  = (string) ( $challenge['answer'] + 1 );
		$_POST                    = $post;

		try {
			( new PublicCsvDownload() )->ajax_info();
			$this->fail( 'expected json_error' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'json_error', $e->getMessage() );
			$this->assertSame( 'Error: The math answer is incorrect.', $this->captured['payload']['message'] );
			$this->assertTrue( $this->captured['payload']['refresh_captcha'] );
		}
		$this->assertSame( array( 'fail_captcha' ), $this->audit_results() );
	}

	public function test_ajax_info_peeks_at_the_captcha_instead_of_spending_it(): void {
		// The 6.23.0 regression: this screen burned the token, so the download
		// that re-posts the same payload rejected the answer the visitor had
		// just been told was correct. The gate still runs — it must not
		// consume.
		list( $_POST, $challenge ) = $this->answered_post();

		try {
			( new PublicCsvDownload() )->ajax_info();
			$this->fail( 'expected json_success' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'json_success', $e->getMessage() );
		}
		// The challenge is still unspent, so the download can redeem it once.
		$this->assertTrue( SecurityService::verify_simple_captcha( (string) $challenge['answer'], $challenge['hash'] ) );
		$this->assertFalse( SecurityService::verify_simple_captcha( (string) $challenge['answer'], $challenge['hash'] ) );
	}

	public function test_ajax_info_rejects_missing_form_id(): void {
		list( $_POST ) = $this->answered_post(
			array(
				'form_id' => '0',
				'hash'    => '',
			)
		);

		try {
			( new PublicCsvDownload() )->ajax_info();
			$this->fail( 'expected json_error' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'json_error', $e->getMessage() );
			$this->assertSame( 'Please inform both the Form ID and the Access Hash.', $this->captured['payload']['message'] );
		}
	}

	public function test_ajax_info_rejects_when_hash_validation_fails(): void {
		list( $_POST ) = $this->answered_post( array( 'hash' => 'otherhash' ) );

		try {
			( new PublicCsvDownload() )->ajax_info();
			$this->fail( 'expected json_error' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'Invalid access hash.', $this->captured['payload']['message'] );
		}
		$this->assertSame( array( 'fail_other' ), $this->audit_results() );
	}

	public function test_ajax_info_rejects_when_cpf_gate_fails(): void {
		$this->configure_form( array(), array( PublicCsvDownload::META_CPF_MODE => 'audit' ) );
		list( $_POST ) = $this->answered_post();

		try {
			( new PublicCsvDownload() )->ajax_info();
			$this->fail( 'expected json_error' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'CPF is required to download this CSV.', $this->captured['payload']['message'] );
		}
	}

	public function test_ajax_info_happy_path_returns_form_info(): void {
		list( $_POST ) = $this->answered_post();

		try {
			( new PublicCsvDownload() )->ajax_info();
			$this->fail( 'expected json_success' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'json_success', $e->getMessage() );
			$this->assertSame( 'Form X', $this->captured['payload']['form_title'] );
			$this->assertSame( 3, $this->captured['payload']['submission_count'] );
		}
	}

	// ==================================================================
	//  ajax_cert_preview()
	// ==================================================================

	public function test_ajax_cert_preview_rejects_bad_nonce(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( false );
		$this->valid_post();

		try {
			( new PublicCsvDownload() )->ajax_cert_preview();
			$this->fail( 'expected json_error' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'json_error', $e->getMessage() );
			$this->assertSame( 'Security check failed.', $this->captured['payload']['message'] );
		}
	}

	public function test_ajax_cert_preview_rejects_when_hash_invalid(): void {
		$this->valid_post();
		$_POST['hash'] = 'nope';

		try {
			( new PublicCsvDownload() )->ajax_cert_preview();
			$this->fail( 'expected json_error' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'json_error', $e->getMessage() );
			$this->assertSame( 'Invalid access hash.', $this->captured['payload']['message'] );
		}
	}

	public function test_ajax_cert_preview_rejects_when_form_already_started(): void {
		// Hash ok; the form opened at 11:00 and it is noon → preview unavailable.
		$this->valid_post();

		try {
			( new PublicCsvDownload() )->ajax_cert_preview();
			$this->fail( 'expected json_error' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'json_error', $e->getMessage() );
			$this->assertStringContainsString( 'only available before the form collection period begins', $this->captured['payload']['message'] );
		}
	}

	// ==================================================================
	//  ajax_open_early()
	// ==================================================================

	public function test_ajax_open_early_rejects_bad_nonce(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( false );
		$this->valid_post();

		try {
			( new PublicCsvDownload() )->ajax_open_early();
			$this->fail( 'expected json_error' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'json_error', $e->getMessage() );
			$this->assertSame( 403, $this->captured['code'] );
		}
	}

	public function test_ajax_open_early_rejects_when_cpf_gate_fails(): void {
		$this->configure_form( array( 'time_start' => '13:00' ), array( PublicCsvDownload::META_CPF_MODE => 'audit' ) );
		$this->valid_post();

		try {
			( new PublicCsvDownload() )->ajax_open_early();
			$this->fail( 'expected json_error' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'CPF is required to download this CSV.', $this->captured['payload']['message'] );
			$this->assertSame( 403, $this->captured['code'] );
		}
		// The gate runs silent here, and the form was not opened.
		$this->assertSame( array(), $this->audit_results() );
		$this->assertSame( '13:00', $this->meta[ self::FORM_ID ]['_ffc_geofence_config']['time_start'] );
	}

	/**
	 * #2241 — the three actions check the access hash before the CPF, so a
	 * caller without the hash cannot learn whether a CPF is authorised.
	 *
	 * @dataProvider provider_csv_actions
	 */
	public function test_an_action_refuses_a_wrong_hash_before_reading_the_cpf( string $method ): void {
		$this->configure_form( array( 'time_start' => '13:00' ), array( PublicCsvDownload::META_CPF_MODE => 'whitelist' ) );
		$this->valid_post();
		$_POST['hash'] = 'wronghash';
		$_POST['cpf']  = \FreeFormCertificate\Tests\Support\Identifiers::CPF_A;

		try {
			( new PublicCsvDownload() )->$method();
			$this->fail( 'expected json_error' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 403, $this->captured['code'] );
			$this->assertStringNotContainsString( 'CPF', $this->captured['payload']['message'], 'The answer says nothing about the CPF.' );
		}
		$this->assertSame( '13:00', $this->meta[ self::FORM_ID ]['_ffc_geofence_config']['time_start'] );
	}

	/**
	 * #2241 — the actions take the address's rate limit, as the download does.
	 *
	 * @dataProvider provider_csv_actions
	 */
	public function test_an_action_is_refused_when_the_address_is_rate_limited( string $method ): void {
		$this->configure_form( array( 'time_start' => '13:00' ), array( PublicCsvDownload::META_CPF_MODE => 'audit' ) );
		$this->exhaust_ip_hour_limit();
		$this->valid_post();

		try {
			( new PublicCsvDownload() )->$method();
			$this->fail( 'expected json_error' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 429, $this->captured['code'] );
			$this->assertSame( 'Limit reached. Please wait 1 hour.', $this->captured['payload']['message'] );
		}
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function provider_csv_actions(): array {
		return array(
			'open early'         => array( 'ajax_open_early' ),
			'extend end'         => array( 'ajax_extend_end' ),
			'schedule exception' => array( 'ajax_schedule_exception' ),
		);
	}

	public function test_ajax_open_early_maps_reason_to_message_on_failure(): void {
		// The form opened at 11:00 and it is noon.
		$this->valid_post();

		try {
			( new PublicCsvDownload() )->ajax_open_early();
			$this->fail( 'expected json_error' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'already_started', $this->captured['payload']['reason'] );
			$this->assertStringContainsString( 'already started', $this->captured['payload']['message'] );
			$this->assertSame( 409, $this->captured['code'] );
		}
	}

	public function test_ajax_open_early_reports_an_early_open_already_used(): void {
		// The form was already opened early once; the refusal names that
		// rather than a generic failure (#2169).
		$this->configure_form( array( 'time_start' => '13:00' ), array( '_ffc_csv_public_start_opened_at' => time() - 60 ) );
		$this->valid_post();

		try {
			( new PublicCsvDownload() )->ajax_open_early();
			$this->fail( 'expected json_error' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'already_opened', $this->captured['payload']['reason'] );
			$this->assertSame( 'This form has already been opened early once.', $this->captured['payload']['message'] );
		}
	}

	public function test_ajax_open_early_happy_path(): void {
		// Scheduled to open at 13:00 today; it is noon.
		$this->configure_form( array( 'time_start' => '13:00' ) );
		$this->valid_post();
		$now_time = (string) current_time( 'H:i' );

		try {
			( new PublicCsvDownload() )->ajax_open_early();
			$this->fail( 'expected json_success' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'json_success', $e->getMessage() );
			$this->assertSame( $this->clock->day() . ' ' . $now_time, $this->captured['payload']['new_start_iso'] );
			$this->assertSame( $this->clock->day() . ' 13:00', $this->captured['payload']['original_start_iso'] );
		}
		$this->assertSame( $now_time, $this->meta[ self::FORM_ID ]['_ffc_geofence_config']['time_start'] );
		$this->assertSame( array( 'action_early_open' ), $this->audit_results() );
	}

	// ==================================================================
	//  ajax_extend_end()
	// ==================================================================

	public function test_ajax_extend_end_rejects_bad_nonce(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( false );
		$this->valid_post();

		try {
			( new PublicCsvDownload() )->ajax_extend_end();
			$this->fail( 'expected json_error' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'json_error', $e->getMessage() );
			$this->assertSame( 403, $this->captured['code'] );
		}
	}

	public function test_ajax_extend_end_rejects_when_cpf_gate_fails(): void {
		$this->configure_form(
			array(),
			array(
				PublicCsvDownload::META_CPF_MODE => 'audit',
				ExtendEndAction::META_ENABLED    => '1',
			)
		);
		$this->valid_post();
		$_POST['new_time_end'] = '15:00';

		try {
			( new PublicCsvDownload() )->ajax_extend_end();
			$this->fail( 'expected json_error' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'CPF is required to download this CSV.', $this->captured['payload']['message'] );
		}
		$this->assertSame( '14:00', $this->meta[ self::FORM_ID ]['_ffc_geofence_config']['time_end'] );
	}

	public function test_ajax_extend_end_maps_reason_on_failure(): void {
		$this->configure_form(
			array(),
			array(
				ExtendEndAction::META_ENABLED       => '1',
				ExtendEndAction::META_POSTPONED_AT => time() - 60,
			)
		);
		$this->valid_post();
		$_POST['new_time_end'] = '15:00';

		try {
			( new PublicCsvDownload() )->ajax_extend_end();
			$this->fail( 'expected json_error' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'already_postponed', $this->captured['payload']['reason'] );
			$this->assertStringContainsString( 'already been postponed', $this->captured['payload']['message'] );
		}
	}

	public function test_ajax_extend_end_happy_path(): void {
		$this->configure_form( array(), array( ExtendEndAction::META_ENABLED => '1' ) );
		$this->valid_post();
		$_POST['new_time_end'] = '15:00';

		try {
			( new PublicCsvDownload() )->ajax_extend_end();
			$this->fail( 'expected json_success' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'json_success', $e->getMessage() );
			$this->assertSame( $this->clock->day() . ' 15:00', $this->captured['payload']['new_end_iso'] );
			$this->assertSame( $this->clock->day() . ' 14:00', $this->captured['payload']['original_end_iso'] );
		}
		$this->assertSame( '15:00', $this->meta[ self::FORM_ID ]['_ffc_geofence_config']['time_end'] );
		$this->assertSame( array( 'action_postpone_close' ), $this->audit_results() );
	}

	// ==================================================================
	//  ajax_schedule_exception()
	// ==================================================================

	public function test_ajax_schedule_exception_rejects_bad_nonce(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( false );
		$this->valid_post();

		try {
			( new PublicCsvDownload() )->ajax_schedule_exception();
			$this->fail( 'expected json_error' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'json_error', $e->getMessage() );
			$this->assertSame( 403, $this->captured['code'] );
		}
	}

	public function test_ajax_schedule_exception_rejects_when_cpf_gate_fails(): void {
		$this->configure_form( array( 'schedule_exception_enabled' => '1' ), array( PublicCsvDownload::META_CPF_MODE => 'audit' ) );
		$this->valid_post();
		$_POST['start_override'] = '11:30';

		try {
			( new PublicCsvDownload() )->ajax_schedule_exception();
			$this->fail( 'expected json_error' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'CPF is required to download this CSV.', $this->captured['payload']['message'] );
		}
		$this->assertSame( array(), $this->cookies, 'A refused exception stages no session.' );
	}

	public function test_ajax_schedule_exception_maps_reason_on_failure(): void {
		// No override on either end: identical to the baseline.
		$this->configure_form( array( 'schedule_exception_enabled' => '1' ) );
		$this->valid_post();

		try {
			( new PublicCsvDownload() )->ajax_schedule_exception();
			$this->fail( 'expected json_error' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'no_change', $this->captured['payload']['reason'] );
			$this->assertStringContainsString( 'identical to the baseline', $this->captured['payload']['message'] );
		}
	}

	public function test_ajax_schedule_exception_happy_path(): void {
		$this->configure_form( array( 'schedule_exception_enabled' => '1' ) );
		$this->valid_post();
		$_POST['start_override'] = '11:30';

		try {
			( new PublicCsvDownload() )->ajax_schedule_exception();
			$this->fail( 'expected json_success' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'json_success', $e->getMessage() );
			$this->assertNotEmpty( $this->captured['payload']['token'] );
			$this->assertSame( 'https://example.com/form', $this->captured['payload']['form_url'] );
		}
		// The token is the session the participant form will read.
		$this->assertSame(
			array( array( ScheduleExceptionSession::cookie_name( self::FORM_ID ), $this->captured['payload']['token'] ) ),
			$this->cookies
		);
	}

	// ==================================================================
	//  handle_export_log_request() — delegation to the sync export driver
	// ==================================================================

	/**
	 * The auth gate + decrypting row generator moved to
	 * {@see \FreeFormCertificate\Frontend\Csv\CsvDownloadLogExportSource} (see
	 * CsvDownloadLogExportSourceTest); the handler now just reads form_id and
	 * hands a source of that type to the injected SyncCsvExport driver. (#772.)
	 */
	public function test_export_log_delegates_to_sync_export_with_log_source(): void {
		$_GET = array(
			'form_id'  => '42',
			'_wpnonce' => 'x',
		);

		$captured = null;
		$exporter = Mockery::mock( 'FreeFormCertificate\Core\SyncCsvExport' );
		$exporter->shouldReceive( 'handle' )->once()->with(
			Mockery::on(
				function ( $source ) use ( &$captured ) {
					$captured = $source;
					return $source instanceof \FreeFormCertificate\Frontend\Csv\CsvDownloadLogExportSource;
				}
			)
		);

		( new PublicCsvDownload() )->handle_export_log_request( $exporter );

		$this->assertInstanceOf(
			\FreeFormCertificate\Frontend\Csv\CsvDownloadLogExportSource::class,
			$captured
		);
	}
}
