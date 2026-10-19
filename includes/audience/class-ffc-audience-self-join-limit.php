<?php
/**
 * AudienceSelfJoinLimit
 *
 * How many self-join groups one user may belong to at once (#1916): the
 * setting on Scheduling Settings → Audience Calendars, and the one reader
 * every consumer goes through.
 *
 * @package FreeFormCertificate\Audience
 * @since   6.41.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The self-join group limit.
 *
 * Clamped on read as well as on save, so a value written before the bounds
 * existed, or by hand, still lands somewhere the dashboard can use.
 */
class AudienceSelfJoinLimit {

	/**
	 * Option key.
	 *
	 * @var string
	 */
	public const OPTION = 'ffc_aud_max_self_join_groups';

	/**
	 * The limit before an administrator sets one — what the hard-coded cap was.
	 *
	 * @var int
	 */
	public const DEFAULT_LIMIT = 2;

	/**
	 * Lowest accepted limit: zero would close self-join, which the per-audience
	 * switch already does.
	 *
	 * @var int
	 */
	public const MIN = 1;

	/**
	 * Highest accepted limit.
	 *
	 * @var int
	 */
	public const MAX = 20;

	/**
	 * The configured limit, clamped.
	 *
	 * @return int
	 */
	public static function get(): int {
		return self::clamp( get_option( self::OPTION, self::DEFAULT_LIMIT ) );
	}

	/**
	 * Bring a stored or posted value inside the bounds.
	 *
	 * A value that is not a number is not a limit, so it reads as the
	 * default rather than as the floor.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	public static function clamp( $value ): int {
		if ( ! is_numeric( $value ) ) {
			return self::DEFAULT_LIMIT;
		}
		return max( self::MIN, min( self::MAX, (int) $value ) );
	}
}
