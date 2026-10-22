<?php
/**
 * Audience Booking Rules
 *
 * The rules a booking on a shared space is held to, read in one place by the
 * REST controller that creates the booking and by the month read that tells
 * the calendar which days and spaces are open (#1859).
 *
 * @package FreeFormCertificate\Audience
 * @since 6.40.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Evaluates the booking rules of an audience calendar.
 *
 * A rule here is one a bypass holder may break after confirming a warning:
 * the date is in the past or beyond the advance window, it is a holiday, the
 * space is closed on that weekday, or the times fall outside its opening
 * hours. An inactive calendar or space is not a rule of that kind — it
 * refuses every caller — and neither are the form's own checks (a time range
 * that ends before it starts, the description length).
 *
 * Dates are wall-clock dates in the site's time zone: "today" is
 * `current_time( 'Y-m-d' )`, and the advance window is counted in whole days
 * from it, never from a UTC timestamp.
 *
 * @phpstan-import-type ScheduleRow from AudienceScheduleRepository
 * @phpstan-import-type EnvironmentRow from AudienceEnvironmentRepository
 */
final class AudienceBookingRules {

	public const PAST_DATE     = 'past_date';
	public const BEYOND_WINDOW = 'beyond_window';
	public const HOLIDAY       = 'holiday';
	public const CLOSED_DAY    = 'closed_day';
	public const OUTSIDE_HOURS = 'outside_hours';
	public const CONFLICT      = 'conflict';

	/**
	 * Longest range one booking may cover, in days, inclusive of both ends.
	 * A cap keeps one request from writing a month of conflict checks, and a
	 * course or event that runs longer is two bookings.
	 */
	public const MAX_RANGE_DAYS = 31;

	/**
	 * Every date from `$start_date` to `$end_date`, both included.
	 *
	 * @param string $start_date First date (Y-m-d).
	 * @param string $end_date   Last date (Y-m-d), on or after the first.
	 * @return list<string>
	 */
	public static function range_days( string $start_date, string $end_date ): array {
		$days = array();
		$day  = new \DateTimeImmutable( $start_date );
		$last = new \DateTimeImmutable( $end_date );

		while ( $day <= $last ) {
			$days[] = $day->format( 'Y-m-d' );
			$day    = $day->modify( '+1 day' );
		}

		return $days;
	}

	/**
	 * The start and end a day takes.
	 *
	 * `daily` repeats the same hours every day. `span` runs from the start
	 * time on the first booked day to the end time on the last, and covers
	 * the whole opening window on the days between.
	 *
	 * @param string                            $time_mode  `daily` or `span`.
	 * @param bool                              $is_first   The first booked day of the range.
	 * @param bool                              $is_last    The last booked day of the range.
	 * @param string                            $start_time Start (H:i) the person chose.
	 * @param string                            $end_time   End (H:i) the person chose.
	 * @param array{start: string, end: string} $window Opening window of the day, read only for `span`.
	 * @return array{start_time: string, end_time: string}
	 */
	public static function day_times( string $time_mode, bool $is_first, bool $is_last, string $start_time, string $end_time, array $window ): array {
		if ( 'span' !== $time_mode ) {
			return array(
				'start_time' => $start_time,
				'end_time'   => $end_time,
			);
		}

		return array(
			'start_time' => $is_first ? $start_time : $window['start'],
			'end_time'   => $is_last ? $end_time : $window['end'],
		);
	}

	/**
	 * Whether a day is a holiday for the calendar: a global one or the space's
	 * own. These are the days a booking refuses unless the range skips them.
	 *
	 * @param int    $environment_id Environment ID.
	 * @param string $date           Date (Y-m-d).
	 * @return bool
	 */
	public static function is_blocked_day( int $environment_id, string $date ): bool {
		return \FreeFormCertificate\Scheduling\DateBlockingService::is_global_holiday( $date )
			|| AudienceEnvironmentRepository::is_holiday( $environment_id, $date );
	}

	/**
	 * The days a booking takes, with the hours each one takes, and the days a
	 * range leaves out because they are blocked and the operator skipped them.
	 *
	 * A single day is one occurrence and never skips. For a range the days
	 * are the ones that remain after skipping, so `span` starts and ends on
	 * the days actually booked.
	 *
	 * @param int    $environment_id Environment ID.
	 * @param string $start_date     First date (Y-m-d).
	 * @param string $end_date       Last date (Y-m-d), equal to the first for a single day.
	 * @param string $start_time     Start (H:i).
	 * @param string $end_time       End (H:i).
	 * @param string $time_mode      `daily` or `span`.
	 * @param bool   $skip_blocked   Leave blocked days out of the range.
	 * @return array{days: list<array{date: string, start_time: string, end_time: string}>, skipped: list<string>}
	 */
	public static function occurrences( int $environment_id, string $start_date, string $end_date, string $start_time, string $end_time, string $time_mode, bool $skip_blocked ): array {
		$skipped = array();
		$booked  = array();

		foreach ( self::range_days( $start_date, $end_date ) as $date ) {
			if ( $skip_blocked && self::is_blocked_day( $environment_id, $date ) ) {
				$skipped[] = $date;
				continue;
			}
			$booked[] = $date;
		}

		$days  = array();
		$count = count( $booked );
		foreach ( $booked as $index => $date ) {
			$is_first = 0 === $index;
			$is_last  = $index === $count - 1;
			$window   = array(
				'start' => '',
				'end'   => '',
			);
			if ( 'span' === $time_mode && ! ( $is_first && $is_last ) ) {
				$window = self::all_day_window( $environment_id, $date );
			}

			$days[] = array( 'date' => $date ) + self::day_times( $time_mode, $is_first, $is_last, $start_time, $end_time, $window );
		}

		return array(
			'days'    => $days,
			'skipped' => $skipped,
		);
	}

	/**
	 * SQL predicate that a stored booking occupies a given day: the day falls
	 * inside its range and is not one the range skipped. Used by every
	 * conflict check, so a range blocks each day it covers.
	 *
	 * The skipped days are a JSON list in `skipped_dates`; the date is matched
	 * with its quotes so `2026-10-2` never matches `2026-10-22`.
	 *
	 * @param string $date Date (Y-m-d).
	 * @return array{0: string, 1: list<string>} The SQL fragment and the values its placeholders take, in order.
	 */
	public static function occupies_day_clause( string $date ): array {
		global $wpdb;

		return array(
			'booking_date <= %s AND COALESCE(end_date, booking_date) >= %s AND (skipped_dates IS NULL OR skipped_dates NOT LIKE %s)',
			array( $date, $date, '%' . $wpdb->esc_like( '"' . $date . '"' ) . '%' ),
		);
	}

	/**
	 * Weekday keys of the keyed working-hours format, by `w` number.
	 *
	 * @var array<int, string>
	 */
	private const DAY_KEYS = array( 'sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat' );

	/**
	 * Today's date in the site's time zone.
	 *
	 * @return string Y-m-d.
	 */
	public static function today(): string {
		return (string) current_time( 'Y-m-d' );
	}

	/**
	 * The last date a schedule's advance window allows, or null without a limit.
	 *
	 * Counted in calendar days from today in the site's time zone. The
	 * arithmetic runs on a date with no time of day, in UTC, so no offset can
	 * move it across midnight.
	 *
	 * @param object $schedule Schedule row.
	 * @phpstan-param ScheduleRow $schedule
	 * @return string|null Y-m-d.
	 */
	public static function last_bookable_date( object $schedule ): ?string {
		$limit = isset( $schedule->future_days_limit ) ? (int) $schedule->future_days_limit : 0;
		if ( $limit <= 0 ) {
			return null;
		}

		try {
			$today = new \DateTimeImmutable( self::today(), new \DateTimeZone( 'UTC' ) );
		} catch ( \Exception $e ) {
			return null;
		}

		return $today->modify( '+' . $limit . ' days' )->format( 'Y-m-d' );
	}

	/**
	 * The time window an all-day booking takes on a date.
	 *
	 * All day means the space's whole opening window that day: from the
	 * first opening to the last closing. A space with no working hours, or a
	 * day with no range declared, is open around the clock, and so is a
	 * closed day — the closure is reported as its own rule, and the window is
	 * only what the booking would occupy if a bypass holder confirms it.
	 *
	 * @param int    $environment_id Environment ID.
	 * @param string $date           Date (Y-m-d).
	 * @return array{start: string, end: string}
	 */
	public static function all_day_window( int $environment_id, string $date ): array {
		$window = array(
			'start' => '00:00',
			'end'   => '23:59',
		);

		$hours = AudienceEnvironmentRepository::get_working_hours( $environment_id );
		if ( ! $hours ) {
			return $window;
		}

		$ranges = \FreeFormCertificate\Scheduling\WorkingHoursService::get_day_ranges( $date, $hours );
		if ( empty( $ranges ) ) {
			return $window;
		}

		$starts = array_map(
			static function ( array $r ): string {
				return substr( (string) $r['start'], 0, 5 );
			},
			$ranges
		);
		$ends   = array_map(
			static function ( array $r ): string {
				return substr( (string) $r['end'], 0, 5 );
			},
			$ranges
		);

		return array(
			'start' => min( $starts ),
			'end'   => max( $ends ),
		);
	}

	/**
	 * Every rule a booking breaks, in the order a refusal names them.
	 *
	 * @param object $schedule    Schedule row.
	 * @param object $environment Environment row.
	 * @param string $date        Date (Y-m-d).
	 * @param string $start_time  Start (H:i).
	 * @param string $end_time    End (H:i).
	 * @phpstan-param ScheduleRow $schedule
	 * @phpstan-param EnvironmentRow $environment
	 * @return list<array{code: string, message: string}>
	 */
	public static function violations( object $schedule, object $environment, string $date, string $start_time, string $end_time ): array {
		$violations = array();

		if ( $date < self::today() ) {
			$violations[] = self::reason( self::PAST_DATE );
		}

		$last = self::last_bookable_date( $schedule );
		if ( null !== $last && $date > $last ) {
			$violations[] = self::reason( self::BEYOND_WINDOW, (int) $schedule->future_days_limit );
		}

		$environment_id = (int) $environment->id;
		if ( self::is_blocked_day( $environment_id, $date ) ) {
			$violations[] = self::reason( self::HOLIDAY );
		}

		$hours = AudienceEnvironmentRepository::get_working_hours( $environment_id );
		if ( $hours ) {
			if ( ! \FreeFormCertificate\Scheduling\WorkingHoursService::is_working_day( $date, $hours ) ) {
				$violations[] = self::reason( self::CLOSED_DAY );
			} elseif ( ! \FreeFormCertificate\Scheduling\WorkingHoursService::is_range_within_working_hours( $date, $start_time, $end_time, $hours ) ) {
				$violations[] = self::reason( self::OUTSIDE_HOURS );
			}
		}

		return $violations;
	}

	/**
	 * A reason entry: its machine code and the sentence a person reads.
	 *
	 * @param string $code  One of the class constants.
	 * @param int    $count The advance window, for BEYOND_WINDOW.
	 * @return array{code: string, message: string}
	 */
	public static function reason( string $code, int $count = 0 ): array {
		switch ( $code ) {
			case self::PAST_DATE:
				$message = __( 'Cannot book dates in the past.', 'ffcertificate' );
				break;
			case self::BEYOND_WINDOW:
				$message = sprintf(
					/* translators: %d: maximum number of days allowed for advance booking */
					__( 'Cannot book more than %d days in advance.', 'ffcertificate' ),
					$count
				);
				break;
			case self::HOLIDAY:
				$message = __( 'This date is a holiday.', 'ffcertificate' );
				break;
			case self::CLOSED_DAY:
				$message = __( 'This space is closed on this day.', 'ffcertificate' );
				break;
			case self::OUTSIDE_HOURS:
				$message = __( 'The booking falls outside the opening hours of this space.', 'ffcertificate' );
				break;
			case self::CONFLICT:
				$message = __( 'This time slot is already booked for this environment.', 'ffcertificate' );
				break;
			default:
				$message = $code;
		}

		return array(
			'code'    => $code,
			'message' => $message,
		);
	}

	/**
	 * The opening pattern of each environment, for the calendar to read.
	 *
	 * Per environment: its schedule, the weekdays it is closed, and the
	 * opening range of each weekday (`null` for the whole map when the space
	 * declares no hours, which means always open). The client combines it
	 * with the holidays to tell which spaces are open on a date.
	 *
	 * @param array<int, object> $environments Environment rows.
	 * @phpstan-param array<int, EnvironmentRow> $environments
	 * @return list<array{id: int, schedule_id: int, closed_weekdays: list<int>, hours: array<int, array{start: string, end: string}|null>|null}>
	 */
	public static function availability( array $environments ): array {
		$out = array();

		foreach ( $environments as $environment ) {
			$hours           = AudienceEnvironmentRepository::get_working_hours( (int) $environment->id );
			$closed_weekdays = array();
			$by_weekday      = null;

			if ( $hours ) {
				$by_weekday = array();
				foreach ( self::DAY_KEYS as $weekday => $key ) {
					$day = $hours[ $key ] ?? null;
					if ( null === $day ) {
						$by_weekday[ $weekday ] = null;
						continue;
					}
					if ( ! empty( $day['closed'] ) ) {
						$closed_weekdays[]      = $weekday;
						$by_weekday[ $weekday ] = null;
						continue;
					}
					$by_weekday[ $weekday ] = isset( $day['start'] ) && isset( $day['end'] )
						? array(
							'start' => substr( (string) $day['start'], 0, 5 ),
							'end'   => substr( (string) $day['end'], 0, 5 ),
						)
						: null;
				}
			}

			$out[] = array(
				'id'              => (int) $environment->id,
				'schedule_id'     => (int) $environment->schedule_id,
				'closed_weekdays' => $closed_weekdays,
				'hours'           => $by_weekday,
			);
		}

		return $out;
	}

	/**
	 * The weekdays every given environment is closed on.
	 *
	 * A weekday on which at least one space opens stays open: the "all
	 * spaces" view lets the day be picked and lists the spaces open on it.
	 *
	 * @param list<array{closed_weekdays: list<int>}> $availability Output of {@see self::availability()}.
	 * @return list<int>
	 */
	public static function shared_closed_weekdays( array $availability ): array {
		if ( empty( $availability ) ) {
			return array();
		}

		$shared = null;
		foreach ( $availability as $entry ) {
			$shared = null === $shared
				? $entry['closed_weekdays']
				: array_values( array_intersect( $shared, $entry['closed_weekdays'] ) );
		}

		$shared = array_map( 'intval', (array) $shared );
		sort( $shared );

		return $shared;
	}
}
