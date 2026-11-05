<?php
/**
 * CalendarSessions
 *
 * The named sessions of a personal calendar (#2405): parallel services on
 * the same days and hours, each counting its own places. A session owns its
 * name only; hours, slot length, capacity per slot and blocked dates come
 * from the calendar. A calendar with fewer than two sessions works as one
 * without sessions.
 *
 * Stored in post meta as a list of `{id, name, deleted}` in display order,
 * and copied to the calendars table as JSON for the shortcode. An id never
 * changes and is never reused, because appointments carry it; a deleted
 * session therefore stays in the list, flagged, so the appointments it
 * already holds keep its name and no new session takes its id.
 *
 * @package FreeFormCertificate\SelfScheduling
 * @since 6.44.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\SelfScheduling;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads, cleans and compares a calendar's sessions.
 */
final class CalendarSessions {

	/**
	 * Post meta key holding the sessions.
	 *
	 * @var string
	 */
	public const META = '_ffc_self_scheduling_sessions';

	/**
	 * Longest session name kept.
	 *
	 * @var int
	 */
	public const MAX_NAME = 100;

	/**
	 * Every session in a stored value, deleted ones included: the meta array,
	 * or the table's JSON. Anything else, and any entry without a positive id
	 * and a name, is dropped; a repeated id keeps its first entry.
	 *
	 * @param mixed $value Stored value.
	 * @return list<array{id: int, name: string, deleted: bool}>
	 */
	public static function decode( $value ): array {
		if ( is_string( $value ) && '' !== $value ) {
			$value = json_decode( $value, true );
		}
		if ( ! is_array( $value ) ) {
			return array();
		}

		$sessions = array();
		$seen     = array();
		foreach ( $value as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$id   = isset( $entry['id'] ) && is_numeric( $entry['id'] ) ? (int) $entry['id'] : 0;
			$name = isset( $entry['name'] ) && is_scalar( $entry['name'] ) ? self::clean_name( (string) $entry['name'] ) : '';
			if ( $id < 1 || '' === $name || isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;
			$sessions[]  = array(
				'id'      => $id,
				'name'    => $name,
				'deleted' => ! empty( $entry['deleted'] ),
			);
		}
		return $sessions;
	}

	/**
	 * The sessions a visitor can book and the editor lists, in order.
	 *
	 * @param list<array{id: int, name: string, deleted: bool}> $sessions Every session.
	 * @return list<array{id: int, name: string, deleted: bool}>
	 */
	public static function active( array $sessions ): array {
		return array_values( array_filter( $sessions, static fn( array $session ): bool => ! $session['deleted'] ) );
	}

	/**
	 * Whether a calendar asks for a session: two or more not deleted.
	 *
	 * @param list<array{id: int, name: string, deleted: bool}> $sessions Every session.
	 * @return bool
	 */
	public static function in_use( array $sessions ): bool {
		return count( self::active( $sessions ) ) >= 2;
	}

	/**
	 * The sessions an editor save describes.
	 *
	 * The rows come in the order the editor shows them. A row naming an id
	 * the calendar has keeps that id, so a rename leaves its appointments
	 * where they are; any other row is a new session and takes an id above
	 * every id the calendar has used. A row without a name, and a stored
	 * session no row names, are deletions: the session stays in the list,
	 * flagged, after the active ones.
	 *
	 * @param array<mixed>                                      $rows   Raw rows (`id`, `name`).
	 * @param list<array{id: int, name: string, deleted: bool}> $stored Every session before the save.
	 * @return list<array{id: int, name: string, deleted: bool}>
	 */
	public static function from_rows( array $rows, array $stored ): array {
		$known = array();
		$next  = 1;
		foreach ( $stored as $session ) {
			$known[ $session['id'] ] = $session;
			$next                    = max( $next, $session['id'] + 1 );
		}

		$sessions = array();
		$used     = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$name = isset( $row['name'] ) && is_scalar( $row['name'] ) ? self::clean_name( (string) $row['name'] ) : '';
			if ( '' === $name ) {
				continue;
			}
			$id = isset( $row['id'] ) && is_numeric( $row['id'] ) ? (int) $row['id'] : 0;
			if ( ! isset( $known[ $id ] ) || $known[ $id ]['deleted'] || isset( $used[ $id ] ) ) {
				$id = $next++;
			}
			$used[ $id ] = true;
			$sessions[]  = array(
				'id'      => $id,
				'name'    => $name,
				'deleted' => false,
			);
		}

		foreach ( $known as $id => $session ) {
			if ( ! isset( $used[ $id ] ) ) {
				$session['deleted'] = true;
				$sessions[]         = $session;
			}
		}
		return $sessions;
	}

	/**
	 * Bring sessions back from deletion: each restored one follows the
	 * sessions the save kept, in the order they were listed.
	 *
	 * @param list<array{id: int, name: string, deleted: bool}> $sessions Sessions after the save.
	 * @param array<int, int>                                   $ids      Ids to restore.
	 * @return list<array{id: int, name: string, deleted: bool}>
	 */
	public static function restore( array $sessions, array $ids ): array {
		foreach ( $sessions as $i => $session ) {
			if ( in_array( $session['id'], $ids, true ) ) {
				$sessions[ $i ]['deleted'] = false;
			}
		}
		// Active ones first, the order otherwise kept.
		$active  = self::active( $sessions );
		$deleted = array_values( array_filter( $sessions, static fn( array $session ): bool => $session['deleted'] ) );
		return array_merge( $active, $deleted );
	}

	/**
	 * The name of a session, deleted or not, or '' when the calendar never had it.
	 *
	 * @param list<array{id: int, name: string, deleted: bool}> $sessions Every session.
	 * @param int                                               $id       Session id.
	 * @return string
	 */
	public static function name( array $sessions, int $id ): string {
		foreach ( $sessions as $session ) {
			if ( $session['id'] === $id ) {
				return $session['name'];
			}
		}
		return '';
	}

	/**
	 * Clean one name: plain text, trimmed, at most {@see self::MAX_NAME} characters.
	 *
	 * @param string $name Raw name.
	 * @return string
	 */
	private static function clean_name( string $name ): string {
		$name = trim( sanitize_text_field( $name ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $name, 0, self::MAX_NAME ) : substr( $name, 0, self::MAX_NAME );
	}
}
