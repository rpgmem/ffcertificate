<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Audience\AudiencePresenceAttestation;
use FreeFormCertificate\Audience\AudiencePresenceCode;
use FreeFormCertificate\Audience\AudiencePresenceTicket;
use FreeFormCertificate\Audience\AudienceAttendanceTarget;
use FreeFormCertificate\Audience\AudienceTerminalReading;
use FreeFormCertificate\Audience\AudienceTerminalRestController;
use FreeFormCertificate\Core\Base45;
use FreeFormCertificate\Core\EcdsaP256;
use FreeFormCertificate\Core\SigningKeys;
use FreeFormCertificate\Tests\Support\ActivityLogBuffer;
use FreeFormCertificate\Tests\Support\FakeCredentials;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * A terminal reading a presence code (#2745): what is recorded, what is
 * refused and with which reason, and that a refusal names nobody.
 *
 * Codes are built the way the app builds them, signed by a device key this
 * test generates, so the reading goes through the one real verifier.
 *
 * @covers \FreeFormCertificate\Audience\AudienceCheckinPolicy
 * @covers \FreeFormCertificate\Audience\AudienceTerminalReading
 * @covers \FreeFormCertificate\Audience\AudienceTerminalRestController::sanitize_reading
 * @covers \FreeFormCertificate\Audience\AudienceTerminalRestController::sanitize_queue
 * @covers \FreeFormCertificate\Audience\AudienceCheckinWindowReader::covering
 * @covers \FreeFormCertificate\Audience\AudienceCheckinWindowReader::opened_by
 * @covers \FreeFormCertificate\Audience\AudiencePresenceReader::revoked_key_ids_between
 */
class AudienceTerminalReadingTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** 2027-01-15 08:00:00 UTC, a Friday. */
	private const NOW = 1800000000;

	private const TODAY = '2027-01-15';

	private const BOOKING = 11;

	private const USER = 7;

	/** @var array<string, mixed> */
	private array $options = array();

	/** @var array{public: string, secret: string} */
	private array $device;

	/** @var object */
	private $db;

	/** @var mixed */
	private $wpdb_backup;

	private bool $had_wpdb = false;

	/** @var array<int, object> */
	private array $bookings = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->options = array(
			'ffc_settings' => array(
				'date_format' => 'Y-m-d',
				'time_format' => 'H:i',
			),
		);

		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_salt' )->justReturn( FakeCredentials::WP_SALT );
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );
		Functions\when( 'wp_date' )->alias( static fn( string $format, ?int $ts = null ) => gmdate( $format, $ts ?? time() ) );
		Functions\when( 'date_i18n' )->alias( static fn( string $format, $ts = null ) => gmdate( $format, (int) ( $ts ?? time() ) ) );
		Functions\when( 'get_option' )->alias(
			fn( $key, $default = false ) => array_key_exists( $key, $this->options ) ? $this->options[ $key ] : $default
		);
		Functions\when( 'update_option' )->alias(
			function ( $key, $value ) {
				$this->options[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'wp_cache_get' )->alias(
			fn( $key ) => 1 === preg_match( '/^id_(\d+)$/', (string) $key, $m ) && isset( $this->bookings[ (int) $m[1] ] ) ? $this->bookings[ (int) $m[1] ] : false
		);
		Functions\when( 'wp_cache_set' )->justReturn( true );

		$this->device = (array) EcdsaP256::generate();
		$this->db     = $this->fake_db();

		$this->had_wpdb = array_key_exists( 'wpdb', $GLOBALS );
		if ( $this->had_wpdb ) {
			$this->wpdb_backup = $GLOBALS['wpdb'];
		}
		$GLOBALS['wpdb'] = $this->db;

		$this->bookings[ self::BOOKING ] = (object) array(
			'id'           => self::BOOKING,
			'booking_date' => self::TODAY,
			'end_date'     => null,
			'start_time'   => '09:00:00',
			'end_time'     => '17:00:00',
			'status'       => 'active',
		);
		$this->db->members[ self::BOOKING ] = array( self::USER );
		$this->db->window                   = $this->window( self::NOW - 600, self::NOW + 3600 );
	}

	protected function tearDown(): void {
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_a_member_is_recorded_once_with_the_terminal_method(): void {
		$result = $this->read( $this->code( self::BOOKING ) );

		$this->assertSame( AudienceTerminalReading::RECORDED, $result['status'], $result['message'] );
		$this->assertSame( 'Maria S.', $result['name'] );
		$this->assertFalse( $result['open'], 'an enrolled member is not told the event is open' );
		$this->assertNotSame( '', $result['time'], 'the result shows when it was recorded' );
		$this->assertCount( 1, $this->db->attendance );
		$this->assertSame( 'terminal', $this->db->attendance[0]['method'] );
		$this->assertSame( self::TODAY, $this->db->attendance[0]['attendance_date'] );

		$again = $this->read( $this->code( self::BOOKING ) );
		$this->assertSame( AudienceTerminalReading::ALREADY, $again['status'] );
		$this->assertSame( 'Attendance already recorded at 08:00.', $again['message'] );
		$this->assertCount( 1, $this->db->attendance, 'a second reading records nothing' );
	}

	public function test_a_ticket_admits_someone_the_booking_list_does_not_name(): void {
		$this->db->members[ self::BOOKING ] = array();
		$ticket                             = (string) AudiencePresenceTicket::issue( self::USER, AudienceAttendanceTarget::TYPE, self::BOOKING, self::NOW + 86400 );

		$this->assertSame( 'ffc_terminal_not_enrolled', $this->read( $this->code( self::BOOKING ) )['code'] );
		$this->assertSame( AudienceTerminalReading::RECORDED, $this->read( $this->code( self::BOOKING, $ticket ) )['status'] );
	}

	public function test_an_open_event_admits_someone_who_is_not_enrolled(): void {
		$this->db->members[ self::BOOKING ]            = array();
		$this->bookings[ self::BOOKING ]->checkin_open = '1';

		$result = $this->read( $this->code( self::BOOKING ) );

		$this->assertSame( AudienceTerminalReading::RECORDED, $result['status'], $result['message'] );
		$this->assertTrue( $result['open'], 'the screen says the attendance counts without enrolment' );
		$this->assertCount( 1, $this->db->attendance );
	}

	public function test_an_event_with_the_terminal_path_off_refuses_every_reading(): void {
		$this->bookings[ self::BOOKING ]->checkin_terminal = '0';

		$result = $this->read( $this->code( self::BOOKING ) );

		$this->assertSame( 'ffc_terminal_path_off', $result['code'] );
		$this->assertSame( array(), $this->db->attendance );
	}

	public function test_another_events_code_is_refused(): void {
		$result = $this->read( $this->code( 99 ) );

		$this->assertSame( 'ffc_terminal_other_event', $result['code'] );
		$this->assertSame( array(), $this->db->attendance );
	}

	public function test_a_window_not_yet_opened_and_one_already_closed_are_told_apart(): void {
		$this->db->window = null;
		$this->assertSame( 'ffc_terminal_window_not_open', $this->read( $this->code( self::BOOKING ) )['code'] );

		$this->db->window = $this->window( self::NOW - 7200, self::NOW - 60 );
		$this->assertSame( 'ffc_terminal_window_closed', $this->read( $this->code( self::BOOKING ) )['code'] );
		$this->assertSame( array(), $this->db->attendance );
	}

	public function test_an_event_with_no_check_in_today_is_refused(): void {
		$this->bookings[ self::BOOKING ]->booking_date = '2027-01-16';
		$this->assertSame( 'ffc_terminal_not_today', $this->read( $this->code( self::BOOKING ) )['code'] );

		$this->bookings[ self::BOOKING ]->booking_date = self::TODAY;
		$this->bookings[ self::BOOKING ]->status       = 'cancelled';
		$this->assertSame( 'ffc_terminal_not_today', $this->read( $this->code( self::BOOKING ) )['code'] );
	}

	public function test_a_revoked_phone_an_old_code_and_noise_are_refused_by_the_verifier(): void {
		$code = $this->code( self::BOOKING );
		$key  = AudiencePresenceAttestation::key_id( $this->device['public'] );

		$this->db->revoked = array( $key => self::NOW - 60 );
		$this->assertSame( 'ffc_presence_code_revoked', $this->read( $code )['code'] );

		$this->db->revoked = array();
		$this->assertSame( 'ffc_presence_code_stale', $this->read( $this->code( self::BOOKING, '', self::NOW - 200 ) )['code'] );
		$this->assertSame( 'ffc_presence_code_malformed', $this->read( 'HELLO' )['code'] );
	}

	public function test_a_refusal_names_nobody(): void {
		foreach ( array( $this->code( 99 ), 'HELLO' ) as $text ) {
			$result = $this->read( $text );
			$this->assertSame( AudienceTerminalReading::REFUSED, $result['status'] );
			$this->assertSame( '', $result['name'] );
			$this->assertStringNotContainsString( 'Maria', $result['message'] );
		}
	}

	public function test_the_route_keeps_only_the_qr_alphanumeric_set(): void {
		$this->assertSame( 'FFO1AB CD$%*+-./:', AudienceTerminalRestController::sanitize_reading( "  ffo1ab cd\$%*+-./:<>\"'_\n" ) );
		$this->assertSame( '', AudienceTerminalRestController::sanitize_reading( array( 'FFP1' ) ) );
	}

	// ---- Offline queue --------------------------------------------------

	public function test_a_queued_reading_is_recorded_as_of_the_moment_it_was_read(): void {
		$at               = self::NOW - 1800;
		$this->db->window = $this->window( $at - 600, $at + 600 );

		$result = $this->queued( $this->code( self::BOOKING, '', $at ), $at );

		$this->assertSame( AudienceTerminalReading::RECORDED, $result['status'], $result['message'] );
		$this->assertSame( $at, $this->db->attendance[0]['checked_at'], 'the moment of the reading, not of the sync' );
		$this->assertSame( 'ffc_terminal_window_closed', $this->read( $this->code( self::BOOKING ) )['code'], 'that window has closed by now' );
	}

	public function test_a_reading_before_a_revocation_stands_and_one_after_it_is_refused(): void {
		$at                = self::NOW - 1800;
		$this->db->window  = $this->window( $at - 600, self::NOW + 600 );
		$key               = AudiencePresenceAttestation::key_id( $this->device['public'] );
		$this->db->revoked = array( $key => $at + 60 );

		$this->assertSame( AudienceTerminalReading::RECORDED, $this->queued( $this->code( self::BOOKING, '', $at ), $at )['status'] );
		$this->db->attendance = array();
		$later                = $at + 120;
		$this->assertSame( 'ffc_presence_code_revoked', $this->queued( $this->code( self::BOOKING, '', $later ), $later )['code'] );
	}

	public function test_a_window_closed_and_reopened_counts_the_moment_inside_either(): void {
		$this->db->windows = array( array_merge( $this->window( self::NOW - 3500, self::NOW - 2500 ), array( 'id' => 2 ) ) );
		$this->db->window  = $this->window( self::NOW - 600, self::NOW + 600 );

		$this->assertSame( AudienceTerminalReading::RECORDED, $this->queued( $this->code( self::BOOKING, '', self::NOW - 3000 ), self::NOW - 3000 )['status'] );
		$this->db->attendance = array();
		$this->assertSame( 'ffc_terminal_window_closed', $this->queued( $this->code( self::BOOKING, '', self::NOW - 2000 ), self::NOW - 2000 )['code'], 'between the two windows' );
		$this->assertSame( 'ffc_terminal_window_not_open', $this->queued( $this->code( self::BOOKING, '', self::NOW - 3550 ), self::NOW - 3550 )['code'], 'before the first one' );
	}

	public function test_a_moment_the_terminal_cannot_vouch_for_is_refused(): void {
		$future = self::NOW + AudiencePresenceCode::SKEW + 1;
		$this->assertSame( 'ffc_terminal_reading_time', $this->queued( $this->code( self::BOOKING, '', $future ), $future )['code'] );
		$before = self::NOW - 3600 - AudiencePresenceCode::SKEW - 1;
		$this->assertSame( 'ffc_terminal_reading_time', $this->queued( $this->code( self::BOOKING, '', $before ), $before )['code'], 'before the terminal was paired' );
		$this->assertSame( array(), $this->db->attendance );
	}

	public function test_a_batch_sent_twice_records_once_and_logs_the_refusals(): void {
		Functions\when( 'get_option' )->alias(
			fn( $key, $default = false ) => 'ffc_settings' === $key ? array_merge( $this->options['ffc_settings'], ActivityLogBuffer::ENABLED_SETTINGS ) : ( $this->options[ $key ] ?? $default )
		);
		ActivityLogBuffer::stub_functions();
		ActivityLogBuffer::reset();
		Functions\when( 'wp_cache_get' )->alias(
			fn( $key ) => 1 === preg_match( '/^id_(\d+)$/', (string) $key, $m ) && isset( $this->bookings[ (int) $m[1] ] ) ? $this->bookings[ (int) $m[1] ] : false
		);
		$batch = array(
			array(
				'id'   => 'r1',
				'code' => $this->code( self::BOOKING, '', self::NOW - 60 ),
				'at'   => self::NOW - 60,
			),
			array(
				'id'   => 'r2',
				'code' => $this->code( 99, '', self::NOW - 30 ),
				'at'   => self::NOW - 30,
			),
		);

		$first = AudienceTerminalReading::sync( $this->terminal(), $batch, self::NOW );
		$this->assertSame( array( 'r1', 'r2' ), array_keys( $first ) );
		$this->assertSame( AudienceTerminalReading::RECORDED, $first['r1']['status'] );
		$this->assertSame( 'ffc_terminal_other_event', $first['r2']['code'] );

		$again = AudienceTerminalReading::sync( $this->terminal(), $batch, self::NOW + 5 );
		$this->assertSame( AudienceTerminalReading::ALREADY, $again['r1']['status'] );
		$this->assertCount( 1, $this->db->attendance, 'replaying the batch records nothing twice' );

		$refusals = array_values( array_filter( ActivityLogBuffer::contexts(), static fn( array $c ): bool => 'terminal_readings_refused' === $c['action'] ) );
		$this->assertCount( 2, $refusals );
		$this->assertSame( array( 'ffc_terminal_other_event' => 1 ), $refusals[0]['context']['reasons'] );
		$this->assertStringNotContainsString( 'FFP1', (string) wp_json_encode( $refusals ), 'the log never carries a code' );
	}

	public function test_a_revoked_terminal_sends_what_it_read_before_and_the_rest_is_refused_and_counted(): void {
		$this->db->window = $this->window( self::NOW - 600, self::NOW + 600 );
		$terminal         = array_merge( $this->terminal(), array( 'revoked_at' => self::NOW - 100, 'revoked_by' => 5 ) );
		$batch            = array(
			array(
				'id'   => 'before',
				'code' => $this->code( self::BOOKING, '', self::NOW - 200 ),
				'at'   => self::NOW - 200,
			),
			array(
				'id'   => 'after',
				'code' => $this->code( self::BOOKING, '', self::NOW - 50 ),
				'at'   => self::NOW - 50,
			),
		);

		$results = AudienceTerminalReading::sync( $terminal, $batch, self::NOW );

		$this->assertSame( AudienceTerminalReading::RECORDED, $results['before']['status'], $results['before']['message'] );
		$this->assertSame( AudienceTerminalReading::AFTER_REVOCATION, $results['after']['code'] );
		$this->assertSame( '', $results['after']['name'], 'a refusal names nobody' );
		$this->assertCount( 1, $this->db->attendance );
		$this->assertSame( 1, $this->db->refused_after_revocation, 'counted on the terminal' );
	}

	public function test_the_queue_route_keeps_well_formed_readings_only(): void {
		$queue = AudienceTerminalRestController::sanitize_queue(
			array(
				array(
					'id'   => 'ok_1',
					'code' => 'ffp1abc',
					'at'   => '1800000000',
				),
				array(
					'id'   => str_repeat( 'x', 41 ),
					'code' => 'FFP1A',
					'at'   => 1,
				),
				array(
					'id'   => 'no-time',
					'code' => 'FFP1A',
				),
				'not a reading',
			)
		);
		$this->assertSame(
			array(
				array(
					'id'   => 'ok_1',
					'code' => 'FFP1ABC',
					'at'   => 1800000000,
				),
			),
			$queue
		);
		$this->assertCount( AudienceTerminalReading::SYNC_BATCH, AudienceTerminalRestController::sanitize_queue( array_fill( 0, 150, array( 'id' => 'a', 'code' => 'FFP1A', 'at' => 1 ) ) ) );
	}

	// ---- Helpers ----------------------------------------------------------

	/**
	 * Read a code at the test's terminal, paired for {@see self::BOOKING}.
	 *
	 * @param string $text Code text.
	 * @return array{status: string, code: string, message: string, name: string}
	 */
	private function read( string $text ): array {
		return AudienceTerminalReading::read( $this->terminal(), $text, self::NOW );
	}

	/**
	 * Read a code the terminal queued at a moment.
	 *
	 * @param string $text Code text.
	 * @param int    $at   When the terminal read it.
	 * @return array{status: string, code: string, message: string, name: string}
	 */
	private function queued( string $text, int $at ): array {
		return AudienceTerminalReading::read_queued( $this->terminal(), $text, $at, self::NOW );
	}

	/**
	 * The test's terminal, paired an hour ago for {@see self::BOOKING}.
	 *
	 * @return array<string, mixed>
	 */
	private function terminal(): array {
		return array(
			'id'           => 4,
			'token_hash'   => str_repeat( 'a', 64 ),
			'device_hash'  => str_repeat( 'b', 64 ),
			'label'        => '',
			'bookings'     => array( self::BOOKING ),
			'paired_by'    => 5,
			'paired_at'    => self::NOW - 3600,
			'expires_at'   => self::NOW + 36000,
			'removed'      => array(),
			'last_seen_at' => null,
			'queued'       => 0,
			'readings'     => 0,
			'refused_after_revocation' => 0,
			'revoked_at'   => null,
			'revoked_by'   => null,
		);
	}

	/**
	 * A presence code for user 7, built the way the app builds it.
	 *
	 * @param int    $target_id Booking.
	 * @param string $ticket    Raw ticket, or '' for none.
	 * @param int    $read_at   Reading time.
	 */
	private function code( int $target_id, string $ticket = '', int $read_at = self::NOW ): string {
		$issued      = AudiencePresenceAttestation::issue( self::USER, 'Maria S.', $this->device['public'], $read_at - 60 );
		$attestation = SigningKeys::unb64( (string) $issued['attestation'] );
		$body        = chr( AudiencePresenceCode::VERSION )
			. pack( 'n', strlen( $attestation ) ) . $attestation
			. chr( 1 ) . pack( 'NN', $target_id, $read_at )
			. chr( strlen( $ticket ) ) . $ticket;
		$signature   = (string) EcdsaP256::sign( AudiencePresenceCode::DOMAIN . $body, $this->device['secret'] );
		return AudiencePresenceCode::PREFIX . Base45::encode( $body . $signature );
	}

	/**
	 * A check-in window row for today.
	 *
	 * @param int $opened_at Opened at.
	 * @param int $closes_at Closes at.
	 * @return array<string, mixed>
	 */
	private function window( int $opened_at, int $closes_at ): array {
		return array(
			'id'                 => 3,
			'booking_id'         => self::BOOKING,
			'window_date'        => self::TODAY,
			'rotation_seconds'   => 30,
			'opened_at'          => $opened_at,
			'opened_by'          => 5,
			'closes_at'          => $closes_at,
			'closed_at'          => null,
			'closed_by'          => null,
			'geofence_locations' => '',
		);
	}

	/**
	 * A `$wpdb` double answering the queries a reading makes.
	 */
	private function fake_db(): object {
		return new class() extends \wpdb {
			/** Skips the parent's connection. */
			public function __construct() {
				$this->prefix = 'wp_';
			}

			/** @var array<string, int> Revoked key ids and when. */
			public array $revoked = array();

			/** @var array<int, list<int>> */
			public array $members = array();

			/** @var array<string, mixed>|null The day's latest window. */
			public ?array $window = null;

			/** @var list<array<string, mixed>> Every window of the day, oldest first. */
			public array $windows = array();

			/** @var list<array<string, mixed>> */
			public array $attendance = array();

			public int $readings_counted = 0;

			public int $refused_after_revocation = 0;

			/** @var array<string, array{0: string, 1: list<mixed>}> */
			private array $prepared = array();

			/**
			 * @param string $query   Query.
			 * @param mixed  ...$args Arguments.
			 */
			public function prepare( $query, ...$args ) {
				$handle                    = 'prepared#' . count( $this->prepared );
				$this->prepared[ $handle ] = array( (string) $query, $args );
				return $handle;
			}

			/**
			 * @param string $handle Statement.
			 * @param int    $x      Unused.
			 * @return list<string>
			 */
			public function get_col( $handle = null, $x = 0 ) {
				list( $query, $args ) = $this->prepared[ $handle ];
				if ( false !== strpos( $query, 'SELECT key_id FROM %i WHERE revoked_at IS NOT NULL' ) ) {
					return array_keys( array_filter( $this->revoked, static fn( int $at ): bool => $at >= (int) $args[1] && $at <= (int) $args[2] ) );
				}
				if ( false !== strpos( $query, 'SELECT user_id FROM %i WHERE booking_id = %d' ) ) {
					return array_map( 'strval', $this->members[ (int) $args[1] ] ?? array() );
				}
				throw new \LogicException( 'Unknown query: ' . $query );
			}

			/**
			 * @param string $handle Statement.
			 * @param int    $x      Unused.
			 * @param int    $y      Unused.
			 * @return string|null
			 */
			public function get_var( $handle = null, $x = 0, $y = 0 ) {
				list( $query, $args ) = $this->prepared[ $handle ];
				// The activity log asks whether the custom-field table exists;
				// it does not here, so no field is treated as sensitive.
				if ( 0 === strpos( $query, 'SHOW TABLES LIKE' ) ) {
					return null;
				}
				if ( 0 === strpos( $query, 'SELECT COUNT(*) FROM %i WHERE booking_id = %d AND window_date = %s AND opened_at <= %d' ) ) {
					$windows = null === $this->window ? $this->windows : array_merge( $this->windows, array( $this->window ) );
					return (string) count( array_filter( $windows, static fn( array $w ): bool => (int) $w['opened_at'] <= (int) $args[3] ) );
				}
				throw new \LogicException( 'Unknown query: ' . $query );
			}

			/**
			 * @param string $handle Statement.
			 * @param string $output Unused.
			 * @param int    $y      Unused.
			 * @return array<string, mixed>|null
			 */
			public function get_row( $handle = null, $output = OBJECT, $y = 0 ) {
				list( $query ) = $this->prepared[ $handle ];
				if ( false !== strpos( $query, 'WHERE booking_id = %d AND window_date = %s ORDER BY id DESC LIMIT 1' ) ) {
					return $this->window;
				}
				throw new \LogicException( 'Unknown query: ' . $query );
			}

			/**
			 * @param string $handle Statement.
			 * @param string $output Unused.
			 * @return list<mixed>
			 */
			public function get_results( $handle = null, $output = OBJECT ) {
				list( $query, $args ) = $this->prepared[ $handle ];
				if ( false !== strpos( $query, 'window_date = %s AND opened_at <= %d ORDER BY id DESC' ) ) {
					$windows = null === $this->window ? $this->windows : array_merge( $this->windows, array( $this->window ) );
					return array_reverse( array_values( array_filter( $windows, static fn( array $w ): bool => (int) $w['opened_at'] <= (int) $args[3] ) ) );
				}
				if ( false !== strpos( $query, 'INNER JOIN %i ba ON a.id = ba.audience_id' ) ) {
					return array();
				}
				if ( false !== strpos( $query, 'SELECT id, booking_id, user_id, attendance_date, checked_at' ) ) {
					return $this->attendance;
				}
				throw new \LogicException( 'Unknown query: ' . $query );
			}

			/**
			 * @param string $handle Statement.
			 * @return int|false
			 */
			public function query( $handle ) {
				list( $query, $args ) = $this->prepared[ $handle ];
				if ( 0 === strpos( $query, 'INSERT IGNORE INTO %i (booking_id, user_id, attendance_date, checked_at, method)' ) ) {
					foreach ( $this->attendance as $row ) {
						if ( $row['user_id'] === $args[2] && $row['attendance_date'] === $args[3] ) {
							return 0;
						}
					}
					$this->attendance[] = array(
						'id'              => count( $this->attendance ) + 1,
						'booking_id'      => $args[1],
						'user_id'         => $args[2],
						'attendance_date' => $args[3],
						'checked_at'      => $args[4],
						'method'          => $args[5],
						'marked_by'       => null,
					);
					return 1;
				}
				if ( 0 === strpos( $query, 'UPDATE %i SET readings = readings + 1 WHERE id = %d' ) ) {
					++$this->readings_counted;
					return 1;
				}
				if ( 0 === strpos( $query, 'UPDATE %i SET refused_after_revocation = refused_after_revocation + %d WHERE id = %d' ) ) {
					$this->refused_after_revocation += (int) $args[1];
					return 1;
				}
				throw new \LogicException( 'Unknown query: ' . $query );
			}
		};
	}
}
