<?php
/**
 * How long the dashboard keeps an approved reregistration's banner.
 *
 * @package FreeFormCertificate\Core
 * @since 6.41.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

use FreeFormCertificate\Settings\SettingsReader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Decides when the "approved" banner of the member dashboard stops showing (#1887).
 *
 * **The rule.** The banner is hidden once `now` is later than
 * `max( end of the campaign's end day, approval instant ) + N days`, where N is
 * the `rereg_approved_banner_days` setting. `0` never hides, which is
 * the behaviour before the setting existed.
 *
 * **Two storage categories meet here** (`CLAUDE.md` "Date / time storage
 * convention"). The campaign's `end_date` is wall-clock (category B), so it is
 * read in the site timezone and its END OF DAY is taken: an end on the 20th
 * means the whole of the 20th. The submission's `reviewed_at` is an instant
 * (category A, unix UTC). The N days are added as calendar days in the site
 * timezone, so a daylight-saving change moves the cut-off with the clock the
 * member reads, never by an hour.
 *
 * **Why the bound lives here and in no setting reader.** The same split as
 * `PasswordInvite`: `SettingsReader` returns the stored value and this class
 * clamps it, on read and on save, so the range is written once.
 *
 * Lives in `Core` because the dashboard renderer (`Shortcodes`) and the
 * Settings tab both need it, and `Core` is the module both already depend on.
 */
final class ReregistrationBannerWindow {

	/** Default window, in days. Declared in `Settings::get_default_settings()`. */
	public const DEFAULT_DAYS = 30;

	/** `0` means "never hide". */
	public const MIN_DAYS = 0;

	/** The largest accepted window: one year. */
	public const MAX_DAYS = 365;

	/**
	 * The configured window, clamped.
	 *
	 * @return int Days.
	 */
	public static function days(): int {
		return self::clamp_days( SettingsReader::rereg_approved_banner_days() );
	}

	/**
	 * Clamp a days value into the supported range.
	 *
	 * @param int $days Raw value.
	 * @return int
	 */
	public static function clamp_days( int $days ): int {
		return max( self::MIN_DAYS, min( self::MAX_DAYS, $days ) );
	}

	/**
	 * Whether the approved banner has outlived its window.
	 *
	 * Pure: the clock and the timezone are arguments, so a test never reads
	 * either and never straddles a boundary.
	 *
	 * @param string        $end_date    Campaign end, wall-clock `Y-m-d` or
	 *                                   `Y-m-d H:i:s`; only the date counts.
	 * @param int|null      $approved_at Approval instant (unix UTC), or null
	 *                                   when the submission carries none.
	 * @param int           $days        Window in days; `0` or less never hides.
	 * @param int           $now         Current instant (unix UTC).
	 * @param \DateTimeZone $timezone    Site timezone.
	 * @return bool True when the banner should no longer be shown.
	 */
	public static function approved_banner_expired( string $end_date, ?int $approved_at, int $days, int $now, \DateTimeZone $timezone ): bool {
		if ( $days <= 0 ) {
			return false;
		}

		$anchor = null;

		$end = self::end_of_day( $end_date, $timezone );
		if ( null !== $end ) {
			$anchor = $end;
		}

		if ( null !== $approved_at && $approved_at > 0 ) {
			$approved = ( new \DateTimeImmutable( '@' . $approved_at ) )->setTimezone( $timezone );
			if ( null === $anchor || $approved > $anchor ) {
				$anchor = $approved;
			}
		}

		// Neither date is usable: nothing to count from, so keep the banner.
		if ( null === $anchor ) {
			return false;
		}

		$cutoff = $anchor->add( new \DateInterval( 'P' . $days . 'D' ) );

		return $now > $cutoff->getTimestamp();
	}

	/**
	 * The last second of a wall-clock date, in the given timezone.
	 *
	 * @param string        $end_date `Y-m-d`, optionally followed by a time.
	 * @param \DateTimeZone $timezone Site timezone.
	 * @return \DateTimeImmutable|null Null when the value is not a date.
	 */
	private static function end_of_day( string $end_date, \DateTimeZone $timezone ): ?\DateTimeImmutable {
		$date = substr( trim( $end_date ), 0, 10 );
		$day  = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date, $timezone );
		if ( false === $day || $day->format( 'Y-m-d' ) !== $date ) {
			return null;
		}

		return $day->setTime( 23, 59, 59 );
	}
}
