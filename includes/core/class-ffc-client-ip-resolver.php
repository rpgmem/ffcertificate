<?php
/**
 * ClientIpResolver
 *
 * Single home for client-IP resolution (#899). Consolidates the historically
 * divergent resolvers into one class that implements two strategies:
 *
 * - {@see self::resolve_legacy()} — the pre-#899 behaviour that walks the
 *   forwarded-for header chain left→right and returns the first public,
 *   non-reserved IP. Spoofable by the client, but preserved as the DEFAULT
 *   effective strategy during phase 1 so no observable behaviour changes.
 * - {@see self::resolve_secure()} — the trusted-proxy model: trust only the
 *   TCP peer (`REMOTE_ADDR`) unless it belongs to a known CDN / configured
 *   proxy, in which case the CDN header (`CF-Connecting-IP`) or a right→left
 *   walk of `X-Forwarded-For` (stripping trusted hops) yields the real client.
 *
 * Phase 1 (this class) ships the mechanism but keeps `legacy` effective. The
 * effective strategy is chosen by {@see self::mode()} (filter
 * `ffc_ip_resolver_mode`, default `legacy`). A dormant shadow-comparison hook
 * (filter `ffc_ip_shadow_logging`, default off) logs — with a HASHED IP, never
 * raw PII — when the two strategies would disagree, so a maintainer can measure
 * the real-world blast radius before the phase-3 flip to `secure`.
 *
 * @package FreeFormCertificate\Core
 * @since 6.19.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Client-IP resolution strategies (legacy + trusted-proxy secure).
 */
class ClientIpResolver {

	/**
	 * Effective strategy: preserve the pre-#899 header walk.
	 *
	 * @var string
	 */
	public const MODE_LEGACY = 'legacy';

	/**
	 * Effective strategy: trusted-proxy model (phase-3 target default).
	 *
	 * @var string
	 */
	public const MODE_SECURE = 'secure';

	/**
	 * Sentinel returned when no IP can be resolved.
	 *
	 * @var string
	 */
	public const UNKNOWN = '0.0.0.0';

	/**
	 * Environment verdict: the TCP peer is a Cloudflare edge address.
	 *
	 * @var string
	 */
	public const VERDICT_CLOUDFLARE = 'cloudflare';

	/**
	 * Environment verdict: Cloudflare sits upstream but the TCP peer is a
	 * private/reserved host proxy (a managed-hosting load balancer), NOT a CF
	 * edge (#920). Detected when `REMOTE_ADDR` is private/reserved — which an
	 * external client cannot forge — and a Cloudflare edge is the first public
	 * hop in `X-Forwarded-For`. The `secure` strategy trusts `CF-Connecting-IP`
	 * for this topology without the admin having to register the LB manually.
	 *
	 * @var string
	 */
	public const VERDICT_CLOUDFLARE_VIA_PROXY = 'cloudflare_via_proxy';

	/**
	 * Environment verdict: the TCP peer is a configured trusted proxy.
	 *
	 * @var string
	 */
	public const VERDICT_TRUSTED_PROXY = 'trusted_proxy';

	/**
	 * Environment verdict: direct connection — REMOTE_ADDR is the client.
	 *
	 * @var string
	 */
	public const VERDICT_DIRECT = 'direct';

	/**
	 * Forwarded-for header precedence for the legacy walk. Kept byte-for-byte
	 * identical to the historical `RequestInput::get_user_ip()` order so the
	 * legacy strategy is a pure move (no behaviour change).
	 *
	 * @var array<int, string>
	 */
	private const LEGACY_HEADER_CHAIN = array(
		'HTTP_CLIENT_IP',
		'HTTP_X_FORWARDED_FOR',
		'HTTP_X_FORWARDED',
		'HTTP_X_CLUSTER_CLIENT_IP',
		'HTTP_FORWARDED_FOR',
		'HTTP_FORWARDED',
		'REMOTE_ADDR',
	);

	/**
	 * Bundled Cloudflare IPv4 CIDR ranges (fallback set).
	 *
	 * Phase 2 (#901) adds a cron-refreshed copy via the `ffc_cloudflare_ip_ranges`
	 * filter; this constant is the offline fallback so the secure strategy is
	 * functional and unit-testable today. Source: https://www.cloudflare.com/ips-v4
	 *
	 * @var array<int, string>
	 */
	private const CLOUDFLARE_IPV4 = array(
		'173.245.48.0/20',
		'103.21.244.0/22',
		'103.22.200.0/22',
		'103.31.4.0/22',
		'141.101.64.0/18',
		'108.162.192.0/18',
		'190.93.240.0/20',
		'188.114.96.0/20',
		'197.234.240.0/22',
		'198.41.128.0/17',
		'162.158.0.0/15',
		'104.16.0.0/13',
		'104.24.0.0/14',
		'172.64.0.0/13',
		'131.0.72.0/22',
	);

	/**
	 * Bundled Cloudflare IPv6 CIDR ranges (fallback set).
	 * Source: https://www.cloudflare.com/ips-v6
	 *
	 * @var array<int, string>
	 */
	private const CLOUDFLARE_IPV6 = array(
		'2400:cb00::/32',
		'2606:4700::/32',
		'2803:f800::/32',
		'2405:b500::/32',
		'2405:8100::/32',
		'2a06:98c0::/29',
		'2c0f:f248::/32',
	);

	/**
	 * Resolve the client IP using the effective strategy.
	 *
	 * This is the single entry point the rest of the plugin should call
	 * (via `RequestInput::get_user_ip()`). During phase 1 the effective
	 * strategy is `legacy`, so the returned value matches the pre-#899
	 * behaviour exactly.
	 *
	 * @return string IP address, or {@see self::UNKNOWN} when unresolved.
	 */
	public static function resolve(): string {
		$mode = self::mode();
		$ip   = self::MODE_SECURE === $mode ? self::resolve_secure() : self::resolve_legacy();

		self::maybe_shadow_log( $mode, $ip );

		return $ip;
	}

	/**
	 * The effective resolution strategy.
	 *
	 * Defaults to `legacy` (phase 1). Phase 2 wires this to an admin setting;
	 * phase 3 flips the default to `secure`. Any unrecognised filter value
	 * falls back to `legacy` so a misconfiguration never hard-fails a request.
	 *
	 * @return string One of {@see self::MODE_LEGACY} / {@see self::MODE_SECURE}.
	 */
	public static function mode(): string {
		/**
		 * Filter the effective client-IP resolution strategy.
		 *
		 * @since 6.19.0
		 * @param string $mode Either 'legacy' or 'secure'. Default 'legacy'.
		 */
		$mode = (string) apply_filters( 'ffc_ip_resolver_mode', self::MODE_LEGACY );

		return in_array( $mode, array( self::MODE_LEGACY, self::MODE_SECURE ), true )
			? $mode
			: self::MODE_LEGACY;
	}

	/**
	 * Classify the connection environment of a TCP peer — the verdict the
	 * phase-2 diagnostic surface (#901) renders and the "should we show the
	 * Cloudflare setup guide?" decision hinges on.
	 *
	 * @param string|null $remote TCP peer to classify. Defaults to the current
	 *                            request's `REMOTE_ADDR`.
	 * @return string One of self::VERDICT_* (unresolvable peer → `direct`).
	 */
	public static function classify( ?string $remote = null ): string {
		if ( null === $remote ) {
			$remote = self::server_string( 'REMOTE_ADDR' );
		}

		if ( '' === $remote || ! filter_var( $remote, FILTER_VALIDATE_IP ) ) {
			return self::VERDICT_DIRECT;
		}

		if ( self::ip_in_any_range( $remote, self::cloudflare_ranges() ) ) {
			return self::VERDICT_CLOUDFLARE;
		}

		if ( self::ip_in_any_range( $remote, self::trusted_proxies() ) ) {
			return self::VERDICT_TRUSTED_PROXY;
		}

		// Cloudflare upstream of a non-CF, non-trusted TCP peer — the common
		// managed-hosting topology CF → host LB → PHP (#920). Only trust this
		// when the peer is private/reserved (a client cannot forge its own TCP
		// peer address) AND a Cloudflare edge is the first public hop in XFF.
		// In `direct` proxy-mode the CF range set is cleared upstream, so this
		// never fires there and the verdict correctly falls through to `direct`.
		if ( self::is_private_or_reserved( $remote ) ) {
			$boundary = self::first_public_xff_hop();
			if ( null !== $boundary && self::ip_in_any_range( $boundary, self::cloudflare_ranges() ) ) {
				return self::VERDICT_CLOUDFLARE_VIA_PROXY;
			}
		}

		return self::VERDICT_DIRECT;
	}

	/**
	 * True when the current request reached PHP through Cloudflare — either the
	 * TCP peer is a CF edge ({@see self::VERDICT_CLOUDFLARE}) or CF sits upstream
	 * of the host's reverse proxy ({@see self::VERDICT_CLOUDFLARE_VIA_PROXY}).
	 *
	 * Shared detection helper (#920): the IP-diagnostics surface and the
	 * Cloudflare page-cache safety card (#921) both gate on it.
	 */
	public static function is_behind_cloudflare(): bool {
		$verdict = self::classify();
		return self::VERDICT_CLOUDFLARE === $verdict || self::VERDICT_CLOUDFLARE_VIA_PROXY === $verdict;
	}

	/**
	 * Legacy strategy — walk the forwarded-for chain left→right and return the
	 * first public, non-reserved IP. Spoofable; retained only for phase-1
	 * behavioural parity.
	 *
	 * @return string IP address, or {@see self::UNKNOWN} when none validates.
	 */
	public static function resolve_legacy(): string {
		foreach ( self::LEGACY_HEADER_CHAIN as $key ) {
			if ( ! array_key_exists( $key, $_SERVER ) ) {
				continue;
			}
			foreach ( explode( ',', self::server_string( $key ) ) as $candidate ) {
				$candidate = trim( $candidate );
				if ( filter_var( $candidate, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
					return $candidate;
				}
			}
		}

		return self::UNKNOWN;
	}

	/**
	 * Secure strategy — the trusted-proxy decision tree.
	 *
	 * 1. If `REMOTE_ADDR` is a Cloudflare edge address, trust `CF-Connecting-IP`
	 *    (Cloudflare overwrites any client-supplied copy at the edge).
	 * 2. Else if `REMOTE_ADDR` is a configured trusted proxy, walk
	 *    `X-Forwarded-For` right→left, discarding trusted hops; the first
	 *    untrusted address is the real client.
	 * 3. Else if `REMOTE_ADDR` is private/reserved (a host load balancer) and a
	 *    Cloudflare edge is the first public hop in `X-Forwarded-For`, trust
	 *    `CF-Connecting-IP` — the CF → host-LB → PHP topology (#920). Safe
	 *    because the client cannot forge a private TCP peer.
	 * 4. Else the connection is direct — `REMOTE_ADDR` IS the client; every
	 *    forwarded header is ignored (cannot be spoofed into the result).
	 *
	 * @return string IP address, or {@see self::UNKNOWN} when unresolved.
	 */
	public static function resolve_secure(): string {
		$remote = self::server_string( 'REMOTE_ADDR' );

		if ( '' === $remote || ! filter_var( $remote, FILTER_VALIDATE_IP ) ) {
			return self::UNKNOWN;
		}

		$cloudflare = self::cloudflare_ranges();
		$trusted    = array_merge( $cloudflare, self::trusted_proxies() );

		// 1. Behind Cloudflare → the CDN header is authoritative.
		if ( self::ip_in_any_range( $remote, $cloudflare ) ) {
			$cf = self::server_string( 'HTTP_CF_CONNECTING_IP' );
			if ( '' !== $cf && filter_var( $cf, FILTER_VALIDATE_IP ) ) {
				return $cf;
			}
		}

		// 2. Behind a configured trusted proxy → right→left XFF walk.
		if ( self::ip_in_any_range( $remote, $trusted ) ) {
			$client = self::rightmost_untrusted_xff( $trusted );
			if ( null !== $client ) {
				return $client;
			}
		}

		// 3. Host-proxy topology (#920): a private/reserved TCP peer (managed-
		// hosting LB) with a Cloudflare edge as the first public XFF hop. The
		// client cannot forge a private REMOTE_ADDR, so CF-Connecting-IP is
		// trustworthy here without the admin registering the LB by hand. Skipped
		// automatically in `direct` mode (the CF range set is cleared upstream).
		if ( self::is_private_or_reserved( $remote ) ) {
			$boundary = self::first_public_xff_hop();
			if ( null !== $boundary && self::ip_in_any_range( $boundary, $cloudflare ) ) {
				$cf = self::server_string( 'HTTP_CF_CONNECTING_IP' );
				if ( '' !== $cf && filter_var( $cf, FILTER_VALIDATE_IP ) ) {
					return $cf;
				}
			}
		}

		// 4. Direct connection → the TCP peer is the client.
		return $remote;
	}

	/**
	 * Walk `X-Forwarded-For` right→left and return the first address that is
	 * NOT in the trusted set. Returns null when the header is absent/empty or
	 * every hop is trusted (caller then falls back to `REMOTE_ADDR`).
	 *
	 * @param array<int, string> $trusted Trusted CIDR ranges / literal IPs.
	 * @return string|null Real client IP, or null when none found.
	 */
	private static function rightmost_untrusted_xff( array $trusted ): ?string {
		$raw = self::server_string( 'HTTP_X_FORWARDED_FOR' );
		if ( '' === $raw ) {
			return null;
		}

		$hops = array_map( 'trim', explode( ',', $raw ) );

		for ( $i = count( $hops ) - 1; $i >= 0; $i-- ) {
			$hop = $hops[ $i ];
			if ( '' === $hop || ! filter_var( $hop, FILTER_VALIDATE_IP ) ) {
				continue;
			}
			if ( ! self::ip_in_any_range( $hop, $trusted ) ) {
				return $hop;
			}
		}

		return null;
	}

	/**
	 * Walk `X-Forwarded-For` right→left and return the first PUBLIC address —
	 * i.e. the boundary between the host's own infrastructure (private/reserved
	 * hops, skipped) and the upstream internet. For the CF → host-LB → PHP
	 * topology this boundary hop is the Cloudflare edge, which is how the
	 * {@see self::VERDICT_CLOUDFLARE_VIA_PROXY} detection and the resolve_secure
	 * step 3 prove Cloudflare is genuinely upstream (#920).
	 *
	 * @return string|null First public hop scanning right→left, or null when the
	 *                     header is absent or holds no public address.
	 */
	private static function first_public_xff_hop(): ?string {
		$raw = self::server_string( 'HTTP_X_FORWARDED_FOR' );
		if ( '' === $raw ) {
			return null;
		}

		$hops = array_map( 'trim', explode( ',', $raw ) );

		for ( $i = count( $hops ) - 1; $i >= 0; $i-- ) {
			$hop = $hops[ $i ];
			if ( '' === $hop || ! filter_var( $hop, FILTER_VALIDATE_IP ) ) {
				continue;
			}
			if ( self::is_private_or_reserved( $hop ) ) {
				continue;
			}
			return $hop;
		}

		return null;
	}

	/**
	 * True when `$ip` is a valid address that is NOT a public, non-reserved one
	 * — i.e. an RFC 1918 / unique-local / loopback / link-local / reserved
	 * address. A host load balancer in front of PHP virtually always presents
	 * such an address as `REMOTE_ADDR`, and a remote client cannot forge its own
	 * TCP peer into this space — which is what makes the step-3 CF trust safe.
	 *
	 * @param string $ip Candidate IP.
	 */
	private static function is_private_or_reserved( string $ip ): bool {
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false;
		}
		return ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	}

	/**
	 * The Cloudflare CIDR set (bundled fallback, filterable).
	 *
	 * @return array<int, string>
	 */
	public static function cloudflare_ranges(): array {
		$ranges = array_merge( self::CLOUDFLARE_IPV4, self::CLOUDFLARE_IPV6 );

		/**
		 * Filter the Cloudflare edge CIDR ranges used to detect a CF request.
		 * Phase 2 feeds a cron-refreshed list here.
		 *
		 * @since 6.19.0
		 * @param array<int, string> $ranges Bundled Cloudflare CIDR ranges.
		 */
		$filtered = apply_filters( 'ffc_cloudflare_ip_ranges', $ranges );

		// The `(array)` cast + is_string filter keep runtime safety against a
		// misbehaving third-party filter; PHPStan already knows the declared
		// return type, so no `is_array()` guard (it flags as always-true).
		return array_values( array_filter( (array) $filtered, 'is_string' ) );
	}

	/**
	 * Admin-configured trusted (non-Cloudflare) proxy CIDRs / IPs. Empty by
	 * default — phase 2 exposes this as a setting; today it is filter-only.
	 *
	 * @return array<int, string>
	 */
	public static function trusted_proxies(): array {
		/**
		 * Filter the set of trusted reverse-proxy CIDR ranges / literal IPs.
		 *
		 * @since 6.19.0
		 * @param array<int, string> $proxies Trusted proxy ranges. Default empty.
		 */
		$proxies = apply_filters( 'ffc_trusted_proxies', array() );

		// See cloudflare_ranges(): defensive cast/filter without an is_array()
		// guard that PHPStan would report as always-true.
		return array_values( array_filter( (array) $proxies, 'is_string' ) );
	}

	/**
	 * Shadow-mode divergence logging (dormant by default).
	 *
	 * When the effective mode is `legacy` and `ffc_ip_shadow_logging` is on,
	 * compute what `secure` would return and log — with a HASHED IP — when the
	 * two disagree. Off by default so phase 1 adds zero cost/behaviour.
	 *
	 * @param string $mode          Effective mode used for this request.
	 * @param string $effective_ip  IP the effective mode returned.
	 */
	private static function maybe_shadow_log( string $mode, string $effective_ip ): void {
		if ( self::MODE_LEGACY !== $mode ) {
			return;
		}

		/**
		 * Filter: enable dormant legacy-vs-secure divergence logging.
		 *
		 * @since 6.19.0
		 * @param bool $enabled Default false.
		 */
		if ( ! apply_filters( 'ffc_ip_shadow_logging', false ) ) {
			return;
		}

		$secure_ip = self::resolve_secure();
		if ( $secure_ip === $effective_ip ) {
			return;
		}

		if ( ! class_exists( '\FreeFormCertificate\Core\Debug' ) ) {
			return;
		}

		// Routed through the frontend debug area (no dedicated security area
		// exists yet); phase 2 gives shadow logging its own diagnostic surface.
		Debug::log(
			Debug::AREA_FRONTEND,
			'client-ip legacy/secure divergence',
			array(
				'legacy_hash' => self::hash_ip( $effective_ip ),
				'secure_hash' => self::hash_ip( $secure_ip ),
			)
		);
	}

	/**
	 * Read + unslash + sanitize a `$_SERVER` string value. Returns '' when
	 * absent or non-string.
	 *
	 * @param string $key `$_SERVER` key.
	 * @return string Sanitized value, or '' when absent.
	 */
	private static function server_string( string $key ): string {
		if ( ! isset( $_SERVER[ $key ] ) ) {
			return '';
		}
		$raw = wp_unslash( $_SERVER[ $key ] );
		if ( ! is_string( $raw ) ) {
			return '';
		}
		return sanitize_text_field( $raw );
	}

	/**
	 * True when `$ip` falls inside any of the given CIDR ranges (or equals a
	 * literal IP entry).
	 *
	 * @param string             $ip     Candidate IP.
	 * @param array<int, string> $ranges CIDR ranges / literal IPs.
	 */
	private static function ip_in_any_range( string $ip, array $ranges ): bool {
		foreach ( $ranges as $range ) {
			if ( self::ip_in_range( $ip, $range ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * True when `$ip` is inside CIDR `$range` (v4 or v6). A range without a `/`
	 * is treated as a literal single-address match.
	 *
	 * @param string $ip    Candidate IP.
	 * @param string $range CIDR range or literal IP.
	 */
	private static function ip_in_range( string $ip, string $range ): bool {
		if ( strpos( $range, '/' ) === false ) {
			return $ip === $range;
		}

		list( $subnet, $bits ) = explode( '/', $range, 2 );

		$ip_bin     = inet_pton( $ip );
		$subnet_bin = inet_pton( $subnet );

		if ( false === $ip_bin || false === $subnet_bin || strlen( $ip_bin ) !== strlen( $subnet_bin ) ) {
			// Mismatched families (v4 vs v6) never match.
			return false;
		}

		$bits = (int) $bits;
		if ( $bits < 0 || $bits > ( strlen( $ip_bin ) * 8 ) ) {
			return false;
		}

		$whole_bytes = intdiv( $bits, 8 );
		$remainder   = $bits % 8;

		if ( $whole_bytes > 0 && strncmp( $ip_bin, $subnet_bin, $whole_bytes ) !== 0 ) {
			return false;
		}

		if ( 0 === $remainder ) {
			return true;
		}

		$mask     = chr( 0xFF << ( 8 - $remainder ) & 0xFF );
		$ip_byte  = $ip_bin[ $whole_bytes ];
		$sub_byte = $subnet_bin[ $whole_bytes ];

		return ( ord( $ip_byte ) & ord( $mask ) ) === ( ord( $sub_byte ) & ord( $mask ) );
	}

	/**
	 * Hash an IP for logs/telemetry per the project PII convention. Never log
	 * a raw IP.
	 *
	 * @param string $ip IP address.
	 * @return string 16-char sha256 prefix.
	 */
	private static function hash_ip( string $ip ): string {
		return substr( hash( 'sha256', $ip ), 0, 16 );
	}
}
