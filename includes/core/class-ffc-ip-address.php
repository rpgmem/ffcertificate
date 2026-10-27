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
 * right value.
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

		if ( 16 === strlen( $binary ) && str_repeat( "\0", 10 ) . "\xff\xff" === substr( $binary, 0, 12 ) ) {
			$binary = substr( $binary, 12 );
		}

		return $binary;
	}
}
