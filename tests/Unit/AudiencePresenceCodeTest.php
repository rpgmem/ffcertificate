<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Audience\AudienceAttendanceTarget;
use FreeFormCertificate\Audience\AudiencePresenceAttestation;
use FreeFormCertificate\Audience\AudiencePresenceCode;
use FreeFormCertificate\Audience\AudiencePresenceEvents;
use FreeFormCertificate\Audience\AudiencePresenceKeys;
use FreeFormCertificate\Audience\AudiencePresenceTicket;
use FreeFormCertificate\Core\Base45;
use FreeFormCertificate\Core\EcdsaP256;
use FreeFormCertificate\Core\SigningKeys;
use FreeFormCertificate\Tests\Support\FakeCredentials;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * The presence code (#2742): Base45, the event ticket, the one verifier and
 * the events list the app keeps offline.
 *
 * One code here was made by the app's own script, under WebCrypto, so the
 * byte layout and the signature shape the phone writes are pinned against the
 * verifier that reads them. The rest are built in PHP with the same layout.
 *
 * @covers \FreeFormCertificate\Core\Base45
 * @covers \FreeFormCertificate\Audience\AudiencePresenceCode
 * @covers \FreeFormCertificate\Audience\AudiencePresenceTicket
 * @covers \FreeFormCertificate\Audience\AudiencePresenceEvents
 * @covers \FreeFormCertificate\Audience\AudienceQueryService::find_open_checkin_bookings
 */
class AudiencePresenceCodeTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const NOW = 1800000000;

	/** A code `ffc-presence-app.js` built, read at NOW - 10 for booking 42 of user 7, with a ticket. */
	private const SCRIPT_CODE = 'FFP1V505LJ*D0Y86000-*0K UICV$TUM.UGJF63682MR38N1VFGDZU3JC3.:M/QQV6O*SM$RSJBQ.S6E$M-/7*I9%6RQ4BRB5E7F%OKQNJGYPTQ7A3M%MMFPDUYONPD$SNA21GECMED+34Z%5QBILI2U%I.$8 HD0.33JMBXC%74SH7*25:%N1L3B 0FW2BH6QUTXR0RBW/94J5IY%OF*NS670C498QX6E+0RS180U1L1SPIE000.00FPDKOQ$AA*D0Z86000.00000700HPD/L42UR85CC/BHOL%-7RFQ2.PU3NO3V9KHNRR9/N/%1MMU*9K.4VOOBRGE8S3CE8JX4-EJ6TBD4QNVN 8MM-H$87 03J+HM*1* A7ULE1L: H8BB*J0+XVWTKBW1SYUKT1Q9BDP0NXLJ8EUGHPIG4-T5.AFDMK2KO:SH30P/BKL05%UJ+2N3KJ*PSBG5H0E7Q$OU';

	/** The site key the script's code was attested under, as key `p1`. */
	private const SCRIPT_SITE_KEY = 'BKuK2S5iFEMntdf5GCk1h7vr1968vvmBmAoDm85wcCaI3fejfA5qHwJsS_H58PyVqncMWlOm0L92WTsKUjqGCjI';

	/** @var array<string, mixed> */
	private array $options = array();

	/** @var array{public: string, secret: string} */
	private array $device;

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
		Functions\when( 'wp_date' )->alias( static fn( string $format, int $ts ) => gmdate( $format, $ts ) );
		Functions\when( 'get_option' )->alias(
			fn( $key, $default = false ) => array_key_exists( $key, $this->options ) ? $this->options[ $key ] : $default
		);
		Functions\when( 'update_option' )->alias(
			function ( $key, $value ) {
				$this->options[ $key ] = $value;
				return true;
			}
		);

		$this->device = (array) EcdsaP256::generate();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ---- Base45 -----------------------------------------------------------

	public function test_base45_matches_the_rfc_examples_both_ways(): void {
		$examples = array(
			'AB'      => 'BB8',
			'Hello!!' => '%69 VD92EX0',
			'base-45' => 'UJCLQE7W581',
			'ietf!'   => 'QED8WEX0',
			''        => '',
		);
		foreach ( $examples as $bytes => $text ) {
			$this->assertSame( $text, Base45::encode( (string) $bytes ), (string) $bytes );
			$this->assertSame( (string) $bytes, Base45::decode( $text ), $text );
		}
	}

	public function test_base45_refuses_text_that_cannot_be_bytes(): void {
		$this->assertNull( Base45::decode( 'GGW' ), 'a triple above 65535' );
		$this->assertNull( Base45::decode( 'B' ), 'a single trailing character' );
		$this->assertNull( Base45::decode( 'bb8' ), 'a character outside the alphabet' );
		$this->assertNull( Base45::decode( ':::' ), 'a triple above 65535' );
	}

	public function test_base45_round_trips_every_byte(): void {
		$bytes = '';
		for ( $i = 0; $i < 256; $i++ ) {
			$bytes .= chr( $i );
		}
		$this->assertSame( $bytes, Base45::decode( Base45::encode( $bytes ) ) );
		$this->assertSame( substr( $bytes, 1 ), Base45::decode( Base45::encode( substr( $bytes, 1 ) ) ) );
	}

	// ---- The script's own code --------------------------------------------

	public function test_a_code_made_by_the_app_script_verifies(): void {
		$this->options[ AudiencePresenceKeys::OPTION ] = array(
			'active' => 'p1',
			'next'   => 2,
			'keys'   => array(
				'p1' => array(
					'public'  => self::SCRIPT_SITE_KEY,
					'secret'  => '',
					'created' => self::NOW - 86400,
					'retired' => 0,
				),
			),
		);

		$read = AudiencePresenceCode::verify( self::SCRIPT_CODE, self::NOW );

		$this->assertIsArray( $read, is_wp_error( $read ) ? $read->get_error_code() : '' );
		$this->assertSame( 7, $read['user_id'] );
		$this->assertSame( 'Maria S.', $read['name'] );
		$this->assertSame( AudienceAttendanceTarget::TYPE, $read['target_type'] );
		$this->assertSame( 42, $read['target_id'] );
		$this->assertSame( self::NOW - 10, $read['read_at'] );
		$this->assertTrue( $read['ticket'] );
		$this->assertMatchesRegularExpression( '/^[0-9A-Z $%*+\-.\/:]+$/', self::SCRIPT_CODE, 'the QR alphanumeric set only' );
	}

	// ---- The verifier -----------------------------------------------------

	public function test_a_fresh_code_verifies_with_or_without_a_ticket(): void {
		$plain = AudiencePresenceCode::verify( $this->code( 42, self::NOW ), self::NOW );
		$this->assertIsArray( $plain );
		$this->assertFalse( $plain['ticket'] );
		$this->assertSame( 'Maria S.', $plain['name'] );
		$this->assertSame( AudiencePresenceAttestation::key_id( $this->device['public'] ), $plain['key_id'] );

		$ticket  = AudiencePresenceTicket::issue( 7, AudienceAttendanceTarget::TYPE, 42, self::NOW + 3600 );
		$ticketed = AudiencePresenceCode::verify( $this->code( 42, self::NOW, (string) $ticket ), self::NOW );
		$this->assertIsArray( $ticketed );
		$this->assertTrue( $ticketed['ticket'] );
	}

	public function test_a_reading_is_accepted_one_rotation_old_give_or_take_the_skew(): void {
		$oldest = self::NOW - AudiencePresenceCode::ROTATION - AudiencePresenceCode::SKEW;
		$newest = self::NOW + AudiencePresenceCode::SKEW;

		$this->assertIsArray( AudiencePresenceCode::verify( $this->code( 42, $oldest ), self::NOW ) );
		$this->assertIsArray( AudiencePresenceCode::verify( $this->code( 42, $newest ), self::NOW ) );
		$this->assertSame( 'ffc_presence_code_stale', $this->error( $this->code( 42, $oldest - 1 ) ) );
		$this->assertSame( 'ffc_presence_code_future', $this->error( $this->code( 42, $newest + 1 ) ) );
	}

	public function test_a_revoked_key_is_refused(): void {
		$code = $this->code( 42, self::NOW );
		$this->assertSame(
			'ffc_presence_code_revoked',
			$this->error( $code, array( AudiencePresenceAttestation::key_id( $this->device['public'] ) ) )
		);
		$this->assertIsArray( AudiencePresenceCode::verify( $code, self::NOW, array( 'another-key' ) ) );
	}

	public function test_a_code_signed_by_another_phone_is_refused(): void {
		$other = (array) EcdsaP256::generate();
		$this->assertSame( 'ffc_presence_code_signature', $this->error( $this->code( 42, self::NOW, '', $other['secret'] ) ) );
	}

	public function test_a_changed_byte_is_refused(): void {
		$raw = (string) Base45::decode( substr( $this->code( 42, self::NOW ), 4 ) );
		$at  = strlen( $raw ) - 64 - 5;
		$raw = substr_replace( $raw, chr( ord( $raw[ $at ] ) ^ 1 ), $at, 1 );

		$this->assertSame( 'ffc_presence_code_signature', $this->error( AudiencePresenceCode::PREFIX . Base45::encode( $raw ) ) );
	}

	public function test_a_ticket_for_another_event_or_person_is_refused(): void {
		$other_event  = (string) AudiencePresenceTicket::issue( 7, AudienceAttendanceTarget::TYPE, 43, self::NOW + 3600 );
		$other_person = (string) AudiencePresenceTicket::issue( 8, AudienceAttendanceTarget::TYPE, 42, self::NOW + 3600 );

		$this->assertSame( 'ffc_presence_ticket_mismatch', $this->error( $this->code( 42, self::NOW, $other_event ) ) );
		$this->assertSame( 'ffc_presence_ticket_mismatch', $this->error( $this->code( 42, self::NOW, $other_person ) ) );
	}

	public function test_an_expired_or_forged_ticket_is_refused(): void {
		$expired = (string) AudiencePresenceTicket::issue( 7, AudienceAttendanceTarget::TYPE, 42, self::NOW - 1 );
		$valid   = (string) AudiencePresenceTicket::issue( 7, AudienceAttendanceTarget::TYPE, 42, self::NOW + 3600 );
		// Flip a bit of the signature's last byte: replacing it with a fixed
		// character left the ticket intact whenever the random signature
		// already ended in that character (1 run in 256).
		$forged = substr( $valid, 0, -1 ) . chr( ord( substr( $valid, -1 ) ) ^ 1 );

		$this->assertSame( 'ffc_presence_ticket_expired', $this->error( $this->code( 42, self::NOW, $expired ) ) );
		$this->assertSame( 'ffc_presence_ticket_signature', $this->error( $this->code( 42, self::NOW, $forged ) ) );
		$this->assertSame( 'ffc_presence_ticket_malformed', $this->error( $this->code( 42, self::NOW, 'short' ) ) );
	}

	public function test_a_code_for_a_certificate_form_names_that_target(): void {
		$read = AudiencePresenceCode::verify( $this->code( 40, self::NOW, '', '', null, 2 ), self::NOW );

		$this->assertIsArray( $read, is_wp_error( $read ) ? $read->get_error_code() : '' );
		$this->assertSame( \FreeFormCertificate\Frontend\CertificateFormTarget::TYPE, $read['target_type'], 'byte 2 is the type id the forms module registers (#2891)' );
		$this->assertSame( 40, $read['target_id'] );
	}

	public function test_a_ticket_is_issued_only_for_a_known_target(): void {
		$this->assertNull( AudiencePresenceTicket::issue( 7, 'appointment', 42, self::NOW + 60 ) );
		$this->assertNull( AudiencePresenceTicket::issue( 0, AudienceAttendanceTarget::TYPE, 42, self::NOW + 60 ) );
		$this->assertNull( AudiencePresenceTicket::issue( 7, AudienceAttendanceTarget::TYPE, 0, self::NOW + 60 ) );
	}

	public function test_an_expired_attestation_is_refused(): void {
		$code = $this->code( 42, self::NOW + AudiencePresenceAttestation::TTL + 1, '', '', self::NOW );
		$this->assertSame( 'ffc_presence_attestation_expired', $this->error( $code, array(), self::NOW + AudiencePresenceAttestation::TTL + 1 ) );
	}

	public function test_text_that_is_not_a_code_is_malformed(): void {
		$valid = $this->code( 42, self::NOW );
		$raw   = (string) Base45::decode( substr( $valid, 4 ) );
		$cases = array(
			'no prefix'      => substr( $valid, 4 ),
			'bad base45'     => AudiencePresenceCode::PREFIX . 'bb',
			'empty'          => AudiencePresenceCode::PREFIX,
			'version 2'      => AudiencePresenceCode::PREFIX . Base45::encode( "\x02" . substr( $raw, 1 ) ),
			'truncated'      => AudiencePresenceCode::PREFIX . Base45::encode( substr( $raw, 0, -1 ) ),
			'unknown target' => AudiencePresenceCode::PREFIX . Base45::encode( $this->with_target_type( $raw, 9 ) ),
		);
		foreach ( $cases as $label => $text ) {
			$this->assertSame( 'ffc_presence_code_malformed', $this->error( $text ), $label );
		}
	}

	public function test_surrounding_whitespace_from_a_scanner_is_ignored(): void {
		$this->assertIsArray( AudiencePresenceCode::verify( "  " . $this->code( 42, self::NOW ) . "\n", self::NOW ) );
	}

	// ---- The events list --------------------------------------------------

	public function test_an_upcoming_booking_becomes_an_event_with_its_ticket(): void {
		$event = AudiencePresenceEvents::event(
			array(
				'id'               => 42,
				'booking_date'     => '2027-01-16',
				'end_date'         => '',
				'is_all_day'       => 1,
				'description'      => '',
				'schedule_name'    => 'Library',
				'environment_name' => 'Room 2',
			),
			7,
			self::NOW
		);

		$this->assertIsArray( $event );
		$this->assertSame( 42, $event['target_id'] );
		$this->assertSame( 'Library', $event['title'] );
		$this->assertSame( 'Room 2', $event['place'] );
		$this->assertSame( '2027-01-16', $event['first_date'] );
		$this->assertSame( '2027-01-16', $event['last_date'] );
		$this->assertSame( 'All day', $event['time'] );
		$this->assertGreaterThan( self::NOW, $event['expires'] );

		$ticket = AudiencePresenceTicket::verify( SigningKeys::unb64( $event['ticket'] ), self::NOW );
		$this->assertIsArray( $ticket );
		$this->assertSame( array( AudienceAttendanceTarget::TYPE, 42, 7, $event['expires'] ), array( $ticket['target_type'], $ticket['target_id'], $ticket['user_id'], $ticket['expires'] ) );
	}

	public function test_a_booking_that_ended_or_has_no_id_is_left_out(): void {
		$ended = array(
			'id'           => 42,
			'booking_date' => '2020-01-01',
			'is_all_day'   => 1,
		);
		$this->assertNull( AudiencePresenceEvents::event( $ended, 7, self::NOW ) );
		$this->assertNull( AudiencePresenceEvents::event( array( 'booking_date' => '2027-01-16' ), 7, self::NOW ) );
		$this->assertNull( AudiencePresenceEvents::event( array( 'id' => 42 ), 7, self::NOW ) );
	}

	public function test_an_event_open_to_everyone_is_listed_for_someone_not_on_it(): void {
		$row  = static fn( int $id ): array => array(
			'id'               => (string) $id,
			'booking_date'     => '2027-01-16',
			'end_date'         => null,
			'is_all_day'       => '1',
			'description'      => 'Event ' . $id,
			'schedule_name'    => '',
			'environment_name' => 'Hall',
		);
		$wpdb = \Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn( $sql, ...$args ) => $sql );
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			static function ( string $sql ) use ( $row ): array {
				if ( false !== strpos( $sql, 'b.checkin_open = 1 AND b.checkin_terminal = 1' ) ) {
					return array( $row( 42 ), $row( 43 ) );
				}
				if ( false !== strpos( $sql, 'bu.user_id = %d OR am.user_id = %d' ) ) {
					return array( $row( 42 ) );
				}
				return array();
			}
		);
		$backup          = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = $wpdb;
		// No certificate form is open (#2893); the forms have their own test.
		Functions\when( 'get_posts' )->justReturn( array() );

		try {
			$events = AudiencePresenceEvents::for_user( 7, self::NOW );
		} finally {
			$GLOBALS['wpdb'] = $backup;
		}

		$this->assertSame( array( 42, 43 ), array_column( $events, 'target_id' ), 'The open event joins the list once, beside the person\'s own.' );
	}

	public function test_an_event_carries_its_days_and_the_days_already_recorded(): void {
		$row  = array(
			'id'               => '42',
			'booking_date'     => '2027-01-15',
			'end_date'         => '2027-01-17',
			'skipped_dates'    => '["2027-01-16"]',
			'is_all_day'       => '1',
			'description'      => 'Workshop',
			'schedule_name'    => '',
			'environment_name' => 'Hall',
		);
		$wpdb = \Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn( $sql, ...$args ) => $sql );
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			static function ( string $sql ) use ( $row ): array {
				if ( false !== strpos( $sql, 'SELECT booking_id, attendance_date' ) ) {
					return array(
						array( 'booking_id' => '42', 'attendance_date' => '2027-01-15', 'checked_at' => (string) ( self::NOW - 3600 ), 'method' => 'terminal' ),
						array( 'booking_id' => '42', 'attendance_date' => '2027-01-15', 'checked_at' => (string) self::NOW, 'method' => 'manual' ),
					);
				}
				if ( false !== strpos( $sql, 'bu.user_id = %d OR am.user_id = %d' ) ) {
					return array( $row );
				}
				return array();
			}
		);
		$backup          = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = $wpdb;
		// No certificate form is open (#2893); the forms have their own test.
		Functions\when( 'get_posts' )->justReturn( array() );

		try {
			$events = AudiencePresenceEvents::for_user( 7, self::NOW );
		} finally {
			$GLOBALS['wpdb'] = $backup;
		}

		$this->assertCount( 1, $events );
		$this->assertSame(
			array(
				array( 'date' => '2027-01-15', 'label' => '2027-01-15' ),
				array( 'date' => '2027-01-17', 'label' => '2027-01-17' ),
			),
			$events[0]['days'],
			'The counted days, a skipped day left out.'
		);
		$this->assertSame(
			array( array( 'date' => '2027-01-15', 'time' => gmdate( 'H:i', self::NOW - 3600 ) ) ),
			$events[0]['recorded'],
			'One entry per day, at the first reading.'
		);
		$this->assertSame( 'Present: 1 of 2 days', $events[0]['attendance'] );
	}

	// ---- Helpers ----------------------------------------------------------

	/**
	 * A code for user 7, built the way the app builds it.
	 *
	 * @param int    $target_id Booking.
	 * @param int    $read_at   Reading time.
	 * @param string $ticket    Raw ticket, or '' for none.
	 * @param string $secret    The signing phone's secret; this test's device by default.
	 * @param int    $issued_at When the attestation was issued; a minute before the reading by default.
	 * @param int    $type      Target type byte; 1 is a booking.
	 */
	private function code( int $target_id, int $read_at, string $ticket = '', string $secret = '', ?int $issued_at = null, int $type = 1 ): string {
		$issued      = AudiencePresenceAttestation::issue( 7, 'Maria S.', $this->device['public'], $issued_at ?? $read_at - 60 );
		$attestation = SigningKeys::unb64( (string) $issued['attestation'] );
		$body        = chr( AudiencePresenceCode::VERSION )
			. pack( 'n', strlen( $attestation ) ) . $attestation
			. chr( $type ) . pack( 'NN', $target_id, $read_at )
			. chr( strlen( $ticket ) ) . $ticket;
		$signature   = (string) EcdsaP256::sign( AudiencePresenceCode::DOMAIN . $body, '' === $secret ? $this->device['secret'] : $secret );
		return AudiencePresenceCode::PREFIX . Base45::encode( $body . $signature );
	}

	/**
	 * Raw code bytes with the target type byte replaced.
	 *
	 * @param string $raw  Code bytes.
	 * @param int    $type Type byte.
	 */
	private function with_target_type( string $raw, int $type ): string {
		$size = (int) unpack( 'n', substr( $raw, 1, 2 ) )[1];
		return substr_replace( $raw, chr( $type ), 3 + $size, 1 );
	}

	/**
	 * The error code a verification ends in, or '' when it passes.
	 *
	 * @param string   $text    Code text.
	 * @param string[] $revoked Revoked key ids.
	 * @param int      $now     Verifier time.
	 */
	private function error( string $text, array $revoked = array(), int $now = self::NOW ): string {
		$result = AudiencePresenceCode::verify( $text, $now, $revoked );
		return is_wp_error( $result ) ? $result->get_error_code() : '';
	}

	public function test_the_scripts_rotate_and_tolerate_as_the_php_verifier_does(): void {
		$root = dirname( __DIR__, 2 ) . '/assets/js/';
		$app  = (string) file_get_contents( $root . 'ffc-presence-app.js' );
		$read = (string) file_get_contents( $root . 'ffc-presence-verifier.js' );
		$this->assertMatchesRegularExpression( '/var ROTATION = ' . AudiencePresenceCode::ROTATION . ';/', $app, 'the phone draws a code as often as the site expects' );
		$this->assertMatchesRegularExpression( '/var ROTATION = ' . AudiencePresenceCode::ROTATION . ';/', $read );
		$this->assertMatchesRegularExpression( '/var SKEW = ' . AudiencePresenceCode::SKEW . ';/', $read, 'an offline terminal judges a code as the site does' );
		$this->assertLessThanOrEqual( 30, AudiencePresenceCode::ROTATION + AudiencePresenceCode::SKEW, 'a screenshot dies within half a minute (#2797)' );
	}
}
