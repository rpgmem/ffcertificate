<?php
/**
 * A site timezone in which the real "now" is noon.
 *
 * Code that compares a form's stored window with `time()` cannot be given
 * a frozen clock without a namespaced `time()` stub, and that stub outlives
 * the test that declares it (#2121). This helper keeps the real clock and
 * moves the site instead: it picks the fixed UTC offset that puts the
 * current instant at 12:00 local time, so a window written as wall-clock
 * strings ("today 10:00 to 14:00") lies hours away from any day boundary.
 *
 * `current_time()` answers from the instant read when the helper is built;
 * `time()` keeps running, which only matters for a test that takes hours.
 *
 * @package FreeFormCertificate\Tests\Support
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Support;

use Brain\Monkey\Functions;

/**
 * Picks the site timezone and stubs the functions that read it.
 */
final class SiteClock {

	/** The instant read when the helper was built. */
	public int $now;

	/** The site timezone, a fixed offset. */
	public \DateTimeZone $timezone;

	/** The same instant in the site timezone, between 12:00:00 and 12:00:59. */
	private \DateTimeImmutable $local;

	/**
	 * Read the clock and choose the offset.
	 */
	public function __construct() {
		$this->now = time();
		// Round up to a whole minute, the finest a fixed offset takes; the
		// result stays within the ±12 hours a timezone can be.
		$offset         = (int) ceil( ( 43200 - $this->now % 86400 ) / 60 ) * 60;
		$this->timezone = new \DateTimeZone(
			sprintf( '%s%02d:%02d', $offset < 0 ? '-' : '+', intdiv( abs( $offset ), 3600 ), intdiv( abs( $offset ) % 3600, 60 ) )
		);
		$this->local    = ( new \DateTimeImmutable( '@' . $this->now ) )->setTimezone( $this->timezone );
	}

	/**
	 * Stub `wp_timezone()` and `current_time()` with this clock.
	 */
	public function stub(): void {
		Functions\when( 'wp_timezone' )->justReturn( $this->timezone );
		Functions\when( 'current_time' )->alias(
			fn( string $type ) => 'timestamp' === $type
				? $this->now + $this->timezone->getOffset( $this->local )
				: $this->local->format( 'mysql' === $type ? 'Y-m-d H:i:s' : $type )
		);
	}

	/**
	 * A local date, as the form config stores it.
	 *
	 * @param int $days Days from today.
	 * @return string `Y-m-d`.
	 */
	public function day( int $days = 0 ): string {
		return $this->local->modify( sprintf( '%+d days', $days ) )->format( 'Y-m-d' );
	}
}
