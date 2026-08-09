<?php
/**
 * IP Diagnostics Settings Reader.
 *
 * Centralized accessor for the `ffc_ip_diagnostics_settings` option — the
 * admin-facing config that drives {@see \FreeFormCertificate\Core\ClientIpResolver}
 * (#901, phase 2 of #899). Two orthogonal axes are stored here:
 *
 * - `strategy` (`legacy` | `secure`) — which resolution strategy is *effective*.
 *   Default `legacy`, so phase 2 changes no observable behaviour; the admin opts
 *   into `secure`, and phase 3 flips the default.
 * - `trusted_proxy_mode` (`auto` | `cloudflare` | `custom` | `direct`) — how the
 *   `secure` strategy decides which forwarded headers to trust.
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
 * Read-side facade over the `ffc_ip_diagnostics_settings` WP option.
 *
 * Mirrors {@see GeolocationSettingsReader}: `all()` returns the raw stored
 * array and the per-key typed accessors apply the canonical defaults. Enum
 * values are validated here so a corrupt/absent option never yields an
 * out-of-range strategy or proxy mode.
 */
final class IpDiagnosticsSettingsReader {

	/**
	 * WP option key that backs every read in this class.
	 */
	public const OPTION_KEY = 'ffc_ip_diagnostics_settings';

	/** Effective strategy: preserve the pre-#899 header walk (default). */
	public const STRATEGY_LEGACY = 'legacy';

	/** Effective strategy: trusted-proxy model. */
	public const STRATEGY_SECURE = 'secure';

	/** Trusted-proxy config: auto-detect CDN/known proxy, else direct (default). */
	public const PROXY_AUTO = 'auto';

	/** Trusted-proxy config: force trust in Cloudflare edge ranges. */
	public const PROXY_CLOUDFLARE = 'cloudflare';

	/** Trusted-proxy config: admin-supplied proxy CIDRs. */
	public const PROXY_CUSTOM = 'custom';

	/** Trusted-proxy config: ignore all forwarded headers (REMOTE_ADDR only). */
	public const PROXY_DIRECT = 'direct';

	/**
	 * Read a value from `ffc_ip_diagnostics_settings`.
	 *
	 * @param string $key     Settings key.
	 * @param mixed  $default Value returned when the key is absent.
	 * @return mixed
	 */
	public static function get( string $key, $default = null ) {
		$settings = self::all();
		return $settings[ $key ] ?? $default;
	}

	/**
	 * Return the raw `ffc_ip_diagnostics_settings` array — no defaults merge.
	 *
	 * Behaves exactly like `get_option( self::OPTION_KEY, array() )`, coercing
	 * non-arrays to `array()`.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		$settings = get_option( self::OPTION_KEY, array() );
		return is_array( $settings ) ? $settings : array();
	}

	/**
	 * Effective resolution strategy — `legacy` (default) or `secure`.
	 *
	 * An unrecognised stored value falls back to `legacy` so a misconfiguration
	 * never silently activates the secure path.
	 *
	 * @return string One of self::STRATEGY_*.
	 */
	public static function strategy(): string {
		$value = (string) ( self::all()['strategy'] ?? self::STRATEGY_LEGACY );
		return in_array( $value, array( self::STRATEGY_LEGACY, self::STRATEGY_SECURE ), true )
			? $value
			: self::STRATEGY_LEGACY;
	}

	/** Whether the secure strategy is the effective one. */
	public static function is_secure(): bool {
		return self::STRATEGY_SECURE === self::strategy();
	}

	/**
	 * Trusted-proxy configuration mode — `auto` (default), `cloudflare`,
	 * `custom` or `direct`. An unrecognised value falls back to `auto`.
	 *
	 * @return string One of self::PROXY_*.
	 */
	public static function trusted_proxy_mode(): string {
		$value = (string) ( self::all()['trusted_proxy_mode'] ?? self::PROXY_AUTO );
		return in_array(
			$value,
			array( self::PROXY_AUTO, self::PROXY_CLOUDFLARE, self::PROXY_CUSTOM, self::PROXY_DIRECT ),
			true
		) ? $value : self::PROXY_AUTO;
	}

	/**
	 * Admin-supplied custom trusted-proxy CIDRs / literal IPs (used when
	 * `trusted_proxy_mode` is `custom`). Stored as a newline/comma-separated
	 * string; returned here as a clean list of non-empty trimmed entries.
	 *
	 * Note: shape validation only (non-empty strings). The resolver's CIDR
	 * matcher tolerates malformed entries (they simply never match), and the
	 * tab's save path is where syntactic validation belongs.
	 *
	 * @return array<int, string>
	 */
	public static function custom_proxies(): array {
		$raw = self::all()['custom_proxies'] ?? '';
		if ( is_array( $raw ) ) {
			$parts = $raw;
		} else {
			$parts = preg_split( '/[\r\n,]+/', (string) $raw );
			if ( ! is_array( $parts ) ) {
				$parts = array();
			}
		}

		$out = array();
		foreach ( $parts as $part ) {
			$part = trim( (string) $part );
			if ( '' !== $part ) {
				$out[] = $part;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Whether dormant legacy-vs-secure shadow-divergence logging is enabled
	 * (feeds the `ffc_ip_shadow_logging` filter). Default false.
	 */
	public static function shadow_logging_enabled(): bool {
		return ! empty( self::all()['shadow_logging'] );
	}
}
