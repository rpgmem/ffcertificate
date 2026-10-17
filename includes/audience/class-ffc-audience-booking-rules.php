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
		if ( \FreeFormCertificate\Scheduling\DateBlockingService::is_global_holiday( $date )
			|| AudienceEnvironmentRepository::is_holiday( $environment_id, $date ) ) {
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
