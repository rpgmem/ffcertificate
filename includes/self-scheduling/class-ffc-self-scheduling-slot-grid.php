<?php
/**
 * Slot Grid
 *
 * The start times a regular-mode personal calendar offers on a date: each of
 * the day's working ranges stepped by the slot duration plus the interval,
 * keeping only the slots that end by the range's closing time. The listing
 * and the validator both read it, so a start time the listing never offered
 * is refused on submit (#2244).
 *
 * Times are minutes after midnight in wall-clock time (Category B), so no
 * timezone and no timestamp is involved, and a slot that would end after
 * midnight is never generated.
 *
 * @package FreeFormCertificate\SelfScheduling
 * @since   6.43.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\SelfScheduling;

use FreeFormCertificate\Scheduling\WorkingHoursService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The regular-mode slot grid.
 */
final class SlotGrid {

	/**
	 * The hours a bypass holder sees on a day with no working hours (#1870).
	 */
	private const BYPASS_DAY = array(
		'start' => '09:00',
		'end'   => '18:00',
	);

	/**
	 * Every slot of a date, in order.
	 *
	 * @param array<string, mixed> $calendar   Calendar row (working_hours, slot_duration, slot_interval).
	 * @param string               $date       Date (Y-m-d).
	 * @param bool                 $has_bypass Whether a day with no hours takes the bypass hours.
	 * @return list<array{start: string, end: string}> Times as `H:i:s`.
	 */
	public static function slots( array $calendar, string $date, bool $has_bypass ): array {
		$duration = (int) ( $calendar['slot_duration'] ?? 0 );
		if ( $duration <= 0 ) {
			return array();
		}
		$step = $duration + max( 0, (int) ( $calendar['slot_interval'] ?? 0 ) );

		$ranges = WorkingHoursService::get_day_ranges( $date, $calendar['working_hours'] ?? array() );
		if ( array() === $ranges && $has_bypass ) {
			$ranges = array( self::BYPASS_DAY );
		}

		$slots = array();
		foreach ( $ranges as $range ) {
			$open  = self::minutes( (string) ( $range['start'] ?? '' ) );
			$close = self::minutes( (string) ( $range['end'] ?? '' ) );
			if ( null === $open || null === $close ) {
				continue;
			}
			for ( $start = $open; $start + $duration <= $close; $start += $step ) {
				$slots[] = array(
					'start' => self::clock( $start ),
					'end'   => self::clock( $start + $duration ),
				);
			}
		}

		usort(
			$slots,
			static function ( array $a, array $b ): int {
				return strcmp( $a['start'], $b['start'] );
			}
		);

		return $slots;
	}

	/**
	 * The slot that starts at $time on $date, or null when the grid has none.
	 *
	 * @param array<string, mixed> $calendar   Calendar row.
	 * @param string               $date       Date (Y-m-d).
	 * @param string               $time       Start time (`H:i` or `H:i:s`).
	 * @param bool                 $has_bypass See slots().
	 * @return array{start: string, end: string}|null
	 */
	public static function find( array $calendar, string $date, string $time, bool $has_bypass ): ?array {
		$start = self::normalize( $time );
		if ( null === $start ) {
			return null;
		}
		foreach ( self::slots( $calendar, $date, $has_bypass ) as $slot ) {
			if ( $slot['start'] === $start ) {
				return $slot;
			}
		}
		return null;
	}

	/**
	 * The end of a slot of $duration minutes starting at $time, or null when
	 * it would end after midnight (or the input is not a time).
	 *
	 * @param string $time     Start time.
	 * @param int    $duration Minutes.
	 * @return string|null `H:i:00`.
	 */
	public static function end_after( string $time, int $duration ): ?string {
		$start = self::minutes( $time );
		if ( null === $start || $duration <= 0 || $start + $duration > 1440 ) {
			return null;
		}
		return self::clock( $start + $duration );
	}

	/**
	 * A time as `H:i:00`, or null when it is not a time of day. Seconds are
	 * dropped, so 09:00:07 is the 09:00 slot and not a slot of its own.
	 *
	 * @param string $time `H:i` or `H:i:s`.
	 * @return string|null
	 */
	public static function normalize( string $time ): ?string {
		$minutes = self::minutes( $time );
		return null === $minutes ? null : self::clock( $minutes );
	}

	/**
	 * Minutes after midnight, or null for anything that is not `H:i(:s)`.
	 *
	 * @param string $time Time.
	 * @return int|null
	 */
	private static function minutes( string $time ): ?int {
		if ( 1 !== preg_match( '/^([01]?\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?$/', trim( $time ), $m ) ) {
			// 24:00 is a closing time, never a start.
			return '24:00' === substr( trim( $time ), 0, 5 ) ? 1440 : null;
		}
		return (int) $m[1] * 60 + (int) $m[2];
	}

	/**
	 * Minutes after midnight as `H:i:00`.
	 *
	 * @param int $minutes 0..1440.
	 * @return string
	 */
	private static function clock( int $minutes ): string {
		return sprintf( '%02d:%02d:00', intdiv( $minutes, 60 ), $minutes % 60 );
	}
}
