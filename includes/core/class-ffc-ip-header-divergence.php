<?php
/**
 * IpHeaderDivergence
 *
 * Counts the requests on which the legacy client-IP strategy took the address
 * from a forwarded header the secure strategy does not trust (#2339). Under
 * legacy that header decides the address every rate limit and the geofence
 * see, and anybody can send it, so the count is the evidence IP Diagnostics
 * shows before recommending the secure strategy: it says whether the site's
 * own traffic carries such a header, which the administrator's single visit
 * to the tab cannot.
 *
 * Nothing about the request is kept: no address, no header, only how many and
 * when. The tally is one transient, which the `_transient_ffc_%` sweep in
 * `uninstall.php` already removes.
 *
 * @package FreeFormCertificate\Core
 * @since 6.44.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Seven-day tally of forged-header disagreements.
 */
final class IpHeaderDivergence {

	/**
	 * Transient key.
	 *
	 * @var string
	 */
	public const TRANSIENT = 'ffc_ip_header_divergence';

	/**
	 * How long one tally runs before a new one starts.
	 *
	 * @var int
	 */
	public const WINDOW = 7 * DAY_IN_SECONDS;

	/**
	 * Whether this request was already counted.
	 *
	 * The resolver runs several times per request; one request is one count.
	 *
	 * @var bool
	 */
	private static bool $counted = false;

	/**
	 * Register the listener.
	 *
	 * @return void
	 */
	public static function init(): void {
		\add_action( 'ffcertificate_ip_header_disagrees', array( self::class, 'record' ), 10, 0 );
	}

	/**
	 * Count this request, once.
	 *
	 * @return void
	 */
	public static function record(): void {
		if ( self::$counted ) {
			return;
		}
		self::$counted = true;

		$now   = time();
		$tally = self::tally();
		if ( null === $tally ) {
			$tally = array(
				'count' => 0,
				'first' => $now,
				'last'  => $now,
			);
		}

		++$tally['count'];
		$tally['last'] = $now;

		\set_transient( self::TRANSIENT, $tally, max( 60, $tally['first'] + self::WINDOW - $now ) );
	}

	/**
	 * The running tally, or null when none is open.
	 *
	 * @return array{count: int, first: int, last: int}|null
	 */
	public static function tally(): ?array {
		$raw = \get_transient( self::TRANSIENT );
		if ( ! is_array( $raw ) || ! isset( $raw['count'], $raw['first'], $raw['last'] ) ) {
			return null;
		}

		$tally = array(
			'count' => max( 0, (int) $raw['count'] ),
			'first' => (int) $raw['first'],
			'last'  => (int) $raw['last'],
		);

		return ( $tally['count'] > 0 && $tally['first'] + self::WINDOW > time() ) ? $tally : null;
	}

	/**
	 * Forget that this request was counted.
	 *
	 * Test seam: the flag is static and outlives a test method.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$counted = false;
	}
}
