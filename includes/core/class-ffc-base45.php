<?php
/**
 * Base45, the encoding QR codes carry most compactly.
 *
 * @package FreeFormCertificate\Core
 * @since   6.50.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base45 (RFC 9285), the encoding of the presence QR code (#2742).
 *
 * Its 45 characters are exactly the QR alphanumeric set, which the QR packs
 * at 5.5 bits a character where byte mode spends 8, so the same bytes make a
 * code about a quarter smaller than base64 would. Two bytes become three
 * characters, a final odd byte two.
 */
final class Base45 {

	/**
	 * The alphabet, in value order.
	 */
	public const ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ $%*+-./:';

	/**
	 * Encode bytes.
	 *
	 * @param string $bytes Raw bytes.
	 */
	public static function encode( string $bytes ): string {
		$out    = '';
		$length = strlen( $bytes );
		for ( $i = 0; $i + 1 < $length; $i += 2 ) {
			$n    = ord( $bytes[ $i ] ) * 256 + ord( $bytes[ $i + 1 ] );
			$out .= self::ALPHABET[ $n % 45 ] . self::ALPHABET[ intdiv( $n, 45 ) % 45 ] . self::ALPHABET[ intdiv( $n, 2025 ) ];
		}
		if ( 1 === $length % 2 ) {
			$n    = ord( $bytes[ $length - 1 ] );
			$out .= self::ALPHABET[ $n % 45 ] . self::ALPHABET[ intdiv( $n, 45 ) ];
		}
		return $out;
	}

	/**
	 * Decode text.
	 *
	 * @param string $text Base45 text.
	 * @return string|null The bytes, or null when the text is not base45.
	 */
	public static function decode( string $text ): ?string {
		$length = strlen( $text );
		if ( 1 === $length % 3 ) {
			return null;
		}
		$out = '';
		for ( $i = 0; $i < $length; $i += 3 ) {
			$chunk = substr( $text, $i, 3 );
			$n     = 0;
			$scale = 1;
			for ( $j = 0, $size = strlen( $chunk ); $j < $size; $j++ ) {
				$value = strpos( self::ALPHABET, $chunk[ $j ] );
				if ( false === $value ) {
					return null;
				}
				$n     += $value * $scale;
				$scale *= 45;
			}
			if ( 3 === strlen( $chunk ) ) {
				if ( $n > 0xFFFF ) {
					return null;
				}
				$out .= chr( intdiv( $n, 256 ) ) . chr( $n % 256 );
			} else {
				if ( $n > 0xFF ) {
					return null;
				}
				$out .= chr( $n );
			}
		}
		return $out;
	}
}
