<?php
/**
 * Audience Attendance Roster
 *
 * Who is expected at a booking and how each of them stands (#1947).
 *
 * @package FreeFormCertificate\Audience
 * @since 6.42.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One list for the attendance screen, its live refresh and its CSV export,
 * so the three can never disagree about who was present.
 *
 * The participants are the booking's affected users (its custom users and
 * the members of its audiences, sub-audiences included) as they are now;
 * a line recorded for someone who has since left the booking still counts
 * for them, and they are listed so the line is not hidden.
 *
 * @phpstan-type RosterRow array{user_id: int, name: string, present_at: int|null, method: string|null, days: list<string>, met: bool}
 * @phpstan-type Roster array{rows: list<RosterRow>, present: int, total: int, required: int, days: list<string>}
 *
 * @since 6.42.0
 */
final class AudienceAttendanceRoster {

	/**
	 * The roster of a booking, with one day in focus.
	 *
	 * @param object $booking Booking row.
	 * @param string $date    Day in focus (Y-m-d).
	 * @return array<string, mixed>
	 * @phpstan-return Roster
	 */
	public static function for_day( object $booking, string $date ): array {
		$booking_id = (int) ( $booking->id ?? 0 );
		$days       = AudienceAttendanceRule::counted_days( $booking );
		$by_user    = AudienceAttendanceReader::days_by_user( $booking_id );

		$today = array();
		foreach ( AudienceAttendanceReader::for_day( $booking_id, $date ) as $line ) {
			if ( null !== $line['user_id'] ) {
				$today[ $line['user_id'] ] = $line;
			}
		}

		$ids = array_map( 'intval', AudienceBookingReader::get_all_affected_users( $booking_id ) );
		$ids = array_values( array_unique( array_merge( $ids, array_keys( $by_user ) ) ) );

		$names = self::names( $ids );

		$rows    = array();
		$present = 0;
		foreach ( $ids as $user_id ) {
			$line   = $today[ $user_id ] ?? null;
			$mine   = array_values( array_intersect( $by_user[ $user_id ] ?? array(), $days ) );
			$rows[] = array(
				'user_id'    => $user_id,
				'name'       => $names[ $user_id ] ?? '#' . $user_id,
				'present_at' => null === $line ? null : $line['checked_at'],
				'method'     => null === $line ? null : $line['method'],
				'days'       => $mine,
				'met'        => AudienceAttendanceRule::satisfied_by( $booking, $mine ),
			);
			if ( null !== $line ) {
				++$present;
			}
		}

		usort(
			$rows,
			static function ( array $a, array $b ): int {
				$by_name = strcasecmp( $a['name'], $b['name'] );

				return 0 !== $by_name ? $by_name : $a['user_id'] <=> $b['user_id'];
			}
		);

		return array(
			'rows'     => $rows,
			'present'  => $present,
			'total'    => count( $rows ),
			'required' => AudienceAttendanceRule::required_days( $booking ),
			'days'     => $days,
		);
	}

	/**
	 * Display names of a set of users, in one query.
	 *
	 * @param int[] $ids User IDs.
	 * @return array<int, string>
	 */
	private static function names( array $ids ): array {
		if ( array() === $ids ) {
			return array();
		}

		$names = array();
		foreach ( get_users(
			array(
				'include' => $ids,
				'fields'  => array( 'ID', 'display_name' ),
			)
		) as $user ) {
			$names[ (int) $user->ID ] = (string) $user->display_name;
		}

		return $names;
	}
}
