<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use FreeFormCertificate\SelfScheduling\SlotGrid;
use PHPUnit\Framework\TestCase;

/**
 * The regular-mode slot grid (#2244): what the listing offers and the only
 * start times the validator accepts.
 *
 * @covers \FreeFormCertificate\SelfScheduling\SlotGrid
 */
class SlotGridTest extends TestCase {

	/** 2026-11-02 is a Monday. */
	private const MONDAY = '2026-11-02';

	/** 2026-11-01 is a Sunday. */
	private const SUNDAY = '2026-11-01';

	/**
	 * @param list<array<string, mixed>> $hours Working hours.
	 * @return array<string, mixed>
	 */
	private static function calendar( array $hours, int $duration = 30, int $interval = 0 ): array {
		return array(
			'working_hours' => $hours,
			'slot_duration' => $duration,
			'slot_interval' => $interval,
		);
	}

	/** @return list<string> */
	private static function starts( array $slots ): array {
		return array_column( $slots, 'start' );
	}

	public function test_steps_by_duration_plus_interval_and_ends_by_closing(): void {
		$calendar = self::calendar( array( array( 'day' => 1, 'start' => '09:00', 'end' => '10:40' ) ), 30, 10 );

		$slots = SlotGrid::slots( $calendar, self::MONDAY, false );

		$this->assertSame( array( '09:00:00', '09:40:00' ), self::starts( $slots ), '10:20 would end at 10:50, after closing.' );
		$this->assertSame( '10:10:00', $slots[1]['end'] );
	}

	public function test_a_slot_that_would_end_after_closing_is_not_offered(): void {
		$calendar = self::calendar( array( array( 'day' => 1, 'start' => '09:00', 'end' => '10:00' ) ), 45 );

		$this->assertSame( array( '09:00:00' ), self::starts( SlotGrid::slots( $calendar, self::MONDAY, false ) ) );
	}

	public function test_a_late_range_never_wraps_past_midnight(): void {
		$calendar = self::calendar( array( array( 'day' => 1, 'start' => '23:00', 'end' => '23:59' ) ), 60 );

		$this->assertSame( array(), SlotGrid::slots( $calendar, self::MONDAY, false ) );
	}

	public function test_several_ranges_are_merged_in_order(): void {
		$calendar = self::calendar(
			array(
				array( 'day' => 1, 'start' => '14:00', 'end' => '15:00' ),
				array( 'day' => 1, 'start' => '08:00', 'end' => '09:00' ),
			),
			60
		);

		$this->assertSame( array( '08:00:00', '14:00:00' ), self::starts( SlotGrid::slots( $calendar, self::MONDAY, false ) ) );
	}

	public function test_a_day_without_hours_is_empty_unless_the_bypass_takes_the_default_day(): void {
		$calendar = self::calendar( array( array( 'day' => 1, 'start' => '09:00', 'end' => '10:00' ) ), 60 );

		$this->assertSame( array(), SlotGrid::slots( $calendar, self::SUNDAY, false ) );
		$bypass = SlotGrid::slots( $calendar, self::SUNDAY, true );
		$this->assertSame( '09:00:00', $bypass[0]['start'] );
		$this->assertSame( '18:00:00', end( $bypass )['end'] );
	}

	public function test_a_json_working_hours_string_is_read(): void {
		$calendar = self::calendar( array() );
		$calendar['working_hours'] = (string) json_encode( array( array( 'day' => 1, 'start' => '09:00', 'end' => '09:30' ) ) );

		$this->assertSame( array( '09:00:00' ), self::starts( SlotGrid::slots( $calendar, self::MONDAY, false ) ) );
	}

	public function test_a_zero_duration_offers_nothing(): void {
		$this->assertSame( array(), SlotGrid::slots( self::calendar( array( array( 'day' => 1, 'start' => '09:00', 'end' => '18:00' ) ), 0 ), self::MONDAY, false ) );
	}

	public function test_find_accepts_only_a_start_on_the_grid(): void {
		$calendar = self::calendar( array( array( 'day' => 1, 'start' => '09:00', 'end' => '10:00' ) ), 15 );

		$this->assertSame( array( 'start' => '09:15:00', 'end' => '09:30:00' ), SlotGrid::find( $calendar, self::MONDAY, '09:15', false ) );
		$this->assertSame( '09:15:00', SlotGrid::find( $calendar, self::MONDAY, '09:15:42', false )['start'] ?? null, 'Seconds do not make a slot of their own.' );
		foreach ( array( '09:05', '09:06:00', '10:00', '08:45', 'nine', '25:00', '' ) as $off ) {
			$this->assertNull( SlotGrid::find( $calendar, self::MONDAY, $off, false ), $off );
		}
	}

	public function test_normalize(): void {
		$this->assertSame( '09:05:00', SlotGrid::normalize( '9:05' ) );
		$this->assertSame( '09:05:00', SlotGrid::normalize( '09:05:59' ) );
		$this->assertNull( SlotGrid::normalize( '09:60' ) );
		$this->assertNull( SlotGrid::normalize( '09:05; DROP' ) );
	}
}
