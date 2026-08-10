<?php
/**
 * IP Resolver Settings Bridge.
 *
 * Wires the stored admin config ({@see IpDiagnosticsSettingsReader}) into the
 * dormant filters that {@see \FreeFormCertificate\Core\ClientIpResolver}
 * exposes (#901, phase 2 of #899). Phase 1 shipped those filters with no
 * producer; this bridge is that producer.
 *
 * Boundary note: this class references NO other module — it registers filters
 * by hook name (strings) and reads the same-module reader, whose strategy /
 * proxy-mode string values are byte-identical to the resolver's own
 * constants. So the settings→resolver coupling adds no cross-module edge.
 *
 * @package FreeFormCertificate\Settings
 * @since   6.19.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the settings-backed producers for the client-IP resolver filters.
 */
final class IpResolverSettingsBridge {

	/**
	 * Register every filter. Called unconditionally at orchestrator boot
	 * (IP resolution + WP-Cron both run outside `is_admin()`).
	 */
	public static function init(): void {
		add_filter( 'ffc_ip_resolver_mode', array( self::class, 'filter_mode' ) );
		add_filter( 'ffc_trusted_proxies', array( self::class, 'filter_trusted_proxies' ) );
		// Priority 20 so a `direct` config wins over CloudflareCidrRefresh's
		// range injection (priority 10) — the cleared CF set must be final.
		add_filter( 'ffc_cloudflare_ip_ranges', array( self::class, 'filter_cloudflare_ranges' ), 20 );
		add_filter( 'ffc_ip_shadow_logging', array( self::class, 'filter_shadow_logging' ) );
		add_filter( 'ffc_rate_limit_ip_source', array( self::class, 'filter_rate_limit_ip_source' ) );
	}

	/**
	 * Effective strategy — `legacy` (default) unless the admin opted into
	 * `secure`. The stored value is already validated by the reader.
	 *
	 * @param mixed $mode Incoming filter value (ignored; config is authoritative).
	 * @return string `legacy` or `secure`.
	 */
	public static function filter_mode( $mode ): string {
		unset( $mode );
		return IpDiagnosticsSettingsReader::strategy();
	}

	/**
	 * Trusted (non-Cloudflare) proxy CIDRs. Only the `custom` proxy mode adds
	 * admin-supplied ranges; every other mode passes the incoming set through.
	 *
	 * @param mixed $proxies Incoming trusted-proxy list.
	 * @return array<int, string>
	 */
	public static function filter_trusted_proxies( $proxies ): array {
		$proxies = is_array( $proxies ) ? array_values( array_filter( $proxies, 'is_string' ) ) : array();

		if ( IpDiagnosticsSettingsReader::PROXY_CUSTOM === IpDiagnosticsSettingsReader::trusted_proxy_mode() ) {
			$proxies = array_merge( $proxies, IpDiagnosticsSettingsReader::custom_proxies() );
		}

		return array_values( array_unique( $proxies ) );
	}

	/**
	 * Cloudflare edge ranges. In `direct` mode every forwarded header — CDN
	 * included — is distrusted, so the range set is cleared (the secure
	 * strategy then always returns REMOTE_ADDR). Otherwise the incoming set
	 * (bundled fallback + any cron-refreshed ranges) passes through.
	 *
	 * @param mixed $ranges Incoming Cloudflare CIDR set.
	 * @return array<int, string>
	 */
	public static function filter_cloudflare_ranges( $ranges ): array {
		if ( IpDiagnosticsSettingsReader::PROXY_DIRECT === IpDiagnosticsSettingsReader::trusted_proxy_mode() ) {
			return array();
		}

		return is_array( $ranges ) ? array_values( array_filter( $ranges, 'is_string' ) ) : array();
	}

	/**
	 * Enable shadow-divergence logging when the admin opted in; otherwise leave
	 * the incoming (filter/default) value untouched.
	 *
	 * @param mixed $enabled Incoming flag.
	 * @return bool
	 */
	public static function filter_shadow_logging( $enabled ): bool {
		return IpDiagnosticsSettingsReader::shadow_logging_enabled() ? true : (bool) $enabled;
	}

	/**
	 * Rate-limit IP source — the stored setting is authoritative (`remote_addr`
	 * default or `resolver`), already validated by the reader.
	 *
	 * @param mixed $source Incoming filter value (ignored; config is authoritative).
	 * @return string `remote_addr` or `resolver`.
	 */
	public static function filter_rate_limit_ip_source( $source ): string {
		unset( $source );
		return IpDiagnosticsSettingsReader::rate_limit_ip_source();
	}
}
