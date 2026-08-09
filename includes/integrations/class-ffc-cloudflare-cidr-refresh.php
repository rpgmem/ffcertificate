<?php
/**
 * Cloudflare CIDR Refresh.
 *
 * Keeps a fresh copy of Cloudflare's published edge CIDR ranges so the secure
 * client-IP strategy detects Cloudflare by *range* (not by header presence)
 * even as the published list changes over time (#901, phase 2 of #899).
 *
 * The bundled constants in {@see \FreeFormCertificate\Core\ClientIpResolver}
 * are the offline fallback; this class fetches the live lists on a daily cron
 * and appends them via the `ffc_cloudflare_ip_ranges` filter. A failed fetch
 * never clears the last-known-good copy.
 *
 * Boundary note: registers by hook name only — no cross-module reference.
 *
 * @package FreeFormCertificate\Integrations
 * @since   6.19.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fetches + caches Cloudflare's published IPv4/IPv6 CIDR ranges.
 */
final class CloudflareCidrRefresh {

	/** WP-Cron hook for the scheduled daily refresh. */
	public const CRON_HOOK = 'ffc_cloudflare_cidr_refresh';

	/** Action hook an admin surface can fire for an on-demand refresh. */
	public const REFRESH_ACTION = 'ffc_cloudflare_cidr_refresh_now';

	/** Option holding the fetched ranges + metadata. */
	public const OPTION_KEY = 'ffc_cloudflare_cidr_cache';

	/** Published IPv4 range list. */
	private const IPV4_URL = 'https://www.cloudflare.com/ips-v4';

	/** Published IPv6 range list. */
	private const IPV6_URL = 'https://www.cloudflare.com/ips-v6';

	/**
	 * Wire the cron callback, the on-demand refresh action, and the range
	 * injector. Called unconditionally at orchestrator boot.
	 */
	public static function init(): void {
		add_action( self::CRON_HOOK, array( self::class, 'run_cron' ) );
		add_action( self::REFRESH_ACTION, array( self::class, 'run_cron' ) );
		add_filter( 'ffc_cloudflare_ip_ranges', array( self::class, 'inject_ranges' ), 10 );
	}

	/**
	 * Void wrapper for the cron/action callback: run() returns a count, but an
	 * action callback must not return anything (PHPStan return.void).
	 */
	public static function run_cron(): void {
		self::run();
	}

	/**
	 * Schedule the daily refresh if not already queued. Called on activation.
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Clear the scheduled refresh. Called on deactivation / uninstall.
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Fetch both published lists and cache them. A fetch that yields nothing
	 * (network error, empty body) leaves the previous cache intact.
	 *
	 * @return int Number of ranges cached this run (0 = kept last-known-good).
	 */
	public static function run(): int {
		$ranges = array_values(
			array_unique(
				array_merge( self::fetch_list( self::IPV4_URL ), self::fetch_list( self::IPV6_URL ) )
			)
		);

		if ( empty( $ranges ) ) {
			return 0;
		}

		update_option(
			self::OPTION_KEY,
			array(
				'ranges'  => $ranges,
				'count'   => count( $ranges ),
				'updated' => time(),
				'source'  => 'cloudflare',
			),
			false
		);

		return count( $ranges );
	}

	/**
	 * Append the cron-refreshed ranges to the bundled fallback set. When no
	 * fresh copy exists yet, the incoming (bundled) set is returned unchanged.
	 *
	 * @param mixed $ranges Incoming Cloudflare CIDR set (bundled fallback).
	 * @return array<int, string>
	 */
	public static function inject_ranges( $ranges ): array {
		$ranges = is_array( $ranges ) ? array_values( array_filter( $ranges, 'is_string' ) ) : array();

		$cache = self::get_cache();
		if ( null === $cache || empty( $cache['ranges'] ) || ! is_array( $cache['ranges'] ) ) {
			return $ranges;
		}

		$fresh = array_values( array_filter( $cache['ranges'], 'is_string' ) );

		return array_values( array_unique( array_merge( $ranges, $fresh ) ) );
	}

	/**
	 * The cached fetch result, or null when never fetched.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get_cache(): ?array {
		$cache = get_option( self::OPTION_KEY, null );
		return is_array( $cache ) ? $cache : null;
	}

	/**
	 * Fetch one published list and return its valid CIDR/IP lines.
	 *
	 * @param string $url Cloudflare list URL (fixed host; SSL verified).
	 * @return array<int, string>
	 */
	private static function fetch_list( string $url ): array {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 5,
				'sslverify'  => true,
				'user-agent' => 'FreeFormCertificate; ' . home_url( '/' ),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}

		$body = (string) wp_remote_retrieve_body( $response );
		if ( '' === $body ) {
			return array();
		}

		$lines = preg_split( '/\r\n|\r|\n/', $body );
		if ( ! is_array( $lines ) ) {
			$lines = array();
		}

		$out = array();
		foreach ( $lines as $line ) {
			$line = trim( (string) $line );
			if ( '' !== $line && self::is_valid_cidr( $line ) ) {
				$out[] = $line;
			}
		}

		return $out;
	}

	/**
	 * Shape-validate a CIDR / literal IP line (defence against a tampered or
	 * unexpected response body).
	 *
	 * @param string $cidr Candidate line.
	 */
	private static function is_valid_cidr( string $cidr ): bool {
		if ( strpos( $cidr, '/' ) === false ) {
			return (bool) filter_var( $cidr, FILTER_VALIDATE_IP );
		}

		list( $ip, $bits ) = explode( '/', $cidr, 2 );
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) || ! ctype_digit( $bits ) ) {
			return false;
		}

		$max   = filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ? 128 : 32;
		$value = (int) $bits;

		return $value >= 0 && $value <= $max;
	}
}
