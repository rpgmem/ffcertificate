<?php
/**
 * ActivityLogRetention
 *
 * How long the activity log is kept and how large it may grow (#2338). The
 * same two limits as the rate-limit logs on the Rate Limit tab, with values of
 * their own: this log is the audit trail, so its window is longer.
 *
 * Both the Advanced tab's Save and the field autosave read the bounds from
 * here, so the two writers cannot disagree, and the readers clamp on read, so
 * a value written before a bound moved still lands inside it.
 *
 * The defaults (90 days, 100,000 records) are declared with the other
 * settings defaults. The record default was measured: about 600 bytes a row
 * (data and indexes) with a typical context, so 100,000 rows is about 60 MB.
 *
 * @package FreeFormCertificate\Core
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bounds and defaults of the activity log's retention.
 */
final class ActivityLogRetention {

	/**
	 * 0 keeps the log indefinitely.
	 */
	public const DAYS_MIN = 0;

	/**
	 * Ten years.
	 */
	public const DAYS_MAX = 3650;

	/**
	 * Smallest record cap.
	 */
	public const RECORDS_MIN = 1000;

	/**
	 * Largest record cap, about 600 MB.
	 */
	public const RECORDS_MAX = 1000000;

	/**
	 * A retention window inside its bounds.
	 *
	 * @param int $days Days.
	 * @return int
	 */
	public static function clamp_days( int $days ): int {
		return max( self::DAYS_MIN, min( self::DAYS_MAX, $days ) );
	}

	/**
	 * A record cap inside its bounds.
	 *
	 * @param int $records Records.
	 * @return int
	 */
	public static function clamp_records( int $records ): int {
		return max( self::RECORDS_MIN, min( self::RECORDS_MAX, $records ) );
	}
}
