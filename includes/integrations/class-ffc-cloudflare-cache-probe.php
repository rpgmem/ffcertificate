<?php
/**
 * Cloudflare Cache Probe.
 *
 * Best-effort active check (#921) for the risk that a Cloudflare "Cache
 * Everything" / APO / Cache Rule is caching HTML. A loopback request to the
 * site's own front page reads the `cf-cache-status` response header the edge
 * stamps on every proxied response:
 *
 * - `DYNAMIC` / `BYPASS` → HTML is NOT cached at the edge (the safe default).
 * - `HIT` / `MISS` / `EXPIRED` / `STALE` / `REVALIDATED` / `UPDATING` → the edge
 *   IS caching HTML, so dynamic FFC pages (forms, the per-user dashboard) may be
 *   served stale or cross-user. The admin should exclude FFC URLs from the rule.
 * - header absent → the loopback did not traverse Cloudflare (some hosts resolve
 *   their own domain straight to the origin), so the check is inconclusive.
 *
 * The verdict is cached in a transient so the outbound request runs at most once
 * every few hours, not on every settings-page render.
 *
 * Boundary note: reads only WP core + the same-module classes; no cross-module
 * reference (the `is_behind_cloudflare()` gate lives on Core\ClientIpResolver,
 * an existing ` → Core` edge).
 *
 * @package FreeFormCertificate\Integrations
 * @since   6.20.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loopback probe of the edge `cf-cache-status` for the site's HTML.
 */
final class CloudflareCacheProbe {

	/** Transient caching the last probe verdict. */
	public const TRANSIENT_KEY = 'ffc_cf_cache_probe';

	/** Verdict: the edge is caching HTML — dynamic FFC pages are at risk. */
	public const STATUS_CACHING = 'caching';

	/** Verdict: the edge is not caching HTML (safe default). */
	public const STATUS_SAFE = 'safe';

	/** Verdict: the loopback did not traverse Cloudflare — inconclusive. */
	public const STATUS_NO_CF = 'no_cf';

	/** Verdict: the loopback request failed. */
	public const STATUS_ERROR = 'error';

	/** How long a verdict is trusted before the next loopback (seconds). */
	private const CACHE_TTL = 6 * HOUR_IN_SECONDS;

	/**
	 * `cf-cache-status` values that mean the edge cached (or tried to cache) the
	 * HTML — any of these on a normal page means "Cache Everything"-style rules
	 * are active. `DYNAMIC` / `BYPASS` are deliberately absent (they are safe).
	 *
	 * @var array<int, string>
	 */
	private const CACHING_STATES = array( 'HIT', 'MISS', 'EXPIRED', 'STALE', 'REVALIDATED', 'UPDATING' );

	/**
	 * Return the cached verdict, running a fresh loopback only when the transient
	 * is cold (or `$force` is set).
	 *
	 * @param bool $force Bypass the cache and re-probe now.
	 * @return array{status:string, raw:string, checked:int, detail:string}
	 */
	public static function get( bool $force = false ): array {
		if ( ! $force ) {
			$cached = get_transient( self::TRANSIENT_KEY );
			if ( is_array( $cached ) && isset( $cached['status'] ) ) {
				return array(
					'status'  => (string) $cached['status'],
					'raw'     => (string) ( $cached['raw'] ?? '' ),
					'checked' => (int) ( $cached['checked'] ?? 0 ),
					'detail'  => (string) ( $cached['detail'] ?? '' ),
				);
			}
		}

		$result = self::run();
		set_transient( self::TRANSIENT_KEY, $result, self::CACHE_TTL );

		return $result;
	}

	/**
	 * Perform the loopback request and classify the `cf-cache-status` header.
	 *
	 * @return array{status:string, raw:string, checked:int, detail:string}
	 */
	private static function run(): array {
		$checked = time();

		$response = wp_remote_get(
			home_url( '/' ),
			array(
				'timeout'     => 5,
				'redirection' => 2,
				// Ask the edge for a fresh copy so we observe its caching decision
				// rather than a browser-cached 304.
				'headers'     => array( 'Cache-Control' => 'no-cache' ),
				'sslverify'   => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'status'  => self::STATUS_ERROR,
				'raw'     => '',
				'checked' => $checked,
				'detail'  => $response->get_error_message(),
			);
		}

		$header     = wp_remote_retrieve_header( $response, 'cf-cache-status' );
		$header     = is_array( $header ) ? (string) ( $header[0] ?? '' ) : (string) $header;
		$normalized = strtoupper( trim( $header ) );

		if ( '' === $normalized ) {
			$status = self::STATUS_NO_CF;
		} elseif ( in_array( $normalized, self::CACHING_STATES, true ) ) {
			$status = self::STATUS_CACHING;
		} else {
			// DYNAMIC, BYPASS, or any future non-caching state.
			$status = self::STATUS_SAFE;
		}

		return array(
			'status'  => $status,
			'raw'     => $normalized,
			'checked' => $checked,
			'detail'  => '',
		);
	}
}
