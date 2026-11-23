<?php
/**
 * Audience Attendance Status
 *
 * Whether a participant was recorded present, for their dashboard and their
 * app (#2749).
 *
 * @package FreeFormCertificate\Audience
 * @since 6.50.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

use FreeFormCertificate\Core\Attendance;
use FreeFormCertificate\Core\DateFormatter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A person's attendance on a booking, read from the rows the site already
 * holds, so a reading that synced late shows the moment it arrives.
 *
 * The count follows the booking's attendance rule: a single-day event says
 * "Present", a multi-day one "Present: 2 of 3 days", and `met` says whether
 * the days present satisfy the rule.
 *
 * @phpstan-type AttendanceStatusShape array{met: bool, days: int, total: int, required: int, label: string, method: string, checked_at: int, detail: string}
 * @phpstan-type AttendanceConfirmation array{type: string, key: string, title: string, text: string, detail: string}
 */
final class AudienceAttendanceStatus {

	/**
	 * How long a confirmation stays among the participant's pending items, in
	 * days, unless they dismiss it.
	 */
	public const CONFIRMATION_DAYS = 7;

	/**
	 * The status of each booking for one person, from one query.
	 *
	 * @param int                              $user_id  User.
	 * @param array<int, array<string, mixed>> $bookings Booking rows.
	 * @return array<int, AttendanceStatusShape> Booking ID => status; a booking with no attendance is absent.
	 */
	public static function for_bookings( int $user_id, array $bookings ): array {
		$by_id = array();
		foreach ( $bookings as $booking ) {
			$id = (int) ( $booking['id'] ?? 0 );
			if ( $id > 0 ) {
				$by_id[ $id ] = $booking;
			}
		}
		$rows   = AudienceAttendanceReader::for_user( $user_id, array_keys( $by_id ) );
		$status = array();
		foreach ( $rows as $booking_id => $lines ) {
			if ( ! isset( $by_id[ $booking_id ] ) ) {
				continue;
			}
			$one = self::of( (object) $by_id[ $booking_id ], $lines );
			if ( null !== $one ) {
				$status[ $booking_id ] = $one;
			}
		}
		return $status;
	}

	/**
	 * One booking's status from the person's rows on it.
	 *
	 * @param object                                                     $booking Booking row.
	 * @param list<array{date: string, checked_at: int, method: string}> $rows    The person's rows, oldest first.
	 * @return AttendanceStatusShape|null Null when the person has no row.
	 */
	public static function of( object $booking, array $rows ): ?array {
		if ( array() === $rows ) {
			return null;
		}

		$counted  = AudienceAttendanceRule::counted_days( $booking );
		$days     = array_values( array_unique( array_column( $rows, 'date' ) ) );
		$present  = count( array_intersect( $days, $counted ) );
		$total    = max( 1, count( $counted ) );
		$required = AudienceAttendanceRule::required_days( $booking );
		$latest   = $rows[ count( $rows ) - 1 ];

		$label = 1 === $total
			? __( 'Present', 'ffcertificate' )
			: sprintf(
				/* translators: 1: days the person was present, 2: days the event counts */
				__( 'Present: %1$d of %2$d days', 'ffcertificate' ),
				$present,
				$total
			);

		return array(
			'met'        => AudienceAttendanceRule::satisfied_by( $booking, $days ),
			'days'       => $present,
			'total'      => $total,
			'required'   => $required,
			'label'      => $label,
			'method'     => $latest['method'],
			'checked_at' => $latest['checked_at'],
			'detail'     => self::detail( $latest['method'], $latest['checked_at'] ),
		);
	}

	/**
	 * The attendance confirmations a person has not had for long: one per day
	 * recorded within {@see self::CONFIRMATION_DAYS} days.
	 *
	 * @param int                              $user_id  User.
	 * @param array<int, array<string, mixed>> $bookings Booking rows.
	 * @param int                              $now      Unix time.
	 * @return list<AttendanceConfirmation> Newest first.
	 */
	public static function confirmations( int $user_id, array $bookings, int $now ): array {
		$by_id = array();
		foreach ( $bookings as $booking ) {
			$id = (int) ( $booking['id'] ?? 0 );
			if ( $id > 0 && 'cancelled' !== ( $booking['status'] ?? '' ) ) {
				$by_id[ $id ] = $booking;
			}
		}
		$since = $now - self::CONFIRMATION_DAYS * DAY_IN_SECONDS;
		$items = array();
		foreach ( AudienceAttendanceReader::for_user( $user_id, array_keys( $by_id ) ) as $booking_id => $lines ) {
			if ( ! isset( $by_id[ $booking_id ] ) ) {
				continue;
			}
			$title = self::title( $by_id[ $booking_id ] );
			foreach ( $lines as $line ) {
				if ( $line['checked_at'] < $since ) {
					continue;
				}
				$items[] = array(
					'type'       => 'confirmed',
					'key'        => 'confirmed:' . $booking_id . ':' . $line['date'],
					'title'      => $title,
					/* translators: %s: event title */
					'text'       => sprintf( __( 'Attendance confirmed at %s', 'ffcertificate' ), $title ),
					'detail'     => self::detail( $line['method'], $line['checked_at'] ),
					'checked_at' => $line['checked_at'],
				);
			}
		}
		usort( $items, static fn( array $a, array $b ): int => $b['checked_at'] <=> $a['checked_at'] );

		return array_map(
			static function ( array $item ): array {
				unset( $item['checked_at'] );
				return $item;
			},
			$items
		);
	}

	/**
	 * The name a participant knows an event by: its description, or the
	 * calendar's name when it has none.
	 *
	 * @param array<string, mixed> $booking Booking row.
	 * @return string
	 */
	public static function title( array $booking ): string {
		$title = trim( (string) ( $booking['description'] ?? '' ) );
		return '' === $title ? (string) ( $booking['schedule_name'] ?? '' ) : $title;
	}

	/**
	 * How and when a reading was recorded, in the site's formats.
	 *
	 * @param string $method     Attendance method.
	 * @param int    $checked_at Unix time.
	 * @return string
	 */
	private static function detail( string $method, int $checked_at ): string {
		$when = DateFormatter::format_datetime( $checked_at );
		return '' === $when ? Attendance::label( $method ) : Attendance::label( $method ) . ' · ' . $when;
	}
}
