<?php
/**
 * EventDate
 *
 * The date an event takes place, as the certificate prints it (#2579).
 *
 * @package FreeFormCertificate\Core
 * @since   6.45.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads a form's event date and resolves the `{{schedule:date}}` and
 * `{{schedule:dates}}` tokens.
 *
 * The event date lives in the Event Schedule block, beside the hours
 * `{{schedule}}` prints (`class_date_start` / `class_date_end` in
 * `_ffc_geofence_config`). It is a wall-clock date (Category B): stored as
 * `Y-m-d`, never converted. It is deliberately NOT the form's opening window
 * (`date_start` / `date_end`): the two differed on 15 of the 240 production
 * forms measured for #2579, and the certificate prints the event, so an empty
 * event date resolves to empty instead of falling back.
 */
final class EventDate {

	/**
	 * Keys the generator passes to the renderer. The underscore keeps them out
	 * of the token vocabulary, the way `_quiz_score` is kept out.
	 */
	public const DATA_START = '_event_date_start';
	public const DATA_END   = '_event_date_end';

	/**
	 * Matches `{{schedule:date}}`, `{{schedule:dates}}` and either with a
	 * `:format=` modifier. The format runs to the closing braces, so it may
	 * itself contain a colon.
	 */
	private const TOKEN_PATTERN = '/\{\{schedule:(dates?)(?::format=([^}]*))?\}\}/';

	/**
	 * A form's event date range as stored, each side `Y-m-d` or ''.
	 *
	 * An end before the start, or equal to it, reads as a one-day event.
	 *
	 * @param int $form_id Form post id.
	 * @return array{0: string, 1: string}
	 */
	public static function range( int $form_id ): array {
		$config = $form_id > 0 ? get_post_meta( $form_id, '_ffc_geofence_config', true ) : array();
		return self::range_from_config( is_array( $config ) ? $config : array() );
	}

	/**
	 * The event date range carried by a geofence config array.
	 *
	 * @param array<string, mixed> $config `_ffc_geofence_config` value.
	 * @return array{0: string, 1: string}
	 */
	public static function range_from_config( array $config ): array {
		$start = self::valid( $config['class_date_start'] ?? '' );
		$end   = self::valid( $config['class_date_end'] ?? '' );
		if ( '' === $start ) {
			return array( '', '' );
		}
		if ( '' === $end || $end <= $start ) {
			$end = '';
		}
		return array( $start, $end );
	}

	/**
	 * Whether a layout uses either date token.
	 *
	 * @param string $layout Layout HTML.
	 */
	public static function layout_uses_date( string $layout ): bool {
		return 1 === preg_match( self::TOKEN_PATTERN, $layout );
	}

	/**
	 * Replace every date token in a layout.
	 *
	 * @param string $layout Layout HTML.
	 * @param string $start  Event start, `Y-m-d` or ''.
	 * @param string $end    Event end, `Y-m-d` or ''.
	 * @return string
	 */
	public static function replace_tokens( string $layout, string $start, string $end ): string {
		if ( false === strpos( $layout, '{{schedule:date' ) ) {
			return $layout;
		}
		return (string) preg_replace_callback(
			self::TOKEN_PATTERN,
			static function ( array $m ) use ( $start, $end ): string {
				$format = isset( $m[2] ) ? html_entity_decode( (string) $m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : '';
				$text   = 'dates' === $m[1] ? self::format_range( $start, $end, $format ) : self::format_one( $start, $format );
				return esc_html( $text );
			},
			$layout
		);
	}

	/**
	 * The start date, or the start and the end for an event of several days.
	 *
	 * @param string $start  Event start, `Y-m-d` or ''.
	 * @param string $end    Event end, `Y-m-d` or ''.
	 * @param string $format PHP date format, or '' for the plugin's PDF date format.
	 * @return string
	 */
	public static function format_range( string $start, string $end, string $format = '' ): string {
		$first = self::format_one( $start, $format );
		if ( '' === $first || '' === $end || $end <= $start ) {
			return $first;
		}
		return sprintf(
			/* translators: 1: first day of an event, 2: last day of the event */
			_x( '%1$s to %2$s', 'event date range', 'ffcertificate' ),
			$first,
			self::format_one( $end, $format )
		);
	}

	/**
	 * One wall-clock date in the given format, or the plugin's PDF date format.
	 *
	 * @param string $date   `Y-m-d` or ''.
	 * @param string $format PHP date format, or ''.
	 * @return string
	 */
	public static function format_one( string $date, string $format = '' ): string {
		$date = self::valid( $date );
		if ( '' === $date ) {
			return '';
		}
		if ( '' === $format ) {
			return DateFormatter::format_wallclock_date( $date, 'pdf' );
		}
		// A wall-clock date: pinned to UTC on both ends so no timezone moves the day.
		$utc       = new \DateTimeZone( 'UTC' );
		$moment    = new \DateTimeImmutable( $date . ' 00:00:00', $utc );
		$formatted = wp_date( $format, $moment->getTimestamp(), $utc );
		return false === $formatted ? '' : $formatted;
	}

	/**
	 * A `Y-m-d` value, or '' when the input is not one.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function valid( $value ): string {
		$value = is_string( $value ) ? trim( $value ) : '';
		return DateFormatter::is_valid_date( $value ) ? $value : '';
	}
}
