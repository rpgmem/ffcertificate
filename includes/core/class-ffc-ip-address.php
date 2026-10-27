<?php
/**
 * IpAddress
 *
 * The one rule for telling whether two requests come from the same visitor
 * by their address (#2106). A resolver hands back a string, and two strings
 * can name one caller: `2001:DB8::1` and `2001:db8:0:0::1` are the same
 * address, `::ffff:192.0.2.1` is the IPv4 address `192.0.2.1`, and every
 * address inside one IPv6 /64 belongs to one subscriber, who can rotate
 * through it at will.
 *
 * It identifies a caller for counting; it does not decide what is shown,
 * stored as an audit record or geolocated, where the full address is the
 * right value. It also owns the range comparison every address list uses
 * — trusted proxies and the Rate Limit allow and deny lists (#2109) — so
 * a list and a counter agree on what one address is.
 *
 * @package FreeFormCertificate\Core
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Canonical form and counting key of a client address.
 */
final class IpAddress {

	/**
	 * The address in its canonical text form, or null when it is not one.
	 *
	 * An IPv4 address written inside IPv6 comes back as IPv4, and an IPv6
	 * address in the compressed lower-case form `inet_ntop()` writes. The
	 * resolver's sentinel for an unread address is not an address.
	 *
	 * @param string $ip Address as resolved.
	 * @return string|null
	 */
	public static function canonical( string $ip ): ?string {
		$binary = self::binary( $ip );
		if ( null === $binary ) {
			return null;
		}

		$text = inet_ntop( $binary );

		return false === $text ? null : $text;
	}

	/**
	 * What a caller is counted by, or null when the address is unresolved.
	 *
	 * An IPv4 address whole, an IPv6 address by its /64.
	 *
	 * @param string $ip Address as resolved.
	 * @return string|null
	 */
	public static function rate_key( string $ip ): ?string {
		$binary = self::binary( $ip );
		if ( null === $binary ) {
			return null;
		}

		return 4 === strlen( $binary )
			? 'v4:' . bin2hex( $binary )
			: 'v6:' . bin2hex( substr( $binary, 0, 8 ) ) . '::/64';
	}

	/**
	 * Who a caller is, for anything that counts, limits or owns by address,
	 * without the address itself (#2109).
	 *
	 * A keyed hash of the counting key, so it follows `rate_key()`: one
	 * value for every spelling of an address, for an IPv4 address inside
	 * IPv6, and for a whole IPv6 /64. An unresolved address has no key, and
	 * its callers share one fingerprint, which is what the counters did
	 * with the resolver's sentinel before. The key is the plugin's hash
	 * secret, so the IPv4 space cannot be enumerated against a stored
	 * value without `wp-config.php`.
	 *
	 * @param string $ip Address as resolved.
	 * @return string 64 hex characters.
	 */
	public static function fingerprint( string $ip ): string {
		return Encryption::keyed_hash( self::rate_key( $ip ) ?? 'unresolved', 'ip' );
	}

	/**
	 * A list entry in canonical form, or null when it is neither an address
	 * nor a CIDR range (#2109).
	 *
	 * An address comes back as `canonical()` writes it. A range comes back
	 * as its network address and prefix length, so `10.1.2.3/8` reads
	 * `10.0.0.0/8`, and an IPv4-mapped IPv6 range of /96 or longer reads as
	 * the IPv4 range it covers.
	 *
	 * @param string $entry One line of an allow or deny list.
	 * @return string|null
	 */
	public static function range( string $entry ): ?string {
		$parsed = self::parse_range( $entry );
		if ( null === $parsed ) {
			return null;
		}

		list( $network, $bits ) = $parsed;

		$text = inet_ntop( $network );
		if ( false === $text ) {
			return null;
		}

		return null === $bits ? $text : $text . '/' . $bits;
	}

	/**
	 * Whether an address falls inside a list entry, an address or a CIDR
	 * range, IPv4 or IPv6 (#2109).
	 *
	 * Both sides are canonicalised first, so spelling never decides a match
	 * and an IPv4 address written inside IPv6 matches an IPv4 entry. An
	 * IPv4 address never matches an IPv6 range, nor the other way round.
	 *
	 * @param string $ip    Address as resolved.
	 * @param string $entry Address or CIDR range.
	 * @return bool
	 */
	public static function in_range( string $ip, string $entry ): bool {
		$address = self::binary( $ip );
		$parsed  = self::parse_range( $entry );
		if ( null === $address || null === $parsed ) {
			return false;
		}

		list( $network, $bits ) = $parsed;
		if ( strlen( $address ) !== strlen( $network ) ) {
			return false;
		}

		return null === $bits
			? $address === $network
			: self::mask( $address, $bits ) === $network;
	}

	/**
	 * Whether an address falls inside any entry of a list.
	 *
	 * @param string            $ip      Address as resolved.
	 * @param array<int, mixed> $entries Addresses or CIDR ranges.
	 * @return bool
	 */
	public static function in_any( string $ip, array $entries ): bool {
		foreach ( $entries as $entry ) {
			if ( is_string( $entry ) && self::in_range( $ip, $entry ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Packed network address and prefix length of a list entry; the length
	 * is null for a single address.
	 *
	 * @param string $entry Address or CIDR range.
	 * @return array{0: string, 1: int|null}|null
	 */
	private static function parse_range( string $entry ): ?array {
		$entry = trim( $entry );
		$bits  = null;

		if ( false !== strpos( $entry, '/' ) ) {
			list( $entry, $length ) = explode( '/', $entry, 2 );
			if ( ! preg_match( '/^\d{1,3}$/', $length ) ) {
				return null;
			}
			$bits = (int) $length;
		}

		if ( ! filter_var( $entry, FILTER_VALIDATE_IP ) ) {
			return null;
		}

		$packed = inet_pton( $entry );
		if ( false === $packed ) {
			return null;
		}

		if ( self::is_mapped( $packed ) && ( null === $bits || $bits >= 96 ) ) {
			$packed = substr( $packed, 12 );
			$bits   = null === $bits ? null : $bits - 96;
		}

		if ( null === $bits ) {
			return array( $packed, null );
		}

		if ( $bits > strlen( $packed ) * 8 ) {
			return null;
		}

		return array( self::mask( $packed, $bits ), $bits );
	}

	/**
	 * The first `$bits` bits of a packed address, the rest zeroed.
	 *
	 * @param string $packed Packed address.
	 * @param int    $bits   Prefix length.
	 * @return string
	 */
	private static function mask( string $packed, int $bits ): string {
		$whole     = intdiv( $bits, 8 );
		$remainder = $bits % 8;
		$masked    = substr( $packed, 0, $whole );

		if ( $remainder > 0 ) {
			$masked .= chr( ord( $packed[ $whole ] ) & ( 0xFF << ( 8 - $remainder ) & 0xFF ) );
		}

		return str_pad( $masked, strlen( $packed ), "\0" );
	}

	/**
	 * Whether a packed IPv6 address is an IPv4 address written inside it.
	 *
	 * @param string $packed Packed address.
	 * @return bool
	 */
	private static function is_mapped( string $packed ): bool {
		return 16 === strlen( $packed ) && str_repeat( "\0", 10 ) . "\xff\xff" === substr( $packed, 0, 12 );
	}

	/**
	 * Packed address, with an IPv4-mapped IPv6 address reduced to IPv4.
	 *
	 * @param string $ip Address as resolved.
	 * @return string|null
	 */
	private static function binary( string $ip ): ?string {
		if ( '' === $ip || ClientIpResolver::UNKNOWN === $ip || ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return null;
		}

		$binary = inet_pton( $ip );
		if ( false === $binary ) {
			return null;
		}

		if ( self::is_mapped( $binary ) ) {
			$binary = substr( $binary, 12 );
		}

		return $binary;
	}
}
