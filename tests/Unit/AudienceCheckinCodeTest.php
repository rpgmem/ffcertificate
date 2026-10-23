<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Audience\AudienceCheckinCode;
use FreeFormCertificate\Audience\AudienceCheckinReceipt;
use PHPUnit\Framework\TestCase;

/**
 * The rotating check-in code and the scan receipt (#1947).
 *
 * @covers \FreeFormCertificate\Audience\AudienceCheckinCode
 * @covers \FreeFormCertificate\Audience\AudienceCheckinReceipt
 */
class AudienceCheckinCodeTest extends TestCase {

	/** A moment exactly on a 60-second boundary. */
	private const T0 = 1800000000;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_salt' )->justReturn( 'test-salt' );
		Functions\when( 'home_url' )->alias( static fn( $path = '' ) => 'https://example.org' . $path );
		Functions\when( 'add_query_arg' )->alias( static fn( $key, $value, $url ) => $url . '?' . $key . '=' . $value );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A window open on one day of booking 123.
	 *
	 * @param array<string, mixed> $override Fields to change.
	 * @return array{id: int, booking_id: int, window_date: string, rotation_seconds: int, opened_at: int, opened_by: int|null, closes_at: int, closed_at: int|null, closed_by: int|null, geofence_locations: list<string>}
	 */
	private function window( array $override = array() ): array {
		return array_merge(
			array(
				'id'               => 7,
				'booking_id'       => 123,
				'window_date'      => '2027-01-15',
				'rotation_seconds' => 60,
				'opened_at'        => self::T0 - 600,
				'opened_by'        => 1,
				'closes_at'        => self::T0 + 3600,
				'closed_at'          => null,
				'closed_by'          => null,
				'geofence_locations' => array(),
			),
			$override
		);
	}

	public function test_a_code_has_the_booking_the_slot_and_a_sixteen_character_signature(): void {
		$code = AudienceCheckinCode::for_window( $this->window(), self::T0 + 5 );

		$this->assertMatchesRegularExpression( '/^123\.30000000\.[A-Za-z0-9_-]{16}$/', $code );
		$this->assertSame(
			'https://example.org/?ffc_checkin=' . $code,
			AudienceCheckinCode::url( $this->window(), self::T0 + 5 )
		);
	}

	public function test_the_code_shown_now_is_valid_and_rotates_on_the_boundary(): void {
		$window = $this->window();

		$this->assertTrue( AudienceCheckinCode::verify( AudienceCheckinCode::for_window( $window, self::T0 ), $window, self::T0 + 59 ) );
		$this->assertSame(
			AudienceCheckinCode::for_window( $window, self::T0 ),
			AudienceCheckinCode::for_window( $window, self::T0 + 59 ),
			'Two screens projecting one window show the same code within a rotation.'
		);
		$this->assertNotSame(
			AudienceCheckinCode::for_window( $window, self::T0 + 59 ),
			AudienceCheckinCode::for_window( $window, self::T0 + 60 ),
			'The code changes exactly on the boundary.'
		);
	}

	public function test_the_previous_rotation_is_accepted_and_the_one_before_it_is_not(): void {
		$window = $this->window();
		$code   = AudienceCheckinCode::for_window( $window, self::T0 );

		$this->assertTrue( AudienceCheckinCode::verify( $code, $window, self::T0 + 60 ), 'A scan made just before the code changed still lands.' );
		$this->assertTrue( AudienceCheckinCode::verify( $code, $window, self::T0 + 119 ) );
		$this->assertFalse( AudienceCheckinCode::verify( $code, $window, self::T0 + 120 ), 'A photo two rotations old is dead.' );
	}

	public function test_a_code_from_the_future_is_refused(): void {
		$window = $this->window();

		$this->assertFalse( AudienceCheckinCode::verify( AudienceCheckinCode::for_window( $window, self::T0 + 60 ), $window, self::T0 ) );
	}

	public function test_the_rotation_chosen_sets_the_lifetime(): void {
		$window = $this->window( array( 'rotation_seconds' => 300 ) );
		$code   = AudienceCheckinCode::for_window( $window, self::T0 );

		$this->assertTrue( AudienceCheckinCode::verify( $code, $window, self::T0 + 599 ) );
		$this->assertFalse( AudienceCheckinCode::verify( $code, $window, self::T0 + 600 ) );
	}

	public function test_a_tampered_signature_is_refused(): void {
		$window = $this->window();
		$code   = AudienceCheckinCode::for_window( $window, self::T0 );
		$last   = substr( $code, -1 );

		$this->assertFalse( AudienceCheckinCode::verify( substr( $code, 0, -1 ) . ( 'A' === $last ? 'B' : 'A' ), $window, self::T0 ) );
	}

	public function test_a_code_is_bound_to_its_booking_day_and_window(): void {
		$window = $this->window();
		$code   = AudienceCheckinCode::for_window( $window, self::T0 );

		$this->assertFalse( AudienceCheckinCode::verify( $code, $this->window( array( 'booking_id' => 124 ) ), self::T0 ), 'Another booking.' );
		$this->assertFalse( AudienceCheckinCode::verify( $code, $this->window( array( 'window_date' => '2027-01-16' ) ), self::T0 ), 'The next day of the same range.' );
		$this->assertFalse( AudienceCheckinCode::verify( $code, $this->window( array( 'id' => 8 ) ), self::T0 ), 'A reopened window voids the codes shown before.' );

		$forged = '124.' . substr( $code, 4 );
		$this->assertFalse( AudienceCheckinCode::verify( $forged, $this->window( array( 'booking_id' => 124 ) ), self::T0 ), 'Changing the booking in the URL breaks the signature.' );
	}

	public function test_a_closed_window_refuses_every_code(): void {
		$code = AudienceCheckinCode::for_window( $this->window(), self::T0 );

		$this->assertFalse( AudienceCheckinCode::verify( $code, $this->window( array( 'closed_at' => self::T0 - 1 ) ), self::T0 ), 'Closed by hand.' );
		$this->assertFalse( AudienceCheckinCode::verify( $code, $this->window( array( 'closes_at' => self::T0 ) ), self::T0 ), 'Past its automatic close.' );
	}

	public function test_malformed_codes_never_parse(): void {
		foreach ( array( '', '123', '123.30000000', '0.1.AAAAAAAAAAAAAAAA', '123.1.short', '123.1.AAAAAAAAAAAAAAA+', 'a.b.AAAAAAAAAAAAAAAA', '123.1.AAAAAAAAAAAAAAAAA' ) as $code ) {
			$this->assertNull( AudienceCheckinCode::parse( $code ), $code );
			$this->assertFalse( AudienceCheckinCode::verify( $code, $this->window(), self::T0 ), $code );
		}
	}

	public function test_rotation_slot_and_seconds_remaining(): void {
		$this->assertSame( 60, AudienceCheckinCode::normalize_rotation( 45 ), 'An unlisted rotation reads as the default.' );
		$this->assertSame( 600, AudienceCheckinCode::normalize_rotation( 600 ) );
		$this->assertSame( 60, AudienceCheckinCode::seconds_remaining( 60, self::T0 ) );
		$this->assertSame( 1, AudienceCheckinCode::seconds_remaining( 60, self::T0 + 59 ) );
		$this->assertSame( 180, AudienceCheckinCode::seconds_remaining( 180, self::T0 + 180 ) );
		$this->assertSame( intdiv( self::T0, 120 ), AudienceCheckinCode::slot( 120, self::T0 ) );
	}

	public function test_a_receipt_round_trips_and_lasts_ten_minutes(): void {
		$receipt = AudienceCheckinReceipt::issue( 123, '2027-01-15', 7, self::T0 );

		$expected = array(
			'booking_id' => 123,
			'date'       => '2027-01-15',
			'window_id'  => 7,
			'issued_at'  => self::T0,
		);
		$this->assertSame( $expected, AudienceCheckinReceipt::verify( $receipt, self::T0 ) );
		$this->assertSame( $expected, AudienceCheckinReceipt::verify( $receipt, self::T0 + 600 ), 'Valid for the whole TTL, enough to log in.' );
		$this->assertNull( AudienceCheckinReceipt::verify( $receipt, self::T0 + 601 ) );
	}

	public function test_a_receipt_from_the_future_is_refused_beyond_the_skew(): void {
		$receipt = AudienceCheckinReceipt::issue( 123, '2027-01-15', 7, self::T0 + 61 );

		$this->assertNull( AudienceCheckinReceipt::verify( $receipt, self::T0 ) );
		$this->assertNotNull( AudienceCheckinReceipt::verify( AudienceCheckinReceipt::issue( 123, '2027-01-15', 7, self::T0 + 60 ), self::T0 ) );
	}

	public function test_a_tampered_receipt_is_refused(): void {
		$receipt = AudienceCheckinReceipt::issue( 123, '2027-01-15', 7, self::T0 );

		$this->assertNull( AudienceCheckinReceipt::verify( str_replace( '123.20270115', '124.20270115', $receipt ), self::T0 ), 'Another booking.' );
		$this->assertNull( AudienceCheckinReceipt::verify( str_replace( '.20270115.', '.20270116.', $receipt ), self::T0 ), 'Another day.' );
		$this->assertNull( AudienceCheckinReceipt::verify( str_replace( '.' . self::T0 . '.', '.' . ( self::T0 + 300 ) . '.', $receipt ), self::T0 ), 'A later issue time, to stretch the TTL.' );
		$this->assertNull( AudienceCheckinReceipt::verify( 'not-a-receipt', self::T0 ) );
	}

	public function test_a_code_signature_does_not_verify_as_a_receipt_and_back(): void {
		$code = AudienceCheckinCode::for_window( $this->window(), self::T0 );

		$this->assertNull( AudienceCheckinReceipt::verify( $code, self::T0 ) );
		$this->assertFalse( AudienceCheckinCode::verify( AudienceCheckinReceipt::issue( 123, '2027-01-15', 7, self::T0 ), $this->window(), self::T0 ) );
	}
}
