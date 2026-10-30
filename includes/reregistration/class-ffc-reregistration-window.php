<?php
/**
 * Reregistration Window
 *
 * Whether a campaign is open right now, and how many days are left. A
 * campaign's `start_date` and `end_date` are wall-clock values (Category B in
 * CLAUDE.md): they mean the site's calendar, so they are compared with
 * `current_time( 'mysql' )` and never with UTC or with the database server's
 * own clock (#2242).
 *
 * @package FreeFormCertificate\Reregistration
 * @since 6.43.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Reregistration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Campaign window on the site's clock.
 */
final class ReregistrationWindow {

	/**
	 * The campaign has not opened yet.
	 */
	public const BEFORE = 'before';

	/**
	 * The campaign is open.
	 */
	public const OPEN = 'open';

	/**
	 * The campaign has closed.
	 */
	public const AFTER = 'after';

	/**
	 * Where the site's clock stands against a campaign's window.
	 *
	 * Both ends are inclusive. Before #2242 `start_date` was never read and the
	 * end held only once the daily run expired the campaign, so a form
	 * answered for up to a day after it closed.
	 *
	 * @param string      $start_date Campaign start, wall-clock.
	 * @param string      $end_date   Campaign end, wall-clock.
	 * @param string|null $now        Site wall-clock `Y-m-d H:i:s`; the current one when null.
	 * @return string One of the class constants.
	 */
	public static function state( string $start_date, string $end_date, ?string $now = null ): string {
		$now   = $now ?? current_time( 'mysql' );
		$start = self::normalise( $start_date );
		$end   = self::normalise( $end_date );

		if ( '' !== $start && $now < $start ) {
			return self::BEFORE;
		}
		if ( '' !== $end && $now > $end ) {
			return self::AFTER;
		}
		return self::OPEN;
	}

	/**
	 * Whether a campaign accepts a draft or a submission now.
	 *
	 * @param string      $start_date Campaign start, wall-clock.
	 * @param string      $end_date   Campaign end, wall-clock.
	 * @param string|null $now        Site wall-clock `Y-m-d H:i:s`; the current one when null.
	 * @return bool
	 */
	public static function is_open( string $start_date, string $end_date, ?string $now = null ): bool {
		return self::OPEN === self::state( $start_date, $end_date, $now );
	}

	/**
	 * The message for a campaign the clock has not reached or has passed.
	 *
	 * @param string $state {@see self::BEFORE} or {@see self::AFTER}.
	 * @return string
	 */
	public static function closed_message( string $state ): string {
		return self::BEFORE === $state
			? __( 'This reregistration has not opened yet.', 'ffcertificate' )
			: __( 'This reregistration has closed.', 'ffcertificate' );
	}

	/**
	 * Calendar days from the site's today to the end date, never negative.
	 *
	 * Counted between dates in the site's calendar, so a campaign that ends
	 * today has 0 days left all day long. The old count divided the seconds
	 * between `strtotime( $end )` — the wall-clock string read as UTC — and
	 * `time()`, which in America/Sao_Paulo was three hours off and could drop
	 * a day.
	 *
	 * @param string      $end_date Campaign end, wall-clock.
	 * @param string|null $today    Site date `Y-m-d`; the current one when null.
	 * @return int
	 */
	public static function days_left( string $end_date, ?string $today = null ): int {
		$end_day = substr( self::normalise( $end_date ), 0, 10 );
		$today   = $today ?? current_time( 'Y-m-d' );

		$end   = \DateTimeImmutable::createFromFormat( '!Y-m-d', $end_day, new \DateTimeZone( 'UTC' ) );
		$start = \DateTimeImmutable::createFromFormat( '!Y-m-d', $today, new \DateTimeZone( 'UTC' ) );
		if ( false === $end || false === $start ) {
			return 0;
		}

		return max( 0, (int) round( ( $end->getTimestamp() - $start->getTimestamp() ) / DAY_IN_SECONDS ) );
	}

	/**
	 * A stored or posted date-time as `Y-m-d H:i:s`.
	 *
	 * The editor posts `datetime-local` (`2026-11-30T18:00`), which MySQL
	 * stores as `2026-11-30 18:00:00`; both read the same here.
	 *
	 * @param string $value Date-time.
	 * @return string Normalised value, or '' when empty or unreadable.
	 */
	public static function normalise( string $value ): string {
		$value = trim( str_replace( 'T', ' ', $value ) );
		if ( '' === $value ) {
			return '';
		}
		if ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return $value . ' 00:00:00';
		}
		if ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value ) ) {
			return $value . ':00';
		}
		return 1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value ) ? $value : '';
	}
}
