<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Audience\AudienceBookingRules;

/**
 * Tests for the multi-day booking helpers (#1946): how a range expands into
 * days, the hours each day takes in `daily` and `span`, which days a range
 * skips when they are blocked, and the predicate that makes a stored range
 * occupy each of its days.
 *
 * The holiday check (DateBlockingService) and the environment repository are
 * the real classes: global holidays come through a `get_option` stub, and the
 * environment row and its own holidays through the `$wpdb` double below.
 *
 * @covers \FreeFormCertificate\Audience\AudienceBookingRules
 */
class AudienceBookingRangeTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var list<string> Global holidays the test sets, answered through `ffc_global_holidays`. */
	private array $global_holidays = array();

	/** @var list<string> The space's own holidays, answered by the holidays COUNT query. */
	private array $space_holidays = array();

	/** @var array<string, mixed>|null Working hours the test sets, stored as JSON on environment 1. */
	private ?array $working_hours = null;

	/** @var int How many times an environment row was read. */
	private int $environment_reads = 0;

	/** @var array<int, mixed> Arguments of the last prepare(). */
	private array $bound = array();

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'get_option' )->alias(
			function ( $name, $default_value = false ) {
				if ( 'ffc_global_holidays' === $name ) {
					return array_map( static fn( string $date ): array => array( 'date' => $date ), $this->global_holidays );
				}
				return $default_value;
			}
		);

		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'esc_like' )->andReturnUsing( static fn( string $text ): string => addcslashes( $text, '_%\\' ) );
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( $sql, ...$args ) {
				$this->bound = is_array( $args[0] ?? null ) ? $args[0] : $args;
				return $sql;
			}
		);
		// AudienceEnvironmentRepository::get_by_id(): environment 1, in schedule 1.
		$wpdb->shouldReceive( 'get_row' )->andReturnUsing(
			function () {
				++$this->environment_reads;
				if ( 'wp_ffc_audience_environments' !== $this->bound[0] || 1 !== (int) $this->bound[1] ) {
					return null;
				}
				return (object) array(
					'id'            => '1',
					'schedule_id'   => '1',
					'working_hours' => null === $this->working_hours ? null : json_encode( $this->working_hours ),
				);
			}
		);
		// AudienceEnvironmentRepository::is_holiday(): the holidays COUNT binds the date last.
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			fn() => in_array( $this->bound[2] ?? null, $this->space_holidays, true ) ? '1' : '0'
		);

		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']   = $wpdb;
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

	public function test_range_days_includes_both_ends_across_a_month_and_a_leap_day(): void {
		$this->assertSame(
			array( '2028-02-28', '2028-02-29', '2028-03-01' ),
			AudienceBookingRules::range_days( '2028-02-28', '2028-03-01' )
		);
	}

	public function test_range_days_of_one_day_is_that_day(): void {
		$this->assertSame( array( '2026-03-15' ), AudienceBookingRules::range_days( '2026-03-15', '2026-03-15' ) );
	}

	public function test_daily_repeats_the_chosen_hours_on_every_day(): void {
		$times = AudienceBookingRules::day_times( 'daily', false, false, '09:00', '12:00', array( 'start' => '08:00', 'end' => '18:00' ) );

		$this->assertSame( array( 'start_time' => '09:00', 'end_time' => '12:00' ), $times );
	}

	public function test_span_starts_at_the_chosen_start_and_ends_the_window_on_a_middle_day(): void {
		$window = array(
			'start' => '08:00',
			'end'   => '18:00',
		);

		$this->assertSame(
			array( 'start_time' => '14:00', 'end_time' => '18:00' ),
			AudienceBookingRules::day_times( 'span', true, false, '14:00', '10:00', $window )
		);
		$this->assertSame(
			array( 'start_time' => '08:00', 'end_time' => '18:00' ),
			AudienceBookingRules::day_times( 'span', false, false, '14:00', '10:00', $window )
		);
		$this->assertSame(
			array( 'start_time' => '08:00', 'end_time' => '10:00' ),
			AudienceBookingRules::day_times( 'span', false, true, '14:00', '10:00', $window )
		);
	}

	public function test_occurrences_of_a_daily_range_gives_each_day_the_same_hours(): void {
		$plan = AudienceBookingRules::occurrences( 1, '2026-03-16', '2026-03-18', '09:00', '12:00', 'daily', false );

		$this->assertSame( array(), $plan['skipped'] );
		$this->assertSame(
			array( '2026-03-16', '2026-03-17', '2026-03-18' ),
			array_column( $plan['days'], 'date' )
		);
		foreach ( $plan['days'] as $day ) {
			$this->assertSame( '09:00', $day['start_time'] );
			$this->assertSame( '12:00', $day['end_time'] );
		}
	}

	public function test_occurrences_of_a_single_day_span_uses_the_chosen_hours_without_reading_the_window(): void {
		$plan = AudienceBookingRules::occurrences( 1, '2026-03-16', '2026-03-16', '14:00', '16:00', 'span', false );

		$this->assertSame(
			array( array( 'date' => '2026-03-16', 'start_time' => '14:00', 'end_time' => '16:00' ) ),
			$plan['days']
		);
		$this->assertSame( 0, $this->environment_reads, 'A single day never reads the working hours.' );
	}

	public function test_skipping_blocked_days_leaves_them_out_and_records_them(): void {
		$this->global_holidays = array( '2026-03-17' );

		$plan = AudienceBookingRules::occurrences( 1, '2026-03-16', '2026-03-18', '09:00', '12:00', 'daily', true );

		$this->assertSame( array( '2026-03-17' ), $plan['skipped'] );
		$this->assertSame( array( '2026-03-16', '2026-03-18' ), array_column( $plan['days'], 'date' ) );
	}

	public function test_skipping_blocked_days_also_leaves_out_the_weekdays_the_space_is_closed(): void {
		// Closed on Saturday and Sunday (#2078): a Monday-to-Monday range
		// that skips blocked days books the weekdays and records the weekend.
		$this->working_hours = array(
			'sat' => array( 'closed' => true ),
			'sun' => array( 'closed' => true ),
			'mon' => array( 'start' => '08:00', 'end' => '18:00' ),
		);

		$plan = AudienceBookingRules::occurrences( 1, '2026-03-16', '2026-03-23', '09:00', '12:00', 'daily', true );

		$this->assertSame( array( '2026-03-21', '2026-03-22' ), $plan['skipped'] );
		$this->assertSame( array( '2026-03-16', '2026-03-17', '2026-03-18', '2026-03-19', '2026-03-20', '2026-03-23' ), array_column( $plan['days'], 'date' ) );
	}

	public function test_a_closed_weekday_is_kept_when_the_range_does_not_skip(): void {
		$this->working_hours = array( 'sat' => array( 'closed' => true ) );

		$plan = AudienceBookingRules::occurrences( 1, '2026-03-20', '2026-03-22', '09:00', '12:00', 'daily', false );

		$this->assertSame( array(), $plan['skipped'] );
		$this->assertCount( 3, $plan['days'] );
	}

	public function test_a_blocked_day_is_kept_when_the_range_does_not_skip(): void {
		$this->space_holidays = array( '2026-03-17' );

		$plan = AudienceBookingRules::occurrences( 1, '2026-03-16', '2026-03-18', '09:00', '12:00', 'daily', false );

		$this->assertSame( array(), $plan['skipped'] );
		$this->assertCount( 3, $plan['days'] );
	}

	public function test_span_starts_on_the_first_day_actually_booked_after_a_skip(): void {
		$this->global_holidays = array( '2026-03-16' );

		$plan = AudienceBookingRules::occurrences( 1, '2026-03-16', '2026-03-17', '14:00', '10:00', 'span', true );

		$this->assertSame( array( '2026-03-16' ), $plan['skipped'] );
		$this->assertSame(
			array( array( 'date' => '2026-03-17', 'start_time' => '14:00', 'end_time' => '10:00' ) ),
			$plan['days']
		);
	}

	public function test_occupies_day_clause_binds_the_day_twice_and_a_quoted_skip_pattern(): void {
		list( $sql, $args ) = AudienceBookingRules::occupies_day_clause( '2026-10-22' );

		$this->assertStringContainsString( 'booking_date <= %s AND COALESCE(end_date, booking_date) >= %s', $sql );
		$this->assertStringContainsString( 'skipped_dates NOT LIKE %s', $sql );
		$this->assertSame( array( '2026-10-22', '2026-10-22', '%"2026-10-22"%' ), $args );
	}

	public function test_date_label_shows_one_date_for_a_single_day(): void {
		$this->assertSame( '2026-03-15', AudienceBookingRules::date_label( '2026-03-15', null ) );
		$this->assertSame( '2026-03-15', AudienceBookingRules::date_label( '2026-03-15', '2026-03-15' ) );
	}

	public function test_date_label_joins_both_ends_of_a_range_through_the_formatter(): void {
		$label = AudienceBookingRules::date_label( '2026-03-16', '2026-03-18', static fn( string $d ): string => 'D' . $d );

		$this->assertSame( 'D2026-03-16 – D2026-03-18', $label );
	}

	public function test_booked_days_leaves_out_the_skipped_days(): void {
		$this->assertSame(
			array( '2026-03-16', '2026-03-18' ),
			AudienceBookingRules::booked_days( '2026-03-16', '2026-03-18', '["2026-03-17"]' )
		);
	}

	public function test_booked_days_of_a_single_day_is_that_day_whatever_is_skipped(): void {
		$this->assertSame( array( '2026-03-16' ), AudienceBookingRules::booked_days( '2026-03-16', null, '["2026-03-16"]' ) );
	}

	public function test_booked_days_survives_a_malformed_skip_list(): void {
		$this->assertSame(
			array( '2026-03-16', '2026-03-17' ),
			AudienceBookingRules::booked_days( '2026-03-16', '2026-03-17', 'not-json' )
		);
	}

	public function test_occupies_day_clause_prefixes_every_column(): void {
		list( $sql ) = AudienceBookingRules::occupies_day_clause( '2026-10-22', 'b.' );

		$this->assertStringContainsString( 'b.booking_date <= %s', $sql );
		$this->assertStringContainsString( 'COALESCE(b.end_date, b.booking_date) >= %s', $sql );
		$this->assertStringContainsString( 'b.skipped_dates NOT LIKE %s', $sql );
	}

	public function test_cancel_strings_carry_every_key_the_confirmation_reads(): void {
		$strings = AudienceBookingRules::cancel_strings();

		foreach ( array( 'title', 'intro', 'agree', 'agreeIn', 'cancel', 'countdownStart', 'countdownEnd', 'single', 'range' ) as $key ) {
			$this->assertNotSame( '', $strings[ $key ], "The $key string must not be empty." );
		}
		$this->assertSame( \FreeFormCertificate\Core\ConfirmCountdown::SECONDS, $strings['seconds'] );
	}

	public function test_the_range_sentence_names_both_the_day_count_and_the_dates(): void {
		$range = AudienceBookingRules::cancel_strings()['range'];

		$this->assertStringContainsString( '%1$d', $range );
		$this->assertStringContainsString( '%2$s', $range );
	}

	public function test_a_span_row_takes_its_start_on_the_first_day_the_window_between_and_its_end_on_the_last(): void {
		$row = (object) array(
			'environment_id' => 1,
			'booking_date'   => '2026-03-16',
			'end_date'       => '2026-03-18',
			'time_mode'      => 'span',
			'skipped_dates'  => null,
			'start_time'     => '14:00:00',
			'end_time'       => '10:00:00',
		);

		$this->assertSame( array( 'start_time' => '14:00', 'end_time' => '23:59' ), AudienceBookingRules::stored_day_times( $row, '2026-03-16' ) );
		$this->assertSame( array( 'start_time' => '00:00', 'end_time' => '23:59' ), AudienceBookingRules::stored_day_times( $row, '2026-03-17' ) );
		$this->assertSame( array( 'start_time' => '00:00', 'end_time' => '10:00' ), AudienceBookingRules::stored_day_times( $row, '2026-03-18' ) );
	}

	public function test_a_span_whose_first_day_was_skipped_starts_on_the_first_day_it_books(): void {
		$row = (object) array(
			'environment_id' => 1,
			'booking_date'   => '2026-03-16',
			'end_date'       => '2026-03-18',
			'time_mode'      => 'span',
			'skipped_dates'  => '["2026-03-16"]',
			'start_time'     => '14:00:00',
			'end_time'       => '10:00:00',
		);

		$this->assertSame( array( 'start_time' => '14:00', 'end_time' => '23:59' ), AudienceBookingRules::stored_day_times( $row, '2026-03-17' ) );
	}

	public function test_a_daily_row_takes_its_own_hours_on_every_day(): void {
		$row = (object) array(
			'environment_id' => 1,
			'booking_date'   => '2026-03-16',
			'end_date'       => '2026-03-18',
			'time_mode'      => 'daily',
			'start_time'     => '09:00:00',
			'end_time'       => '12:00:00',
		);

		$this->assertSame( array( 'start_time' => '09:00', 'end_time' => '12:00' ), AudienceBookingRules::stored_day_times( $row, '2026-03-17' ) );
		$this->assertSame( 0, $this->environment_reads, 'A daily row never reads the working hours.' );
	}

	public function test_overlap_keeps_a_middle_day_of_a_span_and_drops_touching_ends(): void {
		$span     = (object) array(
			'id'             => 1,
			'environment_id' => 1,
			'booking_date'   => '2026-03-16',
			'end_date'       => '2026-03-18',
			'time_mode'      => 'span',
			'start_time'     => '14:00:00',
			'end_time'       => '10:00:00',
		);
		$before   = (object) array(
			'id'           => 2,
			'booking_date' => '2026-03-17',
			'start_time'   => '07:00:00',
			'end_time'     => '09:00:00',
		);
		$touching = (object) array(
			'id'           => 3,
			'booking_date' => '2026-03-17',
			'start_time'   => '12:00:00',
			'end_time'     => '13:00:00',
		);

		$kept = AudienceBookingRules::overlapping_on_day( array( $span, $before, $touching ), '2026-03-17', '09:00', '12:00' );

		// The span's stored 14:00–10:00 read as one day's hours would never
		// overlap 09:00–12:00; on its middle day it takes the whole window.
		$this->assertSame( array( 1 ), array_map( static fn( $row ): int => $row->id, $kept ) );
	}

	public function test_ics_of_a_single_day_is_that_day(): void {
		$this->assertSame(
			array( 'date' => '2026-03-16', 'end_date' => '2026-03-16' ),
			AudienceBookingRules::ics_fields( '2026-03-16', null, null, null )
		);
	}

	public function test_ics_of_a_span_runs_from_the_first_booked_day_to_the_last(): void {
		// The first and last dates were skipped: the event starts and ends on days it books.
		$this->assertSame(
			array( 'date' => '2026-03-17', 'end_date' => '2026-03-19' ),
			AudienceBookingRules::ics_fields( '2026-03-16', '2026-03-20', 'span', '["2026-03-16","2026-03-20"]' )
		);
	}

	public function test_ics_of_a_daily_range_repeats_and_leaves_out_only_the_skipped_days_between(): void {
		$fields = AudienceBookingRules::ics_fields( '2026-03-16', '2026-03-20', 'daily', '["2026-03-16","2026-03-18"]' );

		$this->assertSame( '2026-03-17', $fields['date'], 'A skipped first date does not start the event.' );
		$this->assertSame( '2026-03-17', $fields['end_date'] );
		$this->assertSame( 'FREQ=DAILY;UNTIL=20260320T235959', $fields['rrule'] );
		$this->assertSame( array( '2026-03-18' ), $fields['exdates'] );
	}

	public function test_time_label_says_which_day_each_end_of_a_span_belongs_to(): void {
		$this->assertSame( '09:00 - 12:00', AudienceBookingRules::time_label( '09:00:00', '12:00:00', false ) );
		$this->assertSame( 'From 14:00 on the first day to 10:00 on the last day', AudienceBookingRules::time_label( '14:00:00', '10:00:00', true ) );
	}

	public function test_is_span_needs_a_span_over_more_than_one_booked_day(): void {
		$base = array(
			'booking_date' => '2026-03-16',
			'end_date'     => '2026-03-17',
			'time_mode'    => 'span',
		);

		$this->assertTrue( AudienceBookingRules::is_span( (object) $base ) );
		$this->assertFalse( AudienceBookingRules::is_span( (object) ( array( 'time_mode' => 'daily' ) + $base ) ) );
		$this->assertFalse( AudienceBookingRules::is_span( (object) ( array( 'skipped_dates' => '["2026-03-17"]' ) + $base ) ), 'One day left after skipping is a single day.' );
	}

	public function test_day_times_map_lists_every_booked_day_of_a_span_and_nothing_for_a_daily_range(): void {
		$span = (object) array(
			'environment_id' => 1,
			'booking_date'   => '2026-03-16',
			'end_date'       => '2026-03-17',
			'time_mode'      => 'span',
			'start_time'     => '14:00:00',
			'end_time'       => '10:00:00',
		);

		$this->assertSame(
			array(
				'2026-03-16' => array( 'start_time' => '14:00', 'end_time' => '23:59' ),
				'2026-03-17' => array( 'start_time' => '00:00', 'end_time' => '10:00' ),
			),
			AudienceBookingRules::day_times_map( $span )
		);
		$this->assertSame( array(), AudienceBookingRules::day_times_map( (object) ( array( 'time_mode' => 'daily' ) + (array) $span ) ) );
	}
}
