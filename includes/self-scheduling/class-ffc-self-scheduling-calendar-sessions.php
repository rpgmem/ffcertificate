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
	 * Post meta keys holding the calendar's own word for a session (#2413),
	 * singular and plural. Empty means the default, "Session" / "Sessions".
	 *
	 * @var string
	 */
	public const LABEL_META = '_ffc_self_scheduling_session_label';

	/**
	 * Post meta key holding the plural label (#2413).
	 *
	 * @var string
	 */
	public const LABEL_PLURAL_META = '_ffc_self_scheduling_session_label_plural';

	/**
	 * The calendar's word for a session (#2413): its own label when set, the
	 * default otherwise. Read from the calendar row, or from the post meta
	 * when the row is not at hand ({@see self::labels_of_post()}).
	 *
	 * @param array<string, mixed> $calendar Calendar row.
	 * @param bool                 $plural   Whether to return the plural.
	 * @return string
	 */
	public static function label( array $calendar, bool $plural = false ): string {
		$custom = $calendar[ $plural ? 'session_label_plural' : 'session_label' ] ?? '';
		$custom = is_scalar( $custom ) ? trim( (string) $custom ) : '';
		if ( '' !== $custom ) {
			return $custom;
		}
		return $plural ? __( 'Sessions', 'ffcertificate' ) : __( 'Session', 'ffcertificate' );
	}

	/**
	 * A calendar post's labels as the two row columns carry them (#2413).
	 *
	 * @param int $post_id Calendar post.
	 * @return array{session_label: string, session_label_plural: string}
	 */
	public static function labels_of_post( int $post_id ): array {
		$singular = get_post_meta( $post_id, self::LABEL_META, true );
		$plural   = get_post_meta( $post_id, self::LABEL_PLURAL_META, true );
		return array(
			'session_label'        => is_string( $singular ) ? $singular : '',
			'session_label_plural' => is_string( $plural ) ? $plural : '',
		);
	}

	/**
	 * The booking step's strings for a calendar with its own labels (#2413).
	 * Only the strings a custom label replaces are returned, so the general
	 * ones stand otherwise: the step heading and its back button name the
	 * plural, the line above the times the singular.
	 *
	 * @param array<string, mixed> $calendar Calendar row.
	 * @return array<string, string>
	 */
	public static function step_strings( array $calendar ): array {
		$strings  = array();
		$singular = is_scalar( $calendar['session_label'] ?? null ) ? trim( (string) $calendar['session_label'] ) : '';
		$plural   = is_scalar( $calendar['session_label_plural'] ?? null ) ? trim( (string) $calendar['session_label_plural'] ) : '';
		if ( '' !== $plural ) {
			$strings['chooseSession'] = $plural;
			$strings['changeSession'] = $plural;
		}
		if ( '' !== $singular ) {
			$strings['session'] = $singular;
		}
		return $strings;
	}

	/**
	 * Clean a label typed in the editor (#2413), like a session name.
	 *
	 * @param string $label Raw label.
	 * @return string
	 */
	public static function clean_label( string $label ): string {
		return self::clean_name( $label );
	}

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
			$id = isset( $entry['id'] ) && is_numeric( $entry['id'] ) ? (int) $entry['id'] : 0;
			// Names were cleaned when saved; reading only trims.
			$name = isset( $entry['name'] ) && is_scalar( $entry['name'] ) ? trim( (string) $entry['name'] ) : '';
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
	 * Every session of a calendar row, read from its `sessions` column.
	 *
	 * @param array<string, mixed> $calendar Calendar row.
	 * @return list<array{id: int, name: string, deleted: bool}>
	 */
	public static function of_calendar( array $calendar ): array {
		return self::decode( $calendar['sessions'] ?? null );
	}

	/**
	 * The session a booking or a count on this calendar is scoped to (#2405).
	 *
	 * Null for a calendar that does not use sessions: its counts read the
	 * whole calendar, as before sessions existed. Otherwise the id when it
	 * names one of the calendar's active sessions, and 0 when it names none,
	 * which a booking refuses.
	 *
	 * @param array<string, mixed> $calendar   Calendar row.
	 * @param mixed                $session_id Requested session id.
	 * @return int|null
	 */
	public static function scope( array $calendar, $session_id ): ?int {
		$sessions = self::of_calendar( $calendar );
		if ( ! self::in_use( $sessions ) ) {
			return null;
		}
		$id = is_numeric( $session_id ) ? (int) $session_id : 0;
		foreach ( self::active( $sessions ) as $session ) {
			if ( $session['id'] === $id ) {
				return $id;
			}
		}
		return 0;
	}

	/**
	 * The session an existing appointment counts in: its own, on a calendar
	 * that uses sessions, even one deleted since; null otherwise. A booking
	 * made before the calendar had sessions answers 0, which the counts read
	 * as the whole calendar.
	 *
	 * @param array<string, mixed> $calendar    Calendar row.
	 * @param array<string, mixed> $appointment Appointment row.
	 * @return int|null
	 */
	public static function scope_of_appointment( array $calendar, array $appointment ): ?int {
		if ( ! self::in_use( self::of_calendar( $calendar ) ) ) {
			return null;
		}
		return is_numeric( $appointment['session_id'] ?? null ) ? (int) $appointment['session_id'] : 0;
	}

	/**
	 * The name of an appointment's session (#2405), deleted or not, or ''
	 * when the appointment has none.
	 *
	 * @param array<string, mixed> $calendar    Calendar row.
	 * @param array<string, mixed> $appointment Appointment row.
	 * @return string
	 */
	public static function name_of_appointment( array $calendar, array $appointment ): string {
		$id = is_numeric( $appointment['session_id'] ?? null ) ? (int) $appointment['session_id'] : 0;
		return $id > 0 ? self::name( self::of_calendar( $calendar ), $id ) : '';
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
