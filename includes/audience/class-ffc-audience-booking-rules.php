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
	 * Option holding the longest range one booking may cover, in consecutive
	 * days, both ends included (#2243). Set on Scheduling → Settings →
	 * Audience; a cap keeps one request from writing a year of conflict
	 * checks, and a course that runs longer is two bookings.
	 */
	public const MAX_RANGE_OPTION = 'ffc_aud_max_range_days';

	/**
	 * The longest range before an administrator sets one.
	 */
	public const DEFAULT_MAX_RANGE_DAYS = 30;

	/**
	 * The highest value the setting accepts: a year.
	 */
	public const MAX_RANGE_LIMIT = 365;

	/**
	 * Fewest characters of the reason an override gives the people who lose
	 * days to it (#2243).
	 */
	public const OVERRIDE_REASON_MIN = 10;

	/**
	 * Most characters of that reason: it is stored in `cancellation_reason`
	 * when a booking is cancelled whole, which holds 500.
	 */
	public const OVERRIDE_REASON_MAX = 300;

	/**
	 * The longest range one booking may cover, clamped on read as well as on
	 * save, so a value written by hand still lands between 1 and a year.
	 *
	 * @return int
	 */
	public static function max_range_days(): int {
		return self::clamp_max_range( get_option( self::MAX_RANGE_OPTION, self::DEFAULT_MAX_RANGE_DAYS ) );
	}

	/**
	 * Bring a stored or posted range limit inside 1 to {@see self::MAX_RANGE_LIMIT}.
	 * A value that is not a number is not a limit, so it reads as the default.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	public static function clamp_max_range( $value ): int {
		if ( ! is_numeric( $value ) ) {
			return self::DEFAULT_MAX_RANGE_DAYS;
		}
		return max( 1, min( self::MAX_RANGE_LIMIT, (int) $value ) );
	}

	/**
	 * Whether the current user may book for people named one by one (#2243).
	 *
	 * An individual booking reaches whoever it names, so it takes the right
	 * to manage audiences, the audience bypass or WordPress administration.
	 * The booking route enforces it and the calendar offers the choice only
	 * to those who hold it.
	 *
	 * @return bool
	 */
	public static function can_book_individuals(): bool {
		return AudienceScheduleRepository::user_has_bypass_capability()
			|| \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_audiences' );
	}

	/**
	 * Days a range covers, both ends included: 1 for one day.
	 *
	 * A date difference, never an enumeration, so an absurd end date costs
	 * nothing to refuse. Both dates must already be valid.
	 *
	 * @param string $start_date First date (Y-m-d).
	 * @param string $end_date   Last date (Y-m-d), on or after the first.
	 * @return int
	 */
	public static function range_length( string $start_date, string $end_date ): int {
		$utc   = new \DateTimeZone( 'UTC' );
		$start = new \DateTimeImmutable( $start_date, $utc );
		$end   = new \DateTimeImmutable( $end_date, $utc );

		return (int) $start->diff( $end )->days + 1;
	}

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
	 * Whether "Skip blocked days" leaves a day out of a range: a holiday, or a
	 * weekday the space's working hours close (#2078). The option speaks of
	 * blocked days, and an operator skipping them means the weekend too.
	 *
	 * @param int    $environment_id Environment ID.
	 * @param string $date           Date (Y-m-d).
	 * @return bool
	 */
	public static function is_skippable_day( int $environment_id, string $date ): bool {
		if ( self::is_blocked_day( $environment_id, $date ) ) {
			return true;
		}

		$hours = AudienceEnvironmentRepository::get_working_hours( $environment_id );

		return $hours && ! \FreeFormCertificate\Scheduling\WorkingHoursService::is_working_day( $date, $hours );
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
	 * @param bool   $skip_blocked   Leave holidays and closed weekdays out of the range.
	 * @return array{days: list<array{date: string, start_time: string, end_time: string}>, skipped: list<string>}
	 */
	public static function occurrences( int $environment_id, string $start_date, string $end_date, string $start_time, string $end_time, string $time_mode, bool $skip_blocked ): array {
		$skipped = array();
		$booked  = array();

		foreach ( self::range_days( $start_date, $end_date ) as $date ) {
			if ( $skip_blocked && self::is_skippable_day( $environment_id, $date ) ) {
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
	 * @param string $date   Date (Y-m-d).
	 * @param string $prefix Table alias with its dot (`b.`), or '' for none.
	 * @return array{0: string, 1: list<string>} The SQL fragment and the values its placeholders take, in order.
	 */
	public static function occupies_day_clause( string $date, string $prefix = '' ): array {
		global $wpdb;

		return array(
			"{$prefix}booking_date <= %s AND COALESCE({$prefix}end_date, {$prefix}booking_date) >= %s AND ({$prefix}skipped_dates IS NULL OR {$prefix}skipped_dates NOT LIKE %s)",
			array( $date, $date, '%' . $wpdb->esc_like( '"' . $date . '"' ) . '%' ),
		);
	}

	/**
	 * The dates a booking row covers, for display: one date for a single day,
	 * `start – end` for a range.
	 *
	 * @param string                          $booking_date Start date (Y-m-d).
	 * @param string|null                     $end_date     Last date of a range, or NULL for one day.
	 * @param (callable(string): string)|null $format Renders one wall-clock date; NULL keeps Y-m-d.
	 * @return string
	 */
	public static function date_label( string $booking_date, ?string $end_date, ?callable $format = null ): string {
		$render = null === $format ? static fn( string $date ): string => $date : $format;
		$start  = $render( $booking_date );

		if ( null === $end_date || '' === $end_date || $end_date === $booking_date ) {
			return $start;
		}

		/* translators: 1: first date of a booking range, 2: last date of it. */
		return sprintf( __( '%1$s – %2$s', 'ffcertificate' ), $start, $render( $end_date ) );
	}

	/**
	 * The hours of a booking for display. A span starts on its first day and
	 * ends on its last, so `14:00 – 10:00` read as one day's hours would be
	 * nonsense; it says which day each end belongs to.
	 *
	 * @param string                          $start_time Stored start (H:i or H:i:s).
	 * @param string                          $end_time   Stored end (H:i or H:i:s).
	 * @param bool                            $is_span    The row is a `span` range.
	 * @param (callable(string): string)|null $format Renders one wall-clock time; NULL keeps H:i.
	 * @return string
	 */
	public static function time_label( string $start_time, string $end_time, bool $is_span, ?callable $format = null ): string {
		$render = null === $format ? static fn( string $time ): string => substr( $time, 0, 5 ) : $format;
		$start  = $render( $start_time );
		$end    = $render( $end_time );

		if ( ! $is_span ) {
			return $start . ' - ' . $end;
		}

		/* translators: 1: start time on the first day of a booking, 2: end time on its last day. */
		return sprintf( __( 'From %1$s on the first day to %2$s on the last day', 'ffcertificate' ), $start, $end );
	}

	/**
	 * Whether a stored row is a `span` range, whose stored hours are its
	 * first start and last end rather than every day's hours.
	 *
	 * @param object $row Booking row.
	 * @return bool
	 */
	public static function is_span( object $row ): bool {
		return 'span' === ( $row->time_mode ?? '' )
			&& count( self::booked_days( (string) ( $row->booking_date ?? '' ), isset( $row->end_date ) ? (string) $row->end_date : null, isset( $row->skipped_dates ) ? (string) $row->skipped_dates : null ) ) > 1;
	}

	/**
	 * The calendar-event dates of a booking, for an `.ics` file or a calendar
	 * link: `date` (the first booked day), `end_date` (the day the event
	 * ends), and for a `daily` range `rrule` and `exdates`.
	 *
	 * A daily range is one event repeated until its last booked day, with the
	 * days it skipped between them left out. A span is one block from the
	 * first booked day to the last. Either way the event starts and ends on
	 * booked days: a range whose first or last date was skipped starts or
	 * ends on the nearest day it actually books, not on the skipped one.
	 *
	 * @param string      $booking_date  Start date (Y-m-d).
	 * @param string|null $end_date      Last date of a range, or NULL for one day.
	 * @param string|null $time_mode     `daily` or `span`.
	 * @param string|null $skipped_dates JSON list of the days a range skipped.
	 * @return array{date: string, end_date: string, rrule?: string, exdates?: list<string>}
	 */
	public static function ics_fields( string $booking_date, ?string $end_date, ?string $time_mode, ?string $skipped_dates ): array {
		$booked = self::booked_days( $booking_date, $end_date, $skipped_dates );
		if ( count( $booked ) < 2 ) {
			$day = $booked[0] ?? $booking_date;
			return array(
				'date'     => $day,
				'end_date' => $day,
			);
		}

		$first = $booked[0];
		$last  = $booked[ count( $booked ) - 1 ];

		if ( 'daily' !== $time_mode ) {
			return array(
				'date'     => $first,
				'end_date' => $last,
			);
		}

		return array(
			'date'     => $first,
			'end_date' => $first,
			'rrule'    => 'FREQ=DAILY;UNTIL=' . str_replace( '-', '', $last ) . 'T235959',
			'exdates'  => array_values( array_diff( self::range_days( $first, $last ), $booked ) ),
		);
	}

	/**
	 * Words for the cancel confirmation, read by FFC.overrideConfirm().
	 *
	 * Cancelling a booking cancels every day it covers, so the dialog states
	 * the whole range and keeps the confirm button disabled for a countdown.
	 * The placeholders are filled in the browser: `%1$d` is the number of
	 * days, `%2$s` the date label, `%s` the date label of a single day.
	 *
	 * @return array{title: string, intro: string, agree: string, agreeIn: string, cancel: string, countdownStart: string, countdownEnd: string, single: string, range: string, seconds: int}
	 */
	public static function cancel_strings(): array {
		return array(
			'title'          => __( 'Cancel this booking?', 'ffcertificate' ),
			'intro'          => __( 'Read before you confirm:', 'ffcertificate' ),
			'agree'          => __( 'Cancel booking', 'ffcertificate' ),
			/* translators: %d: seconds left before the confirm button is enabled. */
			'agreeIn'        => __( 'Cancel booking (%d)', 'ffcertificate' ),
			'cancel'         => __( 'Keep booking', 'ffcertificate' ),
			/* translators: %d: seconds left before the confirm button is enabled. */
			'countdownStart' => __( 'You can confirm in %d seconds.', 'ffcertificate' ),
			'countdownEnd'   => __( 'You can confirm now.', 'ffcertificate' ),
			/* translators: %s: the date of the booking being cancelled. */
			'single'         => __( 'This cancels the booking on %s.', 'ffcertificate' ),
			/* translators: 1: number of days the booking covers, 2: the first and last dates of it. */
			'range'          => __( 'This booking covers %1$d days (%2$s). Cancelling it cancels every one of them.', 'ffcertificate' ),
			'seconds'        => \FreeFormCertificate\Core\ConfirmCountdown::SECONDS,
		);
	}

	/**
	 * The days of a stored booking row, skipping the ones its range left out.
	 * A single-day row has exactly its own date.
	 *
	 * @param string      $booking_date  Start date (Y-m-d).
	 * @param string|null $end_date      Last date of a range, or NULL for one day.
	 * @param string|null $skipped_dates JSON list of the days a range skipped.
	 * @return list<string>
	 */
	public static function booked_days( string $booking_date, ?string $end_date, ?string $skipped_dates ): array {
		if ( null === $end_date || '' === $end_date || $end_date === $booking_date ) {
			return array( $booking_date );
		}

		$skipped = null !== $skipped_dates && '' !== $skipped_dates ? json_decode( $skipped_dates, true ) : array();
		$skipped = is_array( $skipped ) ? $skipped : array();

		return array_values(
			array_filter(
				self::range_days( $booking_date, $end_date ),
				static function ( string $day ) use ( $skipped ): bool {
					return ! in_array( $day, $skipped, true );
				}
			)
		);
	}

	/**
	 * The hours a stored booking row takes on one of its days.
	 *
	 * A `daily` row takes its own hours every day. A `span` row takes its
	 * start time on its first booked day, its end time on its last, and the
	 * space's opening window on the days between, the same plan
	 * {@see occurrences()} wrote when the row was created. The stored
	 * `start_time` and `end_time` of a span are therefore not one day's hours,
	 * and comparing them as if they were misses every clash on a middle day.
	 *
	 * A span written since #2243 carries those hours frozen in `day_times`,
	 * so editing the space's working hours later changes no booking; a row
	 * without them (written before) still reads the window of today.
	 *
	 * @param object $row  Booking row with `environment_id`, `booking_date`, `start_time`, `end_time` and, for a range, `end_date`, `time_mode`, `skipped_dates` and `day_times`.
	 * @param string $date A day the row occupies (Y-m-d).
	 * @return array{start_time: string, end_time: string} Both in H:i.
	 */
	public static function stored_day_times( object $row, string $date ): array {
		$frozen = self::frozen_day_times( $row );
		if ( isset( $frozen[ $date ] ) ) {
			return $frozen[ $date ];
		}

		$start = substr( (string) ( $row->start_time ?? '' ), 0, 5 );
		$end   = substr( (string) ( $row->end_time ?? '' ), 0, 5 );
		$days  = self::booked_days( (string) ( $row->booking_date ?? '' ), isset( $row->end_date ) ? (string) $row->end_date : null, isset( $row->skipped_dates ) ? (string) $row->skipped_dates : null );

		if ( 'span' !== ( $row->time_mode ?? '' ) || count( $days ) < 2 ) {
			return array(
				'start_time' => $start,
				'end_time'   => $end,
			);
		}

		$is_first = $date === $days[0];
		$is_last  = $date === $days[ count( $days ) - 1 ];
		$window   = array(
			'start' => '',
			'end'   => '',
		);
		if ( ! ( $is_first && $is_last ) ) {
			$window = self::all_day_window( (int) ( $row->environment_id ?? 0 ), $date );
		}

		return self::day_times( 'span', $is_first, $is_last, $start, $end, $window );
	}

	/**
	 * The hours each day of a span takes, as stored when it was created
	 * (#2243): `{"Y-m-d": ["H:i", "H:i"], …}`. NULL for a single day or a
	 * `daily` range, whose stored hours are already every day's hours.
	 *
	 * @param list<array{date: string, start_time: string, end_time: string}> $days The planned days ({@see occurrences()}).
	 * @param string                                                          $time_mode `daily` or `span`.
	 * @return string|null JSON, or NULL when there is nothing to freeze.
	 */
	public static function encode_day_times( array $days, string $time_mode ): ?string {
		if ( 'span' !== $time_mode || count( $days ) < 2 ) {
			return null;
		}

		$map = array();
		foreach ( $days as $day ) {
			$map[ $day['date'] ] = array( substr( $day['start_time'], 0, 5 ), substr( $day['end_time'], 0, 5 ) );
		}

		$json = wp_json_encode( $map );
		return false === $json ? null : $json;
	}

	/**
	 * The hours frozen on a row, by date; empty when it carries none or the
	 * stored value is not the shape {@see encode_day_times()} writes.
	 *
	 * @param object $row Booking row.
	 * @return array<string, array{start_time: string, end_time: string}>
	 */
	public static function frozen_day_times( object $row ): array {
		$raw = isset( $row->day_times ) ? (string) $row->day_times : '';
		if ( '' === $raw ) {
			return array();
		}

		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$map = array();
		foreach ( $decoded as $date => $hours ) {
			if ( is_string( $date ) && is_array( $hours ) && isset( $hours[0], $hours[1] ) && is_string( $hours[0] ) && is_string( $hours[1] ) ) {
				$map[ $date ] = array(
					'start_time' => $hours[0],
					'end_time'   => $hours[1],
				);
			}
		}
		return $map;
	}

	/**
	 * Each booked day of a span row with the hours it takes, for a screen that
	 * lists the booking on every day. Empty for a single day or a `daily`
	 * range, whose stored hours are already every day's hours.
	 *
	 * @param object $row Booking row ({@see stored_day_times()}).
	 * @return array<string, array{start_time: string, end_time: string}> Keyed by date (Y-m-d).
	 */
	public static function day_times_map( object $row ): array {
		if ( ! self::is_span( $row ) ) {
			return array();
		}

		$map = array();
		foreach ( self::booked_days( (string) ( $row->booking_date ?? '' ), isset( $row->end_date ) ? (string) $row->end_date : null, isset( $row->skipped_dates ) ? (string) $row->skipped_dates : null ) as $day ) {
			$map[ $day ] = self::stored_day_times( $row, $day );
		}
		return $map;
	}

	/**
	 * The rows, among those occupying a day, whose hours on that day overlap
	 * the given hours. Touching ends do not overlap: 09:00–10:00 and
	 * 10:00–11:00 can both be booked.
	 *
	 * @template T of object
	 * @param object[] $rows       Rows already known to occupy `$date` ({@see occupies_day_clause()}).
	 * @phpstan-param list<T> $rows
	 * @param string   $date       Day (Y-m-d).
	 * @param string   $start_time Start of the hours checked (H:i or H:i:s).
	 * @param string   $end_time   End of the hours checked (H:i or H:i:s).
	 * @return list<T>
	 */
	public static function overlapping_on_day( array $rows, string $date, string $start_time, string $end_time ): array {
		$start = substr( $start_time, 0, 5 );
		$end   = substr( $end_time, 0, 5 );

		return array_values(
			array_filter(
				$rows,
				static function ( object $row ) use ( $date, $start, $end ): bool {
					$taken = self::stored_day_times( $row, $date );
					return $taken['start_time'] < $end && $start < $taken['end_time'];
				}
			)
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
