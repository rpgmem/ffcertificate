<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
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
 * The holiday check and the working-hours window are aliased, so each test
 * runs in its own process.
 *
 * @covers \FreeFormCertificate\Audience\AudienceBookingRules
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class AudienceBookingRangeTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface */
	private $global_holiday;

	/** @var Mockery\MockInterface */
	private $environment;

	/** @var list<string> Global holidays the test sets, answered by the holiday alias. */
	private array $global_holidays = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Monkey\Functions\when( '__' )->returnArg();

		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'esc_like' )->andReturnUsing( static fn( string $text ): string => addcslashes( $text, '_%\\' ) );

		$this->global_holiday = Mockery::mock( 'alias:FreeFormCertificate\Scheduling\DateBlockingService' );
		$this->global_holiday->shouldReceive( 'is_global_holiday' )->andReturnUsing( fn( string $date ): bool => in_array( $date, $this->global_holidays, true ) );

		$this->environment = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceEnvironmentRepository' );
		$this->environment->shouldReceive( 'is_holiday' )->andReturn( false );
		$this->environment->shouldReceive( 'get_working_hours' )->andReturn( null );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
		Mockery::close();
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
		$this->environment->shouldNotReceive( 'get_working_hours' );

		$plan = AudienceBookingRules::occurrences( 1, '2026-03-16', '2026-03-16', '14:00', '16:00', 'span', false );

		$this->assertSame(
			array( array( 'date' => '2026-03-16', 'start_time' => '14:00', 'end_time' => '16:00' ) ),
			$plan['days']
		);
	}

	public function test_skipping_blocked_days_leaves_them_out_and_records_them(): void {
		$this->global_holidays = array( '2026-03-17' );

		$plan = AudienceBookingRules::occurrences( 1, '2026-03-16', '2026-03-18', '09:00', '12:00', 'daily', true );

		$this->assertSame( array( '2026-03-17' ), $plan['skipped'] );
		$this->assertSame( array( '2026-03-16', '2026-03-18' ), array_column( $plan['days'], 'date' ) );
	}

	public function test_a_blocked_day_is_kept_when_the_range_does_not_skip(): void {
		$this->environment->shouldReceive( 'is_holiday' )->with( 1, '2026-03-17' )->andReturn( true );

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
		$this->assertSame( 5, $strings['seconds'] );
	}

	public function test_the_range_sentence_names_both_the_day_count_and_the_dates(): void {
		$range = AudienceBookingRules::cancel_strings()['range'];

		$this->assertStringContainsString( '%1$d', $range );
		$this->assertStringContainsString( '%2$s', $range );
	}
}
