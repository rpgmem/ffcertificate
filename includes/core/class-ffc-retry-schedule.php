<?php
/**
 * RetrySchedule
 *
 * When to try an outbound check again after it failed, shared by every
 * check that must not ask an unreachable service on each admin page: the
 * GitHub release check and the Cloudflare cache probe (#2342).
 *
 * After the 1st, 2nd, 3rd and 4th failure in a row the next attempt waits
 * 10 min, 30 min, 1 h and 2 h. After the 5th it waits until one CYCLE after
 * the first failure, and the count starts over. The cycle is the caller's:
 * how long its successful answer stands (12 h for a release, 6 h for a
 * probe verdict), so a failing check is never asked more often than a
 * working one once the retries are spent.
 *
 * @package FreeFormCertificate\Core
 * @since   6.44.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Retry state after a failed check.
 */
final class RetrySchedule {

	/**
	 * Waits after the 1st, 2nd, 3rd and 4th failure in a row (seconds).
	 */
	public const DELAYS = array( 600, 1800, 3600, 7200 );

	/**
	 * The state after one more failure.
	 *
	 * Reads and writes three keys of `$state` and leaves every other key as
	 * it was, so a caller keeps its payload in the same array:
	 * `failures` (in the current cycle), `first_failure_at` and `retry_at`.
	 *
	 * @param array<mixed> $state State before the attempt.
	 * @param int          $now   Time of the failed attempt.
	 * @param int          $cycle Seconds a successful answer stands for.
	 * @return array<mixed>
	 */
	public static function after_failure( array $state, int $now, int $cycle ): array {
		$failures = ( isset( $state['failures'] ) ? (int) $state['failures'] : 0 ) + 1;
		$first    = ! empty( $state['first_failure_at'] ) ? (int) $state['first_failure_at'] : $now;

		if ( $failures <= count( self::DELAYS ) ) {
			$state['failures']         = $failures;
			$state['first_failure_at'] = $first;
			$state['retry_at']         = $now + self::DELAYS[ $failures - 1 ];
			return $state;
		}

		// The cycle is spent: wait out the cycle from its first failure, then start over.
		$state['failures']         = 0;
		$state['first_failure_at'] = 0;
		$state['retry_at']         = $first + $cycle;
		return $state;
	}

	/**
	 * Whether a failed check is still waiting for its retry time.
	 *
	 * @param array<mixed> $state State stored after the last attempt.
	 * @param int          $now   Current time.
	 */
	public static function waiting( array $state, int $now ): bool {
		return isset( $state['retry_at'] ) && $now < (int) $state['retry_at'];
	}
}
