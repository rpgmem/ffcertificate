<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\SelfScheduling\AppointmentReminderScanner;

/**
 * Tests for the appointment-reminder cron scanner (#650): the missing driver
 * that fires the reminder email for due appointments.
 *
 * SettingsReader, CalendarRepository, AppointmentRepository (with its reader
 * and writer) and UserManager are the real classes. `get_option()` answers
 * the "disable all emails" switch, and a `$wpdb` double answers the
 * active-calendar list, the reminder window query and the profile read by
 * their SQL text, recording every query and every update. A query nothing
 * here expects fails the test.
 *
 * @covers \FreeFormCertificate\SelfScheduling\AppointmentReminderScanner
 */
class AppointmentReminderScannerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface */
	private $wpdb;

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	/** @var bool What the "disable all emails" switch reads. */
	private bool $emails_disabled = false;

	/** @var list<array<string, mixed>> Rows the active-calendar query answers. */
	private array $calendars = array();

	/** @var list<array<string, mixed>> Rows the reminder-window query answers. */
	private array $due = array();

	/** @var array<int, string|null> Stored `preferences` JSON by user id, for the profile table. */
	private array $preferences = array();

	/** @var list<array<int, mixed>> Every prepare() call, as its argument list. */
	private array $prepared = array();

	/** @var int How many times the active-calendar list was read. */
	private int $calendar_reads = 0;

	/** @var list<array{0: string, 1: string}> Date and time each reminder-window query was bound to. */
	private array $windows = array();

	/** @var list<int> User ids whose profile row was read. */
	private array $profile_reads = array();

	/**
	 * Every `$wpdb->update()`, as `[ table, data, where ]`.
	 *
	 * @var list<array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}>
	 */
	private array $updates = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->emails_disabled = false;
		$this->calendars       = array();
		$this->due             = array();
		$this->preferences     = array();
		$this->prepared        = array();
		$this->calendar_reads  = 0;
		$this->windows         = array();
		$this->profile_reads   = array();
		$this->updates         = array();

		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) {
				if ( 'ffc_settings' === $name ) {
					return array( 'disable_all_emails' => $this->emails_disabled ? 1 : 0 );
				}
				return $default;
			}
		);
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'get_userdata' )->justReturn( false );
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );

		$this->wpdb             = Mockery::mock( 'wpdb' );
		$this->wpdb->prefix     = 'wp_';
		$this->wpdb->last_error = '';
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( ...$args ) {
				$flat             = 2 === count( $args ) && is_array( $args[1] ) ? array_merge( array( $args[0] ), $args[1] ) : $args;
				$this->prepared[] = $flat;
				return 'Q' . ( count( $this->prepared ) - 1 ) . ':' . $flat[0];
			}
		);
		$this->wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $sql ) {
				$bound = $this->boundTo( (string) $sql );
				if ( false !== strpos( (string) $sql, 'reminder_sent_at IS NULL' ) ) {
					$this->windows[] = array( (string) $bound[5], (string) $bound[6] );
					return $this->due;
				}
				if ( false !== strpos( (string) $sql, 'SELECT * FROM %i' ) && 'wp_ffc_self_scheduling_calendars' === ( $bound[1] ?? null ) ) {
					++$this->calendar_reads;
					return $this->calendars;
				}
				$this->fail( 'Unexpected get_results(): ' . $sql );
			}
		);
		$this->wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			function ( $sql ) {
				$bound = $this->boundTo( (string) $sql );
				if ( false !== strpos( (string) $sql, 'SHOW TABLES LIKE' ) && 'wp_ffc_user_profiles' === ( $bound[1] ?? null ) ) {
					return 'wp_ffc_user_profiles';
				}
				$this->fail( 'Unexpected get_var(): ' . $sql );
			}
		);
		$this->wpdb->shouldReceive( 'get_row' )->andReturnUsing(
			function ( $sql ) {
				$bound = $this->boundTo( (string) $sql );
				if ( false !== strpos( (string) $sql, 'WHERE user_id = %d' ) && 'wp_ffc_user_profiles' === ( $bound[1] ?? null ) ) {
					$user_id               = (int) $bound[2];
					$this->profile_reads[] = $user_id;
					if ( ! array_key_exists( $user_id, $this->preferences ) ) {
						return null;
					}
					return array(
						'user_id'     => (string) $user_id,
						'preferences' => $this->preferences[ $user_id ],
					);
				}
				$this->fail( 'Unexpected get_row(): ' . $sql );
			}
		);
		$this->wpdb->shouldReceive( 'update' )->andReturnUsing(
			function ( $table, $data, $where ) {
				$this->updates[] = array( $table, $data, $where );
				return 1;
			}
		);

		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']   = $this->wpdb;
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

	/**
	 * The prepare() argument list behind a prepared query string.
	 *
	 * @return array<int, mixed>
	 */
	private function boundTo( string $sql ): array {
		if ( 1 === preg_match( '/^Q(\d+):/', $sql, $m ) ) {
			return $this->prepared[ (int) $m[1] ];
		}
		return array( $sql );
	}

	private static function cfg( int $send, int $hours ): string {
		return (string) json_encode( array( 'send_reminder' => $send, 'reminder_hours_before' => $hours ) );
	}

	/**
	 * Run the scanner and assert every reminder-window query runs from now
	 * to now + `$hours` (#2244), now read on both sides of the run.
	 */
	private function runExpectingWindow( int $hours ): void {
		$before = time();
		AppointmentReminderScanner::run();
		$after = time();

		$this->assertCount( 1, $this->windows, 'One reminder-window query, for the one window a calendar asked for.' );
		$from = strtotime( $this->windows[0][0] . ' UTC' );
		$to   = strtotime( $this->windows[0][1] . ' UTC' );
		$this->assertGreaterThanOrEqual( $before, $from );
		$this->assertLessThanOrEqual( $after, $from );
		$this->assertSame( $hours * 3600, $to - $from );
	}

	/** @return list<int> The ids the writer stamped `reminder_sent_at` on. */
	private function markedIds(): array {
		$ids = array();
		foreach ( $this->updates as $update ) {
			$this->assertSame( 'wp_ffc_self_scheduling_appointments', $update[0] );
			$this->assertSame( array( 'reminder_sent_at' ), array_keys( $update[1] ) );
			$ids[] = (int) $update[2]['id'];
		}
		return $ids;
	}

	public function test_skips_entirely_when_emails_globally_disabled(): void {
		$this->emails_disabled = true;
		$this->calendars       = array( array( 'id' => 5, 'email_config' => self::cfg( 1, 24 ) ) );

		AppointmentReminderScanner::run();

		$this->assertSame( 0, $this->calendar_reads, 'Calendars are never queried while emails are off.' );
		$this->assertSame( array(), $this->prepared, 'No query at all runs while emails are off.' );
	}

	public function test_reminds_due_appointment_and_marks_sent(): void {
		$this->calendars = array( array( 'id' => 5, 'email_config' => self::cfg( 1, 24 ) ) );
		$this->due       = array(
			array(
				'id'               => 99,
				'calendar_id'      => 5,
				'calendar_title'   => 'Clinic',
				'email_config'     => self::cfg( 1, 24 ),
				'appointment_date' => '2026-05-20',
				'start_time'       => '09:00:00',
			),
		);

		Actions\expectDone( 'ffcertificate_self_scheduling_appointment_reminder_email' )->once();

		$this->runExpectingWindow( 24 );

		$this->assertSame( array( 99 ), $this->markedIds(), 'the due appointment must be marked reminded' );
	}

	public function test_skips_calendars_with_reminders_off(): void {
		$this->calendars = array( array( 'id' => 5, 'email_config' => self::cfg( 0, 24 ) ) );

		AppointmentReminderScanner::run();

		// No calendar wants reminders, so the appointment table is never queried.
		$this->assertSame( 1, $this->calendar_reads );
		$this->assertSame( array(), $this->windows );
		$this->assertSame( array(), $this->updates );
	}

	public function test_does_not_remind_appointment_from_other_hours_window(): void {
		// Calendar 5 wants a 24h reminder.
		$this->calendars = array( array( 'id' => 5, 'email_config' => self::cfg( 1, 24 ) ) );

		// The 24h scan returns an appointment for calendar 7 (not in the
		// reminder-enabled set) — it must be ignored, not reminded.
		$this->due = array(
			array( 'id' => 42, 'calendar_id' => 7, 'calendar_title' => 'X', 'email_config' => self::cfg( 1, 48 ) ),
		);

		Actions\expectDone( 'ffcertificate_self_scheduling_appointment_reminder_email' )->never();

		$this->runExpectingWindow( 24 );

		$this->assertSame( array(), $this->updates, 'The foreign appointment is not marked reminded.' );
	}

	public function test_a_person_who_turned_reminders_off_is_skipped_but_marked(): void {
		$this->calendars   = array( array( 'id' => 5, 'email_config' => self::cfg( 1, 24 ) ) );
		$this->preferences = array( 7 => (string) json_encode( array( 'notify_appointment_reminder' => false ) ) );
		$this->due         = array(
			array(
				'id'          => 99,
				'calendar_id' => 5,
				'user_id'     => '7',
			),
		);

		Actions\expectDone( 'ffcertificate_self_scheduling_appointment_reminder_email' )->never();

		$this->runExpectingWindow( 24 );

		$this->assertSame( array( 7 ), $this->profile_reads, "The person's stored preference is read once." );
		$this->assertSame( array( 99 ), $this->markedIds(), 'Handled once, so the next hourly scan does not reconsider it.' );
	}
}
