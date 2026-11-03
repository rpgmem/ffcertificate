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

use FreeFormCertificate\Core\IpAddress;

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
			wp_schedule_event( \FreeFormCertificate\Core\ScheduledTasks::first_run( self::CRON_HOOK, time() + HOUR_IN_SECONDS ), 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Clear the scheduled refresh. Called on deactivation / uninstall.
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Fetch both published lists and cache them.
	 *
	 * Each half stands alone (#2339): a half whose fetch yields nothing keeps
	 * the ranges it had, so a failed IPv6 list never empties the IPv6 set while
	 * the IPv4 one is refreshed. Only a run in which both halves fail leaves
	 * the cache untouched.
	 *
	 * @return int Number of ranges cached this run (0 = kept last-known-good).
	 */
	public static function run(): int {
		$ipv4 = self::fetch_list( self::IPV4_URL, false );
		$ipv6 = self::fetch_list( self::IPV6_URL, true );

		if ( empty( $ipv4 ) && empty( $ipv6 ) ) {
			return 0;
		}

		$cache    = self::get_cache();
		$previous = ( is_array( $cache ) && isset( $cache['ranges'] ) && is_array( $cache['ranges'] ) )
			? array_values( array_filter( $cache['ranges'], 'is_string' ) )
			: array();

		if ( empty( $ipv4 ) ) {
			$ipv4 = array_values( array_filter( $previous, static fn( string $r ): bool => false === strpos( $r, ':' ) ) );
		}
		if ( empty( $ipv6 ) ) {
			$ipv6 = array_values( array_filter( $previous, static fn( string $r ): bool => false !== strpos( $r, ':' ) ) );
		}

		$ranges = array_values( array_unique( array_merge( $ipv4, $ipv6 ) ) );

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
	 * Only lines of the list's own family, and only ranges a proxy may own
	 * ({@see IpAddress::is_trustable_range()}), are kept: a tampered body
	 * cannot widen the trusted set to a slice of the internet (#2339).
	 *
	 * @param string $url  Cloudflare list URL (fixed host; SSL verified).
	 * @param bool   $ipv6 Whether the list is the IPv6 one.
	 * @return array<int, string>
	 */
	private static function fetch_list( string $url, bool $ipv6 ): array {
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
			if ( '' !== $line && ( false !== strpos( $line, ':' ) ) === $ipv6 && IpAddress::is_trustable_range( $line ) ) {
				$out[] = $line;
			}
		}

		return $out;
	}
}
