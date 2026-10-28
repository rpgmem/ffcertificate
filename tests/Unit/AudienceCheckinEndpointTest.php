<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Audience\AudienceCheckin;
use FreeFormCertificate\Audience\AudienceCheckinCode;
use FreeFormCertificate\Audience\AudienceCheckinEndpoint;
use FreeFormCertificate\Audience\AudienceCheckinReceipt;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * The public check-in URL (#1947): what a scan and a receipt URL answer.
 *
 * The flow behind it is {@see AudienceCheckinTest}; here it runs for real —
 * AudienceCheckin, the code and the receipt, the window, booking and
 * attendance stores, the attendance writer, Button and DateFormatter — over a
 * `$wpdb` double that answers each query by its SQL text from the rows a test
 * sets, and records every query it ran. These tests pin the routing: a
 * logged-out visitor goes to the login, a GET never writes, and a POST
 * without a valid nonce never reaches the write.
 *
 * @covers \FreeFormCertificate\Audience\AudienceCheckinEndpoint
 */
class AudienceCheckinEndpointTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** 2027-01-15 08:00:00 UTC. */
	private const NOW = 1800000000;

	private const DAY = '2027-01-15';

	private const USER = 42;

	private const BOOKING = 31;

	private const WINDOW = 7;

	/** @var array<string, mixed>|null The window row the window store answers; null for none. */
	private ?array $window_row = null;

	/** @var list<array<string, mixed>> Attendance rows the day's query answers. */
	private array $attendance = array();

	/** @var list<string> Every SQL string the double ran (get_row, get_results, get_col, query). */
	private array $ran = array();

	/** @var list<array<int, mixed>> The bound arguments of every INSERT the double ran. */
	private array $inserts = array();

	/** @var array<int, array<string, mixed>> Geofence locations stored in the option. */
	private array $locations = array();

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'current_time' )->justReturn( self::DAY );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_salt' )->justReturn( 'test-salt' );
		Functions\when( 'is_wp_error' )->alias( static fn( $v ) => $v instanceof \WP_Error );
		Functions\when( 'home_url' )->alias( static fn( $path = '' ) => 'https://example.org' . $path );
		Functions\when( 'add_query_arg' )->alias( static fn( $key, $value, $url ) => $url . '?' . $key . '=' . $value );
		Functions\when( 'wp_login_url' )->alias( static fn( $to ) => 'https://example.org/wp-login.php?redirect_to=' . rawurlencode( $to ) );
		Functions\when( 'wp_nonce_field' )->justReturn( '<input type="hidden" name="_wpnonce" value="n">' );
		Functions\when( 'get_current_user_id' )->justReturn( self::USER );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'wp_timezone' )->alias( static fn() => new \DateTimeZone( 'UTC' ) );
		Functions\when( 'wp_date' )->alias(
			static fn( $format, $ts = null, $tz = null ) => ( new \DateTimeImmutable( '@' . (int) $ts ) )->setTimezone( $tz ?? new \DateTimeZone( 'UTC' ) )->format( $format )
		);
		Functions\when( 'get_option' )->alias(
			function ( $key, $default = false ) {
				if ( 'ffc_settings' === $key ) {
					// What DateFormatter reads.
					return array(
						'date_format' => 'd/m/Y',
						'time_format' => 'H:i',
					);
				}
				if ( 'ffc_geofence_locations' === $key ) {
					return $this->locations;
				}
				if ( 'ffc_geolocation_settings' === $key ) {
					// The GPS tolerance setting: nothing stored, so the default.
					return array();
				}
				return $default;
			}
		);

		$this->window_row = $this->window();
		$this->attendance = array();
		$this->ran        = array();
		$this->inserts    = array();
		$this->locations  = array();

		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( $sql, ...$args ) {
				$flat = 1 === count( $args ) && is_array( $args[0] ) ? $args[0] : $args;
				return array(
					'sql'  => $sql,
					'args' => $flat,
				);
			}
		);
		$wpdb->shouldReceive( 'get_row' )->andReturnUsing(
			function ( $q ) {
				$this->ran[] = $q['sql'];
				if ( false !== strpos( $q['sql'], 'window_date = %s ORDER BY id DESC' ) ) {
					return $this->window_row;
				}
				if ( false !== strpos( $q['sql'], 'INNER JOIN %i e ON b.environment_id' ) ) {
					return self::BOOKING === $q['args'][2] ? $this->booking_row() : null;
				}
				$this->fail( 'Unexpected get_row(): ' . $q['sql'] );
			}
		);
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $q ) {
				$this->ran[] = $q['sql'];
				if ( false !== strpos( $q['sql'], 'WHERE ba.booking_id = %d' ) ) {
					// No audience on the booking: its participants are added directly.
					return array();
				}
				if ( false !== strpos( $q['sql'], 'attendance_date = %s ORDER BY checked_at' ) ) {
					return $this->attendance;
				}
				$this->fail( 'Unexpected get_results(): ' . $q['sql'] );
			}
		);
		$wpdb->shouldReceive( 'get_col' )->andReturnUsing(
			function ( $q ) {
				$this->ran[] = $q['sql'];
				if ( false !== strpos( $q['sql'], 'SELECT user_id FROM %i WHERE booking_id = %d' ) ) {
					return array( '7', (string) self::USER );
				}
				$this->fail( 'Unexpected get_col(): ' . $q['sql'] );
			}
		);
		$wpdb->shouldReceive( 'query' )->andReturnUsing(
			function ( $q ) {
				$this->ran[] = $q['sql'];
				if ( 0 === strpos( $q['sql'], 'INSERT IGNORE INTO %i (booking_id, user_id, attendance_date, checked_at, method)' ) ) {
					$this->inserts[] = $q['args'];
					return 1;
				}
				$this->fail( 'Unexpected query(): ' . $q['sql'] );
			}
		);

		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']   = $wpdb;
	}

	protected function tearDown(): void {
		$_POST = array();
		unset( $_SERVER['REQUEST_METHOD'] );
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The stored window: open today on the booking, until an hour from now.
	 *
	 * @param string $geofence Stored geofence location ids, comma-separated.
	 * @return array<string, mixed>
	 */
	private function window( string $geofence = '' ): array {
		return array(
			'id'                 => self::WINDOW,
			'booking_id'         => self::BOOKING,
			'window_date'        => self::DAY,
			'rotation_seconds'   => 60,
			'opened_at'          => self::NOW - 600,
			'opened_by'          => 1,
			'closes_at'          => self::NOW + 3600,
			'closed_at'          => null,
			'closed_by'          => null,
			'geofence_locations' => $geofence,
		);
	}

	/**
	 * The stored booking: active, one day, today.
	 */
	private function booking_row(): object {
		return (object) array(
			'id'               => self::BOOKING,
			'status'           => 'active',
			'booking_date'     => self::DAY,
			'end_date'         => null,
			'description'      => 'Workshop',
			'environment_name' => 'Room 3',
		);
	}

	/**
	 * A geofenced window, with its one area stored: 500 m around a point in São Paulo.
	 */
	private function geofenced(): void {
		$this->window_row = $this->window( 'loc_a' );
		$this->locations  = array(
			array(
				'id'     => 'loc_a',
				'lat'    => -23.5,
				'lng'    => -46.6,
				'radius' => 500,
			),
		);
	}

	/**
	 * A receipt for today's window, issued now.
	 */
	private function receipt(): string {
		return AudienceCheckinReceipt::issue( self::BOOKING, self::DAY, self::WINDOW, self::NOW );
	}

	/**
	 * How many of the queries the double ran write.
	 */
	private function writes(): int {
		return count( preg_grep( '/^\s*(INSERT|UPDATE|DELETE|REPLACE)\b/i', $this->ran ) );
	}

	public function test_init_routes_before_canonical_redirects(): void {
		AudienceCheckinEndpoint::init();

		$this->assertSame( 1, has_action( 'template_redirect', array( AudienceCheckinEndpoint::class, 'route' ) ) );
	}

	public function test_a_valid_scan_redirects_to_its_receipt(): void {
		// The code the projected QR shows for the stored window right now.
		$code = AudienceCheckinCode::for_window( array_merge( $this->window(), array( 'geofence_locations' => array() ) ), self::NOW );

		$response = AudienceCheckinEndpoint::handle_scan( $code, self::NOW );

		$this->assertSame(
			array( 'redirect' => 'https://example.org/?ffc_checkin_r=' . rawurlencode( $this->receipt() ) ),
			$response,
			'A live code is exchanged for the receipt of its window, issued at the scan.'
		);
	}

	public function test_an_invalid_scan_draws_the_error_with_403(): void {
		$response = AudienceCheckinEndpoint::handle_scan( 'CODE', self::NOW );

		$this->assertSame( 403, $response['status'] );
		$this->assertStringContainsString( AudienceCheckin::message( AudienceCheckin::ERROR_INVALID_CODE ), $response['page'] );
	}

	public function test_a_logged_out_visitor_is_sent_to_log_in_and_back(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( false );

		$response = AudienceCheckinEndpoint::handle_receipt( 'RECEIPT', self::NOW );

		$this->assertSame(
			'https://example.org/wp-login.php?redirect_to=' . rawurlencode( 'https://example.org/?ffc_checkin_r=RECEIPT' ),
			$response['redirect']
		);
		$this->assertSame( array(), $this->ran, 'A logged-out visitor reaches no store: neither the context nor the confirmation runs.' );
	}

	public function test_a_get_shows_the_confirmation_and_never_writes(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		$_SERVER['REQUEST_METHOD'] = 'GET';

		$response = AudienceCheckinEndpoint::handle_receipt( $this->receipt(), self::NOW );

		$this->assertSame( 200, $response['status'] );
		$this->assertFalse( $response['script'] );
		$this->assertStringContainsString( '<form method="post"', $response['page'] );
		$this->assertStringContainsString( 'Workshop', $response['page'] );
		$this->assertStringContainsString( '15/01/2027 · Room 3', $response['page'] );
		$this->assertStringContainsString( '<button type="submit" class="ffc-btn ffc-btn-primary ffc-icon-user-check ffc-btn-block ffc-checkin-submit">Confirm my attendance</button>', $response['page'] );
		$this->assertStringNotContainsString( 'data-ffc-checkin-geo', $response['page'] );
		$this->assertSame( 0, $this->writes(), 'A GET never writes.' );
	}

	public function test_a_geofenced_window_asks_for_the_location_script(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		$this->geofenced();

		$response = AudienceCheckinEndpoint::handle_receipt( $this->receipt(), self::NOW );

		$this->assertTrue( $response['script'] );
		$this->assertStringContainsString( 'data-ffc-checkin-geo="1"', $response['page'] );
		// The margin of error travels with the position (#2040), and the
		// pre-flight's telemetry names the booking.
		$this->assertStringContainsString( 'name="ffc_accuracy"', $response['page'] );
		$this->assertSame( self::BOOKING, $response['booking'] );
	}

	public function test_a_get_by_someone_already_present_says_so(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		$this->attendance = array(
			array(
				'id'              => 1,
				'booking_id'      => self::BOOKING,
				'user_id'         => self::USER,
				'attendance_date' => self::DAY,
				'checked_at'      => self::NOW - 600,
				'method'          => 'qr',
				'marked_by'       => null,
			),
		);

		$response = AudienceCheckinEndpoint::handle_receipt( $this->receipt(), self::NOW );

		$this->assertStringContainsString( 'already recorded at 07:50', $response['page'] );
		$this->assertStringNotContainsString( '<form', $response['page'] );
	}

	public function test_a_post_without_a_valid_nonce_never_writes(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( false );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST['_wpnonce']          = 'stale';

		$response = AudienceCheckinEndpoint::handle_receipt( $this->receipt(), self::NOW );

		$this->assertSame( 403, $response['status'] );
		$this->assertSame( array(), $this->ran, 'A refused nonce reaches no store, the attendance write included.' );
	}

	public function test_a_post_confirms_with_the_posted_position(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		// The window asks for a location, so only the posted position lets the
		// confirmation through: without it the check-in is refused.
		$this->geofenced();
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'_wpnonce'     => 'n',
			'ffc_lat'      => '-23.5',
			'ffc_lng'      => '-46.6',
			'ffc_accuracy' => '12',
		);

		$response = AudienceCheckinEndpoint::handle_receipt( $this->receipt(), self::NOW );

		$this->assertSame( 200, $response['status'] );
		$this->assertStringContainsString( 'Recorded at 08:00', $response['page'] );
		$this->assertSame(
			array( array( 'wp_ffc_audience_attendance', self::BOOKING, self::USER, self::DAY, self::NOW, 'qr' ) ),
			$this->inserts,
			'Exactly one attendance row is written, for this user, today, by QR.'
		);
	}

	public function test_a_refused_confirmation_draws_its_reason(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		$this->geofenced();
		$_SERVER['REQUEST_METHOD'] = 'POST';
		// A precise reading far from the event's area.
		$_POST = array(
			'_wpnonce'     => 'n',
			'ffc_lat'      => '0',
			'ffc_lng'      => '0',
			'ffc_accuracy' => '5',
		);

		$response = AudienceCheckinEndpoint::handle_receipt( $this->receipt(), self::NOW );

		$this->assertSame( 403, $response['status'] );
		$this->assertStringContainsString( AudienceCheckin::message( AudienceCheckin::ERROR_OUTSIDE_AREA ), $response['page'] );
		$this->assertSame( 0, $this->writes(), 'A refused confirmation writes nothing.' );
	}
}
