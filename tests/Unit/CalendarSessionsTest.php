<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\SelfScheduling\CalendarSessions;

/**
 * The sessions of a personal calendar (#2405): what a stored value holds,
 * what an editor save makes of it, and why an id is never reused.
 *
 * @covers \FreeFormCertificate\SelfScheduling\CalendarSessions
 */
class CalendarSessionsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'sanitize_text_field' )->alias( static fn( $v ) => strip_tags( (string) $v ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A stored list: two active sessions and one deleted.
	 *
	 * @return list<array{id: int, name: string, deleted: bool}>
	 */
	private static function stored(): array {
		return array(
			array( 'id' => 1, 'name' => 'Room A', 'deleted' => false ),
			array( 'id' => 2, 'name' => 'Room B', 'deleted' => false ),
			array( 'id' => 5, 'name' => 'Old room', 'deleted' => true ),
		);
	}

	public function test_decode_reads_the_meta_array_and_the_table_json_alike(): void {
		$value = array(
			array( 'id' => '1', 'name' => ' Room A ' ),
			array( 'id' => 2, 'name' => 'Room B', 'deleted' => 1 ),
		);
		$expected = array(
			array( 'id' => 1, 'name' => 'Room A', 'deleted' => false ),
			array( 'id' => 2, 'name' => 'Room B', 'deleted' => true ),
		);

		$this->assertSame( $expected, CalendarSessions::decode( $value ) );
		$this->assertSame( $expected, CalendarSessions::decode( (string) json_encode( $value ) ) );
	}

	public function test_decode_drops_what_is_not_a_session(): void {
		$this->assertSame( array(), CalendarSessions::decode( null ) );
		$this->assertSame( array(), CalendarSessions::decode( 'not json' ) );
		$this->assertSame(
			array( array( 'id' => 3, 'name' => 'Kept', 'deleted' => false ) ),
			CalendarSessions::decode(
				array(
					'loose',
					array( 'id' => 0, 'name' => 'No id' ),
					array( 'id' => 4, 'name' => '   ' ),
					array( 'id' => 3, 'name' => 'Kept' ),
					array( 'id' => 3, 'name' => 'Repeated id' ),
					array( 'id' => 6, 'name' => array( 'not a name' ) ),
				)
			)
		);
	}

	public function test_a_calendar_asks_for_a_session_only_with_two_active_ones(): void {
		$this->assertTrue( CalendarSessions::in_use( self::stored() ) );
		$this->assertFalse( CalendarSessions::in_use( array_slice( self::stored(), 1 ) ), 'One active session and a deleted one is a calendar without sessions.' );
		$this->assertFalse( CalendarSessions::in_use( array() ) );
		$this->assertSame( array( 1, 2 ), array_column( CalendarSessions::active( self::stored() ), 'id' ) );
	}

	public function test_a_save_keeps_the_id_of_a_renamed_or_reordered_session(): void {
		$sessions = CalendarSessions::from_rows(
			array(
				array( 'id' => '2', 'name' => 'Room B, renamed' ),
				array( 'id' => '1', 'name' => 'Room A' ),
			),
			self::stored()
		);

		$this->assertSame(
			array(
				array( 'id' => 2, 'name' => 'Room B, renamed', 'deleted' => false ),
				array( 'id' => 1, 'name' => 'Room A', 'deleted' => false ),
				array( 'id' => 5, 'name' => 'Old room', 'deleted' => true ),
			),
			$sessions
		);
	}

	public function test_a_new_session_takes_an_id_above_every_id_ever_used(): void {
		$sessions = CalendarSessions::from_rows(
			array(
				array( 'id' => '1', 'name' => 'Room A' ),
				array( 'id' => '2', 'name' => 'Room B' ),
				array( 'id' => '', 'name' => 'Room C' ),
				// A forged id of a deleted session is a new session, not a revival.
				array( 'id' => '5', 'name' => 'Revived?' ),
			),
			self::stored()
		);

		$this->assertSame( array( 1, 2, 6, 7, 5 ), array_column( $sessions, 'id' ), 'A deleted id is never handed out again: its appointments keep it.' );
		$this->assertSame( array( false, false, false, false, true ), array_column( $sessions, 'deleted' ) );
	}

	public function test_a_row_left_out_or_emptied_is_a_deletion_that_keeps_the_name(): void {
		$sessions = CalendarSessions::from_rows(
			array( array( 'id' => '1', 'name' => '' ) ),
			self::stored()
		);

		$this->assertSame( array(), CalendarSessions::active( $sessions ) );
		$this->assertSame( 'Room A', CalendarSessions::name( $sessions, 1 ), 'A deleted session still names its old appointments.' );
		$this->assertSame( '', CalendarSessions::name( $sessions, 99 ) );
	}

	public function test_a_repeated_id_in_the_rows_becomes_a_new_session(): void {
		$sessions = CalendarSessions::from_rows(
			array(
				array( 'id' => '1', 'name' => 'Room A' ),
				array( 'id' => '1', 'name' => 'Copy of A' ),
			),
			array( array( 'id' => 1, 'name' => 'Room A', 'deleted' => false ) )
		);

		$this->assertSame( array( 1, 2 ), array_column( $sessions, 'id' ) );
	}

	public function test_restore_brings_a_session_back_after_the_kept_ones(): void {
		$after = CalendarSessions::from_rows( array( array( 'id' => '2', 'name' => 'Room B' ) ), self::stored() );

		$restored = CalendarSessions::restore( $after, array( 1 ) );

		$this->assertSame( array( 2, 1 ), array_column( CalendarSessions::active( $restored ), 'id' ) );
		$this->assertSame( array( 2, 1, 5 ), array_column( $restored, 'id' ), 'The deleted ones stay last.' );
	}

	public function test_a_name_is_plain_text_and_capped(): void {
		$sessions = CalendarSessions::from_rows( array( array( 'name' => '<b>' . str_repeat( 'x', 150 ) . '</b>' ) ), array() );

		$this->assertSame( str_repeat( 'x', CalendarSessions::MAX_NAME ), $sessions[0]['name'] );
	}

	public function test_scope_is_null_for_a_calendar_without_sessions(): void {
		$this->assertNull( CalendarSessions::scope( array(), 3 ) );
		$this->assertNull( CalendarSessions::scope( array( 'sessions' => '[{"id":1,"name":"Only"}]' ), 3 ), 'One session is no session.' );
		$this->assertNull( CalendarSessions::scope_of_appointment( array(), array( 'session_id' => 4 ) ) );
	}

	public function test_scope_names_an_active_session_and_refuses_any_other(): void {
		$calendar = array( 'sessions' => (string) json_encode( self::stored() ) );

		$this->assertSame( 2, CalendarSessions::scope( $calendar, '2' ) );
		$this->assertSame( 0, CalendarSessions::scope( $calendar, 5 ), 'A deleted session takes no new booking.' );
		$this->assertSame( 0, CalendarSessions::scope( $calendar, 99 ) );
		$this->assertSame( 0, CalendarSessions::scope( $calendar, null ) );
	}

	public function test_an_appointment_counts_in_its_own_session_even_a_deleted_one(): void {
		$calendar = array( 'sessions' => (string) json_encode( self::stored() ) );

		$this->assertSame( 5, CalendarSessions::scope_of_appointment( $calendar, array( 'session_id' => '5' ) ) );
		$this->assertSame( 0, CalendarSessions::scope_of_appointment( $calendar, array( 'session_id' => null ) ), 'A booking from before sessions counts against the whole calendar.' );
	}

	public function test_an_appointment_names_its_session_even_a_deleted_one(): void {
		$calendar = array( 'sessions' => (string) json_encode( self::stored() ) );

		$this->assertSame( 'Room B', CalendarSessions::name_of_appointment( $calendar, array( 'session_id' => '2' ) ) );
		$this->assertSame( 'Old room', CalendarSessions::name_of_appointment( $calendar, array( 'session_id' => 5 ) ) );
		$this->assertSame( '', CalendarSessions::name_of_appointment( $calendar, array( 'session_id' => null ) ) );
		$this->assertSame( '', CalendarSessions::name_of_appointment( array(), array( 'session_id' => 2 ) ) );
	}

	public function test_a_calendar_names_a_session_with_its_own_label_or_the_default(): void {
		Functions\when( '__' )->returnArg();
		$own = array( 'session_label' => ' Room ', 'session_label_plural' => 'Rooms' );

		$this->assertSame( 'Room', CalendarSessions::label( $own ) );
		$this->assertSame( 'Rooms', CalendarSessions::label( $own, true ) );
		$this->assertSame( 'Session', CalendarSessions::label( array( 'session_label' => '' ) ) );
		$this->assertSame( 'Sessions', CalendarSessions::label( array(), true ) );
	}

	public function test_the_step_strings_replace_only_what_a_label_was_given_for(): void {
		$this->assertSame(
			array( 'chooseSession' => 'Rooms', 'changeSession' => 'Rooms', 'session' => 'Room' ),
			CalendarSessions::step_strings( array( 'session_label' => 'Room', 'session_label_plural' => 'Rooms' ) )
		);
		$this->assertSame( array( 'session' => 'Room' ), CalendarSessions::step_strings( array( 'session_label' => 'Room', 'session_label_plural' => ' ' ) ) );
		$this->assertSame( array(), CalendarSessions::step_strings( array() ), 'Without labels the general strings stand.' );
	}

	public function test_a_post_reads_its_labels_as_the_row_carries_them(): void {
		Functions\when( 'get_post_meta' )->alias(
			static fn( $id, $key ) => CalendarSessions::LABEL_META === $key ? 'Room' : false
		);

		$this->assertSame( array( 'session_label' => 'Room', 'session_label_plural' => '' ), CalendarSessions::labels_of_post( 9 ) );
	}
}
