<?php
/**
 * Birth-date value rules.
 *
 * @package FreeFormCertificate\Core
 * @since   6.33.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The one place that decides what a stored birth date looks like (#1538).
 *
 * A birth date is a **wall-clock DATE** (Category B in `CLAUDE.md` "Date / time
 * storage convention"): it means the same day wherever the reader is, so it is
 * stored as the literal ISO `Y-m-d` and never converted. ISO and not the
 * `d/m/Y` an operator types, because ISO is the only spelling that sorts and
 * compares correctly as text — `02/01/2000` sorts after `01/12/2030`.
 * Display goes through `DateFormatter::format_wallclock_date()`, which applies the
 * format configured in the panel.
 *
 * Two derived shapes live here as well, so the three cannot drift apart:
 *
 *   - **`MM-DD`** — the month and day without the year. The full date is
 *     stored encrypted; this plaintext slice is what a scheduled job queries,
 *     and it is deliberately the least identifying part of the value.
 *   - **plausibility** — a real date is not necessarily a birth date. A future
 *     date, or one implying an age outside a working life, is refused at the
 *     input boundary rather than stored and mailed.
 */
final class BirthDate {

	/**
	 * Youngest age accepted at the input boundary.
	 */
	public const MIN_AGE = 14;

	/**
	 * Oldest age accepted at the input boundary.
	 */
	public const MAX_AGE = 110;

	/**
	 * Canonical ISO form of a birth date, or null when the input is not one.
	 *
	 * Accepts the stored ISO form and the two day-first spellings an operator
	 * or an older import may have left behind (`d/m/Y`, `d-m-Y`). Anything
	 * else — including an impossible date such as `2001-02-30` — is null,
	 * never a best guess.
	 *
	 * @param string $value Raw value.
	 * @return string|null `Y-m-d`, or null.
	 */
	public static function normalize( string $value ): ?string {
		$value = trim( $value );
		if ( '' === $value ) {
			return null;
		}

		if ( DateFormatter::is_valid_date( $value ) ) {
			return $value;
		}

		if ( 1 === preg_match( '/^(\d{1,2})[\/-](\d{1,2})[\/-](\d{4})$/', $value, $m ) ) {
			$iso = sprintf( '%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1] );
			return DateFormatter::is_valid_date( $iso ) ? $iso : null;
		}

		return null;
	}

	/**
	 * Month and day of a birth date, as `MM-DD`.
	 *
	 * @param string $value Raw or canonical value.
	 * @return string|null `MM-DD`, or null when the value is not a date.
	 */
	public static function month_day( string $value ): ?string {
		$iso = self::normalize( $value );
		return null === $iso ? null : substr( $iso, 5 );
	}

	/**
	 * Age in whole years on a given day.
	 *
	 * @param string             $iso   Canonical `Y-m-d`.
	 * @param \DateTimeImmutable $today The day to measure on (site timezone).
	 * @return int|null Null when the value is not a date.
	 */
	public static function age_on( string $iso, \DateTimeImmutable $today ): ?int {
		$iso = self::normalize( $iso );
		if ( null === $iso ) {
			return null;
		}

		$birth = \DateTimeImmutable::createFromFormat( '!Y-m-d', $iso, $today->getTimezone() );
		if ( false === $birth ) {
			return null;
		}

		$diff = $birth->diff( $today->setTime( 0, 0 ) );
		return 1 === $diff->invert ? -1 * (int) $diff->y : (int) $diff->y;
	}

	/**
	 * Whether a value is a believable birth date on a given day: a real date,
	 * not in the future, and implying an age between MIN_AGE and MAX_AGE.
	 *
	 * @param string             $value Raw value.
	 * @param \DateTimeImmutable $today The day to measure on (site timezone).
	 * @return bool
	 */
	public static function is_plausible( string $value, \DateTimeImmutable $today ): bool {
		$iso = self::normalize( $value );
		if ( null === $iso || $iso > $today->format( 'Y-m-d' ) ) {
			return false;
		}

		$age = self::age_on( $iso, $today );
		return null !== $age && $age >= self::MIN_AGE && $age <= self::MAX_AGE;
	}
}
