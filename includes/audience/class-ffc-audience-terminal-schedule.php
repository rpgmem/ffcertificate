<?php
/**
 * Audience Terminal Schedule
 *
 * The check-in windows of the targets a terminal holds (#2746, #2890).
 *
 * @package FreeFormCertificate\Audience
 * @since 6.50.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

use FreeFormCertificate\Core\AttendanceTargetRegistry;
use FreeFormCertificate\Core\DateFormatter;
use FreeFormCertificate\Core\TerminalTargetKey;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * When each target of a terminal's queue takes readings.
 *
 * A target's planned window on one of its days comes from the target itself
 * ({@see TerminalTargetInterface::terminal_windows()}); for an audience
 * booking it runs from the moment check-in may open to the automatic close an
 * operator's window would get. A terminal reads for one target at a time, so
 * two targets whose windows cross cannot share a terminal; windows that only
 * touch, one closing at the moment the next opens, can.
 *
 * The planned window is what the queue is checked against. Whether a window
 * is actually open is the target's to say: an audience operator opens
 * check-in when the event starts, and the terminal shows it as open only then.
 *
 * Queues hold {@see TerminalTargetKey} values. The class name keeps the
 * module it was born in; the queue is no longer the audience's alone (#2890).
 *
 * @phpstan-import-type TerminalRow from AudienceTerminalReader
 * @phpstan-import-type TerminalWindow from \FreeFormCertificate\Core\TerminalTargetInterface
 * @phpstan-type PlannedWindow array{target: int|string, date: string, opens: int, closes: int}
 * @phpstan-type PlanEntry array{target: int|string, booking_id: int|string, title: string, place: string, date: string, opens: int, closes: int, opens_label: string, opens_time: string, closes_label: string, hours: string, open: bool, present: int, enrolled: int}
 */
final class AudienceTerminalSchedule {

	/**
	 * The planned check-in windows of one queued target.
	 *
	 * @param int|string $key A {@see TerminalTargetKey}.
	 * @return list<PlannedWindow> Empty for a key no terminal target answers.
	 */
	public static function windows( $key ): array {
		$found = AttendanceTargetRegistry::terminal( $key );
		if ( null === $found ) {
			return array();
		}
		$key = TerminalTargetKey::of( $found['target']->type(), $found['id'] );
		$out = array();
		foreach ( $found['target']->terminal_windows( $found['id'] ) as $window ) {
			$out[] = array(
				'target' => $key,
				'date'   => $window['date'],
				'opens'  => $window['opens'],
				'closes' => $window['closes'],
			);
		}
		return $out;
	}

	/**
	 * Whether two windows cross. Windows that only touch do not.
	 *
	 * @param array $a Window.
	 * @param array $b Window.
	 * @phpstan-param PlannedWindow $a
	 * @phpstan-param PlannedWindow $b
	 * @return bool
	 */
	public static function cross( array $a, array $b ): bool {
		return $a['opens'] < $b['closes'] && $b['opens'] < $a['closes'];
	}

	/**
	 * The first two targets of a queue whose windows still to come cross.
	 *
	 * @param array $keys Queued {@see TerminalTargetKey} values.
	 * @param int   $now  Unix time.
	 * @phpstan-param list<int|string> $keys
	 * @return array{0: int|string, 1: int|string}|null The two keys, in queue order.
	 */
	public static function collision( array $keys, int $now ): ?array {
		$windows = array();
		foreach ( $keys as $key ) {
			$windows[] = array(
				'key'     => $key,
				'windows' => array_values(
					array_filter(
						self::windows( $key ),
						static fn( array $w ): bool => $w['closes'] > $now
					)
				),
			);
		}
		$count = count( $windows );
		for ( $i = 0; $i < $count; $i++ ) {
			for ( $j = $i + 1; $j < $count; $j++ ) {
				foreach ( $windows[ $i ]['windows'] as $a ) {
					foreach ( $windows[ $j ]['windows'] as $b ) {
						if ( self::cross( $a, $b ) ) {
							return array( $windows[ $i ]['key'], $windows[ $j ]['key'] );
						}
					}
				}
			}
		}
		return null;
	}

	/**
	 * The refusal a crossing queue gives, naming both targets.
	 *
	 * @param array $keys Queued {@see TerminalTargetKey} values.
	 * @param int   $now  Unix time.
	 * @phpstan-param list<int|string> $keys
	 * @return \WP_Error|null
	 */
	public static function collision_error( array $keys, int $now ): ?\WP_Error {
		$pair = self::collision( $keys, $now );
		if ( null === $pair ) {
			return null;
		}
		return new \WP_Error(
			'ffc_terminal_overlap',
			sprintf(
				/* translators: 1: an event, 2: the event its check-in collides with */
				__( 'The check-in of "%1$s" overlaps the check-in of "%2$s". A terminal checks in one event at a time: put them on different terminals.', 'ffcertificate' ),
				self::title( $pair[1] ),
				self::title( $pair[0] )
			),
			array( 'status' => 409 )
		);
	}

	/**
	 * What a terminal shows: every window of its queue still to close, in
	 * the order they open (#2746).
	 *
	 * @param array $terminal Terminal row.
	 * @param int   $now      Unix time.
	 * @phpstan-param TerminalRow $terminal
	 * @return list<PlanEntry>
	 */
	public static function plan( array $terminal, int $now ): array {
		$out = array();
		foreach ( $terminal['targets'] as $key ) {
			$found = AttendanceTargetRegistry::terminal( $key );
			if ( null === $found ) {
				continue;
			}
			foreach ( $found['target']->terminal_windows( $found['id'] ) as $window ) {
				if ( $window['closes'] <= $now ) {
					continue;
				}
				$entry = $found['target']->terminal_entry( $found['id'], $window, $now );
				$out[] = array(
					'target'       => $key,
					// The name the kiosk script read before #2890; a script
					// cached from then still finds the entry by it.
					'booking_id'   => $key,
					'title'        => $entry['title'],
					'place'        => $entry['place'],
					'date'         => $window['date'],
					'opens'        => $window['opens'],
					'closes'       => $entry['closes'],
					'opens_label'  => DateFormatter::format_datetime( $window['opens'] ),
					'opens_time'   => DateFormatter::format_time( $window['opens'] ),
					'closes_label' => DateFormatter::format_time( $entry['closes'] ),
					'hours'        => $entry['hours'],
					'open'         => $entry['open'],
					'present'      => $entry['present'],
					'enrolled'     => $entry['enrolled'],
				);
			}
		}
		usort( $out, static fn( array $a, array $b ): int => array( $a['opens'], is_int( $a['target'] ) ? 0 : 1, $a['target'] ) <=> array( $b['opens'], is_int( $b['target'] ) ? 0 : 1, $b['target'] ) );
		return $out;
	}

	/**
	 * A queued target's name, or "Event #id" when no target answers the key.
	 *
	 * @param int|string $key A {@see TerminalTargetKey}.
	 * @return string
	 */
	public static function title( $key ): string {
		$found = AttendanceTargetRegistry::terminal( $key );
		if ( null !== $found ) {
			return $found['target']->terminal_title( $found['id'] );
		}
		$parts = TerminalTargetKey::parse( $key );
		/* translators: %d: event id */
		return sprintf( __( 'Event #%d', 'ffcertificate' ), null === $parts ? 0 : $parts['id'] );
	}
}
